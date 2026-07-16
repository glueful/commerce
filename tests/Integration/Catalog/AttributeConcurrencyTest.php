<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Catalog;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Catalog\AttributeRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Validation\ValidationException;
use Psr\Container\ContainerInterface;

/**
 * Attribute claim discipline: revision-claim mechanics and the
 * delete-vs-product-attribute-set-list race. Follows the same
 * deterministic-claim-plus-pgsql-race split as CategoryTreeConcurrencyTest /
 * MediaTenancyConcurrencyTest — a real two-connection interleave is only observable
 * in a genuinely separate OS process (PHP has no threads), so the default suite
 * proves the invariant sequentially (see
 * AttributeEndpointTest::testSetProductAttributesWithAttributeDeletedConcurrentlyReturns422())
 * and the pgsql-gated test here proves it under a true row-lock race. Schema shape
 * lives in CatalogBreadthShapeTest; this file is scoped to attribute claim/race
 * behavior only.
 */
final class AttributeConcurrencyTest extends CommerceTestCase
{
    private const TENANT_B = 'tenantBBBB02';

    public function testClaimRevisionIncrementsAndReturnsTrueRepeatedly(): void
    {
        $this->seedAttribute('', 'attrclaim001');
        $repository = new AttributeRepository();

        self::assertTrue($repository->claimRevision($this->context, '', 'attrclaim001'));
        self::assertSame(1, $this->currentRevision('attrclaim001'));

        self::assertTrue($repository->claimRevision($this->context, '', 'attrclaim001'));
        self::assertSame(2, $this->currentRevision('attrclaim001'));
    }

    public function testClaimRevisionReturnsFalseForUnknownOrCrossTenantAttribute(): void
    {
        $repository = new AttributeRepository();
        self::assertFalse($repository->claimRevision($this->context, '', 'no-such-attr'));

        $this->seedAttribute(self::TENANT_B, 'attrclaimtb1');
        self::assertFalse($repository->claimRevision($this->context, '', 'attrclaimtb1'));
        self::assertSame(0, $this->currentRevision('attrclaimtb1', self::TENANT_B));
    }

    /**
     * Real cross-connection interleaving: connection A (this test) holds the target
     * attribute's claim open and uncommitted (as the first step of a delete) while
     * connection B (a genuinely independent subprocess,
     * fixtures/attribute_delete_vs_assignment_race_child.php) runs the real
     * AttributeService::setProductAttributes() referencing that same attribute. B's
     * own claim on the attribute (part of its claim set, since the attribute is
     * newly proposed for the product) blocks on PostgreSQL row-lock contention until
     * A completes its delete cascade and commits. B's claim then affects zero rows
     * (the attribute is gone), so B must fail 422 rather than insert a
     * commerce_product_attributes row referencing a deleted attribute -- never a
     * dangling join row. The reverse interleave (set-list lands first, delete's
     * cascade then sweeps the join row away) is covered deterministically by
     * AttributeEndpointTest::testDeleteAttributeCascadesValuesAndProductAssignments(),
     * since AttributeService::delete()'s cascade unconditionally detaches every
     * product-attribute row for the attribute inside the same claimed transaction
     * regardless of when that row was created.
     */
    public function testConcurrentDeleteVsProductAttributeSetListSerializesDeterministically(): void
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
        $attributeUuid = 'attrpgrace01';
        $valueUuid = 'valpgrace001';
        $productUuid = 'prodattrpg01';

        // Self-healing: wipe any debris a previously-interrupted run of this same
        // pgsql-gated test left behind before inserting the fixture rows.
        $this->deleteRaceDebris($connectionA, $attributeUuid, $productUuid);

        $connectionA->table('commerce_attributes')->insert([
            'uuid' => $attributeUuid,
            'tenant_uuid' => '',
            'slug' => 'colorpgrace',
            'name' => 'Color Pg Race',
        ]);
        $connectionA->table('commerce_attribute_values')->insert([
            'uuid' => $valueUuid,
            'attribute_uuid' => $attributeUuid,
            'slug' => 'red',
            'value' => 'Red',
        ]);
        $connectionA->table('commerce_products')->insert([
            'uuid' => $productUuid,
            'tenant_uuid' => '',
            'slug' => $productUuid,
            'name' => 'Race Product',
            'type' => 'physical',
            'status' => 'active',
        ]);

