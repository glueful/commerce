<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Catalog;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\CategoryService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Validation\ValidationException;
use Psr\Container\ContainerInterface;

/**
 * Category tree claim discipline: revision-claim mechanics and the
 * delete-vs-child-attach race. Follows the same deterministic-claim-plus-pgsql-race
 * split as MediaTenancyConcurrencyTest — a real two-connection interleave is only
 * observable in a genuinely separate OS process (PHP has no threads), so the
 * default suite proves the invariant sequentially and the pgsql-gated test proves
 * it under a true row-lock race. Schema shape lives in CatalogBreadthShapeTest;
 * this file is scoped to category business behavior only.
 */
final class CategoryTreeConcurrencyTest extends CommerceTestCase
{
    private const TENANT_B = 'tenantBBBB02';

    public function testClaimRevisionIncrementsAndReturnsTrueRepeatedly(): void
    {
        $this->seedCategory('', 'catclaim0001');
        $repository = new CategoryRepository();

        self::assertTrue($repository->claimRevision($this->context, '', 'catclaim0001'));
        self::assertSame(1, $this->currentRevision('catclaim0001'));

        self::assertTrue($repository->claimRevision($this->context, '', 'catclaim0001'));
        self::assertSame(2, $this->currentRevision('catclaim0001'));
    }

    public function testClaimRevisionReturnsFalseForUnknownOrCrossTenantCategory(): void
    {
        $repository = new CategoryRepository();
        self::assertFalse($repository->claimRevision($this->context, '', 'no-such-category'));

        $this->seedCategory(self::TENANT_B, 'catclaimtb01');
        self::assertFalse($repository->claimRevision($this->context, '', 'catclaimtb01'));
        self::assertSame(0, $this->currentRevision('catclaimtb01', self::TENANT_B));
    }

    /**
     * Deterministic replacement for a true two-connection interleave: the delete
     * commits FIRST (claiming target+parent+child, reparenting, detaching, and
     * deleting in one transaction), then the child-attach is attempted — exactly
     * the state a racing attacher would observe once the delete's claim on the
     * shared parent row unblocks it. The attach's own claim on the now-deleted
     * parent affects zero rows, so it fails 422 rather than creating an orphan
     * category. The real two-connection interleave is exercised by
     * testConcurrentDeleteVsChildAttachSerializesDeterministically(), gated to a
     * pgsql test lane.
     */
    public function testSequentialDeleteThenChildAttachLeavesNoOrphanCategory(): void
    {
        $service = $this->categoryService();
        $parent = $service->create($this->context, ['slug' => 'parent-race', 'name' => 'Parent Race']);
        $child = $service->create(
            $this->context,
            ['slug' => 'child-race', 'name' => 'Child Race', 'parent_uuid' => $parent['uuid']]
        );

        $service->delete($this->context, $parent['uuid']);

        // The existing child was re-parented to root, not orphaned.
        $categories = new CategoryRepository();
        $reloadedChild = $categories->findByUuid($this->context, '', $child['uuid']);
        self::assertNull($reloadedChild['parent_uuid']);
        self::assertNull($categories->findByUuid($this->context, '', $parent['uuid']));

        // A NEW child-attach against the now-deleted parent must fail, not land.
        try {
            $service->create(
                $this->context,
                ['slug' => 'late-child', 'name' => 'Late Child', 'parent_uuid' => $parent['uuid']]
            );
            self::fail('expected ValidationException for a parent deleted before the attach claim ran');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('parent_uuid', $e->firstErrors());
        }

        self::assertNull($categories->findBySlug($this->context, '', 'late-child'));
    }

    /**
     * Real cross-connection interleaving: connection A (this test) holds the
     * target category's claim open and uncommitted (as the first step of a
     * delete) while connection B (a genuinely independent subprocess,
     * fixtures/category_child_attach_race_child.php) attempts to create a new
     * child category under the SAME parent. B's claim blocks on PostgreSQL
     * row-lock contention until A completes its delete and commits; B's claim
     * then affects zero rows (the parent is gone) and B must fail with a
     * validation error rather than create an orphan.
     */
    public function testConcurrentDeleteVsChildAttachSerializesDeterministically(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            $this->markTestSkipped('Requires a PostgreSQL test lane for true row-claim interleaving.');
        }

