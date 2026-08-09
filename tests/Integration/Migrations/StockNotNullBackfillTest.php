<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Migrations;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Database\Migrations\EnforceStockQuantityTrackedNotNull;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Psr\Container\ContainerInterface;

/**
 * Rider 2 (admin-order-creation cycle 2, Task 2): `commerce_stock.quantity`/`tracked`
 * NOT NULL hygiene. Migration 002 declared both columns nullable at the DB level
 * (neither call site chains `notNull()`/`notNullable()`) even though every
 * application write path treats a NULL the same as its intended default --
 * {@see StockRepository::isTracked()} folds a NULL `tracked` to `false` via
 * `(int) ($row['tracked'] ?? 0) === 1`, and {@see StockRepository::quantity()}
 * folds a NULL `quantity` to `0` via `(int) ($row['quantity'] ?? 0)`. This
 * migration backfills any existing NULL rows to those SAME runtime defaults
 * (0 / false) before constraining both columns NOT NULL, so the schema simply
 * codifies a behavior that already held -- no install can observe a change.
 *
 * Covers both the fresh-install shape (columns reject NULL from day one, via
 * the base {@see CommerceTestCase} connection which already runs every
 * migration including this one) and the upgrade path (a pre-existing install
 * with NULL rows converges to the backfilled, NOT-NULL-enforced shape), on
 * both SQLite and -- gated behind `COMMERCE_TEST_DB_DRIVER=pgsql`, matching
 * this codebase's established convention (see
 * {@see \Glueful\Extensions\Commerce\Tests\Integration\Customers\CustomerAggregationPgsqlTest}) --
 * real PostgreSQL.
 */
final class StockNotNullBackfillTest extends CommerceTestCase
{
    // =====================================================================
    // Fresh install (setUp() already ran EnforceStockQuantityTrackedNotNull
    // via CommerceTestCase::MIGRATIONS)
    // =====================================================================

