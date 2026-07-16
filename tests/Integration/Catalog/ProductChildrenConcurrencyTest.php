<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Catalog;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Validation\ValidationException;
use Psr\Container\ContainerInterface;

/**
 * Product-children (grouped-product set-list) claim discipline under a real
 * two-connection PostgreSQL race. Spec §8 names product-child set-list races
 * explicitly; `CatalogService::setProductChildren()`'s claim/re-read discipline
 * (class docblock) is proven deterministically by
 * `ProductTypesTest::testSetChildrenRejectsChildWhoseTypeChangedSinceItWasDiscovered()`,
 * but per the same deterministic-claim-plus-pgsql-race split used by
 * `AttributeConcurrencyTest` / `CategoryTreeConcurrencyTest` /
 * `MediaTenancyConcurrencyTest`, a genuine cross-connection row-lock interleave is
 * only observable in a truly separate OS process (PHP has no threads) -- this
 * file supplies that pgsql-gated proof for TWO distinct sharable-claimed-row
 * races the set-list can lose:
 *
 * - The PARENT'S row: a concurrent type-change away from `grouped` racing the
 *   set-list's own claim on the same parent. The set-list must see the fresh
 *   (now non-grouped) type on its post-claim re-read and reject -- leaving NO
 *   `commerce_product_children` rows attached to a non-grouped parent.
 * - A proposed CHILD'S row: a concurrent type-change away from physical/digital
 *   racing the set-list's claim on that same child (children are themselves
 *   `commerce_products` rows, claimed via the same `catalog_revision` primitive
 *   per the class docblock). The set-list must see the fresh (now
 *   non-purchasable) type and reject -- leaving NO dangling
 *   `commerce_product_children` row referencing that child.
 *
 * Both races assert exactly one winner (the direct row mutation always
 * commits first, since the set-list's colliding claim is what blocks) and no
 * invalid graph survives. Schema shape lives in CatalogBreadthShapeTest; this
 * file is scoped to product-children claim/race behavior only.
 */
