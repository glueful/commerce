<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Migrations;

use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Database\Migrations\CreateSellerLifecycleEventsTable;
use Glueful\Extensions\Commerce\Support\DiagnosticsReport;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * Freezes the MV5b seller-lifecycle audit schema foundation (design spec §3)
 * before any lifecycle-transition service code consumes it: the brand-new
 * `commerce_seller_lifecycle_events` table (017) -- §2.2/§3 -- and its
 * `DiagnosticsReport` registration in both inventories.
 *
 * Because the schema builder exposes `hasTable`/`hasColumn` but no `hasIndex`,
 * every index assertion here is made via direct SQLite driver introspection
 * (`PRAGMA index_list` / `PRAGMA index_info`), matching this codebase's
 * established convention (see {@see ReserveChargebackShapeTest}).
 */
final class SellerLifecycleShapeTest extends CommerceTestCase
{
    // === commerce_seller_lifecycle_events (new table, §3) ============================

    public function testSellerLifecycleEventsTableExists(): void
    {
        self::assertTrue(
            $this->connection->getSchemaBuilder()->hasTable('commerce_seller_lifecycle_events'),
            'missing table commerce_seller_lifecycle_events'
        );
    }

    public function testSellerLifecycleEventsHasEverySpecColumn(): void
    {
        $columns = [
            'id', 'uuid', 'tenant_uuid', 'seller_uuid', 'from_status', 'to_status',
            'actor_uuid', 'reason', 'created_at',
        ];

        $schema = $this->connection->getSchemaBuilder();
        foreach ($columns as $column) {
            self::assertTrue(
                $schema->hasColumn('commerce_seller_lifecycle_events', $column),
                "commerce_seller_lifecycle_events missing column {$column}"
            );
        }
    }

    private function minimalLifecycleEventRow(array $overrides = []): array
    {
        return array_merge([
            'uuid' => 'lifecyclmv001',
            'tenant_uuid' => 'tenant0mv5blev',
            'seller_uuid' => 'sellerlevmv01',
            'from_status' => 'active',
            'to_status' => 'suspended',
            'actor_uuid' => 'operatorlev01',
            'reason' => 'Repeated chargebacks.',
        ], $overrides);
    }

    public function testSellerLifecycleEventsTenantUuidDefaultsToEmptyStringWhenOmitted(): void
    {
        $this->connection->table('commerce_seller_lifecycle_events')->insert(
            array_diff_key(
                $this->minimalLifecycleEventRow(['uuid' => 'lifecyclmv002']),
                ['tenant_uuid' => true]
            )
        );

        $row = $this->connection->table('commerce_seller_lifecycle_events')
            ->where('uuid', '=', 'lifecyclmv002')
            ->first();
        self::assertNotNull($row);
        self::assertSame('', $row['tenant_uuid']);
    }

    public function testSellerLifecycleEventsAcceptsAssignedValues(): void
    {
        $this->connection->table('commerce_seller_lifecycle_events')->insert(
            $this->minimalLifecycleEventRow(['uuid' => 'lifecyclmv003'])
        );

        $row = $this->connection->table('commerce_seller_lifecycle_events')
            ->where('uuid', '=', 'lifecyclmv003')
            ->first();
        self::assertNotNull($row);
        self::assertSame('tenant0mv5blev', $row['tenant_uuid']);
        self::assertSame('sellerlevmv01', $row['seller_uuid']);
        self::assertSame('active', $row['from_status']);
        self::assertSame('suspended', $row['to_status']);
        self::assertSame('operatorlev01', $row['actor_uuid']);
        self::assertSame('Repeated chargebacks.', $row['reason']);
        self::assertNotNull($row['created_at']);
    }

    public function testSellerLifecycleEventsReasonIsNullableWhenOmitted(): void
    {
        $this->connection->table('commerce_seller_lifecycle_events')->insert(
            array_diff_key(
                $this->minimalLifecycleEventRow(['uuid' => 'lifecyclmv004']),
                ['reason' => true]
            )
        );

        $row = $this->connection->table('commerce_seller_lifecycle_events')
            ->where('uuid', '=', 'lifecyclmv004')
            ->first();
        self::assertNotNull($row);
        self::assertNull($row['reason']);
    }

