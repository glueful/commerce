<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Psr\Container\ContainerInterface;

/**
 * Real-PostgreSQL race lane (admin-order-creation cycle 2, Task 8): two genuinely
 * concurrent finalizations of the SAME draft. PHP has no threads, so a real
 * update-vs-update race needs a truly separate OS process/connection -- mirrors
 * `Orders\DraftAttemptRepositoryPgsqlTest`'s gating/fixture-width/self-healing
 * discipline exactly.
 *
 * Connection B (subprocess) runs `finalizeDraftTransition()` first and holds the
 * resulting row lock open/uncommitted; connection A (this test) then runs its OWN
 * finalize, which blocks on that lock. Once B commits, A's blocked UPDATE re-reads
 * the row, sees `status = 'pending_payment'` (no longer `'draft'`), matches ZERO
 * rows, and loses deterministically -- a typed `DomainException`, never a silent
 * double-finalize and never a raw PDO error. Exactly one `status:pending_payment`
 * audit row exists afterwards: the loser wrote nothing at all.
 */
final class DraftFinalizeTransitionPgsqlTest extends CommerceTestCase
{
    public function testConcurrentFinalizationsOfTheSameDraftResolveToExactlyOneWinner(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'draftfin0001';
        $orderUuid = 'draftfinord1';

        $this->deleteRows($connectionA, $tenant, $orderUuid);

        try {
            $this->seedDraft($connectionA, $tenant, $orderUuid);

            $handle = $this->launchRaceChild($pgConfig, 'hold_finalize', [
                'tenant' => $tenant,
                'orderUuid' => $orderUuid,
                'sleepMs' => 500,
            ]);

            // Give B time to take the row lock before A attempts its own CAS.
            usleep(200_000);

            $loserMessage = null;
            try {
                (new OrderRepository())->finalizeDraftTransition($contextA, $tenant, $orderUuid);
            } catch (\DomainException $e) {
                $loserMessage = $e->getMessage();
            }

            $childResult = $this->collectRaceChild($handle);
            self::assertTrue($childResult['ok'] ?? false, 'the holding finalize must commit cleanly');
            self::assertIsString($loserMessage, 'the second finalize must lose with a typed DomainException');

            $row = $connectionA->table('commerce_orders')
                ->where('tenant_uuid', '=', $tenant)
                ->where('uuid', '=', $orderUuid)
                ->first();
            self::assertIsArray($row);
            self::assertSame('pending_payment', (string) $row['status']);

            self::assertSame(
                1,
                $connectionA->table('commerce_order_events')
                    ->where('order_uuid', '=', $orderUuid)
                    ->where('type', '=', 'status:pending_payment')
                    ->count()
            );
        } finally {
            $this->deleteRows($connectionA, $tenant, $orderUuid);
        }
    }

    // --- Helpers -------------------------------------------------------------
    // (pgsql lane setup mirrors Orders\DraftAttemptRepositoryPgsqlTest exactly.)

    private function skipUnlessPgsql(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            self::markTestSkipped('Requires a PostgreSQL test lane to prove the concurrent finalize loser.');
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

    private function seedDraft(Connection $connection, string $tenant, string $orderUuid): void
    {
        $connection->table('commerce_orders')->insert([
            'uuid' => $orderUuid,
            'tenant_uuid' => $tenant,
            'order_number' => null,
            'status' => 'draft',
            'email' => null,
            'guest_token_hash' => null,
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
            'origin' => 'admin',
            'fulfillment_mode' => 'in_store',
        ]);
    }

    private function deleteRows(Connection $connection, string $tenant, string $orderUuid): void
    {
        $connection->table('commerce_order_events')->where('order_uuid', '=', $orderUuid)->delete();
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
                __DIR__ . '/fixtures/draft_finalize_race_child.php',
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
