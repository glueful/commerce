<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Customers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Customers\AddressBookRepository;
use Glueful\Extensions\Commerce\Customers\AddressBookService;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Psr\Container\ContainerInterface;

/**
 * Design spec §9's fourth named race: concurrent first-default address creation.
 * `AddressBookRepository`'s class docblock states the invariant this proves under a
 * genuine cross-connection interleave: "two concurrent first-address (or
 * default-swap) requests against the same account [] serialize on the same parent
 * row [via `claimBook()`], not the counter itself." Follows the same
 * deterministic-claim-plus-pgsql-race split used throughout this suite (e.g.
 * `Catalog\MediaTenancyConcurrencyTest`) -- a deterministic SQLite sibling proves the
 * outcome sequentially first; the pgsql-gated test proves it under a genuine row-lock
 * interleave. `Http\AccountAddressTest::testConcurrentFirstAddressCreationsSerializeOnTheSharedParentRow()`
 * already covers plain sequential creates (neither address requesting a default) --
 * this file is scoped to the DEFAULT-swap race specifically.
 */
final class AddressBookConcurrencyTest extends CommerceTestCase
{
    /**
     * Deterministic replacement for a true two-connection interleave (SQLite
     * `:memory:` cannot run one -- see `GatewayRefundTest`'s docblock for the same
     * adjudication). Two sequential creates for a BRAND NEW account, each requesting
     * `is_default_shipping: true`, simulate two racing "first address, make it my
     * default" requests once the loser's claim unblocks after the winner commits:
     * both land, but only the second insert's `clearDefaultShipping()` demotes the
     * first.
     */
    public function testSequentialFirstDefaultCreatesDemoteDeterministicallyToExactlyOneDefault(): void
    {
        $userUuid = 'useraddrdet1';
        $service = $this->service();

        $first = $service->create($this->context, $userUuid, [
            'address' => ['country' => 'US'],
            'is_default_shipping' => true,
        ]);
        self::assertTrue($first['is_default_shipping']);

        $second = $service->create($this->context, $userUuid, [
            'address' => ['country' => 'CA'],
            'is_default_shipping' => true,
        ]);
        self::assertTrue($second['is_default_shipping']);

        $rows = (new AddressBookRepository())->forUser($this->context, '', $userUuid);
        self::assertCount(2, $rows);
        $defaults = array_values(array_filter(
            $rows,
            static fn (array $row): bool => (bool) $row['is_default_shipping']
        ));
        self::assertCount(1, $defaults, 'Exactly one default must survive the claim protocol.');
        self::assertSame($second['uuid'], $defaults[0]['uuid']);

        $demoted = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['uuid'] === $first['uuid']
        ));
        self::assertFalse((bool) $demoted[0]['is_default_shipping']);
    }

    /**
     * Real cross-connection interleaving: connection A (this test) holds the address
     * book's parent row claimed and uncommitted (as the first step of a "first
     * address, make it default" request) while connection B (a genuinely independent
     * subprocess, fixtures/address_default_race_child.php) runs the real
     * `AddressBookService::create()` -- also requesting `is_default_shipping: true`
     * -- against the SAME brand-new account. B's own claim blocks on A's held
     * PostgreSQL row lock until A commits its own address row; B's claim then
     * succeeds, its `clearDefaultShipping()` demotes A's now-committed default, and
     * B's own row becomes the sole default.
     */
    public function testConcurrentFirstDefaultAddressCreationsOverRealPostgresLeaveExactlyOneDefault(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            $this->markTestSkipped('Requires a PostgreSQL test lane for true row-claim interleaving.');
        }

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);

        $userUuid = 'useraddrpg01';

        // Self-healing: wipe any debris a previously-interrupted run of this same
        // pgsql-gated test left behind before inserting the fixture rows.
        $this->deleteRaceDebris($connectionA, $userUuid);

        try {
            $books = new AddressBookRepository();
            $books->ensureBook($contextA, '', $userUuid);

            // A claims the book's parent row first -- this holds the row lock,
            // uncommitted. The claim primitive (not the full service) is used
            // directly so the test can pause mid-create while B's real create call
            // attempts to claim the same parent row.
            $connectionA->getTransactionManager()->begin();
            self::assertTrue($books->claimBook($contextA, '', $userUuid));

            $process = proc_open(
                [
                    PHP_BINARY,
                    __DIR__ . '/fixtures/address_default_race_child.php',
                    json_encode($pgConfig, JSON_THROW_ON_ERROR),
                    '',
                    $userUuid,
                    'CA',
                ],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            self::assertIsResource($process);

            // Give B time to reach and block on its own claim UPDATE before A proceeds.
            usleep(300_000);

            // A completes its own create directly (it already holds the claim, so no
            // service-level re-claim is needed): no address exists yet, so nothing to
            // demote; insert A's own default-shipping address, then commit --
            // releasing the row lock so B's blocked claim can proceed.
            $books->insert($contextA, [
                'uuid' => 'addrpgracea1',
                'tenant_uuid' => '',
                'user_uuid' => $userUuid,
                'label' => 'A',
                'address' => ['country' => 'US'],
                'is_default_shipping' => true,
                'is_default_billing' => false,
            ]);
            $connectionA->getTransactionManager()->commit();

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            $result = json_decode(trim($stdout), true);
            self::assertIsArray($result, "Connection B's subprocess produced no parseable result. stderr: {$stderr}");
            self::assertNull($result['exceptionClass'], "B's create must succeed (stderr: {$stderr}).");
            self::assertTrue($result['isDefaultShipping'] ?? false);

            $rows = $connectionA->table('commerce_customer_addresses')
                ->where('tenant_uuid', '=', '')
                ->where('user_uuid', '=', $userUuid)
                ->get();
            self::assertCount(2, $rows, 'Both A and B\'s addresses must have landed.');

            $defaults = array_values(array_filter(
                $rows,
                static fn (array $row): bool => (bool) $row['is_default_shipping']
            ));
            self::assertCount(1, $defaults, 'Exactly one default must survive the interleaved claim race.');
            self::assertSame((string) $result['uuid'], (string) $defaults[0]['uuid']);

            $demoted = array_values(array_filter(
                $rows,
                static fn (array $row): bool => $row['uuid'] === 'addrpgracea1'
            ));
            self::assertFalse(
                (bool) $demoted[0]['is_default_shipping'],
                "A's default must be demoted once B's claim commits after it."
            );

            $book = $books->findBook($contextA, '', $userUuid);
            self::assertNotNull($book);
            self::assertSame(2, (int) $book['revision'], 'Both A and B\'s claims must have bumped the revision.');
        } finally {
            $this->deleteRaceDebris($connectionA, $userUuid);
        }
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function service(): AddressBookService
    {
        return new AddressBookService(new AddressBookRepository(), new SentinelTenantResolver());
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

    /**
     * Idempotent pgsql-lane cleanup: addresses then the book row -- neither
     * commerce_customer_addresses nor commerce_customer_address_books carries a
     * deleted_at column, so a plain delete() is enough for both.
     */
    private function deleteRaceDebris(Connection $connection, string $userUuid): void
    {
        $connection->table('commerce_customer_addresses')
            ->where('tenant_uuid', '=', '')
            ->where('user_uuid', '=', $userUuid)
            ->delete();
        $connection->table('commerce_customer_address_books')
            ->where('tenant_uuid', '=', '')
            ->where('user_uuid', '=', $userUuid)
            ->delete();
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
