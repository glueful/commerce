<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Migrations;

use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Database\Migrations\CreateSellerOrderTables;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * Freezes the MV2 shared-checkout schema foundation (design spec §3) before any
 * allocation/checkout/fulfillment code consumes it:
 *  - `commerce_order_lines` gains `seller_uuid` (nullable), `discount_amount`
 *    (default 0), `tax_amount` (default 0), and the `(order_uuid, seller_uuid)`
 *    index -- folded into the table's existing `createTable` block (§3.1).
 *  - `commerce_orders` gains `marketplace_partitioned` (default false) and
 *    `fulfillment_revision` (default 0) -- folded into its existing
 *    `createTable` block (§3.2).
 *  - `commerce_seller_orders` is a brand-new table (§3.3): every column, the
 *    four uniques, and the two indexes are pinned explicitly.
 *
 * Because the schema builder exposes `hasTable`/`hasColumn` but no `hasIndex`,
 * every index assertion here is made via direct SQLite driver introspection
 * (`PRAGMA index_list` / `PRAGMA index_info`), matching this codebase's
 * established convention (see {@see ReportIndexShapeTest}).
 */
final class MarketplaceOrderShapeTest extends CommerceTestCase
{
    // === commerce_order_lines (folded columns) ===================================

    public function testOrderLinesSellerUuidIsNullableAndDefaultsToNullWhenOmitted(): void
    {
        $this->connection->table('commerce_order_lines')->insert([
            'uuid' => 'oline0mv2001',
            'order_uuid' => 'order0mv2001',
            'variant_uuid' => 'var000mv2001',
            'product_name' => 'Widget',
            'sku' => 'WIDGET-MV2-1',
            'option_values' => '{}',
            'unit_price' => 1000,
            'quantity' => 1,
            'line_total' => 1000,
        ]);

        $row = $this->connection->table('commerce_order_lines')->where('uuid', '=', 'oline0mv2001')->first();
        self::assertNotNull($row);
        self::assertNull($row['seller_uuid']);
        self::assertSame(0, (int) $row['discount_amount']);
        self::assertSame(0, (int) $row['tax_amount']);
    }

    public function testOrderLinesSellerUuidDiscountAmountAndTaxAmountAcceptAssignedValues(): void
    {
        $this->connection->table('commerce_order_lines')->insert([
            'uuid' => 'oline0mv2002',
            'order_uuid' => 'order0mv2001',
            'variant_uuid' => 'var000mv2002',
            'product_name' => 'Widget',
            'sku' => 'WIDGET-MV2-2',
            'option_values' => '{}',
            'unit_price' => 1000,
            'quantity' => 2,
            'line_total' => 2000,
            'seller_uuid' => 'seller0mv2001',
            'discount_amount' => 150,
            'tax_amount' => 80,
        ]);

        $row = $this->connection->table('commerce_order_lines')->where('uuid', '=', 'oline0mv2002')->first();
        self::assertNotNull($row);
        self::assertSame('seller0mv2001', $row['seller_uuid']);
        self::assertSame(150, (int) $row['discount_amount']);
        self::assertSame(80, (int) $row['tax_amount']);
    }

    public function testOrderLinesHasOrderUuidSellerUuidIndex(): void
    {
        $this->assertIndexExists(
            'commerce_order_lines',
            'commerce_order_lines_order_uuid_seller_uuid_index',
            ['order_uuid', 'seller_uuid']
        );
    }

    // === commerce_orders (folded columns) =========================================