        $pgConfig = [
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

        $connectionA = new Connection($pgConfig);
        $schema = $connectionA->getSchemaBuilder();
        foreach (static::MIGRATIONS as $migration) {
            (new $migration())->up($schema);
        }

        $contextA = $this->pgsqlContext($connectionA);
        $parentUuid = 'catpgrace001';
        $connectionA->table('commerce_categories')->insert([
            'uuid' => $parentUuid,
            'tenant_uuid' => '',
            'slug' => 'catpgrace001',
            'name' => 'Race Parent',
        ]);

        // A claims the parent first -- this holds the row lock, uncommitted. The
        // claim primitive (not the full service) is used directly so the test can
        // pause mid-delete while B attempts to claim the same row.
        $connectionA->getTransactionManager()->begin();
        $categories = new CategoryRepository();
        self::assertTrue($categories->claimRevision($contextA, '', $parentUuid));

        // Launch B: its own child-attach claim on the same parent blocks on A's row lock.
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/fixtures/category_child_attach_race_child.php',
                json_encode($pgConfig, JSON_THROW_ON_ERROR),
                $parentUuid,
                'latechildpg1',
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        // Give B time to reach and block on its own claim UPDATE before A proceeds.
        usleep(300_000);

        // A completes the delete directly (it already holds the claim, so no
        // service-level re-claim is needed): no children/products exist yet, so
        // delete the row outright, then commit -- releasing the row lock so B's
        // blocked claim can proceed.
        $connectionA->table('commerce_categories')
            ->where('tenant_uuid', '=', '')
            ->where('uuid', '=', $parentUuid)
            ->delete();
        $connectionA->getTransactionManager()->commit();

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $result = json_decode(trim($stdout), true);
        self::assertIsArray($result, "Connection B's subprocess produced no parseable result. stderr: {$stderr}");
        self::assertFalse(
            $result['created'],
            "B's child-attach must fail once the parent is deleted, not succeed (stderr: {$stderr})."
        );
        self::assertSame(ValidationException::class, $result['exceptionClass'] ?? null);

        self::assertNull(
            $connectionA->table('commerce_categories')->where('uuid', '=', $parentUuid)->first(),
            'The race parent must remain deleted.'
        );
        self::assertNull(
            $connectionA->table('commerce_categories')->where('slug', '=', 'latechildpg1')->first(),
            "B's child-attach must not have created an orphan category."
        );
    }

    /**
     * The bug this fix closes: two DISJOINT reparents sharing a node on the same
     * root->leaf path, each individually valid against its OWN pre-transaction
     * view, whose combination compounds depth past 6. Connection A stands in for
     * a larger in-flight "move B under A" mutation that has already claimed R (an
     * ancestor of A) as part of its claim set -- exactly what CategoryService now
     * does for every create-with-parent/reparent. Connection B (a genuinely
     * independent subprocess) runs the real CategoryService::update() to move R
     * under D; its own claim set includes R (the node being reparented), so it
     * blocks on A's held row lock until A commits. Once unblocked, B's fresh
     * subtree-height read sees B-under-A already applied and must reject its own
     * reparent rather than compound the tree past the max depth.
     */
    public function testConcurrentDisjointReparentsSharingAnAncestorSerializeAndRejectCompoundedDepth(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            $this->markTestSkipped('Requires a PostgreSQL test lane for true row-claim interleaving.');
        }

