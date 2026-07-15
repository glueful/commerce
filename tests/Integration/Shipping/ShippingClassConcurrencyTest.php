<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Shipping;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Shipping\ShippingClassRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Validation\ValidationException;
use Psr\Container\ContainerInterface;

/**
 * Shipping-class claim discipline: revision-claim mechanics and the
 * class-delete-vs-variant-assign race. `ShippingClassService::delete()` and
 * `CatalogService::updateVariant()`'s shared-claim protocol both claim the
 * SAME class row from opposite sides (see `ShippingClassService`'s class
 * docblock for the full interleave analysis). Follows the same
 * deterministic-claim-plus-pgsql-race split as AttributeConcurrencyTest --
 * a real two-connection interleave is only observable in a genuinely
 * separate OS process (PHP has no threads), so the default suite proves the
 * "delete wins first" interleave sequentially (see
 * ShippingClassEndpointTest::testClassDeleteThenVariantAssignReturnsUnprocessable())
 * and the reverse "assign wins first" interleave via
 * ShippingClassEndpointTest::testDeleteReferencedClassReturns409ThenSucceedsAfterDetach();
 * the pgsql-gated test here proves the "delete wins" interleave under a true
 * row-lock race. Schema shape lives in ShippingTaxShapeTest; this file is
 * scoped to shipping-class claim/race behavior only.
 */
final class ShippingClassConcurrencyTest extends CommerceTestCase
{
    private const TENANT_B = 'tenantBBBB02';

    public function testClaimRevisionIncrementsAndReturnsTrueRepeatedly(): void
    {
        $this->seedClass('', 'clsclaim0001', 'claimable');
        $repository = new ShippingClassRepository();

        self::assertTrue($repository->claimRevision($this->context, '', 'clsclaim0001'));
        self::assertSame(1, $this->currentRevision('clsclaim0001'));

        self::assertTrue($repository->claimRevision($this->context, '', 'clsclaim0001'));
        self::assertSame(2, $this->currentRevision('clsclaim0001'));
    }

    public function testClaimRevisionReturnsFalseForUnknownOrCrossTenantClass(): void
    {
        $repository = new ShippingClassRepository();
        self::assertFalse($repository->claimRevision($this->context, '', 'no-such-class'));

        $this->seedClass(self::TENANT_B, 'clsclaimtb01', 'other');
        self::assertFalse($repository->claimRevision($this->context, '', 'clsclaimtb01'));
        self::assertSame(0, $this->currentRevision('clsclaimtb01', self::TENANT_B));
    }

