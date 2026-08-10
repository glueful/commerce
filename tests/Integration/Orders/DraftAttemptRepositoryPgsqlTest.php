<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Orders\DraftAttemptRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Psr\Container\ContainerInterface;

/**
 * Real-PostgreSQL race lane (admin-order-creation cycle 2, Task 6): two genuinely
 * concurrent FIRST claims for the SAME `(tenant_uuid, idempotency_key)` against
 * DIFFERENT drafts (different fingerprint/order_uuid). PHP has no threads, so a
 * genuine insert-vs-insert race needs a truly separate OS process/connection --
 * mirrors `Orders\OrderNumberGeneratorTest`'s
 * `testCompetingAllocationInsideOpenTransactionDoesNotPoisonItOnRealPostgres()`
 * pattern and gating/fixture-width/self-healing discipline exactly.
 *
 * Connection B (subprocess) inserts first and holds it open/uncommitted just long
 * enough for connection A's (this test's) OWN `DraftAttemptRepository::claimOrReplay()`
 * call to run its pre-check (sees zero rows, since B hasn't committed) and then block
 * on its own INSERT attempt. Once B commits, A's blocked insert resolves into a
 * genuine unique-constraint conflict. Pre-fix (no savepoint isolation), a caught
 * conflict would abort A's own ambient PostgreSQL transaction outright, so the
 * required "later write" below would fail with "current transaction is aborted".
 * Post-fix (savepoint-isolated fresh-insert attempt), A's ambient transaction stays
 * perfectly usable: the conflict resolves to a clean, typed `fingerprint_mismatch`
 * (different draft, same key) with no raw PDO error ever reaching calling code, and
 * A can still commit further work of its own afterward.
 */
final class DraftAttemptRepositoryPgsqlTest extends CommerceTestCase
{
    public function testConcurrentFirstClaimsForTheSameKeyAgainstDifferentDraftsResolveDeterministically(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'draftrace001';
        $key = 'idem-race-key';

        $this->deleteAttemptRows($connectionA, $tenant);

        try {
            // A's own already-open ambient transaction -- the shape a real finalize
            // flow leaves `claimOrReplay()` in (called inside the caller's transaction).
            $connectionA->getTransactionManager()->begin();

            $handle = $this->launchRaceChild($pgConfig, 'hold_insert', [
                'tenant' => $tenant,
                'idempotencyKey' => $key,
                'fingerprint' => 'fp-from-connection-b',
                'orderUuid' => 'orderuuidbbb',
                'sleepMs' => 500,
            ]);

            // Give B time to insert (uncommitted) before A's own claimOrReplay() call
            // runs its pre-check SELECT against the same (tenant, idempotency_key).
            usleep(200_000);

            $result = (new DraftAttemptRepository())->claimOrReplay(
                $contextA,
                $tenant,
                $key,
                'fp-from-connection-a',
                'orderuuidaaa'
            );

            $childResult = $this->collectRaceChild($handle);
            self::assertTrue($childResult['ok'] ?? false, 'the holding insert must commit cleanly');

            // B's insert (a different draft) won the race; A's caught conflict
            // resolved to a deterministic, typed mismatch -- never a raw PDO error.
            self::assertSame('fingerprint_mismatch', $result['state']);
            self::assertSame('orderuuidbbb', $result['attempt']['order_uuid']);
            self::assertSame('fp-from-connection-b', $result['attempt']['request_fingerprint']);

            // Exactly one row exists for this key -- A never inserted a second one.
            self::assertSame(
                1,
                $connectionA->table('commerce_order_draft_attempts')
                    ->where('tenant_uuid', '=', $tenant)
                    ->where('idempotency_key', '=', $key)
                    ->count()
            );

            // Proof the ambient transaction survived: A can still write and commit.
            $connectionA->table('commerce_order_draft_attempts')->insert([
                'tenant_uuid' => $tenant,
                'idempotency_key' => 'idem-race-key-later',
                'request_fingerprint' => 'fp-later',
                'order_uuid' => 'orderuuidccc',
                'status' => 'pending',
            ]);
            $connectionA->getTransactionManager()->commit();

            // Reload on a fresh statement to confirm the commit really took.
            self::assertSame(
                1,
                $connectionA->table('commerce_order_draft_attempts')
                    ->where('tenant_uuid', '=', $tenant)
                    ->where('idempotency_key', '=', 'idem-race-key-later')
                    ->count()
            );
        } finally {
            $this->deleteAttemptRows($connectionA, $tenant);
        }
    }

    // --- Helpers -------------------------------------------------------------
    // (pgsql lane setup mirrors Orders\OrderNumberGeneratorTest exactly.)

    private function skipUnlessPgsql(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            self::markTestSkipped('Requires a PostgreSQL test lane to prove savepoint-isolated claim behavior.');
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

    private function deleteAttemptRows(Connection $connection, string $tenant): void
    {
        $connection->table('commerce_order_draft_attempts')
            ->where('tenant_uuid', '=', $tenant)
            ->delete();
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
                __DIR__ . '/fixtures/draft_attempt_race_child.php',
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
