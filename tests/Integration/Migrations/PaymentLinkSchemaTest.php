<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Migrations;

use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Database\Migrations\CreatePaymentLinksTable;
use Glueful\Extensions\Commerce\Support\DiagnosticsReport;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * Payment-links Task 5 (spec §2.2 bullet 1): the `commerce_payment_links`
 * shape -- every column, the `(tenant_uuid, token_hash)` unique, the
 * `(tenant_uuid, order_uuid, status)` lookup index, and the issued-session
 * index the expiry/cancel guard prefilters read.
 *
 * Gating/fixture-width/self-healing discipline mirrors
 * `Migrations\WalkInOrderSchemaTest` exactly (fresh install via
 * `CommerceTestCase::MIGRATIONS`, a genuine pre-migration v1.10-shape
 * connection for the upgrade lane, and pgsql lanes behind
 * `COMMERCE_TEST_DB_DRIVER`).
 *
 * The one-active-per-order authority is Ruling 7 -- TRANSACTIONAL, never an
 * index. `testTwoActiveLinksForOneOrderCoexistAtTheDatabaseLevel()` pins that
 * deliberately: a partial/filtered unique index here would silently move the
 * authority into the schema and turn a legitimate service-side race resolution
 * into a driver error.
 */
final class PaymentLinkSchemaTest extends CommerceTestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id', 'uuid', 'tenant_uuid', 'order_uuid', 'token_hash', 'status', 'expires_at', 'created_by',
        'consumed_at', 'revoked_at', 'initiation_window_started_at', 'initiation_count',
        'provider_session_issued_at', 'created_at', 'updated_at',
    ];

    // =====================================================================
    // Fresh install (setUp() already ran migration 023 via CommerceTestCase::MIGRATIONS)
    // =====================================================================

    public function testFreshInstallCreatesPaymentLinksTableWithEveryColumn(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        self::assertTrue($schema->hasTable('commerce_payment_links'));

        foreach (self::COLUMNS as $column) {
            self::assertTrue(
                $schema->hasColumn('commerce_payment_links', $column),
                "missing column {$column}"
            );
        }
    }

    public function testInitiationCountDefaultsToZeroAndEveryOptionalStampDefaultsToNull(): void
    {
        $this->connection->table('commerce_payment_links')->insert([
            'uuid' => 'plinkdef0001',
            'tenant_uuid' => '',
            'order_uuid' => 'plinkord0001',
            'token_hash' => str_repeat('a', 64),
            'status' => 'active',
            'expires_at' => '2026-09-01 00:00:00',
            'created_by' => 'plinkactor01',
        ]);

        $row = $this->connection->table('commerce_payment_links')->where('uuid', '=', 'plinkdef0001')->first();
        self::assertNotNull($row);
        self::assertSame(0, (int) $row['initiation_count']);
        self::assertNull($row['initiation_window_started_at']);
        self::assertNull($row['provider_session_issued_at']);
        self::assertNull($row['consumed_at']);
        self::assertNull($row['revoked_at']);
    }

    /**
     * `status` is NOT NULL with NO standing default: this table is brand new, so
     * unlike migration 022's `origin`/`fulfillment_mode` there is no pre-existing
     * body of fixtures forcing a default, and a writer that forgets `status`
     * must fail loudly rather than silently mint an ACTIVE payment link.
     */
    public function testStatusHasNoStandingDefaultSoAWriterThatOmitsItFails(): void
    {
        try {
            $this->connection->table('commerce_payment_links')->insert([
                'uuid' => 'plinknost001',
                'tenant_uuid' => '',
                'order_uuid' => 'plinkord0002',
                'token_hash' => str_repeat('b', 64),
                'expires_at' => '2026-09-01 00:00:00',
                'created_by' => 'plinkactor01',
            ]);
            self::fail('an insert omitting status must fail -- no fail-open default to active');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    public function testExpiresAtIsNotNull(): void
    {
        try {
            $this->connection->table('commerce_payment_links')->insert([
                'uuid' => 'plinknoex001',
                'tenant_uuid' => '',
                'order_uuid' => 'plinkord0003',
                'token_hash' => str_repeat('c', 64),
                'status' => 'active',
                'expires_at' => null,
                'created_by' => 'plinkactor01',
            ]);
            self::fail('expires_at must be NOT NULL');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * `token_hash` is NOT NULL for the same reason `status` carries no default:
     * a row with no credential is not a payment link, and under ANSI NULL
     * semantics it would additionally be exempt from the
     * `(tenant_uuid, token_hash)` unique -- so any number of them could
     * accumulate unnoticed.
     */
    public function testTokenHashIsNotNull(): void
    {
        try {
            $this->connection->table('commerce_payment_links')->insert([
                'uuid' => 'plinknoth001',
                'tenant_uuid' => '',
                'order_uuid' => 'plinkord0007',
                'token_hash' => null,
                'status' => 'active',
                'expires_at' => '2026-09-01 00:00:00',
                'created_by' => 'plinkactor01',
            ]);
            self::fail('token_hash must be NOT NULL');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * Review fix (Important 1): `(tenant_uuid, uuid)` is a ROW IDENTITY
     * guarantee, not a performance index. `PaymentLinkRepository` resolves a
     * link by uuid with `first()` and then revokes/consumes/expires/claims
     * against it -- a duplicate `(tenant, uuid)` would make every one of those
     * operations silently act on an arbitrary row, with no error anywhere.
     * Every other uuid-bearing table in this schema already carries it.
     */
    public function testUniqueTenantUuidRejectsDuplicateButAllowsTheSameUuidForAnotherTenant(): void
    {
        $this->connection->table('commerce_payment_links')->insert([
            'uuid' => 'plinkiden001',
            'tenant_uuid' => 'plinktenI001',
            'order_uuid' => 'plinkordI001',
            'token_hash' => str_repeat('1', 64),
            'status' => 'active',
            'expires_at' => '2026-09-01 00:00:00',
            'created_by' => 'plinkactor01',
        ]);

        try {
            $this->connection->table('commerce_payment_links')->insert([
                'uuid' => 'plinkiden001',
                'tenant_uuid' => 'plinktenI001',
                'order_uuid' => 'plinkordI002',
                'token_hash' => str_repeat('2', 64),
                'status' => 'revoked',
                'expires_at' => '2026-09-01 00:00:00',
                'created_by' => 'plinkactor01',
            ]);
            self::fail('a duplicate (tenant_uuid, uuid) must be rejected -- link identity is not advisory');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }

        // The same uuid under a DIFFERENT tenant is unaffected (the unique is
        // tenant-scoped, like every sibling table's).
        $this->connection->table('commerce_payment_links')->insert([
            'uuid' => 'plinkiden001',
            'tenant_uuid' => 'plinktenI002',
            'order_uuid' => 'plinkordI003',
            'token_hash' => str_repeat('3', 64),
            'status' => 'active',
            'expires_at' => '2026-09-01 00:00:00',
            'created_by' => 'plinkactor01',
        ]);

        self::assertSame(2, $this->connection->table('commerce_payment_links')->count());
    }

    public function testUniqueTenantTokenHashRejectsDuplicateButAllowsOtherTenantOrHash(): void
    {
        $hash = str_repeat('d', 64);

        $this->connection->table('commerce_payment_links')->insert([
            'uuid' => 'plinkuniq001',
            'tenant_uuid' => 'plinktenA001',
            'order_uuid' => 'plinkord0004',
            'token_hash' => $hash,
            'status' => 'active',
            'expires_at' => '2026-09-01 00:00:00',
            'created_by' => 'plinkactor01',
        ]);

        try {
            $this->connection->table('commerce_payment_links')->insert([
                'uuid' => 'plinkuniq002',
                'tenant_uuid' => 'plinktenA001',
                'order_uuid' => 'plinkord0005',
                'token_hash' => $hash,
                'status' => 'revoked',
                'expires_at' => '2026-09-01 00:00:00',
                'created_by' => 'plinkactor01',
            ]);
            self::fail('a duplicate (tenant_uuid, token_hash) must be rejected');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }

        // Same hash, different tenant -- allowed (cross-tenant collisions are not
        // this table's business; the repository always scopes by tenant anyway).
        $this->connection->table('commerce_payment_links')->insert([
            'uuid' => 'plinkuniq003',
            'tenant_uuid' => 'plinktenB002',
            'order_uuid' => 'plinkord0006',
            'token_hash' => $hash,
            'status' => 'active',
            'expires_at' => '2026-09-01 00:00:00',
            'created_by' => 'plinkactor01',
        ]);

        // Same tenant, different hash -- allowed.
        $this->connection->table('commerce_payment_links')->insert([
            'uuid' => 'plinkuniq004',
            'tenant_uuid' => 'plinktenA001',
            'order_uuid' => 'plinkord0004',
            'token_hash' => str_repeat('e', 64),
            'status' => 'active',
            'expires_at' => '2026-09-01 00:00:00',
            'created_by' => 'plinkactor01',
        ]);

        self::assertSame(3, $this->connection->table('commerce_payment_links')->count());
    }

    /**
     * Ruling 7: the one-active-per-order authority is TRANSACTIONAL. The schema
     * must NOT carry a partial unique index over (tenant_uuid, order_uuid) where
     * status = 'active' -- if it did, a legitimate service-side race would become
     * a raw driver error instead of a typed conflict.
     */
    public function testTwoActiveLinksForOneOrderCoexistAtTheDatabaseLevel(): void
    {
        foreach (['plinkrul7001', 'plinkrul7002'] as $i => $uuid) {
            $this->connection->table('commerce_payment_links')->insert([
                'uuid' => $uuid,
                'tenant_uuid' => 'plinktenR001',
                'order_uuid' => 'plinkordR001',
                'token_hash' => str_repeat((string) $i, 64),
                'status' => 'active',
                'expires_at' => '2026-09-01 00:00:00',
                'created_by' => 'plinkactor01',
            ]);
        }

        self::assertSame(
            2,
            $this->connection->table('commerce_payment_links')
                ->where('tenant_uuid', '=', 'plinktenR001')
                ->where('order_uuid', '=', 'plinkordR001')
                ->where('status', '=', 'active')
                ->count()
        );
    }

    public function testEveryClosedStatusValueRoundTrips(): void
    {
        foreach (['active', 'revoked', 'expired', 'consumed'] as $i => $status) {
            $this->connection->table('commerce_payment_links')->insert([
                'uuid' => 'plinkst' . str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'tenant_uuid' => 'plinktenS001',
                'order_uuid' => 'plinkordS001',
                'token_hash' => str_repeat(dechex($i + 10), 64),
                'status' => $status,
                'expires_at' => '2026-09-01 00:00:00',
                'created_by' => 'plinkactor01',
            ]);
        }

        $rows = $this->connection->table('commerce_payment_links')
            ->where('tenant_uuid', '=', 'plinktenS001')
            ->get();
        self::assertSame(
            ['active', 'consumed', 'expired', 'revoked'],
            $this->sortedStatuses($rows)
        );
    }

    // =====================================================================
    // Index shape (SQLite introspection)
    // =====================================================================

    public function testDeclaredIndexesExistWithTheExpectedColumnsOnSqlite(): void
    {
        $indexes = $this->sqliteIndexes('commerce_payment_links');

        self::assertContains(
            ['tenant_uuid', 'uuid'],
            array_values($indexes),
            'the (tenant_uuid, uuid) identity unique must exist'
        );
        self::assertContains(
            ['tenant_uuid', 'token_hash'],
            array_values($indexes),
            'the (tenant_uuid, token_hash) unique must exist'
        );
        self::assertContains(
            ['tenant_uuid', 'order_uuid', 'status'],
            array_values($indexes),
            'the (tenant_uuid, order_uuid, status) lookup index must exist'
        );
        self::assertContains(
            ['tenant_uuid', 'provider_session_issued_at', 'order_uuid'],
            array_values($indexes),
            'the issued-session index the expiry/cancel guard prefilters read must exist'
        );
    }

    /** Ruling 7 again, at the schema-introspection level: no partial/filtered unique. */
    public function testNoPartialUniqueIndexOverOrderStatusExistsOnSqlite(): void
    {
        $sql = (string) $this->connection->getPDO()
            ->query(
                "SELECT COALESCE(GROUP_CONCAT(sql, ' '), '') FROM sqlite_master "
                . "WHERE type = 'index' AND tbl_name = 'commerce_payment_links'"
            )
            ->fetchColumn();

        self::assertStringNotContainsStringIgnoringCase(
            ' WHERE ',
            $sql,
            'no partial (filtered) index may exist on commerce_payment_links -- Ruling 7 is transactional'
        );
    }

    // =====================================================================
    // Upgrade path (SQLite): a genuine v1.10-shape pre-existing install
    // (migrations 001-022 only) converging through migration 023.
    // =====================================================================

    public function testUpgradePathFromV110ShapeCreatesTheTableOnSqlite(): void
    {
        $connection = $this->preMigrationConnection(['engine' => 'sqlite', 'sqlite' => ['primary' => ':memory:']]);
        self::assertFalse(
            $connection->getSchemaBuilder()->hasTable('commerce_payment_links'),
            'the v1.10 shape must genuinely lack the table before the migration runs'
        );

        // A pre-existing v1.10-shape order -- the table this migration references
        // by uuid (no FK is declared anywhere in commerce; the reference is logical).
        $connection->table('commerce_orders')->insert([
            'uuid' => 'plinkupg0001',
            'tenant_uuid' => 'plinkupgt001',
            'order_number' => 'ORD-PLINKUPG-1',
            'email' => 'legacy@example.com',
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
        ]);

        (new CreatePaymentLinksTable())->up($connection->getSchemaBuilder());

        self::assertTrue($connection->getSchemaBuilder()->hasTable('commerce_payment_links'));
        foreach (self::COLUMNS as $column) {
            self::assertTrue(
                $connection->getSchemaBuilder()->hasColumn('commerce_payment_links', $column),
                "missing column {$column} after upgrade"
            );
        }

        // The pre-existing order is untouched and a link can be attached to it.
        $connection->table('commerce_payment_links')->insert([
            'uuid' => 'plinkupgl001',
            'tenant_uuid' => 'plinkupgt001',
            'order_uuid' => 'plinkupg0001',
            'token_hash' => str_repeat('f', 64),
            'status' => 'active',
            'expires_at' => '2026-09-01 00:00:00',
            'created_by' => 'plinkactor01',
        ]);
        self::assertSame(1, $connection->table('commerce_payment_links')->count());
        self::assertSame(
            1,
            $connection->table('commerce_orders')->where('uuid', '=', 'plinkupg0001')->count()
        );
    }

    /**
     * Every migration in this codebase is safe to call `up()` against an
     * already-migrated database. Proven here by running `up()` twice and
     * asserting the second run is a genuine no-op with the table's data intact.
     */
    public function testRerunningUpMigrationIsANoOpOnSqlite(): void
    {
        $connection = $this->preMigrationConnection(['engine' => 'sqlite', 'sqlite' => ['primary' => ':memory:']]);
        $migration = new CreatePaymentLinksTable();
        $schema = $connection->getSchemaBuilder();

        $migration->up($schema);
        $connection->table('commerce_payment_links')->insert([
            'uuid' => 'plinkrerun1',
            'tenant_uuid' => 'plinkrerunt',
            'order_uuid' => 'plinkrero01',
            'token_hash' => str_repeat('9', 64),
            'status' => 'active',
            'expires_at' => '2026-09-01 00:00:00',
            'created_by' => 'plinkactor01',
        ]);

        $migration->up($schema);

        self::assertTrue($schema->hasTable('commerce_payment_links'));
        self::assertSame(1, $connection->table('commerce_payment_links')->count());
    }

    public function testDownDropsTheTableAndUpConvergesAgain(): void
    {
        $connection = $this->preMigrationConnection(['engine' => 'sqlite', 'sqlite' => ['primary' => ':memory:']]);
        $migration = new CreatePaymentLinksTable();
        $schema = $connection->getSchemaBuilder();

        $migration->up($schema);
        self::assertTrue($schema->hasTable('commerce_payment_links'));

        $migration->down($schema);
        self::assertFalse($schema->hasTable('commerce_payment_links'));

        $migration->up($schema);
        self::assertTrue($schema->hasTable('commerce_payment_links'));

        // The re-converged shape still enforces the unique.
        $connection->table('commerce_payment_links')->insert([
            'uuid' => 'plinkdown001',
            'tenant_uuid' => 'plinkdownt1',
            'order_uuid' => 'plinkdowno1',
            'token_hash' => str_repeat('7', 64),
            'status' => 'active',
            'expires_at' => '2026-09-01 00:00:00',
            'created_by' => 'plinkactor01',
        ]);
        try {
            $connection->table('commerce_payment_links')->insert([
                'uuid' => 'plinkdown002',
                'tenant_uuid' => 'plinkdownt1',
                'order_uuid' => 'plinkdowno2',
                'token_hash' => str_repeat('7', 64),
                'status' => 'active',
                'expires_at' => '2026-09-01 00:00:00',
                'created_by' => 'plinkactor01',
            ]);
            self::fail('the (tenant_uuid, token_hash) unique must survive an up->down->up cycle');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }

        // ...and the identity unique too.
        try {
            $connection->table('commerce_payment_links')->insert([
                'uuid' => 'plinkdown001',
                'tenant_uuid' => 'plinkdownt1',
                'order_uuid' => 'plinkdowno3',
                'token_hash' => str_repeat('6', 64),
                'status' => 'active',
                'expires_at' => '2026-09-01 00:00:00',
                'created_by' => 'plinkactor01',
            ]);
            self::fail('the (tenant_uuid, uuid) unique must survive an up->down->up cycle');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }
    }

    // =====================================================================
    // DiagnosticsReport registration -- the tenant purge/adoption inventory.
    // (Kept here with the shape assertions, matching SellerApiKeyShapeTest et al.)
    // =====================================================================

    public function testDiagnosticsCommerceTablesIncludesPaymentLinks(): void
    {
        self::assertContains(
            'commerce_payment_links',
            DiagnosticsReport::commerceTables(),
            'DiagnosticsReport::commerceTables() missing commerce_payment_links'
        );
    }

    /**
     * `commerce_payment_links` carries its own `tenant_uuid`, so it must be a
     * TENANT table -- that membership is what wires both `CommerceTenantPurge`
     * and `TenantAdopter` to it. A surviving row after a purge would leave a
     * hashed bearer credential resolvable against a workspace that no longer
     * exists.
     */
    public function testDiagnosticsTenantTablesIncludesPaymentLinks(): void
    {
        self::assertContains(
            'commerce_payment_links',
            DiagnosticsReport::tenantTables(),
            'DiagnosticsReport::tenantTables() missing commerce_payment_links'
        );
    }

    public function testDiagnosticsReportBuildShowsPaymentLinksPresent(): void
    {
        $report = DiagnosticsReport::build($this->appContext());

        self::assertTrue(
            $report['database']['commerce_tables_present']['commerce_payment_links'] ?? false,
            'DiagnosticsReport::build() must report commerce_payment_links as present'
        );
    }

    // =====================================================================
    // Real-PostgreSQL convergence lanes.
    // =====================================================================

    public function testFreshInstallAcceptsPaymentLinkShapeOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedPgConnection();
        $tenant = 'pgplink0001';
        $connection->table('commerce_payment_links')->where('tenant_uuid', '=', $tenant)->delete();

        try {
            $connection->table('commerce_payment_links')->insert([
                'uuid' => 'pgplinkl001',
                'tenant_uuid' => $tenant,
                'order_uuid' => 'pgplinkord1',
                'token_hash' => str_repeat('a', 64),
                'status' => 'active',
                'expires_at' => '2026-09-01 00:00:00',
                'created_by' => 'pgplinkact1',
            ]);

            $row = $connection->table('commerce_payment_links')->where('uuid', '=', 'pgplinkl001')->first();
            self::assertNotNull($row);
            self::assertSame(0, (int) $row['initiation_count']);
            self::assertNull($row['initiation_window_started_at']);
            self::assertNull($row['provider_session_issued_at']);

            try {
                $connection->table('commerce_payment_links')->insert([
                    'uuid' => 'pgplinkl002',
                    'tenant_uuid' => $tenant,
                    'order_uuid' => 'pgplinkord2',
                    'token_hash' => str_repeat('a', 64),
                    'status' => 'active',
                    'expires_at' => '2026-09-01 00:00:00',
                    'created_by' => 'pgplinkact1',
                ]);
                self::fail('a duplicate (tenant_uuid, token_hash) must be rejected on real PostgreSQL');
            } catch (\PDOException) {
                $this->addToAssertionCount(1);
            }

            try {
                $connection->table('commerce_payment_links')->insert([
                    'uuid' => 'pgplinkl001',
                    'tenant_uuid' => $tenant,
                    'order_uuid' => 'pgplinkord3',
                    'token_hash' => str_repeat('d', 64),
                    'status' => 'active',
                    'expires_at' => '2026-09-01 00:00:00',
                    'created_by' => 'pgplinkact1',
                ]);
                self::fail('a duplicate (tenant_uuid, uuid) must be rejected on real PostgreSQL');
            } catch (\PDOException) {
                $this->addToAssertionCount(1);
            }

            // Ruling 7: two ACTIVE links for one order still coexist on PostgreSQL.
            $connection->table('commerce_payment_links')->insert([
                'uuid' => 'pgplinkl003',
                'tenant_uuid' => $tenant,
                'order_uuid' => 'pgplinkord1',
                'token_hash' => str_repeat('b', 64),
                'status' => 'active',
                'expires_at' => '2026-09-01 00:00:00',
                'created_by' => 'pgplinkact1',
            ]);
            self::assertSame(
                2,
                $connection->table('commerce_payment_links')
                    ->where('tenant_uuid', '=', $tenant)
                    ->where('order_uuid', '=', 'pgplinkord1')
                    ->where('status', '=', 'active')
                    ->count()
            );
        } finally {
            $connection->table('commerce_payment_links')->where('tenant_uuid', '=', $tenant)->delete();
        }
    }

    public function testDeclaredIndexesExistOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedPgConnection();
        $rows = $connection->getPDO()
            ->query("SELECT indexdef FROM pg_indexes WHERE tablename = 'commerce_payment_links'")
            ->fetchAll(\PDO::FETCH_COLUMN);
        $definitions = implode("\n", array_map('strval', $rows));

        self::assertMatchesRegularExpression(
            '/UNIQUE INDEX .*\(tenant_uuid, uuid\)/i',
            $definitions,
            'the (tenant_uuid, uuid) identity unique must exist on PostgreSQL'
        );
        self::assertMatchesRegularExpression(
            '/UNIQUE INDEX .*\(tenant_uuid, token_hash\)/i',
            $definitions,
            'the (tenant_uuid, token_hash) unique must exist on PostgreSQL'
        );
        self::assertMatchesRegularExpression(
            '/\(tenant_uuid, order_uuid, status\)/i',
            $definitions,
            'the (tenant_uuid, order_uuid, status) lookup index must exist on PostgreSQL'
        );
        self::assertMatchesRegularExpression(
            '/\(tenant_uuid, provider_session_issued_at, order_uuid\)/i',
            $definitions,
            'the issued-session prefilter index must exist on PostgreSQL'
        );
        self::assertStringNotContainsStringIgnoringCase(
            ' WHERE ',
            $definitions,
            'no partial (filtered) index may exist -- Ruling 7 is transactional'
        );
    }

    public function testUpgradePathFromV110ShapeOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->preMigrationConnection($this->pgConfig());
        self::assertFalse($connection->getSchemaBuilder()->hasTable('commerce_payment_links'));

        (new CreatePaymentLinksTable())->up($connection->getSchemaBuilder());
        self::assertTrue($connection->getSchemaBuilder()->hasTable('commerce_payment_links'));

        $tenant = 'pgplinkup01';
        $connection->table('commerce_payment_links')->where('tenant_uuid', '=', $tenant)->delete();
        try {
            $connection->table('commerce_payment_links')->insert([
                'uuid' => 'pgplinkup01',
                'tenant_uuid' => $tenant,
                'order_uuid' => 'pgplinkupo1',
                'token_hash' => str_repeat('c', 64),
                'status' => 'active',
                'expires_at' => '2026-09-01 00:00:00',
                'created_by' => 'pgplinkact1',
            ]);
            self::assertSame(
                1,
                $connection->table('commerce_payment_links')->where('tenant_uuid', '=', $tenant)->count()
            );
        } finally {
            $connection->table('commerce_payment_links')->where('tenant_uuid', '=', $tenant)->delete();
        }
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<string>
     */
    private function sortedStatuses(array $rows): array
    {
        $statuses = array_map(static fn (array $row): string => (string) $row['status'], $rows);
        sort($statuses);

        return array_values($statuses);
    }

    /**
     * Every index on the table, keyed by index name, mapping to its column list
     * IN DECLARED ORDER (`PRAGMA index_info` returns rows ordered by `seqno`).
     *
     * @return array<string,list<string>>
     */
    private function sqliteIndexes(string $table): array
    {
        $pdo = $this->connection->getPDO();
        $indexes = [];
        $list = $pdo->query("PRAGMA index_list(" . $pdo->quote($table) . ")")->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($list as $index) {
            $name = (string) $index['name'];
            $columns = $pdo->query('PRAGMA index_info(' . $pdo->quote($name) . ')')->fetchAll(\PDO::FETCH_ASSOC);
            $indexes[$name] = array_values(array_map(static fn (array $c): string => (string) $c['name'], $columns));
        }

        return $indexes;
    }

    private function skipUnlessPgsql(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            self::markTestSkipped('Requires a PostgreSQL test lane to prove migration convergence is portable.');
        }
    }

    /** @return array<string,mixed> */
    private function pgConfig(): array
    {
        return [
            'engine' => 'pgsql',
            'pgsql' => [
                'host' => getenv('DB_PGSQL_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('DB_PGSQL_PORT') ?: 5432),
                'db' => getenv('DB_PGSQL_DATABASE') ?: 'glueful_test',
                'user' => getenv('DB_PGSQL_USERNAME') ?: 'postgres',
                'pass' => getenv('DB_PGSQL_PASSWORD') ?: '',
                'schema' => getenv('DB_PGSQL_SCHEMA') ?: 'public',
            ],
            'pooling' => ['enabled' => false],
        ];
    }

    /**
     * Every migration EXCEPT the one under test -- the exact v1.10 shape a real
     * install has immediately before this migration ships. Drops
     * `commerce_payment_links` first (no-op on a fresh SQLite `:memory:`
     * database; necessary on the shared PostgreSQL fixture database, which an
     * earlier run may already have carried through migration 023).
     *
     * @param array<string,mixed> $config
     */
    private function preMigrationConnection(array $config): Connection
    {
        $connection = new Connection($config + ['pooling' => ['enabled' => false]]);
        $connection->getPDO()->exec('DROP TABLE IF EXISTS commerce_payment_links');
        $schema = $connection->getSchemaBuilder();
        foreach (self::MIGRATIONS as $migration) {
            if ($migration === CreatePaymentLinksTable::class) {
                continue;
            }
            (new $migration())->up($schema);
        }

        return $connection;
    }

    /**
     * The full migration list against the shared PostgreSQL fixture database.
     *
     * `commerce_payment_links` is DROPPED first, unlike the sibling shape tests'
     * equivalent helper. Migration 023 is `hasTable()`-guarded, so once the
     * fixture database holds ANY version of this table `up()` returns
     * immediately -- and 023 is unreleased and was amended in review (the
     * `(tenant_uuid, uuid)` identity unique, `token_hash NOT NULL`), so a
     * fixture database carried through the pre-amendment shape by an earlier run
     * would silently keep it and fail the index assertions below for a reason
     * that has nothing to do with the code under test. Dropping makes this lane
     * self-healing across the amendment. Safe precisely because no released
     * database has ever contained this table: there is no upgrade path to
     * preserve here, only a test fixture to rebuild.
     */
    private function migratedPgConnection(): Connection
    {
        $connection = new Connection($this->pgConfig());
        $connection->getPDO()->exec('DROP TABLE IF EXISTS commerce_payment_links');
        $schema = $connection->getSchemaBuilder();
        foreach (self::MIGRATIONS as $migration) {
            (new $migration())->up($schema);
        }

        return $connection;
    }
}
