<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Shipping;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Shipping\ShippingZoneRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Psr\Container\ContainerInterface;

/**
 * Shipping-zone claim discipline: revision-claim mechanics and the
 * zone-delete-vs-method-create race. Both `ShippingZoneService::delete()` and
 * `::createMethod()` claim the SAME zone row (see `ShippingZoneService`'s class
 * docblock -- neither `commerce_shipping_zone_locations` nor
 * `commerce_shipping_methods` carries a revision of its own, so the owning
 * zone's revision is the single serialization point for everything beneath
 * it). Follows the same deterministic-claim-plus-pgsql-race split as
 * AttributeConcurrencyTest/CategoryTreeConcurrencyTest -- a real
 * two-connection interleave is only observable in a genuinely separate OS
 * process (PHP has no threads), so the default suite proves the invariant
 * sequentially (see
 * ShippingZoneEndpointTest::testDeleteZoneThenMethodCreateThrowsNotFoundLeavingNoOrphanedMethod())
 * and the pgsql-gated test here proves it under a true row-lock race. Schema
 * shape lives in ShippingTaxShapeTest; this file is scoped to zone/method
 * claim/race behavior only.
 */
final class ZoneMethodConcurrencyTest extends CommerceTestCase
{
    private const TENANT_B = 'tenantBBBB02';

    public function testClaimRevisionIncrementsAndReturnsTrueRepeatedly(): void
    {
        $this->seedZone('', 'zoneclaim001');
        $repository = new ShippingZoneRepository();

        self::assertTrue($repository->claimRevision($this->context, '', 'zoneclaim001'));
        self::assertSame(1, $this->currentRevision('zoneclaim001'));

        self::assertTrue($repository->claimRevision($this->context, '', 'zoneclaim001'));
        self::assertSame(2, $this->currentRevision('zoneclaim001'));
    }

    public function testClaimRevisionReturnsFalseForUnknownOrCrossTenantZone(): void
    {
        $repository = new ShippingZoneRepository();
        self::assertFalse($repository->claimRevision($this->context, '', 'no-such-zone'));

        $this->seedZone(self::TENANT_B, 'zoneclaimtb1');
        self::assertFalse($repository->claimRevision($this->context, '', 'zoneclaimtb1'));
        self::assertSame(0, $this->currentRevision('zoneclaimtb1', self::TENANT_B));
    }

    /**
     * Real cross-connection interleaving: connection A (this test) holds the
     * target zone's claim open and uncommitted (as the first step of a delete)
     * while connection B (a genuinely independent subprocess,
     * fixtures/zone_delete_vs_method_create_race_child.php) runs the real
     * ShippingZoneService::createMethod() against that same zone. B's own
     * claim on the zone (its first step, since neither location nor method
     * rows carry a revision of their own) blocks on PostgreSQL row-lock
     * contention until A completes its delete cascade and commits. B's claim
     * then affects zero rows (the zone is gone), so B must fail with a
     * non-revealing 404 rather than insert a commerce_shipping_methods row
     * referencing a deleted zone -- never an orphaned method.
     */
    public function testConcurrentZoneDeleteVsMethodCreateSerializesDeterministically(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            $this->markTestSkipped('Requires a PostgreSQL test lane for true row-claim interleaving.');
        }

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);

        $zoneUuid = 'zonepgrace01';

        // Self-healing: wipe any debris a previously-interrupted run of this
        // same pgsql-gated test left behind before inserting the fixture row.
        $this->deleteRaceDebris($connectionA, $zoneUuid);

        $connectionA->table('commerce_shipping_zones')->insert([
            'uuid' => $zoneUuid,
            'tenant_uuid' => '',
            'name' => 'Race Zone',
        ]);

        // A claims the zone first -- this holds the row lock, uncommitted. The
        // claim primitive (not the full service) is used directly so the test
        // can pause mid-delete while B's method-create attempts to claim the
        // same zone row.
        $connectionA->getTransactionManager()->begin();
        $zones = new ShippingZoneRepository();
        self::assertTrue($zones->claimRevision($contextA, '', $zoneUuid));

        // Launch B: its own method-create claim on the same zone blocks on A's
        // held row lock.
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/fixtures/zone_delete_vs_method_create_race_child.php',
                json_encode($pgConfig, JSON_THROW_ON_ERROR),
                $zoneUuid,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        // Give B time to reach and block on its own claim UPDATE before A proceeds.
        usleep(300_000);

        // A completes the delete cascade directly (it already holds the claim,
        // so no service-level re-claim is needed): delete every method (none
        // exist yet), delete every location (none exist yet), then delete the
        // zone row itself, then commit -- releasing the row lock so B's
        // blocked claim can proceed.
        $connectionA->table('commerce_shipping_methods')->where('zone_uuid', '=', $zoneUuid)->delete();
        $connectionA->table('commerce_shipping_zone_locations')->where('zone_uuid', '=', $zoneUuid)->delete();
        $connectionA->table('commerce_shipping_zones')
            ->where('tenant_uuid', '=', '')
            ->where('uuid', '=', $zoneUuid)
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
            $result['method'],
            "B's method-create must fail once the zone is deleted, not land (stderr: {$stderr})."
        );
        self::assertSame(
            \Glueful\Http\Exceptions\Client\NotFoundException::class,
            $result['exceptionClass'] ?? null
        );

        self::assertNull(
            $connectionA->table('commerce_shipping_zones')->where('uuid', '=', $zoneUuid)->first(),
            'The race zone must remain deleted.'
        );
        self::assertSame(
            0,
            $connectionA->table('commerce_shipping_methods')->where('zone_uuid', '=', $zoneUuid)->count(),
            "B's method-create must not have left an orphaned method row referencing the deleted zone."
        );

        // Leave the pgsql fixture database as we found it.
        $this->deleteRaceDebris($connectionA, $zoneUuid);
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

    private function deleteRaceDebris(Connection $connection, string $zoneUuid): void
    {
        $connection->table('commerce_shipping_methods')->where('zone_uuid', '=', $zoneUuid)->delete();
        $connection->table('commerce_shipping_zone_locations')->where('zone_uuid', '=', $zoneUuid)->delete();
        $connection->table('commerce_shipping_zones')->where('uuid', '=', $zoneUuid)->delete();
    }

    private function currentRevision(string $uuid, string $tenant = ''): int
    {
        $row = $this->connection->table('commerce_shipping_zones')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();

        return $row === null ? -1 : (int) $row['revision'];
    }

    private function seedZone(string $tenant, string $uuid): void
    {
        $this->connection->table('commerce_shipping_zones')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
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