    public function testFreshInstallRejectsNullQuantityOnSqlite(): void
    {
        try {
            $this->connection->getPDO()->exec(
                "INSERT INTO commerce_stock (uuid, tenant_uuid, variant_uuid, quantity, tracked) "
                . "VALUES ('stockfresh01', 't1', 'varfresh0001', NULL, 1)"
            );
            self::fail('a NULL commerce_stock.quantity insert must be rejected after the migration');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testFreshInstallRejectsNullTrackedOnSqlite(): void
    {
        try {
            $this->connection->getPDO()->exec(
                "INSERT INTO commerce_stock (uuid, tenant_uuid, variant_uuid, quantity, tracked) "
                . "VALUES ('stockfresh02', 't1', 'varfresh0002', 0, NULL)"
            );
            self::fail('a NULL commerce_stock.tracked insert must be rejected after the migration');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testFreshInstallOmittedColumnsStillDefaultToZeroAndTrue(): void
    {
        // The migration must preserve the ORIGINAL column defaults (quantity 0,
        // tracked true) declared by migration 002 -- an insert that omits both
        // columns entirely (not the same as inserting an explicit NULL) must
        // keep working exactly as it did before this migration.
        $this->connection->table('commerce_stock')->insert([
            'uuid' => 'stockfresh03',
            'tenant_uuid' => 't1',
            'variant_uuid' => 'varfresh0003',
        ]);

        $row = $this->connection->table('commerce_stock')->where('uuid', '=', 'stockfresh03')->first();
        self::assertNotNull($row);
        self::assertSame(0, (int) $row['quantity']);
        self::assertTrue((bool) $row['tracked']);
    }

    public function testRerunningMigrationIsANoOpOnSqlite(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        $migration = new EnforceStockQuantityTrackedNotNull();

        // setUp() already ran this migration once via CommerceTestCase::MIGRATIONS;
        // re-running up() must converge to the same NOT NULL shape without error.
        $migration->up($schema);

        self::assertTrue($schema->hasColumn('commerce_stock', 'quantity'));
        self::assertTrue($schema->hasColumn('commerce_stock', 'tracked'));

        try {
            $this->connection->getPDO()->exec(
                "INSERT INTO commerce_stock (uuid, tenant_uuid, variant_uuid, quantity, tracked) "
                . "VALUES ('stockfresh04', 't1', 'varfresh0004', NULL, 1)"
            );
            self::fail('NOT NULL must still be enforced after re-running the migration');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }
    }

    // =====================================================================
    // Upgrade path (SQLite): a pre-existing install with NULL rows, exactly
    // as migrations 001-020 alone would allow, converging through this
    // migration.
    // =====================================================================

    public function testUpgradePathBackfillsNullRowsAndEnforcesNotNullOnSqlite(): void
    {
        $connection = $this->preMigrationConnection(['engine' => 'sqlite', 'sqlite' => ['primary' => ':memory:']]);
        $context = $this->contextFor($connection);
        $repo = new StockRepository();

        // A pre-existing row with NULL quantity/tracked -- representable ONLY before
        // this migration runs (migrations 001-020 alone leave both columns nullable).
        $connection->getPDO()->exec(
            "INSERT INTO commerce_stock (uuid, tenant_uuid, variant_uuid, quantity, tracked) "
            . "VALUES ('stockupg0001', 'tupg1', 'varupg00001', NULL, NULL)"
        );

        // Baseline: StockRepository already treats a NULL row as untracked/zero-quantity
        // BEFORE this migration exists -- {@see StockRepository::isTracked()} and
        // {@see StockRepository::quantity()}'s `?? 0` fallback. Pin that down first so the
        // assertions after the migration below can prove genuine equivalence, not
        // coincidence.
        self::assertFalse($repo->isTracked($context, 'tupg1', 'varupg00001'));
        self::assertSame(0, $repo->quantity($context, 'tupg1', 'varupg00001'));

        (new EnforceStockQuantityTrackedNotNull())->up($connection->getSchemaBuilder());

        $row = $connection->table('commerce_stock')->where('uuid', '=', 'stockupg0001')->first();
        self::assertNotNull($row);
        self::assertSame(0, (int) $row['quantity']);
        self::assertSame(0, (int) $row['tracked']);

        // Equivalence: the SAME repository calls, against the SAME row, return the
        // SAME results after the migration as they did before it.
        self::assertFalse($repo->isTracked($context, 'tupg1', 'varupg00001'));
        self::assertSame(0, $repo->quantity($context, 'tupg1', 'varupg00001'));

        try {
            $connection->getPDO()->exec(
                "INSERT INTO commerce_stock (uuid, tenant_uuid, variant_uuid, quantity, tracked) "
                . "VALUES ('stockupg0002', 'tupg1', 'varupg00002', NULL, 1)"
            );
            self::fail('a NULL commerce_stock.quantity insert must be rejected after the upgrade migration');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testUpgradePathPreservesNonNullRowsUnchangedOnSqlite(): void
    {
        $connection = $this->preMigrationConnection(['engine' => 'sqlite', 'sqlite' => ['primary' => ':memory:']]);

        $connection->table('commerce_stock')->insert([
            'uuid' => 'stockupg0010',
            'tenant_uuid' => 'tupg1',
            'variant_uuid' => 'varupg00010',
            'quantity' => 42,
            'tracked' => 1,
        ]);

        (new EnforceStockQuantityTrackedNotNull())->up($connection->getSchemaBuilder());

        $row = $connection->table('commerce_stock')->where('uuid', '=', 'stockupg0010')->first();
        self::assertNotNull($row);
        self::assertSame(42, (int) $row['quantity']);
        self::assertSame(1, (int) $row['tracked']);
    }

    // =====================================================================
    // Real-PostgreSQL convergence lanes (mirrors
    // `Customers\CustomerAggregationPgsqlTest`'s gating/fixture-width
    // discipline exactly).
    // =====================================================================

    public function testFreshInstallRejectsNullQuantityAndTrackedOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedPgConnection();
        $connection->table('commerce_stock')->where('tenant_uuid', '=', 'pgstocknn001')->delete();

        try {
            try {
                $connection->getPDO()->exec(
                    "INSERT INTO commerce_stock (uuid, tenant_uuid, variant_uuid, quantity, tracked) "
                    . "VALUES ('pgstocknn01', 'pgstocknn001', 'pgvarnn0001', NULL, true)"
                );
                self::fail('a NULL commerce_stock.quantity insert must be rejected on real PostgreSQL');
            } catch (\PDOException) {
                $this->addToAssertionCount(1);
            }

            try {
                $connection->getPDO()->exec(
                    "INSERT INTO commerce_stock (uuid, tenant_uuid, variant_uuid, quantity, tracked) "
                    . "VALUES ('pgstocknn02', 'pgstocknn001', 'pgvarnn0002', 0, NULL)"
                );
                self::fail('a NULL commerce_stock.tracked insert must be rejected on real PostgreSQL');
            } catch (\PDOException) {
                $this->addToAssertionCount(1);
            }
        } finally {
            $connection->table('commerce_stock')->where('tenant_uuid', '=', 'pgstocknn001')->delete();
        }
    }

    public function testUpgradePathBackfillsNullRowsAndEnforcesNotNullOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        // A genuinely pre-migration connection: only migrations 001-020, so
        // commerce_stock.quantity/tracked are still nullable on this real database.
        $connection = $this->preMigrationConnection($this->pgConfig());
        $context = $this->contextFor($connection);
        $repo = new StockRepository();

        // Self-healing: wipe debris a previously-interrupted run of this same
        // pgsql-gated test left behind before inserting fixtures.
        $connection->table('commerce_stock')->where('tenant_uuid', '=', 'pgstockupg1')->delete();

        try {
            $connection->getPDO()->exec(
                "INSERT INTO commerce_stock (uuid, tenant_uuid, variant_uuid, quantity, tracked) "
                . "VALUES ('pgstockupg01', 'pgstockupg1', 'pgvarupg0001', NULL, NULL)"
            );

            self::assertFalse($repo->isTracked($context, 'pgstockupg1', 'pgvarupg0001'));
            self::assertSame(0, $repo->quantity($context, 'pgstockupg1', 'pgvarupg0001'));

            (new EnforceStockQuantityTrackedNotNull())->up($connection->getSchemaBuilder());

            $row = $connection->table('commerce_stock')->where('uuid', '=', 'pgstockupg01')->first();
            self::assertNotNull($row);
            self::assertSame(0, (int) $row['quantity']);
            self::assertFalse((bool) $row['tracked']);

            self::assertFalse($repo->isTracked($context, 'pgstockupg1', 'pgvarupg0001'));
            self::assertSame(0, $repo->quantity($context, 'pgstockupg1', 'pgvarupg0001'));

            try {
                $connection->getPDO()->exec(
                    "INSERT INTO commerce_stock (uuid, tenant_uuid, variant_uuid, quantity, tracked) "
                    . "VALUES ('pgstockupg02', 'pgstockupg1', 'pgvarupg0002', NULL, true)"
                );
                self::fail('a NULL commerce_stock.quantity insert must be rejected after the upgrade migration');
            } catch (\PDOException) {
                $this->addToAssertionCount(1);
            }
        } finally {
            $connection->table('commerce_stock')->where('tenant_uuid', '=', 'pgstockupg1')->delete();
        }
    }

    public function testRerunningMigrationIsANoOpOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedPgConnection();
        $schema = $connection->getSchemaBuilder();

        // migratedPgConnection() already ran every migration (including this one)
        // once; re-running up() again must converge without error.
        (new EnforceStockQuantityTrackedNotNull())->up($schema);
        (new EnforceStockQuantityTrackedNotNull())->up($schema);

        self::assertTrue($schema->hasColumn('commerce_stock', 'quantity'));
        self::assertTrue($schema->hasColumn('commerce_stock', 'tracked'));
    }

    // =====================================================================
    // Helpers
    // =====================================================================

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
     * Every migration EXCEPT the one under test -- the exact pre-upgrade shape a
     * real install would have immediately before this migration ships.
     *
     * Unlike SQLite's private `:memory:` database, the PostgreSQL lane's `commerce_stock`
     * table is a REAL table in a persistent, shared fixture database -- an earlier test
     * method (or an earlier full run of this suite) may already have carried it through
     * migration 021 and left it NOT NULL. `hasTable()`-guarded migration 002 would then
     * skip re-creating it, silently handing back an ALREADY-migrated table instead of the
     * genuine pre-021 shape this helper promises. Dropping it first (no-op on SQLite,
     * where it never exists yet) guarantees migration 002 below actually recreates it
     * fresh and nullable, no matter what a prior run left behind.
     *
     * @param array<string,mixed> $config
     */
    private function preMigrationConnection(array $config): Connection
    {
        $connection = new Connection($config + ['pooling' => ['enabled' => false]]);
        $connection->getPDO()->exec('DROP TABLE IF EXISTS commerce_stock');
        $schema = $connection->getSchemaBuilder();
        foreach (self::MIGRATIONS as $migration) {
            if ($migration === EnforceStockQuantityTrackedNotNull::class) {
                continue;
            }
            (new $migration())->up($schema);
        }

        return $connection;
    }

    private function migratedPgConnection(): Connection
    {
        $connection = new Connection($this->pgConfig());
        $schema = $connection->getSchemaBuilder();
        foreach (self::MIGRATIONS as $migration) {
            (new $migration())->up($schema);
        }

        return $connection;
    }

    private function contextFor(Connection $connection): ApplicationContext
    {
        $container = new class ($connection) implements ContainerInterface {
            public function __construct(private Connection $connection)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === 'database' || $id === Connection::class) {
                    return $this->connection;
                }

                throw new \RuntimeException("Unknown service: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === 'database' || $id === Connection::class;
            }
        };

        $context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
        $context->setContainer($container);
        $context->mergeConfigDefaults('commerce', require __DIR__ . '/../../../config/commerce.php');

        return $context;
    }
}