        $pgConfig = [
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

        $connectionA = new Connection($pgConfig);
        $schema = $connectionA->getSchemaBuilder();
        foreach (static::MIGRATIONS as $migration) {
            (new $migration())->up($schema);
        }

        $contextA = $this->pgsqlContext($connectionA);
        $categories = new CategoryRepository();

        // root(1) -> R(2) -> P(3) -> A(4): A's ancestor chain is [P, R, root].
        $rootUuid = 'catraceroot1';
        $rUuid = 'catracer0001';
        $pUuid = 'catracep0001';
        $aUuid = 'catracea0001';
        // B(1) -> B1(2): a standalone subtree of height 1, about to move under A.
        $bUuid = 'catraceb0001';
        $b1Uuid = 'catraceb1001';
        // root -> Dp(2) -> D(3): destination for R.
        $dpUuid = 'catracedp001';
        $dUuid = 'catraced0001';
        $allUuids = [$rootUuid, $rUuid, $pUuid, $aUuid, $bUuid, $b1Uuid, $dpUuid, $dUuid];

        // Self-healing: wipe any debris a previously-interrupted run of this same
        // pgsql-gated test left behind before inserting the fixture rows.
        $this->deleteCategoriesByUuid($connectionA, $allUuids);

        foreach (
            [
                [$rootUuid, null],
                [$rUuid, $rootUuid],
                [$pUuid, $rUuid],
                [$aUuid, $pUuid],
                [$bUuid, null],
                [$b1Uuid, $bUuid],
                [$dpUuid, $rootUuid],
                [$dUuid, $dpUuid],
            ] as [$uuid, $parentUuid]
        ) {
            $connectionA->table('commerce_categories')->insert([
                'uuid' => $uuid,
                'tenant_uuid' => '',
                'parent_uuid' => $parentUuid,
                'slug' => $uuid,
                'name' => $uuid,
            ]);
        }

        // A holds R's claim open and uncommitted -- standing in for a larger
        // in-flight "move B under A" mutation that already claimed R as one of
        // A's snapshotted ancestors. The claim primitive is used directly (not the
        // full service) so the test can pause mid-mutation while B races it.
        $connectionA->getTransactionManager()->begin();
        self::assertTrue($categories->claimRevision($contextA, '', $rUuid));

        // Launch B: the real CategoryService::update(), reparenting R under D. R
        // is in B's own claim set (the node being reparented), so its claim
        // blocks on A's held row lock.
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/fixtures/category_reparent_race_child.php',
                json_encode($pgConfig, JSON_THROW_ON_ERROR),
                $rUuid,
                $dUuid,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        // Give B time to reach and block on its own claim UPDATE before A proceeds.
        usleep(300_000);

        // A completes its half of the race for real (B moved under A), then
        // commits -- releasing R's row lock so B's blocked claim can proceed.
        $connectionA->table('commerce_categories')
            ->where('tenant_uuid', '=', '')
            ->where('uuid', '=', $bUuid)
            ->update(['parent_uuid' => $aUuid]);
        $connectionA->getTransactionManager()->commit();

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $result = json_decode(trim($stdout), true);
        self::assertIsArray($result, "Connection B's subprocess produced no parseable result. stderr: {$stderr}");
        self::assertFalse(
            $result['updated'],
            'B\'s reparent must be rejected once serialization lets it see the compounded depth '
                . "(stderr: {$stderr})."
        );
        self::assertSame(ValidationException::class, $result['exceptionClass'] ?? null);

        // Exactly one side landed: B's move under A committed; R's move under D
        // did not -- so no leaf sits deeper than depth 6 (root->R->P->A->B->B1 = 6).
        $reloadedR = $connectionA->table('commerce_categories')->where('uuid', '=', $rUuid)->first();
        self::assertSame($rootUuid, $reloadedR['parent_uuid']);

        $reloadedB = $connectionA->table('commerce_categories')->where('uuid', '=', $bUuid)->first();
        self::assertSame($aUuid, $reloadedB['parent_uuid']);

        // Leave the pgsql fixture database as we found it.
        $this->deleteCategoriesByUuid($connectionA, $allUuids);
    }

    /** @param list<string> $uuids */
    private function deleteCategoriesByUuid(Connection $connection, array $uuids): void
    {
        foreach ($uuids as $uuid) {
            $connection->table('commerce_categories')->where('uuid', '=', $uuid)->delete();
        }
    }

    private function currentRevision(string $uuid, string $tenant = ''): int
    {
        $row = $this->connection->table('commerce_categories')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();

        return $row === null ? -1 : (int) $row['revision'];
    }

    private function seedCategory(string $tenant, string $uuid): void
    {
        $this->connection->table('commerce_categories')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => strtolower($uuid),
            'name' => $uuid,
        ]);
    }

    private function categoryService(?string $tenant = null): CategoryService
    {
        return new CategoryService(
            new CategoryRepository(),
            new ProductRepository(),
            $tenant === null ? new SentinelTenantResolver() : $this->fixedTenant($tenant)
        );
    }

    private function fixedTenant(string $tenant): CurrentTenantResolver
    {
        return new class ($tenant) implements CurrentTenantResolver {
            public function __construct(private string $tenant)
            {
            }

            public function tenantUuid(ApplicationContext $context): string
            {
                return $this->tenant;
            }
        };
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
