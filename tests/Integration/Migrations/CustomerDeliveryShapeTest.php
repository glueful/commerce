<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Migrations;

use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * Freezes the migration-008 table shapes (address books, addresses, downloads, download
 * grants) and the migration-004 folded `commerce_order_lines.downloads` snapshot column
 * before any repository/service code consumes them. Exercises the database-level invariants
 * directly against the schema: the address-book (tenant_uuid, user_uuid) parent unique, the
 * downloads (variant_uuid, blob_uuid) definition unique, the grants (order_uuid,
 * download_uuid) idempotency unique, and the grants token_hash unique -- which the spec
 * requires to be GLOBAL (it must reject a collision across two different tenants, unlike
 * every other unique in this schema which is scoped per-tenant).
 *
 * Also pins down a platform quirk load-bearing for Task 4: the two grant uniques are given
 * explicit constraint names (`uniq_grant_order_download`, `uniq_grant_token_hash`) so MySQL
 * and PostgreSQL surface them in driver error messages, but SQLite silently discards custom
 * names for UNIQUE constraints declared inline inside CREATE TABLE. See
 * testGrantUniqueConstraintNamesAreNotPreservedBySqliteInlineCreateTable().
 */
final class CustomerDeliveryShapeTest extends CommerceTestCase
{
    public function testAllFourCustomerDeliveryTablesExist(): void
    {
        $tables = [
            'commerce_customer_address_books',
            'commerce_customer_addresses',
            'commerce_downloads',
            'commerce_download_grants',
        ];

        $schema = $this->connection->getSchemaBuilder();
        foreach ($tables as $table) {
            self::assertTrue($schema->hasTable($table), "missing table {$table}");
        }
    }

    public function testAddressBookRevisionDefaultsToZeroWhenOmitted(): void
    {
        $this->connection->table('commerce_customer_address_books')->insert([
            'uuid' => 'abook0000001',
            'tenant_uuid' => '',
            'user_uuid' => 'user000000001',
        ]);

        $row = $this->connection->table('commerce_customer_address_books')
            ->where('uuid', '=', 'abook0000001')
            ->first();
        self::assertNotNull($row);
        self::assertSame(0, (int) $row['revision']);
    }