    /**
     * Design spec §2.1/§3: `actor_uuid` is NOT NULL at the schema level (not
     * just service validation) -- an insert omitting it must fail on the
     * driver itself.
     */
    public function testSellerLifecycleEventsActorUuidIsNotNullAtTheSchemaLevel(): void
    {
        // The assertion is made OUTSIDE the try/catch on purpose: PHPUnit's
        // own self::fail() throws AssertionFailedError, which IS a
        // \Throwable (and even a \RuntimeException), so a self::fail() call
        // placed inside a `catch (\Throwable)` block would be silently
        // swallowed by that same catch and falsely reported as a pass.
        $rejected = false;
        try {
            $this->connection->table('commerce_seller_lifecycle_events')->insert(
                array_diff_key(
                    $this->minimalLifecycleEventRow(['uuid' => 'lifecyclmv005']),
                    ['actor_uuid' => true]
                )
            );
        } catch (\Throwable) {
            $rejected = true;
        }
        self::assertTrue($rejected, 'an insert omitting actor_uuid must be rejected by a NOT NULL constraint');

        self::assertNull(
            $this->connection->table('commerce_seller_lifecycle_events')
                ->where('uuid', '=', 'lifecyclmv005')
                ->first(),
            'a rejected insert must leave no row behind'
        );
    }

    public function testSellerLifecycleEventsActorUuidRejectsExplicitNullToo(): void
    {
        // See the note on testSellerLifecycleEventsActorUuidIsNotNullAtTheSchemaLevel()
        // above -- the assertion stays outside the try/catch deliberately.
        $rejected = false;
        try {
            $this->connection->table('commerce_seller_lifecycle_events')->insert(
                $this->minimalLifecycleEventRow(['uuid' => 'lifecyclmv006', 'actor_uuid' => null])
            );
        } catch (\Throwable) {
            $rejected = true;
        }
        self::assertTrue(
            $rejected,
            'an insert with an explicit null actor_uuid must be rejected by a NOT NULL constraint'
        );
    }

    public function testSellerLifecycleEventsUniqueTenantUuidUuidIsEnforced(): void
    {
        $this->connection->table('commerce_seller_lifecycle_events')->insert(
            $this->minimalLifecycleEventRow(['uuid' => 'lifecyclmv010'])
        );

        // See the note on testSellerLifecycleEventsActorUuidIsNotNullAtTheSchemaLevel()
        // above -- the assertion stays outside the try/catch deliberately.
        $rejected = false;
        try {
            $this->connection->table('commerce_seller_lifecycle_events')->insert(
                $this->minimalLifecycleEventRow(['uuid' => 'lifecyclmv010'])
            );
        } catch (\Throwable) {
            $rejected = true;
        }
        self::assertTrue($rejected, 'duplicate (tenant_uuid, uuid) lifecycle-event insert must be rejected');

        // A different tenant with the SAME uuid must succeed (uniqueness is
        // per-tenant, not global).
        $this->connection->table('commerce_seller_lifecycle_events')->insert(
            $this->minimalLifecycleEventRow(['uuid' => 'lifecyclmv010', 'tenant_uuid' => 'tenant0mv5blevb'])
        );
        self::assertSame(
            2,
            $this->connection->table('commerce_seller_lifecycle_events')
                ->where('uuid', '=', 'lifecyclmv010')
                ->count()
        );
    }

    public function testSellerLifecycleEventsHasSellerCreatedIndex(): void
    {
        $this->assertIndexExists(
            'commerce_seller_lifecycle_events',
            'commerce_seller_lifecycle_events_seller_created_index',
            ['tenant_uuid', 'seller_uuid', 'created_at']
        );
    }

    public function testRerunning017MigrationIsANoOp(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        $migration = new CreateSellerLifecycleEventsTable();

        // setUp() already ran this migration once via CommerceTestCase::MIGRATIONS;
        // re-running up() must be a no-op guarded by hasTable().
        $migration->up($schema);
        $migration->up($schema);

        self::assertTrue($schema->hasTable('commerce_seller_lifecycle_events'));
        self::assertTrue($schema->hasColumn('commerce_seller_lifecycle_events', 'actor_uuid'));
    }

    // === DiagnosticsReport (§3) ========================================================

    public function testDiagnosticsCommerceTablesIncludesSellerLifecycleEvents(): void
    {
        self::assertContains(
            'commerce_seller_lifecycle_events',
            DiagnosticsReport::commerceTables(),
            'DiagnosticsReport::commerceTables() missing commerce_seller_lifecycle_events'
        );
    }

    public function testDiagnosticsTenantTablesIncludesSellerLifecycleEvents(): void
    {
        self::assertContains(
            'commerce_seller_lifecycle_events',
            DiagnosticsReport::tenantTables(),
            'DiagnosticsReport::tenantTables() missing commerce_seller_lifecycle_events'
        );
    }