        // A claims the attribute first -- this holds the row lock, uncommitted. The
        // claim primitive (not the full service) is used directly so the test can
        // pause mid-delete while B attempts to claim the same row via its own
        // set-list call.
        $connectionA->getTransactionManager()->begin();
        $attributes = new AttributeRepository();
        self::assertTrue($attributes->claimRevision($contextA, '', $attributeUuid));

        // Launch B: its own product-attribute set-list claim on the same attribute
        // blocks on A's held row lock.
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/fixtures/attribute_delete_vs_assignment_race_child.php',
                json_encode($pgConfig, JSON_THROW_ON_ERROR),
                $productUuid,
                $attributeUuid,
                'red',
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        // Give B time to reach and block on its own claim UPDATE before A proceeds.
        usleep(300_000);

        // A completes the delete cascade directly (it already holds the claim, so no
        // service-level re-claim is needed): delete every value, detach every
        // product assignment (none exist yet), then delete the attribute row itself,
        // then commit -- releasing the row lock so B's blocked claim can proceed.
        $connectionA->table('commerce_attribute_values')
            ->where('attribute_uuid', '=', $attributeUuid)
            ->delete();
        $connectionA->table('commerce_product_attributes')
            ->where('attribute_uuid', '=', $attributeUuid)
            ->delete();
        $connectionA->table('commerce_attributes')
            ->where('tenant_uuid', '=', '')
            ->where('uuid', '=', $attributeUuid)
            ->delete();
        $connectionA->getTransactionManager()->commit();

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $result = json_decode(trim($stdout), true);
        self::assertIsArray($result, "Connection B's subprocess produced no parseable result. stderr: {$stderr}");
        self::assertNull(
            $result['attributes'],
            "B's set-list must fail once the attribute is deleted, not land (stderr: {$stderr})."
        );
        self::assertSame(ValidationException::class, $result['exceptionClass'] ?? null);

        self::assertNull(
            $connectionA->table('commerce_attributes')->where('uuid', '=', $attributeUuid)->first(),
            'The race attribute must remain deleted.'
        );
        self::assertSame(
            0,
            $connectionA->table('commerce_product_attributes')
                ->where('attribute_uuid', '=', $attributeUuid)->count(),
            "B's set-list must not have created a dangling join row referencing the deleted attribute."
        );
        self::assertSame(
            0,
            $connectionA->table('commerce_product_attributes')
                ->where('product_uuid', '=', $productUuid)->count(),
            'The race product must have no attribute assignments after the race.'
        );

        // Leave the pgsql fixture database as we found it.
        $this->deleteRaceDebris($connectionA, $attributeUuid, $productUuid);
    }

    private function deleteRaceDebris(Connection $connection, string $attributeUuid, string $productUuid): void
    {
        $connection->table('commerce_product_attributes')
            ->where('attribute_uuid', '=', $attributeUuid)
            ->delete();
        $connection->table('commerce_product_attributes')
            ->where('product_uuid', '=', $productUuid)
            ->delete();
        $connection->table('commerce_attribute_values')
            ->where('attribute_uuid', '=', $attributeUuid)
            ->delete();
        $connection->table('commerce_attributes')
            ->where('uuid', '=', $attributeUuid)
            ->delete();
        // forceDelete(), not delete(): commerce_products carries a deleted_at column,
        // so a plain delete() would soft-delete it -- leaving the row (and its unique
        // uuid) physically in place and breaking this cleanup's idempotency across
        // repeated runs of this same pgsql-gated test.
        $connection->table('commerce_products')
            ->where('uuid', '=', $productUuid)
            ->forceDelete();
    }

    private function currentRevision(string $uuid, string $tenant = ''): int
    {
        $row = $this->connection->table('commerce_attributes')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();

        return $row === null ? -1 : (int) $row['revision'];
    }

    private function seedAttribute(string $tenant, string $uuid): void
    {
        $this->connection->table('commerce_attributes')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => strtolower($uuid),
            'name' => $uuid,
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
