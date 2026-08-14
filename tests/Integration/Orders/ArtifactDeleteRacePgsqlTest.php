<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderArtifactController;
use Glueful\Extensions\Commerce\Orders\DraftCleanupService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Real-PostgreSQL race lane for the draft-artifact delete GUARD (cleanup-train
 * Task 5). SQLite's database-wide write lock makes every delete trivially
 * serial, so a delete that trusted its own precheck instead of the
 * compare-and-set would pass unnoticed there; only two genuinely concurrent
 * connections can prove the CAS is the authority.
 *
 * Both arms drive the REAL {@see AdminOrderArtifactController} end to end, so
 * what is pinned is the endpoint's answer, not just the service's boolean:
 *
 *  1. **delete vs delete** -- the other side (a second operator, or the purge
 *     sweep on the same tick) removes the artifact first. The blocked CAS
 *     matches zero rows, the endpoint RE-READS, finds nothing, and answers the
 *     ordinary non-revealing 404. Nothing is double-deleted and no exception
 *     escapes.
 *  2. **delete vs "it stopped being an artifact"** -- the other side stamps an
 *     order number and a live status on the row while the delete is in flight.
 *     The blocked CAS matches zero rows and the endpoint re-reads to a typed
 *     409, WITH THE ORDER AND ITS LINES INTACT. This is the falsifiable half:
 *     drop `order_number IS NULL AND status = 'canceled'` from the DELETE's
 *     predicate and a numbered order plus its lines are destroyed here.
 *
 * Arm 2's interleaving is not reachable through today's state machine (a
 * canceled row has no transition back to live, and finalize's own CAS demands
 * `status = 'draft'`), which is exactly why the child stages the write
 * directly: the guard is DEFENSE IN DEPTH against any future writer that makes
 * a row stop being an artifact, and defense in depth that is never exercised is
 * only a comment.
 *
 * Gating, fixture width and cleanup discipline mirror `Orders\PaidCasRacePgsqlTest`.
 */
final class ArtifactDeleteRacePgsqlTest extends CommerceTestCase
{
    public function testAConcurrentDeleteMakesTheGuardedDeleteLoseAndTheEndpointAnswer404(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'artrace0001';
        $orderUuid = 'artraceord1';

        $this->deleteRows($connectionA, $tenant, $orderUuid);

        try {
            $this->seedArtifact($connectionA, $tenant, $orderUuid);
            $this->seedLine($connectionA, $orderUuid, 'artracelin1');

            $handle = $this->launchRaceChild($pgConfig, 'hold_delete', [
                'tenant' => $tenant,
                'orderUuid' => $orderUuid,
                'sleepMs' => 500,
            ]);

            // Give B time to take the row lock before A's own delete attempts it.
            usleep(200_000);

            $thrown = null;
            try {
                $this->controller($contextA, $tenant)->destroy($this->request($orderUuid), $orderUuid);
            } catch (NotFoundException $e) {
                $thrown = $e;
            }

            $childResult = $this->collectRaceChild($handle);
            self::assertTrue($childResult['ok'] ?? false, 'the holding delete must commit cleanly');
            self::assertTrue($childResult['deleted'] ?? false, 'the winner must report that it deleted');

            self::assertInstanceOf(
                NotFoundException::class,
                $thrown,
                'a delete whose row already went away is the ordinary non-revealing 404'
            );
            self::assertSame('Resource not found.', $thrown->getMessage());

            self::assertNull($this->rowOf($connectionA, $orderUuid));
            self::assertSame(0, $this->lineCount($connectionA, $orderUuid));
        } finally {
            $this->deleteRows($connectionA, $tenant, $orderUuid);
        }
    }

