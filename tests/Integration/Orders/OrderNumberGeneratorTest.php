<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Psr\Container\ContainerInterface;

final class OrderNumberGeneratorTest extends CommerceTestCase
{
    public function testFreshSequenceFirstOrderAndIncrements(): void
    {
        $generator = new OrderNumberGenerator();

        self::assertSame('ORD-000001', $generator->next($this->context, ''));
        self::assertSame('ORD-000002', $generator->next($this->context, ''));
    }

    public function testSequencesAreTenantIsolated(): void
    {
        $generator = new OrderNumberGenerator();

        $generator->next($this->context, '');

        self::assertSame('ORD-000001', $generator->next($this->context, 'tenantAAAA01'));
    }

    // =====================================================================
    // Real-PostgreSQL race lanes (admin-order-creation cycle 2, Task 4):
    // `next()`'s competing-insert catch previously ran with no savepoint --
    // on PostgreSQL a caught unique violation inside an open transaction
    // poisons it (every later statement fails with "current transaction is
    // aborted"), which would silently break Task 10's transactional
    // numbering claim. Gating/fixture-width/self-healing discipline mirrors
    // `Migrations\StockNotNullBackfillTest`'s pgsql lane exactly.
    // =====================================================================

    /**
     * Connection A holds an OPEN ambient transaction the whole time -- the
     * exact shape a real order-creation flow leaves `next()` in (Task 10).
     * Connection B (subprocess) inserts the tenant's `commerce_sequences`
     * row first but holds it open/uncommitted just long enough for A's own
     * `next()` call to see zero existing rows (so A also attempts an
     * INSERT), then B commits -- unblocking A's insert into a genuine
     * unique-constraint conflict. Pre-fix, the caught conflict aborts A's
     * ambient PostgreSQL transaction outright: the fallback
     * `incrementExisting()` call inside the catch immediately throws a NEW,
     * uncaught "current transaction is aborted" error, so `next()` itself
     * fails and the test fails at that call. Post-fix (savepoint-isolated
     * allocation attempt), the conflict rolls back to the savepoint only --
     * A's ambient transaction stays perfectly usable, the fallback succeeds,
     * and A can run further statements and commit normally afterward.
     */
    public function testCompetingAllocationInsideOpenTransactionDoesNotPoisonItOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'ordpgpsn001';

        $this->deleteSequenceRow($connectionA, $tenant);

        try {
            // A's own already-open transaction -- the ambient context this
            // fix must never leave aborted.
            $connectionA->getTransactionManager()->begin();

            $handle = $this->launchRaceChild($pgConfig, 'hold_insert', [
                'tenant' => $tenant,
                'sleepMs' => 500,
            ]);

            // Give B time to insert (uncommitted) before A's own next() call
            // runs its own zero-rows-matched UPDATE against the same tenant.
            usleep(200_000);

            $generator = new OrderNumberGenerator();
            $orderNumber = $generator->next($contextA, $tenant);

            $result = $this->collectRaceChild($handle);
            self::assertTrue($result['ok'] ?? false, 'the holding insert must commit cleanly');

            // B's insert (value 1) landed first; A's caught conflict fell
            // back to incrementExisting(), advancing it to 2.
            self::assertSame('ORD-000002', $orderNumber);

            // Proof the ambient transaction survived: a further statement on
            // the SAME connection succeeds, and the whole thing commits.
            $stillUsable = $connectionA->table('commerce_sequences')
                ->where('tenant_uuid', '=', $tenant)
                ->where('name', '=', 'order')
                ->first();
            self::assertNotNull($stillUsable);
            self::assertSame(2, (int) $stillUsable['value']);

            $connectionA->getTransactionManager()->commit();

            // Reload on a fresh statement to confirm the commit really took.
            $committed = $connectionA->table('commerce_sequences')
                ->where('tenant_uuid', '=', $tenant)
                ->where('name', '=', 'order')
                ->first();
            self::assertSame(2, (int) $committed['value']);
        } finally {
            $this->deleteSequenceRow($connectionA, $tenant);
        }
    }

    /**
     * Symmetric proof from the OTHER side: connection A holds the FIRST
     * insert open/uncommitted (mirrors B's role above), and connection B
     * (subprocess) makes the REAL `next()` call from inside ITS OWN open
     * ambient transaction, blocking on A's held row. Once A commits, B's
     * insert unblocks into the same unique-constraint conflict -- pre-fix,
     * that poisons B's ambient transaction and the subprocess reports
     * failure; post-fix, B's savepoint-isolated retry succeeds, B's own
     * transaction commits, and both allocations (A's direct value 1, B's
     * `next()`-assigned value 2) succeed with distinct sequential numbers.
     */
    public function testTwoConcurrentAllocationsEachInsideTheirOwnOpenTransactionBothSucceedOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $tenant = 'ordpgcnc001';

        $this->deleteSequenceRow($connectionA, $tenant);

        try {
            $connectionA->getTransactionManager()->begin();
            $connectionA->table('commerce_sequences')->insert([
                'tenant_uuid' => $tenant,
                'name' => 'order',
                'value' => 1,
            ]);

            $handle = $this->launchRaceChild($pgConfig, 'allocate_in_transaction', ['tenant' => $tenant]);

            // Give B time to reach and block on its own conflicting insert
            // before A releases the row lock.
            usleep(300_000);
            $connectionA->getTransactionManager()->commit();

            $result = $this->collectRaceChild($handle);
            self::assertTrue(
                $result['ok'] ?? false,
                'B\'s own allocation, and its own ambient transaction, must both succeed after the caught '
                    . 'conflict: ' . json_encode($result, JSON_THROW_ON_ERROR)
            );
            self::assertSame('ORD-000002', $result['orderNumber']);

            $final = $connectionA->table('commerce_sequences')
                ->where('tenant_uuid', '=', $tenant)
                ->where('name', '=', 'order')
                ->first();
            self::assertNotNull($final);
            self::assertSame(2, (int) $final['value'], 'exactly one row, advanced by both allocations, no gaps');
        } finally {
            $this->deleteSequenceRow($connectionA, $tenant);
        }
    }

    // --- Helpers -------------------------------------------------------------
    // (pgsql lane setup mirrors Migrations\StockNotNullBackfillTest exactly.)

    private function skipUnlessPgsql(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            self::markTestSkipped('Requires a PostgreSQL test lane to prove savepoint-isolated allocation.');
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

    private function deleteSequenceRow(Connection $connection, string $tenant): void
    {
        $connection->table('commerce_sequences')
            ->where('tenant_uuid', '=', $tenant)
            ->where('name', '=', 'order')
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
                __DIR__ . '/fixtures/order_number_race_child.php',
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