    public function testOrdersMarketplacePartitionedDefaultsToFalseWhenOmitted(): void
    {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => 'order0mv2010',
            'order_number' => 'MV2-1001',
            'email' => 'buyer@example.com',
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
        ]);

        $row = $this->connection->table('commerce_orders')->where('uuid', '=', 'order0mv2010')->first();
        self::assertNotNull($row);
        self::assertFalse((bool) $row['marketplace_partitioned']);
        self::assertSame(0, (int) $row['fulfillment_revision']);
    }

    public function testOrdersMarketplacePartitionedAcceptsAssignedTrueValue(): void
    {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => 'order0mv2011',
            'order_number' => 'MV2-1002',
            'email' => 'buyer2@example.com',
            'guest_token_hash' => str_repeat('b', 64),
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
            'marketplace_partitioned' => true,
            'fulfillment_revision' => 3,
        ]);

        $row = $this->connection->table('commerce_orders')->where('uuid', '=', 'order0mv2011')->first();
        self::assertNotNull($row);
        self::assertTrue((bool) $row['marketplace_partitioned']);
        self::assertSame(3, (int) $row['fulfillment_revision']);
    }

    // === commerce_seller_orders (new table) =======================================

    public function testSellerOrdersTableExists(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        self::assertTrue($schema->hasTable('commerce_seller_orders'), 'missing table commerce_seller_orders');
    }

    public function testSellerOrdersHasEverySpecColumn(): void
    {
        $columns = [
            'id',
            'uuid',
            'tenant_uuid',
            'order_uuid',
            'seller_uuid',
            'seller_name_snapshot',
            'partition_number',
            'seller_reference',
            'currency',
            'subtotal',
            'allocated_discount',
            'allocated_shipping_discount',
            'allocated_shipping',
            'allocated_tax',
            'attributed_total',
            'tax_attribution_method',
            'confirmed_at',
            'fulfillment_status',
            'fulfilled_at',
            'carrier',
            'tracking_number',
            'tracking_url',
            'status',
            'revision',
            'created_at',
            'updated_at',
        ];

        $schema = $this->connection->getSchemaBuilder();
        foreach ($columns as $column) {
            self::assertTrue(
                $schema->hasColumn('commerce_seller_orders', $column),
                "commerce_seller_orders missing column {$column}"
            );
        }
    }

    private function minimalSellerOrderRow(array $overrides = []): array
    {
        return array_merge([
            'uuid' => 'selord0mv001',
            'tenant_uuid' => 'tenantmv2001',
            'order_uuid' => 'order0mv2100',
            'seller_uuid' => 'seller0mv2100',
            'seller_name_snapshot' => 'Acme Seller',
            'partition_number' => 1,
            'seller_reference' => 'MV2-1001-1',
            'currency' => 'USD',
            'subtotal' => 1000,
            'attributed_total' => 1000,
            'tax_attribution_method' => 'aggregate_allocated',
        ], $overrides);
    }

    public function testSellerOrdersDefaultsOnMinimalInsert(): void
    {
        $this->connection->table('commerce_seller_orders')->insert($this->minimalSellerOrderRow());

        $row = $this->connection->table('commerce_seller_orders')->where('uuid', '=', 'selord0mv001')->first();
        self::assertNotNull($row);
        self::assertSame('open', $row['status']);
        self::assertSame('unfulfilled', $row['fulfillment_status']);
        self::assertSame(0, (int) $row['revision']);
        self::assertSame(0, (int) $row['allocated_discount']);
        self::assertSame(0, (int) $row['allocated_shipping_discount']);
        self::assertSame(0, (int) $row['allocated_shipping']);
        self::assertSame(0, (int) $row['allocated_tax']);
        self::assertNull($row['confirmed_at']);
        self::assertNull($row['fulfilled_at']);
        self::assertNull($row['carrier']);
        self::assertNull($row['tracking_number']);
        self::assertNull($row['tracking_url']);
        self::assertNull($row['updated_at']);
        self::assertNotNull($row['created_at']);
    }

    public function testSellerOrdersUniqueOrderUuidSellerUuidIsEnforced(): void
    {
        $this->connection->table('commerce_seller_orders')->insert($this->minimalSellerOrderRow([
            'uuid' => 'selord0mv010',
            'seller_reference' => 'MV2-1010-1',
        ]));

        try {
            // Vary partition_number so ONLY the (order_uuid, seller_uuid) unique is violated —
            // otherwise Row B would also collide on (order_uuid, partition_number) and the test
            // would pass even if the (order_uuid, seller_uuid) unique were dropped.
            $this->connection->table('commerce_seller_orders')->insert($this->minimalSellerOrderRow([
                'uuid' => 'selord0mv011',
                'partition_number' => 2,
                'seller_reference' => 'MV2-1010-2',
            ]));
            self::fail('duplicate (order_uuid, seller_uuid) seller-order insert must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    public function testSellerOrdersUniqueOrderUuidPartitionNumberIsEnforced(): void
    {
        $this->connection->table('commerce_seller_orders')->insert($this->minimalSellerOrderRow([
            'uuid' => 'selord0mv020',
            'order_uuid' => 'order0mv2200',
            'seller_uuid' => 'seller0mv2200',
            'partition_number' => 1,
            'seller_reference' => 'MV2-1020-1',
        ]));

        try {
            $this->connection->table('commerce_seller_orders')->insert($this->minimalSellerOrderRow([
                'uuid' => 'selord0mv021',
                'order_uuid' => 'order0mv2200',
                'seller_uuid' => 'seller0mv2201',
                'partition_number' => 1,
                'seller_reference' => 'MV2-1020-2',
            ]));
            self::fail('duplicate (order_uuid, partition_number) seller-order insert must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    public function testSellerOrdersUniqueTenantUuidSellerReferenceIsEnforced(): void
    {
        $this->connection->table('commerce_seller_orders')->insert($this->minimalSellerOrderRow([
            'uuid' => 'selord0mv030',
            'tenant_uuid' => 'tenantmv2030',
            'order_uuid' => 'order0mv2300',
            'seller_uuid' => 'seller0mv2300',
            'seller_reference' => 'MV2-1030-1',
        ]));

        try {
            $this->connection->table('commerce_seller_orders')->insert($this->minimalSellerOrderRow([
                'uuid' => 'selord0mv031',
                'tenant_uuid' => 'tenantmv2030',
                'order_uuid' => 'order0mv2301',
                'seller_uuid' => 'seller0mv2301',
                'seller_reference' => 'MV2-1030-1',
            ]));
            self::fail('duplicate (tenant_uuid, seller_reference) seller-order insert must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    public function testSellerOrdersUniqueTenantUuidUuidIsEnforced(): void
    {
        $this->connection->table('commerce_seller_orders')->insert($this->minimalSellerOrderRow([
            'uuid' => 'selord0mv040',
            'tenant_uuid' => 'tenantmv2040',
            'order_uuid' => 'order0mv2400',
            'seller_uuid' => 'seller0mv2400',
            'seller_reference' => 'MV2-1040-1',
        ]));

        try {
            $this->connection->table('commerce_seller_orders')->insert($this->minimalSellerOrderRow([
                'uuid' => 'selord0mv040',
                'tenant_uuid' => 'tenantmv2040',
                'order_uuid' => 'order0mv2401',
                'seller_uuid' => 'seller0mv2401',
                'seller_reference' => 'MV2-1040-2',
            ]));
            self::fail('duplicate (tenant_uuid, uuid) seller-order insert must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    public function testSellerOrdersHasConfirmedListingIndex(): void
    {
        $this->assertIndexExists(
            'commerce_seller_orders',
            'commerce_seller_orders_confirmed_listing_index',
            ['tenant_uuid', 'seller_uuid', 'confirmed_at', 'fulfillment_status']
        );
    }

    public function testSellerOrdersHasOrderUuidIndex(): void
    {
        $this->assertIndexExists(
            'commerce_seller_orders',
            'commerce_seller_orders_order_uuid_index',
            ['order_uuid']
        );
    }

    public function testRerunningSellerOrderMigrationIsANoOp(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        $migration = new CreateSellerOrderTables();

        // setUp() already ran this migration once via CommerceTestCase::MIGRATIONS;
        // re-running up() must be a no-op guarded by hasTable().
        $migration->up($schema);
        $migration->up($schema);

        self::assertTrue($schema->hasTable('commerce_seller_orders'));
    }

    /**
     * @param list<string> $expectedColumns ordered, leading column first
     */
    private function assertIndexExists(string $table, string $indexName, array $expectedColumns): void
    {
        $pdo = $this->connection->getPDO();

        $stmt = $pdo->query(sprintf("PRAGMA index_list('%s')", $table));
        self::assertNotFalse($stmt);
        $indexes = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $names = array_column($indexes, 'name');
        self::assertContains($indexName, $names, "missing index {$indexName} on {$table}");

        $infoStmt = $pdo->query(sprintf("PRAGMA index_info('%s')", $indexName));
        self::assertNotFalse($infoStmt);
        $columns = $infoStmt->fetchAll(\PDO::FETCH_ASSOC);
        $actualColumns = array_column($columns, 'name');

        self::assertSame($expectedColumns, $actualColumns, "unexpected column set/order for {$indexName}");
    }

    // =====================================================================
    // Real-PostgreSQL convergence lanes (design spec §3.4/§10, MV2 plan
    // Task 10): the SQLite tests above prove the SHAPE; these prove the same
    // migrations converge on a genuinely different engine -- a fresh install
    // produces the folded `commerce_order_lines`/`commerce_orders` columns
    // and the `commerce_seller_orders` table, every index is asserted via
    // `pg_indexes` (the schema builder exposes no `hasIndex`, and index names
    // are generated identically across drivers -- {@see TableBuilder::generateIndexName()}
    // in the framework -- so the SAME pinned names from the SQLite tests
    // above apply here unchanged), and re-running `011` is a no-op. Gating,
    // fixture-width discipline, and the throwaway `Connection`/
    // `ApplicationContext` construction all mirror
    // `Marketplace\MarketplacePgsqlTest`/`Reports\ReportPgsqlTest` exactly.
    // =====================================================================

    public function testFreshInstallConvergesOnRealPostgresWithFoldedColumnsAndTheNewTable(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedConnection($this->pgConfig());
        $schema = $connection->getSchemaBuilder();

        foreach (['seller_uuid', 'discount_amount', 'tax_amount'] as $column) {
            self::assertTrue(
                $schema->hasColumn('commerce_order_lines', $column),
                "commerce_order_lines missing folded column {$column} on PostgreSQL"
            );
        }
        foreach (['marketplace_partitioned', 'fulfillment_revision'] as $column) {
            self::assertTrue(
                $schema->hasColumn('commerce_orders', $column),
                "commerce_orders missing folded column {$column} on PostgreSQL"
            );
        }

        self::assertTrue($schema->hasTable('commerce_seller_orders'), 'missing commerce_seller_orders on PostgreSQL');
        foreach (
            [
                'id', 'uuid', 'tenant_uuid', 'order_uuid', 'seller_uuid', 'seller_name_snapshot',
                'partition_number', 'seller_reference', 'currency', 'subtotal', 'allocated_discount',
                'allocated_shipping_discount', 'allocated_shipping', 'allocated_tax', 'attributed_total',
                'tax_attribution_method', 'confirmed_at', 'fulfillment_status', 'fulfilled_at', 'carrier',
                'tracking_number', 'tracking_url', 'status', 'revision', 'created_at', 'updated_at',
            ] as $column
        ) {
            self::assertTrue(
                $schema->hasColumn('commerce_seller_orders', $column),
                "commerce_seller_orders missing column {$column} on PostgreSQL"
            );
        }
    }

    public function testFoldedAndNewTableIndexesExistOnRealPostgresViaPgIndexes(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedConnection($this->pgConfig());

        $this->assertPgIndexExists(
            $connection,
            'commerce_order_lines',
            'commerce_order_lines_order_uuid_seller_uuid_index',
            ['order_uuid', 'seller_uuid']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_orders',
            'commerce_seller_orders_confirmed_listing_index',
            ['tenant_uuid', 'seller_uuid', 'confirmed_at', 'fulfillment_status']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_orders',
            'commerce_seller_orders_order_uuid_index',
            ['order_uuid']
        );
    }

    public function testRerunningSellerOrderMigrationIsANoOpOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedConnection($this->pgConfig());
        $schema = $connection->getSchemaBuilder();
        $migration = new CreateSellerOrderTables();

        // migratedConnection() already ran every migration (including 011)
        // once; re-running up() again must be a no-op guarded by hasTable().
        $migration->up($schema);
        $migration->up($schema);

        self::assertTrue($schema->hasTable('commerce_seller_orders'));
    }

    private function skipUnlessPgsql(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            self::markTestSkipped('Requires a PostgreSQL test lane to prove migration convergence is portable.');
        }
    }

    /**
     * `pg_indexes.indexdef` looks like `CREATE INDEX name ON public.table
     * USING btree (col_a, col_b)` -- the column list (in order) is the
     * content of the LAST parenthesized group.
     *
     * @param list<string> $expectedColumns ordered, leading column first
     */
    private function assertPgIndexExists(
        Connection $connection,
        string $table,
        string $indexName,
        array $expectedColumns
    ): void {
        $pdo = $connection->getPDO();
        $stmt = $pdo->prepare('SELECT indexdef FROM pg_indexes WHERE tablename = ? AND indexname = ?');
        $stmt->execute([$table, $indexName]);
        $indexDef = $stmt->fetchColumn();

        self::assertIsString($indexDef, "missing index {$indexName} on {$table} (pg_indexes)");
        self::assertMatchesRegularExpression('/\(([^()]+)\)\s*$/', $indexDef, "unparseable indexdef: {$indexDef}");
        preg_match('/\(([^()]+)\)\s*$/', $indexDef, $matches);
        $actualColumns = array_map('trim', explode(',', $matches[1]));

        self::assertSame($expectedColumns, $actualColumns, "unexpected column set/order for {$indexName}");
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

    /** @param array<string,mixed> $pgConfig */
    private function migratedConnection(array $pgConfig): Connection
    {
        $connection = new Connection($pgConfig);
        $schema = $connection->getSchemaBuilder();
        foreach (static::MIGRATIONS as $migration) {
            (new $migration())->up($schema);
        }

        return $connection;
    }
}
