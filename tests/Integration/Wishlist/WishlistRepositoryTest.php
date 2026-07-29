<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Wishlist;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Commerce\Wishlist\WishlistRepository;
use Psr\Container\ContainerInterface;

final class WishlistRepositoryTest extends CommerceTestCase
{
    private function repository(): WishlistRepository
    {
        return new WishlistRepository();
    }

    public function testEnsureListIsIdempotentAndClaimBumpsTheRevision(): void
    {
        $repo = $this->repository();
        $repo->ensureList($this->context, '', 'user00000001');
        $repo->ensureList($this->context, '', 'user00000001');

        $rows = (int) $this->connection->getPDO()->query(
            "SELECT COUNT(*) FROM commerce_wishlists WHERE user_uuid = 'user00000001'"
        )->fetchColumn();
        self::assertSame(1, $rows);

        self::assertTrue($repo->claimList($this->context, '', 'user00000001'));
        $revision = (int) $this->connection->getPDO()->query(
            "SELECT revision FROM commerce_wishlists WHERE user_uuid = 'user00000001'"
        )->fetchColumn();
        self::assertSame(1, $revision);
    }

    public function testClaimingAListThatDoesNotExistReportsFailure(): void
    {
        // The service must ensureList() first; a silent no-op claim would leave the growth
        // path unserialized while looking successful.
        self::assertFalse($this->repository()->claimList($this->context, '', 'user00000404'));
    }

    public function testItemsReadBackInPositionOrder(): void
    {
        $repo = $this->repository();
        $repo->ensureList($this->context, '', 'user00000001');
        $repo->insertAt($this->context, '', 'user00000001', 'prod00000001', 0);
        $repo->insertAt($this->context, '', 'user00000001', 'prod00000002', -1);
        $repo->insertAt($this->context, '', 'user00000001', 'prod00000003', 1);

        self::assertSame(
            ['prod00000002', 'prod00000001', 'prod00000003'],
            $repo->productUuidsForUser($this->context, '', 'user00000001')
        );
    }

    public function testFrontAndBackPositionsBoundTheList(): void
    {
        $repo = $this->repository();
        $repo->ensureList($this->context, '', 'user00000001');

        self::assertSame(0, $repo->frontPosition($this->context, '', 'user00000001'));
        self::assertSame(0, $repo->backPosition($this->context, '', 'user00000001'));

        $repo->insertAt($this->context, '', 'user00000001', 'prod00000001', 5);
        $repo->insertAt($this->context, '', 'user00000001', 'prod00000002', -3);

        self::assertSame(-3, $repo->frontPosition($this->context, '', 'user00000001'));
        self::assertSame(5, $repo->backPosition($this->context, '', 'user00000001'));
    }

    public function testInsertingAnAlreadySavedProductIsAnIdempotentFalse(): void
    {
        $repo = $this->repository();
        $repo->ensureList($this->context, '', 'user00000001');

        self::assertTrue($repo->insertAt($this->context, '', 'user00000001', 'prod00000001', 0));
        self::assertFalse($repo->insertAt($this->context, '', 'user00000001', 'prod00000001', -1));
        self::assertSame(1, $repo->countForUser($this->context, '', 'user00000001'));
    }

    public function testARealDatabaseFailureIsNotReportedAsAlreadySaved(): void
    {
        // Swallowing every Throwable would turn an outage or schema drift into a cheerful
        // "already on your list" while nothing was written.
        $repo = $this->repository();
        $repo->ensureList($this->context, '', 'user00000001');
        $this->connection->getPDO()->exec('DROP TABLE commerce_wishlist_items');

        $this->expectException(\Throwable::class);
        $repo->insertAt($this->context, '', 'user00000001', 'prod00000001', 0);
    }

    public function testADuplicateInsideAnOpenTransactionLeavesItUsable(): void
    {
        // The PostgreSQL failure mode this savepoint exists for: a unique violation aborts the
        // whole transaction, so both the duplicate re-check AND every later statement would
        // fail with "current transaction is aborted" if the insert were not isolated. SQLite is
        // more forgiving, which is exactly why a pgsql sibling exists too.
        $repo = $this->repository();
        $repo->ensureList($this->context, '', 'user00000001');
        $repo->insertAt($this->context, '', 'user00000001', 'prod00000001', 0);

        $stillWorks = db($this->context)->transaction(function () use ($repo): bool {
            self::assertFalse($repo->insertAt($this->context, '', 'user00000001', 'prod00000001', -1));

            // The outer transaction must still accept writes after the swallowed duplicate.
            return $repo->insertAt($this->context, '', 'user00000001', 'prod00000002', -2);
        });

        self::assertTrue($stillWorks);
        self::assertSame(
            ['prod00000002', 'prod00000001'],
            $repo->productUuidsForUser($this->context, '', 'user00000001')
        );
    }

    public function testRemoveReportsWhetherARowWasDeleted(): void
    {
        $repo = $this->repository();
        $repo->ensureList($this->context, '', 'user00000001');
        $repo->insertAt($this->context, '', 'user00000001', 'prod00000001', 0);

        self::assertTrue($repo->remove($this->context, '', 'user00000001', 'prod00000001'));
        self::assertFalse($repo->remove($this->context, '', 'user00000001', 'prod00000001'));
    }

    /**
     * SQLite tolerates a failed statement inside a transaction; PostgreSQL does not -- it aborts
     * the whole transaction, so without the savepoint the duplicate re-check itself fails with
     * SQLSTATE[25P02] and every later statement in the caller's transaction fails with it too.
     * The savepoint is therefore genuinely untested until it runs against pgsql.
     */
    public function testADuplicateInsideAnOpenTransactionLeavesItUsableOnPostgres(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            $this->markTestSkipped('Requires a PostgreSQL test lane for real transaction-abort semantics.');
        }

        $connection = $this->migratedPgConnection();
        $context = $this->pgsqlContext($connection);
        $repo = new WishlistRepository();

        // Self-healing: wipe debris from a previously-interrupted run of this same test.
        $connection->table('commerce_wishlist_items')
            ->where('tenant_uuid', '=', '')->where('user_uuid', '=', 'userpgdup001')->delete();
        $connection->table('commerce_wishlists')
            ->where('tenant_uuid', '=', '')->where('user_uuid', '=', 'userpgdup001')->delete();

        $repo->ensureList($context, '', 'userpgdup001');
        $repo->insertAt($context, '', 'userpgdup001', 'prodpgdup001', 0);

        $stillWorks = db($context)->transaction(function () use ($repo, $context): bool {
            self::assertFalse($repo->insertAt($context, '', 'userpgdup001', 'prodpgdup001', -1));

            return $repo->insertAt($context, '', 'userpgdup001', 'prodpgdup002', -2);
        });

        self::assertTrue($stillWorks);
        self::assertSame(
            ['prodpgdup002', 'prodpgdup001'],
            $repo->productUuidsForUser($context, '', 'userpgdup001')
        );
    }

    private function migratedPgConnection(): Connection
    {
        $connection = new Connection([
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
        ]);
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

    public function testListsAreScopedToUserAndTenant(): void
    {
        $repo = $this->repository();
        $repo->ensureList($this->context, '', 'user00000001');
        $repo->insertAt($this->context, '', 'user00000001', 'prod00000001', 0);

        self::assertSame([], $repo->productUuidsForUser($this->context, '', 'user00000002'));
        self::assertSame([], $repo->productUuidsForUser($this->context, 'tenantbbbb02', 'user00000001'));
        self::assertFalse($repo->remove($this->context, '', 'user00000002', 'prod00000001'));
    }
}
