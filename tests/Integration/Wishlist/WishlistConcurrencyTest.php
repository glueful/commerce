<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Wishlist;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Commerce\Wishlist\WishlistRepository;
use Glueful\Extensions\Commerce\Wishlist\WishlistService;
use Glueful\Validation\ValidationException;
use Psr\Container\ContainerInterface;

/**
 * The cap and the merge order are claims about a SET, so they hold only if every growth path
 * serializes on the list's parent row. These deterministic siblings prove the outcome
 * sequentially; the pgsql lane proves the parent claim itself blocks a second writer
 * ({@see WishlistRepositoryTest::testADuplicateInsideAnOpenTransactionLeavesItUsableOnPostgres()}
 * covers the other half -- that a losing write leaves the winner's transaction usable).
 */
final class WishlistConcurrencyTest extends CommerceTestCase
{
    private function product(string $uuid): string
    {
        db($this->context)->table('commerce_products')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => '',
            'name' => 'Widget',
            'slug' => strtolower($uuid),
            'status' => 'active',
            'created_at' => '2026-07-01 10:00:00',
        ]);

        return $uuid;
    }

    private function service(): WishlistService
    {
        return new WishlistService(new WishlistRepository(), new ProductRepository(), new SentinelTenantResolver());
    }

    public function testTheLastSlotIsWonByExactlyOneOfTwoSequentialSaves(): void
    {
        $service = $this->service();
        for ($i = 1; $i <= WishlistService::MAX_ITEMS - 1; $i++) {
            $uuid = sprintf('prod%08d', $i);
            $this->product($uuid);
            $service->add($this->context, 'user00000001', $uuid);
        }
        $this->product('prodracea001');
        $this->product('prodraceb002');

        self::assertTrue($service->add($this->context, 'user00000001', 'prodracea001'));

        // The loser is refused, not silently squeezed in past the cap.
        try {
            $service->add($this->context, 'user00000001', 'prodraceb002');
            self::fail('The second save should have been refused at the cap.');
        } catch (ValidationException) {
            // expected
        }

        self::assertSame(
            WishlistService::MAX_ITEMS,
            (new WishlistRepository())->countForUser($this->context, '', 'user00000001')
        );
    }

    public function testAnImportRunAfterASaveStillAppendsBehindIt(): void
    {
        foreach (['prodaccount1', 'proddevice01'] as $uuid) {
            $this->product($uuid);
        }
        $service = $this->service();

        $service->add($this->context, 'user00000001', 'prodaccount1');
        $service->import($this->context, 'user00000001', ['proddevice01']);

        self::assertSame(
            ['prodaccount1', 'proddevice01'],
            array_column($service->list($this->context, 'user00000001'), 'uuid')
        );
    }

    public function testASaveAfterAnImportStillLeadsTheList(): void
    {
        // The mirror of the case above: positions are assigned from both ends, so a later save
        // must still take the front even though the import wrote higher positions after it.
        foreach (['proddevice01', 'prodlatest01'] as $uuid) {
            $this->product($uuid);
        }
        $service = $this->service();

        $service->import($this->context, 'user00000001', ['proddevice01']);
        $service->add($this->context, 'user00000001', 'prodlatest01');

        self::assertSame(
            ['prodlatest01', 'proddevice01'],
            array_column($service->list($this->context, 'user00000001'), 'uuid')
        );
    }

    // ---- real-PostgreSQL races -------------------------------------------------------
    //
    // Connection A holds the parent claim inside an open transaction; connection B is a real
    // subprocess whose own claimList() blocks on A's row lock until A commits. That is the only
    // way to prove the lock does what the cap depends on -- a same-process test cannot block.

    public function testConcurrentSavesForTheLastSlotLeaveExactlyTheCap(): void
    {
        $pg = $this->pgOrSkip();
        [$connection, $context] = $pg;
        $user = 'userwshrace1';
        $this->resetRaceRows($connection, $user);

        // Fill to one below the cap, then two products competing for the final slot.
        for ($i = 1; $i <= WishlistService::MAX_ITEMS - 1; $i++) {
            $this->pgProduct(sprintf('rcap%08d', $i), $connection);
        }
        $this->pgProduct('rcapaaaaaaa1', $connection);
        $this->pgProduct('rcapbbbbbbb2', $connection);

        $repo = new WishlistRepository();
        $repo->ensureList($context, '', $user);
        for ($i = 1; $i <= WishlistService::MAX_ITEMS - 1; $i++) {
            $repo->insertAt($context, '', $user, sprintf('rcap%08d', $i), $i);
        }

        $connection->getTransactionManager()->begin();
        self::assertTrue($repo->claimList($context, '', $user));

        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/wishlist_race_child.php',
                json_encode($this->pgConfig(), JSON_THROW_ON_ERROR), '', $user, 'add', 'rcapbbbbbbb2'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        usleep(300_000);   // let B reach and block on its own claim

        // A takes the last slot and commits, releasing the lock B is waiting on.
        $repo->insertAt($context, '', $user, 'rcapaaaaaaa1', WishlistService::MAX_ITEMS);
        $connection->getTransactionManager()->commit();

        $result = $this->childResult($process, $pipes);

        // B's claim then succeeds, it re-reads the count UNDER that claim, sees the cap, and
        // refuses. Without the parent lock B would have read the pre-A count and inserted.
        self::assertSame(ValidationException::class, $result['exceptionClass'], (string) $result['message']);
        self::assertSame(WishlistService::MAX_ITEMS, $repo->countForUser($context, '', $user));
    }

    public function testAConcurrentImportAppendsBehindASaveAndNeverExceedsTheCap(): void
    {
        $pg = $this->pgOrSkip();
        [$connection, $context] = $pg;
        $user = 'userwshrace2';
        $this->resetRaceRows($connection, $user);

        $this->pgProduct('rimpaaaaaaa1', $connection);
        $this->pgProduct('rimpbbbbbbb2', $connection);

        $repo = new WishlistRepository();
        $repo->ensureList($context, '', $user);

        $connection->getTransactionManager()->begin();
        self::assertTrue($repo->claimList($context, '', $user));

        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/wishlist_race_child.php',
                json_encode($this->pgConfig(), JSON_THROW_ON_ERROR), '', $user, 'import', 'rimpbbbbbbb2'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        usleep(300_000);

        // A saves to the FRONT while holding the claim, then commits.
        $repo->insertAt($context, '', $user, 'rimpaaaaaaa1', $repo->frontPosition($context, '', $user) - 1);
        $connection->getTransactionManager()->commit();

        $result = $this->childResult($process, $pipes);

        self::assertNull($result['exceptionClass'], (string) $result['message']);
        self::assertSame(['rimpbbbbbbb2'], $result['imported']);
        // The import read the list under its own claim, so it appended BEHIND A's save.
        self::assertSame(['rimpaaaaaaa1', 'rimpbbbbbbb2'], $repo->productUuidsForUser($context, '', $user));
    }

    public function testConcurrentIdenticalImportsProduceEachProductExactlyOnce(): void
    {
        $pg = $this->pgOrSkip();
        [$connection, $context] = $pg;
        $user = 'userwshrace3';
        $this->resetRaceRows($connection, $user);

        $this->pgProduct('rdupaaaaaaa1', $connection);

        $repo = new WishlistRepository();
        $repo->ensureList($context, '', $user);

        $connection->getTransactionManager()->begin();
        self::assertTrue($repo->claimList($context, '', $user));

        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/wishlist_race_child.php',
                json_encode($this->pgConfig(), JSON_THROW_ON_ERROR), '', $user, 'import', 'rdupaaaaaaa1'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        usleep(300_000);

        // A imports the SAME product first, then commits.
        $repo->insertAt($context, '', $user, 'rdupaaaaaaa1', 1);
        $connection->getTransactionManager()->commit();

        $result = $this->childResult($process, $pipes);

        // B sees A's row under its own claim and reports nothing imported -- one row, one owner.
        self::assertNull($result['exceptionClass'], (string) $result['message']);
        self::assertSame([], $result['imported']);
        self::assertSame(1, $repo->countForUser($context, '', $user));
    }

    /** @return array{0: Connection, 1: ApplicationContext} */
    private function pgOrSkip(): array
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            $this->markTestSkipped('Requires a PostgreSQL test lane for true row-claim interleaving.');
        }

        $connection = new Connection($this->pgConfig());
        $schema = $connection->getSchemaBuilder();
        foreach (static::MIGRATIONS as $migration) {
            (new $migration())->up($schema);
        }

        return [$connection, $this->pgsqlContext($connection)];
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

    /** Idempotent pgsql-lane cleanup so an interrupted run cannot poison the next one. */
    private function resetRaceRows(Connection $connection, string $user): void
    {
        $connection->table('commerce_wishlist_items')
            ->where('tenant_uuid', '=', '')->where('user_uuid', '=', $user)->delete();
        $connection->table('commerce_wishlists')
            ->where('tenant_uuid', '=', '')->where('user_uuid', '=', $user)->delete();
    }

    private function pgProduct(string $uuid, ?Connection $connection = null): void
    {
        $connection ??= new Connection($this->pgConfig());
        if ($connection->table('commerce_products')->where('uuid', '=', $uuid)->count() > 0) {
            return;
        }
        $connection->table('commerce_products')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => '',
            'name' => 'Widget',
            'slug' => strtolower($uuid),
            'status' => 'active',
        ]);
    }

    /**
     * @param resource $process
     * @param array<int,resource> $pipes
     * @return array<string,mixed>
     */
    private function childResult($process, array $pipes): array
    {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $result = json_decode(trim((string) $stdout), true);
        self::assertIsArray($result, "connection B produced no parseable result. stderr: {$stderr}");

        return $result;
    }
}