    public function testAddressBookTenantUserUniqueRejectsDuplicateButAllowsCrossTenant(): void
    {
        $user = 'user000000010';

        $this->connection->table('commerce_customer_address_books')->insert([
            'uuid' => 'abook0000010',
            'tenant_uuid' => 'tenantAAAA01',
            'user_uuid' => $user,
        ]);

        // Same user, different tenant -- must succeed.
        $this->connection->table('commerce_customer_address_books')->insert([
            'uuid' => 'abook0000011',
            'tenant_uuid' => 'tenantBBBB02',
            'user_uuid' => $user,
        ]);
        self::assertSame(
            2,
            $this->connection->table('commerce_customer_address_books')
                ->where('user_uuid', '=', $user)
                ->count()
        );

        // Same tenant, same user again -- must be rejected.
        try {
            $this->connection->table('commerce_customer_address_books')->insert([
                'uuid' => 'abook0000012',
                'tenant_uuid' => 'tenantAAAA01',
                'user_uuid' => $user,
            ]);
            self::fail('duplicate (tenant_uuid, user_uuid) address book insert must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    public function testAddressDefaultFlagsDefaultToFalseWhenOmitted(): void
    {
        $this->connection->table('commerce_customer_addresses')->insert([
            'uuid' => 'addr00000001',
            'tenant_uuid' => '',
            'user_uuid' => 'user000000020',
            'address' => '{}',
        ]);

        $row = $this->connection->table('commerce_customer_addresses')
            ->where('uuid', '=', 'addr00000001')
            ->first();
        self::assertNotNull($row);
        self::assertFalse((bool) $row['is_default_shipping']);
        self::assertFalse((bool) $row['is_default_billing']);
        self::assertNull($row['label']);
    }

    public function testDownloadDefinitionUniqueRejectsSameVariantBlobPairTwice(): void
    {
        $variant = 'var000000900';

        $this->connection->table('commerce_downloads')->insert([
            'uuid' => 'dl0000000001',
            'tenant_uuid' => '',
            'variant_uuid' => $variant,
            'blob_uuid' => 'blob00000010',
            'name' => 'Manual.pdf',
        ]);

        try {
            $this->connection->table('commerce_downloads')->insert([
                'uuid' => 'dl0000000002',
                'tenant_uuid' => '',
                'variant_uuid' => $variant,
                'blob_uuid' => 'blob00000010',
                'name' => 'Manual.pdf (dup)',
            ]);
            self::fail('duplicate (variant_uuid, blob_uuid) download insert must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        // A different blob on the same variant is unaffected.
        $this->connection->table('commerce_downloads')->insert([
            'uuid' => 'dl0000000003',
            'tenant_uuid' => '',
            'variant_uuid' => $variant,
            'blob_uuid' => 'blob00000011',
            'name' => 'Bonus.pdf',
        ]);
        self::assertSame(
            2,
            $this->connection->table('commerce_downloads')->where('variant_uuid', '=', $variant)->count()
        );
    }

    public function testDownloadDefinitionDefaultsForPositionAndStatusWhenOmitted(): void
    {
        $this->connection->table('commerce_downloads')->insert([
            'uuid' => 'dl0000000010',
            'tenant_uuid' => '',
            'variant_uuid' => 'var000000901',
            'blob_uuid' => 'blob00000020',
            'name' => 'File.zip',
        ]);

        $row = $this->connection->table('commerce_downloads')->where('uuid', '=', 'dl0000000010')->first();
        self::assertNotNull($row);
        self::assertSame(0, (int) $row['position']);
        self::assertSame('active', $row['status']);
        self::assertNull($row['download_limit']);
        self::assertNull($row['expiry_days']);
    }

    public function testGrantOrderDownloadUniqueRejectsDuplicateIdempotencyKey(): void
    {
        $order = 'order0000900';
        $download = 'dl0000000900';

        $this->connection->table('commerce_download_grants')->insert([
            'uuid' => 'grant0000001',
            'tenant_uuid' => '',
            'order_uuid' => $order,
            'download_uuid' => $download,
            'blob_uuid' => 'blob00000030',
            'name' => 'File.zip',
            'token_hash' => str_repeat('a', 64),
        ]);

        try {
            $this->connection->table('commerce_download_grants')->insert([
                'uuid' => 'grant0000002',
                'tenant_uuid' => '',
                'order_uuid' => $order,
                'download_uuid' => $download,
                'blob_uuid' => 'blob00000030',
                'name' => 'File.zip',
                'token_hash' => str_repeat('b', 64),
            ]);
            self::fail('duplicate (order_uuid, download_uuid) grant insert must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    public function testGrantTokenHashIsGloballyUniqueAcrossTenants(): void
    {
        $sharedHash = str_repeat('c', 64);

        $this->connection->table('commerce_download_grants')->insert([
            'uuid' => 'grant0000010',
            'tenant_uuid' => 'tenantAAAA01',
            'order_uuid' => 'order0000910',
            'download_uuid' => 'dl0000000910',
            'blob_uuid' => 'blob00000040',
            'name' => 'File.zip',
            'token_hash' => $sharedHash,
        ]);

        // Different tenant, different order, different download -- only the token_hash
        // collides. The spec requires this to be rejected because the deep-link lookup is a
        // global correlation read, not a per-tenant one.
        try {
            $this->connection->table('commerce_download_grants')->insert([
                'uuid' => 'grant0000011',
                'tenant_uuid' => 'tenantBBBB02',
                'order_uuid' => 'order0000911',
                'download_uuid' => 'dl0000000911',
                'blob_uuid' => 'blob00000041',
                'name' => 'File2.zip',
                'token_hash' => $sharedHash,
            ]);
            self::fail('duplicate token_hash grant insert must be rejected across tenants');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * T6 review finding (perf, Medium): `CommerceDownloadBlobPolicy` scans both
     * delivery tables by `blob_uuid` on EVERY blob request app-wide. Neither
     * table's existing composite/unique indexes lead with `blob_uuid`
     * ((variant_uuid, blob_uuid) and (order_uuid, download_uuid)/token_hash
     * respectively), so a dedicated single-column index is required for SQLite
     * (and any driver) to use an index seek rather than a full table scan.
     */
    public function testDownloadsTableBlobUuidHasALeadingIndex(): void
    {
        self::assertContains(
            'blob_uuid',
            $this->leadingIndexColumns('commerce_downloads'),
            'commerce_downloads must have an index led by blob_uuid'
        );
    }

    public function testDownloadGrantsTableBlobUuidHasALeadingIndex(): void
    {
        self::assertContains(
            'blob_uuid',
            $this->leadingIndexColumns('commerce_download_grants'),
            'commerce_download_grants must have an index led by blob_uuid'
        );
    }

    public function testGrantMintCountDefaultsToZeroAndNullableColumnsDefaultToNullWhenOmitted(): void
    {
        $this->connection->table('commerce_download_grants')->insert([
            'uuid' => 'grant0000020',
            'tenant_uuid' => '',
            'order_uuid' => 'order0000920',
            'download_uuid' => 'dl0000000920',
            'blob_uuid' => 'blob00000050',
            'name' => 'File.zip',
            'token_hash' => str_repeat('d', 64),
        ]);

        $row = $this->connection->table('commerce_download_grants')->where('uuid', '=', 'grant0000020')->first();
        self::assertNotNull($row);
        self::assertSame(0, (int) $row['mint_count']);
        self::assertNull($row['remaining']);
        self::assertNull($row['expires_at']);
        self::assertNull($row['last_minted_at']);
        self::assertNull($row['revoked_at']);
        self::assertNull($row['refund_access_override_at']);
        self::assertNull($row['refund_access_override_by']);
    }

    public function testFoldedOrderLineDownloadsColumnDefaultsToNullWhenOmitted(): void
    {
        $this->connection->table('commerce_order_lines')->insert([
            'uuid' => 'oline0000900',
            'order_uuid' => 'order0000930',
            'variant_uuid' => 'var000000930',
            'product_name' => 'Widget',
            'sku' => 'WIDGET-9',
            'option_values' => '{}',
            'unit_price' => 1000,
            'quantity' => 1,
            'line_total' => 1000,
        ]);

        $row = $this->connection->table('commerce_order_lines')->where('uuid', '=', 'oline0000900')->first();
        self::assertNotNull($row);
        self::assertNull($row['downloads']);
    }

    /**
     * Migration 008 gives the two grant uniques explicit constraint names so MySQL/PostgreSQL
     * expose them in driver error messages (which Task 4 can use in production). On SQLite,
     * however, UNIQUE constraints declared inline inside CREATE TABLE are NOT given the
     * requested name -- SQLite silently substitutes its own `sqlite_autoindex_*` name. This
     * freezes that behaviour so Task 4 does not accidentally rely on constraint-name parsing
     * in a way that breaks under this SQLite-backed test harness; the portable fallback is to
     * probe which key exists after a violation instead.
     */
    public function testGrantUniqueConstraintNamesAreNotPreservedBySqliteInlineCreateTable(): void
    {
        $pdo = $this->connection->getPDO();
        $stmt = $pdo->query("PRAGMA index_list('commerce_download_grants')");
        self::assertNotFalse($stmt);
        $indexes = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $uniqueIndexNames = array_column(
            array_filter($indexes, static fn (array $row): bool => (int) $row['unique'] === 1),
            'name'
        );

        // uuid, (order_uuid, download_uuid), and token_hash are all unique -- three indexes.
        self::assertGreaterThanOrEqual(3, count($uniqueIndexNames));
        self::assertNotContains(
            'uniq_grant_order_download',
            $uniqueIndexNames,
            'SQLite was expected to discard the requested constraint name'
        );
        self::assertNotContains(
            'uniq_grant_token_hash',
            $uniqueIndexNames,
            'SQLite was expected to discard the requested constraint name'
        );
    }

    /**
     * The leading (seqno = 0) column of every index/unique/primary key defined on
     * $table, per SQLite's PRAGMA introspection. A column only benefits from an
     * index for an equality lookup (`WHERE column = ?`) when it is the LEADING
     * column of some index -- a composite index/unique where the column appears
     * in a non-leading position (e.g. `unique(['variant_uuid', 'blob_uuid'])`)
     * does not serve that purpose.
     *
     * @return list<string>
     */
    private function leadingIndexColumns(string $table): array
    {
        $pdo = $this->connection->getPDO();
        $stmt = $pdo->query("PRAGMA index_list('{$table}')");
        self::assertNotFalse($stmt);
        $indexes = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $leading = [];
        foreach ($indexes as $index) {
            $infoStmt = $pdo->query(sprintf("PRAGMA index_info('%s')", $index['name']));
            self::assertNotFalse($infoStmt);
            $columns = $infoStmt->fetchAll(\PDO::FETCH_ASSOC);
            if ($columns !== []) {
                $leading[] = (string) $columns[0]['name'];
            }
        }

        return $leading;
    }
}