    /**
     * Real cross-connection interleaving: connection A (this test) holds the
     * target class's claim open and uncommitted (as the first step of a
     * delete) while connection B (a genuinely independent subprocess,
     * fixtures/shipping_class_delete_vs_variant_assign_race_child.php) runs
     * the real CatalogService::updateVariant() assigning that same class to a
     * variant. B's own claim on the class (part of its claim set, since the
     * variant currently has no class and the class is newly proposed) blocks
     * on PostgreSQL row-lock contention until A completes its delete and
     * commits. B's claim then affects zero rows (the class is gone), so B
     * must fail 422 rather than leave the variant with a dangling
     * shipping_class_uuid pointing at a row that no longer exists.
     */
    public function testConcurrentClassDeleteVsVariantAssignSerializesDeterministically(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            $this->markTestSkipped('Requires a PostgreSQL test lane for true row-claim interleaving.');
        }

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);

        $classUuid = 'clspgrace001';
        $productUuid = 'prodclspg001';
        $variantUuid = 'varclspg0001';

        // Self-healing: wipe any debris a previously-interrupted run of this
        // same pgsql-gated test left behind before inserting the fixture rows.
        $this->deleteRaceDebris($connectionA, $classUuid, $productUuid);

        $connectionA->table('commerce_shipping_classes')->insert([
            'uuid' => $classUuid,
            'tenant_uuid' => '',
            'slug' => 'fragile-pg-race',
            'name' => 'Fragile Pg Race',
        ]);
        $connectionA->table('commerce_products')->insert([
            'uuid' => $productUuid,
            'tenant_uuid' => '',
            'slug' => $productUuid,
            'name' => 'Race Product',
            'type' => 'physical',
            'status' => 'active',
        ]);
        $connectionA->table('commerce_variants')->insert([
            'uuid' => $variantUuid,
            'tenant_uuid' => '',
            'product_uuid' => $productUuid,
            'sku' => 'CLSPGRACESKU1',
            'option_values' => json_encode([], JSON_THROW_ON_ERROR),
            'price' => 1000,
            'currency' => 'USD',
            'position' => 0,
            'status' => 'active',
        ]);

        // A claims the class first -- this holds the row lock, uncommitted.
        // The claim primitive (not the full service) is used directly so the
        // test can pause mid-delete while B's variant assignment attempts to
        // claim the same class row.
        $connectionA->getTransactionManager()->begin();
        $classes = new ShippingClassRepository();
        self::assertTrue($classes->claimRevision($contextA, '', $classUuid));

        // Launch B: its own shared-claim assignment on the same class blocks
        // on A's held row lock (the variant's product claim is uncontested and
        // succeeds immediately; only the class claim collides).
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/fixtures/shipping_class_delete_vs_variant_assign_race_child.php',
                json_encode($pgConfig, JSON_THROW_ON_ERROR),
                $variantUuid,
                $classUuid,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        // Give B time to reach and block on its own claim UPDATE before A proceeds.
        usleep(300_000);

        // A completes the delete directly (it already holds the claim, so no
        // service-level re-claim is needed): the variant has no class
        // reference yet (B is blocked before writing anything), so the
        // reference re-check passes and the class row is deleted, then
        // commit -- releasing the row lock so B's blocked claim can proceed.
        self::assertSame(
            0,
            $connectionA->table('commerce_variants')->where('shipping_class_uuid', '=', $classUuid)->count(),
            'Precondition: nothing may reference the class before A deletes it.'
        );
        $connectionA->table('commerce_shipping_classes')
            ->where('tenant_uuid', '=', '')
            ->where('uuid', '=', $classUuid)
            ->delete();
        $connectionA->getTransactionManager()->commit();

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $result = json_decode(trim($stdout), true);
        self::assertIsArray($result, "Connection B's subprocess produced no parseable result. stderr: {$stderr}");
        self::assertSame(
            ValidationException::class,
            $result['exceptionClass'] ?? null,
            "B's assignment must fail once the class is deleted, not land (stderr: {$stderr})."
        );

        self::assertNull(
            $connectionA->table('commerce_shipping_classes')->where('uuid', '=', $classUuid)->first(),
            'The race class must remain deleted.'
        );
        $variant = $connectionA->table('commerce_variants')->where('uuid', '=', $variantUuid)->first();
        self::assertNotNull($variant);
        self::assertNull(
            $variant['shipping_class_uuid'],
            "B's rejected assignment must never leave a dangling shipping_class_uuid on the variant."
        );

        // Leave the pgsql fixture database as we found it.
        $this->deleteRaceDebris($connectionA, $classUuid, $productUuid);
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

    private function deleteRaceDebris(Connection $connection, string $classUuid, string $productUuid): void
    {
        $connection->table('commerce_variants')->where('product_uuid', '=', $productUuid)->forceDelete();
        // forceDelete(), not delete(): commerce_products carries a deleted_at
        // column, so a plain delete() would soft-delete it -- leaving the row
        // (and its unique uuid) physically in place and breaking this cleanup's
        // idempotency across repeated runs of this same pgsql-gated test.
        $connection->table('commerce_products')->where('uuid', '=', $productUuid)->forceDelete();
        $connection->table('commerce_shipping_classes')->where('uuid', '=', $classUuid)->delete();
    }

    private function currentRevision(string $uuid, string $tenant = ''): int
    {
        $row = $this->connection->table('commerce_shipping_classes')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();

        return $row === null ? -1 : (int) $row['revision'];
    }

    private function seedClass(string $tenant, string $uuid, string $slug): void
    {
        $this->connection->table('commerce_shipping_classes')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => $slug,
            'name' => $slug,
        ]);
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
