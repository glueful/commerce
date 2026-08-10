<?php

declare(strict_types=1);

/**
 * Standalone subprocess for DraftFinalizationTest's real-pgsql race lane
 * (admin-order-creation cycle 2, Task 10; design spec §2.5.6 / Ruling 8): runs a
 * COMPLETE `DraftFinalizationService::finalize()` against a genuinely separate OS
 * process, database connection, and session, then HOLDS its transaction open so
 * the parent's own concurrent finalize really contends with it on
 * `commerce_sequences`' unique index rather than merely running afterwards.
 *
 * This is the two-concurrent-finalizers precondition test for the transactional
 * numbering claim: both sides are the tenant's FIRST order, so both attempt the
 * sequence INSERT, one wins, and the loser must survive the unique violation
 * (savepoint-isolated, Task 4) with its ambient transaction still usable and
 * finish with a DISTINCT number.
 *
 * Mirrors `Orders/fixtures/draft_finalize_race_child.php`'s shape exactly.
 *
 * argv: 1=pgConfig JSON, 2=action, 3=args JSON
 *
 * actions:
 *  - hold_finalize: begins a transaction, finalizes args.orderUuid for args.tenant
 *    with args.idempotencyKey/args.expectedRevision, sleeps for `args.sleepMs`
 *    milliseconds (holding the uncommitted sequence row and order lock), then
 *    commits. stdout: {"ok":true,"orderNumber":"ORD-000001"} or
 *    {"ok":false,"exceptionClass":"...","message":"..."}.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Catalog\AddonRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Orders\DraftAttemptRepository;
use Glueful\Extensions\Commerce\Orders\DraftFinalizationService;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\PurchasableLineResolver;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Shipping\ConfigShippingRateProvider;
use Glueful\Extensions\Commerce\Shipping\ShippingClassRepository;
use Glueful\Extensions\Commerce\Tax\FlatRateTaxCalculator;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Psr\Container\ContainerInterface;

[, $pgConfigJson, $action, $argsJson] = $argv;
/** @var array<string,mixed> $pgConfig */
$pgConfig = json_decode($pgConfigJson, true, 512, JSON_THROW_ON_ERROR);
/** @var array<string,mixed> $args */
$args = json_decode($argsJson, true, 512, JSON_THROW_ON_ERROR);

$connection = new Connection($pgConfig);
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
$context->mergeConfigDefaults('commerce', require __DIR__ . '/../../../../config/commerce.php');

$tenants = new class ((string) $args['tenant']) implements CurrentTenantResolver {
    public function __construct(private string $tenant)
    {
    }

    public function tenantUuid(ApplicationContext $context): string
    {
        return $this->tenant;
    }
};
$discounts = new DiscountRepository();
$finalization = new DraftFinalizationService(
    new OrderRepository(),
    new DraftAttemptRepository(),
    new PurchasableLineResolver(
        new VariantRepository(),
        new ProductRepository(),
        new AddonRepository(),
        new ShippingClassRepository()
    ),
    new PricingEngine(),
    new ConfigShippingRateProvider(),
    new FlatRateTaxCalculator(),
    $discounts,
    new DiscountService($discounts, $tenants),
    new StockRepository(),
    new OrderNumberGenerator(),
    $tenants,
    new MarketplaceMode()
);

$out = [];

try {
    switch ($action) {
        case 'hold_finalize':
            $connection->getTransactionManager()->begin();
            try {
                $result = $finalization->finalize(
                    $context,
                    (string) $args['orderUuid'],
                    (string) $args['idempotencyKey'],
                    (int) $args['expectedRevision']
                );
                usleep((int) $args['sleepMs'] * 1000);
                $connection->getTransactionManager()->commit();
                $out = ['ok' => true, 'orderNumber' => (string) $result['order']['order_number']];
            } catch (\Throwable $e) {
                // The transaction may already be unusable here (exactly the class
                // of bug this fixture exists to detect); attempt a rollback for
                // hygiene but never let a failure there mask the real exception.
                try {
                    $connection->getTransactionManager()->rollback();
                } catch (\Throwable) {
                    // deliberately ignored
                }
                throw $e;
            }
            break;

        default:
            throw new \RuntimeException("Unknown action: {$action}");
    }
} catch (\Throwable $e) {
    $out = ['ok' => false, 'exceptionClass' => $e::class, 'message' => $e->getMessage()];
}

echo json_encode($out, JSON_THROW_ON_ERROR);