final class ProductChildrenConcurrencyTest extends CommerceTestCase
{
    /**
     * Real cross-connection interleaving: connection A (this test) holds the
     * PARENT product's claim open and uncommitted (as the first step of a type
     * change away from `grouped`) while connection B (a genuinely independent
     * subprocess, fixtures/product_children_set_list_race_child.php) runs the
     * real CatalogService::setProductChildren() against the SAME parent. B's
     * own claim on the parent (the set-list's first claim) blocks on A's held
     * PostgreSQL row lock until A completes the type change and commits. B's
     * claim then succeeds (the row still exists, just no longer grouped), so
     * its mandatory post-claim re-read observes the fresh type and rejects --
     * never attaching a children row to a now-non-grouped parent.
     */
    public function testConcurrentParentTypeChangeVsChildrenSetListLeavesNoChildrenOnNonGroupedParent(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            $this->markTestSkipped('Requires a PostgreSQL test lane for true row-claim interleaving.');
        }

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);

        $parentUuid = 'prodchpgrac1';
        $childUuid = 'prodchpgrac2';

        // Self-healing: wipe any debris a previously-interrupted run of this same
        // pgsql-gated test left behind before inserting the fixture rows.
        $this->deleteRaceDebris($connectionA, [$parentUuid, $childUuid]);

        $connectionA->table('commerce_products')->insert([
            'uuid' => $parentUuid,
            'tenant_uuid' => '',
            'slug' => $parentUuid,
            'name' => 'Race Parent',
            'type' => 'grouped',
            'status' => 'active',
        ]);
        $connectionA->table('commerce_products')->insert([
            'uuid' => $childUuid,
            'tenant_uuid' => '',
            'slug' => $childUuid,
            'name' => 'Race Child',
            'type' => 'physical',
            'status' => 'active',
        ]);

        // A claims the PARENT first -- this holds the row lock, uncommitted. The
        // claim primitive (not the full service) is used directly so the test can
        // pause mid-type-change while B's set-list attempts to claim the same
        // parent row.
        $connectionA->getTransactionManager()->begin();
        $products = new ProductRepository();
        self::assertTrue($products->claimCatalogRevision($contextA, '', $parentUuid));

        // Launch B: its own set-list claims the same parent first, blocking on
        // A's held row lock.
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/fixtures/product_children_set_list_race_child.php',
                json_encode($pgConfig, JSON_THROW_ON_ERROR),
                $parentUuid,
                json_encode([$childUuid], JSON_THROW_ON_ERROR),
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        // Give B time to reach and block on its own claim UPDATE before A proceeds.
        usleep(300_000);

        // A completes the type change directly (it already holds the claim, so
        // no service-level re-claim is needed): moves the parent away from
        // `grouped` (no children/variants/refs exist yet, so this is a legal
        // change), then commits -- releasing the row lock so B's blocked claim
        // can proceed.
        $connectionA->table('commerce_products')
            ->where('tenant_uuid', '=', '')
            ->where('uuid', '=', $parentUuid)
            ->update(['type' => 'external', 'metadata' => json_encode(
                ['external_url' => 'https://vendor.example.com/race'],
                JSON_THROW_ON_ERROR
            )]);
        $connectionA->getTransactionManager()->commit();

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $result = json_decode(trim($stdout), true);
        self::assertIsArray($result, "Connection B's subprocess produced no parseable result. stderr: {$stderr}");
        self::assertNull(
            $result['children'],
            "B's set-list must fail once the parent is no longer grouped, not land (stderr: {$stderr})."
        );
        self::assertSame(ValidationException::class, $result['exceptionClass'] ?? null);

        self::assertSame(
            'external',
            $connectionA->table('commerce_products')->where('uuid', '=', $parentUuid)->first()['type'],
            "A's type change must be the one that landed."
        );
        self::assertSame(
            0,
            $connectionA->table('commerce_product_children')->where('product_uuid', '=', $parentUuid)->count(),
            'No children rows may exist against a non-grouped parent after the race.'
        );

        // Leave the pgsql fixture database as we found it.
        $this->deleteRaceDebris($connectionA, [$parentUuid, $childUuid]);
    }

    /**
     * Real cross-connection interleaving: connection A (this test) holds a
     * PROPOSED CHILD's claim open and uncommitted (as the first step of a type
     * change away from physical) while connection B (a genuinely independent
     * subprocess) runs the real CatalogService::setProductChildren() against a
     * grouped parent proposing that SAME child. The parent's own claim
     * (uncontested) succeeds immediately; B's claim on the union of proposed
     * children then blocks on A's held row lock on the shared child row. Once A
     * commits, B's claim succeeds and its mandatory per-child re-read observes
     * the fresh (now non-purchasable) type and rejects the whole set-list --
     * never leaving a dangling `commerce_product_children` row referencing a
     * child that isn't physical/digital.
     */
    public function testConcurrentChildTypeChangeVsChildrenSetListLeavesNoDanglingChildRef(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            $this->markTestSkipped('Requires a PostgreSQL test lane for true row-claim interleaving.');
        }

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);

        $parentUuid = 'prodchpgrac3';
        $childUuid = 'prodchpgrac4';

        $this->deleteRaceDebris($connectionA, [$parentUuid, $childUuid]);

        $connectionA->table('commerce_products')->insert([
            'uuid' => $parentUuid,
            'tenant_uuid' => '',
            'slug' => $parentUuid,
            'name' => 'Race Parent 2',
            'type' => 'grouped',
            'status' => 'active',
        ]);
        $connectionA->table('commerce_products')->insert([
            'uuid' => $childUuid,
            'tenant_uuid' => '',
            'slug' => $childUuid,
            'name' => 'Race Child 2',
            'type' => 'physical',
            'status' => 'active',
        ]);

        // A claims the CHILD first -- this holds the row lock, uncommitted,
        // standing in for the first step of a concurrent type change on the
        // child (e.g. an operator turning it into its own bundle).
        $connectionA->getTransactionManager()->begin();
        $products = new ProductRepository();
        self::assertTrue($products->claimCatalogRevision($contextA, '', $childUuid));

        // Launch B: its parent claim is uncontested and succeeds immediately;
        // its claim on the union of proposed children (just this one child)
        // then blocks on A's held row lock.
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/fixtures/product_children_set_list_race_child.php',
                json_encode($pgConfig, JSON_THROW_ON_ERROR),
                $parentUuid,
                json_encode([$childUuid], JSON_THROW_ON_ERROR),
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        // Give B time to reach and block on its own claim UPDATE before A proceeds.
        usleep(300_000);

        // A completes the type change directly (it already holds the claim):
        // the child has no variants/children/refs yet, so moving it to
        // `grouped` is a legal change on its own. Commit releases the row lock
        // so B's blocked claim can proceed.
        $connectionA->table('commerce_products')
            ->where('tenant_uuid', '=', '')
            ->where('uuid', '=', $childUuid)
            ->update(['type' => 'grouped']);
        $connectionA->getTransactionManager()->commit();

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $result = json_decode(trim($stdout), true);
        self::assertIsArray($result, "Connection B's subprocess produced no parseable result. stderr: {$stderr}");
        self::assertNull(
            $result['children'],
            "B's set-list must fail once the proposed child is no longer purchasable, not land "
                . "(stderr: {$stderr})."
        );
        self::assertSame(ValidationException::class, $result['exceptionClass'] ?? null);

        self::assertSame(
            'grouped',
            $connectionA->table('commerce_products')->where('uuid', '=', $childUuid)->first()['type'],
            "A's type change must be the one that landed."
        );
        self::assertSame(
            0,
            $connectionA->table('commerce_product_children')->where('child_uuid', '=', $childUuid)->count(),
            'No dangling children row may reference a child that is no longer physical/digital.'
        );
        self::assertSame(
            0,
            $connectionA->table('commerce_product_children')->where('product_uuid', '=', $parentUuid)->count(),
            'The race parent must have no children after the race (the only proposed child was rejected).'
        );

        // Leave the pgsql fixture database as we found it.
        $this->deleteRaceDebris($connectionA, [$parentUuid, $childUuid]);
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

    /** @param list<string> $productUuids */
    private function deleteRaceDebris(Connection $connection, array $productUuids): void
    {
        foreach ($productUuids as $productUuid) {
            $connection->table('commerce_product_children')
                ->where('product_uuid', '=', $productUuid)
                ->delete();
            $connection->table('commerce_product_children')
                ->where('child_uuid', '=', $productUuid)
                ->delete();
        }
        // forceDelete(), not delete(): commerce_products carries a deleted_at
        // column, so a plain delete() would soft-delete it -- leaving the row
        // (and its unique uuid) physically in place and breaking this cleanup's
        // idempotency across repeated runs of this same pgsql-gated test.
        foreach ($productUuids as $productUuid) {
            $connection->table('commerce_products')
                ->where('uuid', '=', $productUuid)
                ->forceDelete();
        }
    }

    private function pgsqlContext(Connection $connection): ApplicationContext
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
