<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Customers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Customers\CustomerAggregationRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Psr\Container\ContainerInterface;

/**
 * Real-PostgreSQL regression for {@see CustomerAggregationRepository}'s hand-built
 * GROUP BY SQL (see that class's own docblock for the two portability gotchas this
 * proves live): the listing query's `key_type` discriminator must read
 * `MAX(user_uuid)` rather than the bare grouped column (PostgreSQL rejects a
 * non-aggregated, non-grouped SELECT-list expression outright), and every string
 * literal in the hand-built SQL must be single- not double-quoted (SQLite's
 * double-quote identifier fallback masks that particular mistake, but this test
 * targets the GROUP BY rejection PostgreSQL alone would raise if the class ever
 * regressed to referencing the bare `user_uuid` column in `key_type`).
 *
 * Single-session, no subprocess/concurrency needed — mirrors
 * `Downloads\DownloadGrantExpiryPgsqlTest`'s lighter pgsql-lane pattern rather than
 * the two-connection race tests.
 */
final class CustomerAggregationPgsqlTest extends CommerceTestCase
{
    private const TENANT = 'pgaggtenant1';

    public function testListingAndBothDetailLookupsAggregateCorrectlyOnRealPostgres(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            $this->markTestSkipped('Requires a PostgreSQL test lane to prove the GROUP BY SQL is portable.');
        }

        $connection = $this->migratedConnection($this->pgConfig());
        $context = $this->pgsqlContext($connection);

        // Self-healing: wipe debris a previously-interrupted run of this same
        // pgsql-gated test left behind before inserting fixtures.
        $connection->table('commerce_orders')->where('tenant_uuid', '=', self::TENANT)->delete();

        try {
            $this->seedOrder($connection, 'pgaggord0001', 'pgagguser001', 'user@example.com', 1000);
            $this->seedOrder($connection, 'pgaggord0002', 'pgagguser001', 'user@example.com', 500, 100);
            $this->seedOrder($connection, 'pgaggord0003', null, ' Guest@Example.COM ', 700);
            $this->seedOrder($connection, 'pgaggord0004', null, 'guest@example.com', 300);

            $repo = new CustomerAggregationRepository();

            $result = $repo->paginate($context, self::TENANT, [], 'total_spent', 'desc', 1, 25);
            self::assertSame(2, $result['total']);

            $byKey = [];
            foreach ($result['items'] as $item) {
                $byKey[$item['key']] = $item;
            }

            self::assertSame('user', $byKey['pgagguser001']['key_type']);
            self::assertSame(2, $byKey['pgagguser001']['orders_count']);
            self::assertSame(1500, $byKey['pgagguser001']['total_spent_minor']);
            self::assertSame(100, $byKey['pgagguser001']['refunded_minor']);

            self::assertSame('email', $byKey['guest@example.com']['key_type']);
            self::assertSame(2, $byKey['guest@example.com']['orders_count']);
            self::assertSame(1000, $byKey['guest@example.com']['total_spent_minor']);

            $byUser = $repo->findByUser($context, self::TENANT, 'pgagguser001');
            self::assertNotNull($byUser);
            self::assertSame(2, $byUser['orders_count']);
            self::assertSame(1500, $byUser['total_spent_minor']);

            $byEmail = $repo->findByEmail($context, self::TENANT, 'GUEST@Example.com');
            self::assertNotNull($byEmail);
            self::assertSame(2, $byEmail['orders_count']);
            self::assertSame(1000, $byEmail['total_spent_minor']);
        } finally {
            // Leave the pgsql fixture database as we found it.
            $connection->table('commerce_orders')->where('tenant_uuid', '=', self::TENANT)->delete();
        }
    }

    // --- Helpers -------------------------------------------------------------
    // (pgsql lane setup mirrors Downloads\DownloadGrantExpiryPgsqlTest exactly.)

    private function seedOrder(
        Connection $connection,
        string $uuid,
        ?string $userUuid,
        string $email,
        int $grandTotal,
        int $refundedTotal = 0,
    ): void {
        $connection->table('commerce_orders')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT,
            'order_number' => 'ORD-' . $uuid,
            'status' => 'paid',
            'email' => $email,
            'user_uuid' => $userUuid,
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => $grandTotal,
            'grand_total' => $grandTotal,
            'refunded_total' => $refundedTotal,
        ]);
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
}