    public function testDiagnosticsReportBuildShowsSellerLifecycleEventsPresent(): void
    {
        $report = DiagnosticsReport::build($this->appContext());

        self::assertTrue(
            $report['database']['commerce_tables_present']['commerce_seller_lifecycle_events'] ?? false,
            'DiagnosticsReport::build() must report commerce_seller_lifecycle_events as present'
        );
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
    // Real-PostgreSQL convergence lane (design spec §3/§10): the SQLite tests
    // above prove the SHAPE; this proves the SAME migration (017's brand-new
    // table) converges on a genuinely different engine -- every index via
    // `pg_indexes` (the schema builder exposes no `hasIndex`, and index names
    // are generated identically across drivers -- {@see \Glueful\Database\Schema\TableBuilder::generateIndexName()}
    // in the framework -- so the SAME pinned names from the SQLite tests
    // above apply here unchanged), the NOT NULL constraint on `actor_uuid`,
    // and re-running the migration is a no-op. Gating, fixture-width
    // discipline, and the throwaway `Connection`/`ApplicationContext`
    // construction all mirror `ReserveChargebackShapeTest`'s own pgsql lanes
    // exactly.
    // =====================================================================

    public function testFreshInstallConvergesOnRealPostgresWithSellerLifecycleEventsTable(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedConnection($this->pgConfig());
        $schema = $connection->getSchemaBuilder();

        self::assertTrue(
            $schema->hasTable('commerce_seller_lifecycle_events'),
            'missing commerce_seller_lifecycle_events on PostgreSQL'
        );
        foreach (
            ['id', 'uuid', 'tenant_uuid', 'seller_uuid', 'from_status', 'to_status',
                'actor_uuid', 'reason', 'created_at'] as $column
        ) {
            self::assertTrue(
                $schema->hasColumn('commerce_seller_lifecycle_events', $column),
                "commerce_seller_lifecycle_events missing column {$column} on PostgreSQL"
            );
        }
    }

    public function testSellerLifecycleEventsIndexesExistOnRealPostgresViaPgIndexes(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedConnection($this->pgConfig());

        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_lifecycle_events',
            'commerce_seller_lifecycle_events_tenant_uuid_unique',
            ['tenant_uuid', 'uuid']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_lifecycle_events',
            'commerce_seller_lifecycle_events_seller_created_index',
            ['tenant_uuid', 'seller_uuid', 'created_at']
        );
    }

    public function testSellerLifecycleEventsActorUuidNotNullConstraintIsEnforcedOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedConnection($this->pgConfig());
        // Self-healing cleanup: real commits against the persistent PostgreSQL
        // test database, unlike every SQLite test in this file.
        $connection->table('commerce_seller_lifecycle_events')
            ->where('tenant_uuid', '=', 'tntpglifecycle')->delete();

        // The assertion is made OUTSIDE the try/catch on purpose -- see the
        // note on SellerLifecycleShapeTest's SQLite NOT NULL tests above.
        $rejected = false;
        try {
            $connection->table('commerce_seller_lifecycle_events')->insert([
                'uuid' => 'pglifecyclev01',
                'tenant_uuid' => 'tntpglifecycle',
                'seller_uuid' => 'pglifecyclesl1',
                'from_status' => 'active',
                'to_status' => 'suspended',
                'reason' => 'PostgreSQL NOT NULL probe.',
            ]);
        } catch (\Throwable) {
            $rejected = true;
        }
        $connection->table('commerce_seller_lifecycle_events')
            ->where('tenant_uuid', '=', 'tntpglifecycle')->delete();

        self::assertTrue(
            $rejected,
            'an insert omitting actor_uuid must violate the NOT NULL constraint on PostgreSQL'
        );
    }

    public function testRerunning017MigrationIsANoOpOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedConnection($this->pgConfig());
        $schema = $connection->getSchemaBuilder();

        // migratedConnection() already ran every migration (including 017)
        // once; re-running up() again must be a no-op guarded by hasTable().
        (new CreateSellerLifecycleEventsTable())->up($schema);

        self::assertTrue($schema->hasTable('commerce_seller_lifecycle_events'));
    }

    private function skipUnlessPgsql(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            self::markTestSkipped('Requires a PostgreSQL test lane to prove migration convergence is portable.');
        }
    }

    /**
     * `pg_indexes.indexdef` looks like `CREATE INDEX name ON public.table
     * USING btree (col_a, col_b)` (or `CREATE UNIQUE INDEX ...` for a named
     * unique constraint) -- the column list (in order) is the content of the
     * LAST parenthesized group.
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
