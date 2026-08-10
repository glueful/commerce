<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

abstract class CommerceTestCase extends TestCase
{
    protected ApplicationContext $context;
    protected Connection $connection;

    /** @var array<string,mixed> extra container bindings a test may inject */
    protected array $bindings = [];

    /** @var list<class-string> migration classes to run */
    protected const MIGRATIONS = [
        \Glueful\Extensions\Commerce\Database\Migrations\CreateCommerceCatalogTables::class,
        \Glueful\Extensions\Commerce\Database\Migrations\CreateCommerceInventoryTables::class,
        \Glueful\Extensions\Commerce\Database\Migrations\CreateCommerceCartTables::class,
        \Glueful\Extensions\Commerce\Database\Migrations\CreateCommerceOrderTables::class,
        \Glueful\Extensions\Commerce\Database\Migrations\CreateCommerceDiscountTables::class,
        \Glueful\Extensions\Commerce\Database\Migrations\CreateCommerceRefundTables::class,
        \Glueful\Extensions\Commerce\Database\Migrations\CreateCommerceCatalogBreadthTables::class,
        \Glueful\Extensions\Commerce\Database\Migrations\CreateCommerceCustomerDeliveryTables::class,
        \Glueful\Extensions\Commerce\Database\Migrations\CreateCommerceShippingTaxTables::class,
        \Glueful\Extensions\Commerce\Database\Migrations\CreateMarketplaceSellerTables::class,
        \Glueful\Extensions\Commerce\Database\Migrations\CreateSellerOrderTables::class,
        \Glueful\Extensions\Commerce\Database\Migrations\CreateMarketplaceLedgerTables::class,
        \Glueful\Extensions\Commerce\Database\Migrations\CreatePayoutTable::class,
        \Glueful\Extensions\Commerce\Database\Migrations\CreateSellerPayoutAccountsTable::class,
        \Glueful\Extensions\Commerce\Database\Migrations\CreateSellerReservesTable::class,
        \Glueful\Extensions\Commerce\Database\Migrations\CreateChargebacksTable::class,
        \Glueful\Extensions\Commerce\Database\Migrations\CreateSellerLifecycleEventsTable::class,
        \Glueful\Extensions\Commerce\Database\Migrations\CreateSellerApiKeysTables::class,
        \Glueful\Extensions\Commerce\Database\Migrations\CreateSellerWebhookTables::class,
        \Glueful\Extensions\Commerce\Database\Migrations\CreateWishlistTables::class,
        \Glueful\Extensions\Commerce\Database\Migrations\EnforceStockQuantityTrackedNotNull::class,
        \Glueful\Extensions\Commerce\Database\Migrations\AddWalkInOrderFieldsAndDraftAttemptLedger::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => $this->sqlitePath()],
            'pooling' => ['enabled' => false],
        ]);

        $schema = $this->connection->getSchemaBuilder();
        foreach (static::MIGRATIONS as $migration) {
            (new $migration())->up($schema);
        }

        $connection = $this->connection;
        $bindings = &$this->bindings;

        $container = new class ($connection, $bindings) implements ContainerInterface {
            /** @param array<string,mixed> $bindings */
            public function __construct(
                private Connection $connection,
                private array &$bindings,
            ) {
            }

            public function get(string $id): mixed
            {
                if ($id === 'database' || $id === Connection::class) {
                    return $this->connection;
                }

                if (array_key_exists($id, $this->bindings)) {
                    return $this->bindings[$id];
                }

                throw new \RuntimeException("Unknown service: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === 'database'
                    || $id === Connection::class
                    || array_key_exists($id, $this->bindings);
            }
        };

        $this->context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
        $this->context->setContainer($container);
        $this->context->mergeConfigDefaults('commerce', require __DIR__ . '/../../config/commerce.php');
    }

    /**
     * SQLite target for the per-test connection. `:memory:` (the default) is
     * private per-connection -- fine for every ordinary test, useless for a
     * test that needs a SECOND, independent {@see Connection} to observe the
     * SAME data (e.g. a transaction-visibility proof). Override to return a
     * real file path in that case.
     */
    protected function sqlitePath(): string
    {
        return ':memory:';
    }

    protected function appContext(): ApplicationContext
    {
        return $this->context;
    }

    protected function connection(): Connection
    {
        return $this->connection;
    }

    protected function bind(string $id, mixed $service): void
    {
        $this->bindings[$id] = $service;
    }

    protected function contextContainer(): ContainerInterface
    {
        return $this->context->getContainer();
    }
}
