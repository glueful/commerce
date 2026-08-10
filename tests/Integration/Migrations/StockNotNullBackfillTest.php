<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Migrations;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Database\Migrations\EnforceStockQuantityTrackedNotNull;
use Glueful\Extensions\Commerce\Inventory\StockIntegrityException;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Psr\Container\ContainerInterface;

/**
 * Rider 2 (admin-order-creation cycle 2, Task 2): `commerce_stock.quantity`/`tracked`
 * NOT NULL hygiene. Migration 002 declared both columns nullable at the DB level
 * (neither call site chains `notNull()`/`notNullable()`).
 *
 * Equivalence holds for exactly two read paths --
 * {@see StockRepository::isTracked()} folds a NULL `tracked` to `false` via
 * `(int) ($row['tracked'] ?? 0) === 1`, and {@see StockRepository::quantity()}
 * folds a NULL `quantity` to `0` via `(int) ($row['quantity'] ?? 0)` -- so backfilling
 * to those same values before constraining NOT NULL changes nothing THERE.
 *
 * It is deliberately NOT equivalence-preserving everywhere else, and that divergence
 * is the point of this rider, not an accident (see the migration's own docblock for
 * the full accounting):
 *  - {@see StockRepository::stockProjectionsForProduct()}/`stockProjectionsForProducts()`
 *    select `tracked`/`quantity` raw, so a NULL-valued row is today indistinguishable
 *    from a MISSING one -- {@see \Glueful\Extensions\Commerce\Catalog\CatalogService::stockForProduct()}
 *    throws {@see StockIntegrityException} on it. This migration deliberately HEALS
 *    such a row into a genuine untracked/zero-quantity variant -- the exception stops
 *    firing. Pinned below by
 *    `testIntegritySignalHealsFromExceptionToHealthyZeroStockAfterMigration()` (and,
 *    one level lower, directly against the projection itself, by
 *    `testStockProjectionSurfacesNullBeforeAndRealValuesAfterMigration()`).
 *  - {@see StockRepository::increment()}/`incrementChecked()` do a raw
 *    `quantity = quantity + ?`; SQL NULL propagates through `+`, so a NULL `quantity`
 *    silently never accumulates no matter how many times either is called, even
 *    though the UPDATE itself "succeeds". This migration's backfilled `0` lets that
 *    SAME arithmetic actually work afterward. Pinned below by
 *    `testIncrementSilentlyStaysNullBeforeMigrationThenAccumulatesFromZeroAfter()` and
 *    `testIncrementCheckedSilentlyStaysNullBeforeMigrationThenAccumulatesFromZeroAfter()`.
 *
 * Also covers the fresh-install shape (columns reject NULL from day one, via the base
 * {@see CommerceTestCase} connection which already runs every migration including this
 * one) and the upgrade path (a pre-existing install with NULL rows converges to the
 * backfilled, NOT-NULL-enforced shape), on both SQLite and -- gated behind
 * `COMMERCE_TEST_DB_DRIVER=pgsql`, matching this codebase's established convention
 * (see {@see \Glueful\Extensions\Commerce\Tests\Integration\Customers\CustomerAggregationPgsqlTest}) --
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
    // Deliberate divergence (SQLite): the backfill does NOT merely preserve
    // pre-existing behavior everywhere -- it HEALS two specific integrity
    // signals that a NULL-valued (as opposed to missing) commerce_stock row
    // used to trip. This is disclosed as the rider's intended outcome, not
    // claimed away as a no-op. See the class docblock above and the
    // migration's own docblock for the full accounting.
    // =====================================================================

    /**
     * {@see StockRepository::stockProjectionsForProduct()} selects `commerce_stock
     * .tracked`/`.quantity` RAW (no `?? 0` coalescing) -- a stock row that EXISTS but
     * carries a NULL value is, to that projection, indistinguishable from a MISSING
     * row. Pins the projection's own output directly, one level below the
     * exception-throwing {@see CatalogService::stockForProduct()} pinned separately
     * below.
     */
    public function testStockProjectionSurfacesNullBeforeAndRealValuesAfterMigration(): void
    {
        $connection = $this->preMigrationConnection(['engine' => 'sqlite', 'sqlite' => ['primary' => ':memory:']]);

        $connection->table('commerce_variants')->insert([
            'uuid' => 'varproj00001',
            'tenant_uuid' => '',
            'product_uuid' => 'prodproj0001',
            'sku' => 'PROJ-1',
            'option_values' => '{}',
            'price' => 1000,
            'currency' => 'USD',
            'position' => 0,
        ]);
        $connection->getPDO()->exec(
            "INSERT INTO commerce_stock (uuid, tenant_uuid, variant_uuid, quantity, tracked) "
            . "VALUES ('stockproj0001', '', 'varproj00001', NULL, NULL)"
        );

        $repo = new StockRepository();
        $context = $this->contextFor($connection);

        $before = $repo->stockProjectionsForProduct($context, '', 'prodproj0001');
        self::assertSame(['variant_uuid' => 'varproj00001', 'tracked' => null, 'quantity' => null], $before[0]);

        (new EnforceStockQuantityTrackedNotNull())->up($connection->getSchemaBuilder());

        $after = $repo->stockProjectionsForProduct($context, '', 'prodproj0001');
        self::assertSame(['variant_uuid' => 'varproj00001', 'tracked' => false, 'quantity' => 0], $after[0]);
    }

    /**
     * End-to-end through the actual consumer: {@see CatalogService::stockForProduct()}
     * throws {@see StockIntegrityException} on a NULL-valued row before the migration
     * (Global Constraints: "the read fails loudly"), and reads it as a healthy
     * untracked/zero-quantity variant after -- with NO exception. This is the
     * migration doing exactly what it is for.
     */
    public function testIntegritySignalHealsFromExceptionToHealthyZeroStockAfterMigration(): void
    {
        $connection = $this->preMigrationConnection(['engine' => 'sqlite', 'sqlite' => ['primary' => ':memory:']]);
        $context = $this->contextFor($connection);
        $catalog = new CatalogService(new ProductRepository(), new VariantRepository(), new SentinelTenantResolver());

        $product = $catalog->createProduct($context, [
            'slug' => 'integrity-widget',
            'name' => 'Integrity Widget',
            'type' => 'physical',
            'status' => 'active',
            'variants' => [
                ['sku' => 'INTEGRITY-1', 'price' => 1000, 'currency' => 'USD'],
            ],
        ]);
        $variantUuid = $product['variants'][0]['uuid'];

        // Corrupt the stock row `ensureRow()` just wrote -- exactly the pre-021 state a
        // real install could reach (row present, values NULL) that this migration
        // backfills. No application code writes a NULL today; this simulates the
        // pre-existing data the migration exists to clean up.
        $connection->getPDO()->exec(
            "UPDATE commerce_stock SET quantity = NULL, tracked = NULL WHERE variant_uuid = "
            . $connection->getPDO()->quote($variantUuid)
        );

        try {
            $catalog->stockForProduct($context, $product['uuid']);
            self::fail('a NULL-valued stock row must surface as StockIntegrityException before the migration');
        } catch (StockIntegrityException) {
            $this->addToAssertionCount(1);
        }

        (new EnforceStockQuantityTrackedNotNull())->up($connection->getSchemaBuilder());

        $result = $catalog->stockForProduct($context, $product['uuid']);
        self::assertSame(
            ['variant_uuid' => $variantUuid, 'tracked' => false, 'quantity' => 0],
            $result['items'][0]
        );
    }

    /**
     * {@see StockRepository::increment()} does a raw `quantity = quantity + ?` with no
     * `tracked` predicate. SQL NULL propagates through `+`, so against a NULL
     * `quantity` the stored value silently stays NULL forever, no matter how many
     * times increment() is called -- even though the UPDATE itself matches the row
     * (no exception, no false return; `increment()` is `void`).
     */
    public function testIncrementSilentlyStaysNullBeforeMigrationThenAccumulatesFromZeroAfter(): void
    {
        $connection = $this->preMigrationConnection(['engine' => 'sqlite', 'sqlite' => ['primary' => ':memory:']]);
        $context = $this->contextFor($connection);
        $repo = new StockRepository();

        $connection->getPDO()->exec(
            "INSERT INTO commerce_stock (uuid, tenant_uuid, variant_uuid, quantity, tracked) "
            . "VALUES ('stockincnull1', 'tinc1', 'varinc00001', NULL, 1)"
        );

        $repo->increment($context, 'tinc1', 'varinc00001', 5);
        $row = $connection->table('commerce_stock')->where('uuid', '=', 'stockincnull1')->first();
        self::assertNotNull($row);
        self::assertNull($row['quantity'], 'NULL + 5 must still be NULL before the migration backfills it');

        (new EnforceStockQuantityTrackedNotNull())->up($connection->getSchemaBuilder());

        $repo->increment($context, 'tinc1', 'varinc00001', 5);
        $row = $connection->table('commerce_stock')->where('uuid', '=', 'stockincnull1')->first();
        self::assertNotNull($row);
        self::assertSame(5, (int) $row['quantity']);
    }

    /**
     * Same underlying `quantity = quantity + ?` NULL-propagation bug as increment()
     * above, but through {@see StockRepository::incrementChecked()} -- which ALSO
     * requires `tracked = true` in its WHERE clause, so the fixture's row is seeded
     * `tracked = 1` explicitly (not NULL) to isolate the quantity-NULL behavior alone.
     * `incrementChecked()` still returns `true` (the row matched and was "updated")
     * even though the stored value never actually changed.
     */
    public function testIncrementCheckedSilentlyStaysNullBeforeMigrationThenAccumulatesFromZeroAfter(): void
    {
        $connection = $this->preMigrationConnection(['engine' => 'sqlite', 'sqlite' => ['primary' => ':memory:']]);
        $context = $this->contextFor($connection);
        $repo = new StockRepository();

        $connection->getPDO()->exec(
            "INSERT INTO commerce_stock (uuid, tenant_uuid, variant_uuid, quantity, tracked) "
            . "VALUES ('stockincnull2', 'tinc2', 'varinc00002', NULL, 1)"
        );

        $ok = $repo->incrementChecked($context, 'tinc2', 'varinc00002', 5);
        self::assertTrue($ok, 'the row matches tracked = true, so incrementChecked() reports success');
        $row = $connection->table('commerce_stock')->where('uuid', '=', 'stockincnull2')->first();
        self::assertNotNull($row);
        self::assertNull($row['quantity'], 'NULL + 5 must still be NULL before the migration backfills it');

        (new EnforceStockQuantityTrackedNotNull())->up($connection->getSchemaBuilder());

        $ok = $repo->incrementChecked($context, 'tinc2', 'varinc00002', 5);
        self::assertTrue($ok);
        $row = $connection->table('commerce_stock')->where('uuid', '=', 'stockincnull2')->first();
        self::assertNotNull($row);
        self::assertSame(5, (int) $row['quantity']);
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