    public function testARowThatStopsBeingAnArtifactMidFlightIsRefusedAndSurvivesIntact(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'artrace0002';
        $orderUuid = 'artraceord2';

        $this->deleteRows($connectionA, $tenant, $orderUuid);

        try {
            $this->seedArtifact($connectionA, $tenant, $orderUuid);
            $this->seedLine($connectionA, $orderUuid, 'artracelin2');

            $handle = $this->launchRaceChild($pgConfig, 'hold_renumber', [
                'tenant' => $tenant,
                'orderUuid' => $orderUuid,
                'orderNumber' => 'ORD-RACE-2',
                'sleepMs' => 500,
            ]);

            usleep(200_000);

            // A read the row as an artifact BEFORE B committed -- the exact
            // interleaving the CAS exists for.
            $response = $this->controller($contextA, $tenant)->destroy($this->request($orderUuid), $orderUuid);

            $childResult = $this->collectRaceChild($handle);
            self::assertTrue($childResult['ok'] ?? false, 'the holding renumber must commit cleanly');
            self::assertSame(1, $childResult['affected'] ?? 0);

            self::assertSame(409, $response->getStatusCode());
            $body = json_decode((string) $response->getContent(), true);
            self::assertIsArray($body);
            self::assertSame(
                AdminOrderArtifactController::REASON_NOT_DELETABLE,
                $body['error']['details']['reason'] ?? null
            );
            self::assertSame('pending_payment', $body['error']['details']['status'] ?? null);

            $row = $this->rowOf($connectionA, $orderUuid);
            self::assertIsArray($row, 'the row that stopped being an artifact must survive');
            self::assertSame('ORD-RACE-2', (string) $row['order_number']);
            self::assertSame(1, $this->lineCount($connectionA, $orderUuid), 'its lines must survive too');
        } finally {
            $this->deleteRows($connectionA, $tenant, $orderUuid);
        }
    }

    // --- Helpers -------------------------------------------------------------
    // (pgsql lane setup mirrors Orders\PaidCasRacePgsqlTest exactly.)

    private function controller(ApplicationContext $context, string $tenant): AdminOrderArtifactController
    {
        $resolver = $this->tenantResolver($tenant);

        return new AdminOrderArtifactController(
            $context,
            new OrderRepository(),
            new DraftCleanupService(new OrderRepository(), $resolver),
            $resolver
        );
    }

    private function request(string $orderUuid): Request
    {
        return Request::create('/commerce/admin/orders/' . $orderUuid . '/artifact', 'DELETE');
    }

    private function skipUnlessPgsql(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            self::markTestSkipped('Requires a PostgreSQL test lane to prove the concurrent artifact-delete guard.');
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

    private function tenantResolver(string $tenant): CurrentTenantResolver
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

    private function seedArtifact(Connection $connection, string $tenant, string $orderUuid): void
    {
        $connection->table('commerce_orders')->insert([
            'uuid' => $orderUuid,
            'tenant_uuid' => $tenant,
            'order_number' => null,
            'status' => 'canceled',
            'email' => null,
            'guest_token_hash' => null,
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
            'origin' => 'admin',
            'fulfillment_mode' => 'in_store',
        ]);
    }

    private function seedLine(Connection $connection, string $orderUuid, string $lineUuid): void
    {
        $connection->table('commerce_order_lines')->insert([
            'uuid' => $lineUuid,
            'order_uuid' => $orderUuid,
            'variant_uuid' => 'variant00001',
            'product_name' => 'Widget',
            'sku' => 'WIDGET-1',
            'option_values' => '[]',
            'unit_price' => 1000,
            'quantity' => 1,
            'line_total' => 1000,
        ]);
    }

    /** @return array<string,mixed>|null */
    private function rowOf(Connection $connection, string $orderUuid): ?array
    {
        $row = $connection->table('commerce_orders')->where('uuid', '=', $orderUuid)->first();

        return is_array($row) ? $row : null;
    }

    private function lineCount(Connection $connection, string $orderUuid): int
    {
        return $connection->table('commerce_order_lines')->where('order_uuid', '=', $orderUuid)->count();
    }

    private function deleteRows(Connection $connection, string $tenant, string $orderUuid): void
    {
        $connection->table('commerce_order_lines')->where('order_uuid', '=', $orderUuid)->delete();
        $connection->table('commerce_order_events')->where('order_uuid', '=', $orderUuid)->delete();
        $connection->table('commerce_order_draft_attempts')->where('tenant_uuid', '=', $tenant)->delete();
        $connection->table('commerce_orders')->where('tenant_uuid', '=', $tenant)->delete();
    }

    /**
     * @param array<string,mixed> $pgConfig
     * @param array<string,mixed> $args
     * @return array{0: resource, 1: array<int,resource>}
     */
    private function launchRaceChild(array $pgConfig, string $action, array $args): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/fixtures/artifact_delete_race_child.php',
                json_encode($pgConfig, JSON_THROW_ON_ERROR),
                $action,
                json_encode($args, JSON_THROW_ON_ERROR),
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        return [$process, $pipes];
    }

    /**
     * @param array{0: resource, 1: array<int,resource>} $handle
     * @return array<string,mixed>
     */
    private function collectRaceChild(array $handle): array
    {
        [$process, $pipes] = $handle;
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $result = json_decode(trim((string) $stdout), true);
        self::assertIsArray($result, "subprocess produced no parseable result. stderr: {$stderr}");

        return $result;
    }
}
