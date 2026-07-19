<?php

declare(strict_types=1);

/**
 * Standalone subprocess for MarketplacePgsqlTest/CheckoutClaimPgsqlTest/
 * SellerSuspensionPgsqlTest's real-pgsql race lanes: runs ONE real
 * marketplace service call in a genuinely separate OS process (and
 * therefore a genuinely separate database connection), so its lock claims
 * really block on PostgreSQL row-lock contention held by the parent test
 * process's own connection A. Mirrors
 * `tests/Integration/Http/fixtures/product_delete_race_child.php`'s shape
 * exactly; a single multiplexed script (rather than one file per action)
 * because every action here shares the identical bootstrap and only differs
 * in which service method it calls and how it reports the outcome.
 *
 * argv: 1=pgConfig JSON, 2=action, 3=args JSON
 * actions: create | activate | assign | close | changeRole | checkout |
 *     suspend | payoutRecord
 * stdout: JSON, shape depends on action (see each branch below)
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Cart\CartRepository;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\DownloadRepository;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Marketplace\FixedSellerRoleAuthority;
use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceActivationException;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceActivationService;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceWorkspaceLock;
use Glueful\Extensions\Commerce\Marketplace\PayoutRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutService;
use Glueful\Extensions\Commerce\Marketplace\SellerAttributionService;
use Glueful\Extensions\Commerce\Marketplace\SellerBalanceService;
use Glueful\Extensions\Commerce\Marketplace\SellerLifecycleEventRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipService;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerService;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Commerce\Shipping\ShippingClassRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
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
$context->mergeConfigDefaults('commerce', ['marketplace' => ['enabled' => true]]);

$tenant = (string) $args['tenant'];

$fixedTenant = static function (string $tenant): CurrentTenantResolver {
    return new class ($tenant) implements CurrentTenantResolver {
        public function __construct(private string $tenant)
        {
        }

        public function tenantUuid(ApplicationContext $context): string
        {
            return $this->tenant;
        }
    };
};

$out = [];

try {
    switch ($action) {
        case 'create':
            $catalog = new CatalogService(
                new ProductRepository(),
                new VariantRepository(),
                $fixedTenant($tenant),
                new StockRepository(),
                new ProductChildrenRepository(),
                new ShippingClassRepository(),
                new MarketplaceMode(),
                new MarketplaceWorkspaceLock(),
                new SellerRepository()
            );
            $product = $catalog->createProduct($context, [
                'slug' => (string) $args['slug'],
                'name' => (string) $args['slug'],
                'type' => 'physical',
                'status' => 'active',
                'variants' => [[
                    'sku' => strtoupper((string) $args['slug']),
                    'option_values' => [],
                    'price' => 1000,
                    'currency' => 'USD',
                ]],
            ], $args['sellerUuid'] ?? null);
            $out = ['ok' => true, 'created' => true, 'sellerUuid' => $product['seller_uuid'], 'exceptionClass' => null];
            break;

        case 'activate':
            $activation = new MarketplaceActivationService(
                new MarketplaceWorkspaceLock(),
                new SellerRepository(),
                new ProductRepository()
            );
            $settings = $activation->activate(
                $context,
                $tenant,
                $args['defaultSellerUuid'] ?? null,
                $args['actor'] ?? null
            );
            $out = ['ok' => true, 'activated' => true, 'status' => $settings['status'], 'exceptionClass' => null];
            break;

        case 'assign':
            $attribution = new SellerAttributionService(
                new MarketplaceWorkspaceLock(),
                new SellerRepository(),
                new ProductRepository()
            );
            $product = $attribution->assign(
                $context,
                $tenant,
                (string) $args['productUuid'],
                (string) $args['targetSellerUuid'],
                $args['actor'] ?? null
            );
            $out = [
                'ok' => true,
                'assigned' => true,
                'sellerUuid' => $product['seller_uuid'],
                'exceptionClass' => null,
            ];
            break;

        case 'close':
            $sellers = new SellerService(
                new SellerRepository(),
                new SellerMembershipRepository(),
                new SellerLifecycleEventRepository()
            );
            $seller = $sellers->close(
                $context,
                $tenant,
                (string) $args['sellerUuid'],
                (string) $args['reason'],
                (string) $args['actor']
            );
            $out = ['ok' => true, 'closed' => true, 'status' => $seller['status'], 'exceptionClass' => null];
            break;

        case 'checkout':
            $cartService = new CartService(
                new CartRepository(),
                new VariantRepository(),
                new ProductRepository(),
                new StockRepository(),
                new DiscountRepository(),
                new PricingEngine(),
                $fixedTenant($tenant)
            );
            $created = $cartService->create($context);
            $cartService->addLine($context, $created['cart'], (string) $args['variantUuid'], 1);

            $checkout = new CheckoutService(
                $cartService,
                new DiscountRepository(),
                new DiscountService(new DiscountRepository(), $fixedTenant($tenant)),
                new StockRepository(),
                new PricingEngine(),
                new class implements ShippingRateProvider {
                    public function quote(ApplicationContext $context, array $lines, array $shippingAddress): array
                    {
                        return [new ShippingQuote('std', 'Standard', 0)];
                    }
                },
                new class implements TaxCalculator {
                    public function quote(
                        ApplicationContext $context,
                        int $grandTotal,
                        array $shippingAddress
                    ): TaxQuote {
                        return new TaxQuote(0);
                    }
                },
                new OrderNumberGenerator(),
                new OrderRepository(),
                new DownloadRepository(),
                new ManualPaymentCollector(),
                $fixedTenant($tenant),
                new MarketplaceMode(),
                new SellerRepository(),
                new ProductRepository(),
                new SellerOrderRepository()
            );

            $placed = $checkout->placeOrder(
                $context,
                $created['token'],
                ['email' => 'racebuyer@example.com', 'user_uuid' => null],
                ['shipping' => ['country' => 'US'], 'billing' => ['country' => 'US']],
                null
            );
            $orderUuid = (string) $placed['order']['uuid'];
            $line = $connection->table('commerce_order_lines')->where('order_uuid', '=', $orderUuid)->first();
            $out = [
                'ok' => true,
                'orderUuid' => $orderUuid,
                'partitioned' => (bool) $placed['order']['marketplace_partitioned'],
                'sellerUuid' => $line['seller_uuid'] ?? null,
                'exceptionClass' => null,
            ];
            break;

        // MV5b Task 7 (design spec §2.1/§2.4/§2.7): the REAL SellerService::suspend()
        // -- claims the seller revision FIRST, the SAME primitive a concurrent
        // checkout/payout-reservation claims for the identical seller row.
        case 'suspend':
            $sellers = new SellerService(
                new SellerRepository(),
                new SellerMembershipRepository(),
                new SellerLifecycleEventRepository()
            );
            $seller = $sellers->suspend(
                $context,
                $tenant,
                (string) $args['sellerUuid'],
                (string) $args['reason'],
                (string) $args['actor']
            );
            $out = ['ok' => true, 'suspended' => true, 'status' => $seller['status'], 'exceptionClass' => null];
            break;

        // MV5b Task 7 (design spec §2.7): the REAL PayoutService::record() manual
        // operator payout -- claims the seller revision FIRST, strictly before the
        // account lock, the SAME primitive a concurrent suspend() claims.
        case 'payoutRecord':
            $ledger = new LedgerRepository();
            $payoutService = new PayoutService(
                new PayoutRepository(),
                $ledger,
                new LedgerAccountLock(),
                new SellerBalanceService($ledger),
                new SellerRepository()
            );
            $payout = $payoutService->record(
                $context,
                $tenant,
                (string) $args['sellerUuid'],
                (string) $args['currency'],
                (int) $args['amount'],
                (string) $args['idempotencyKey'],
                (string) $args['externalRef'],
                null,
                (string) $args['actorUuid']
            );
            $out = [
                'ok' => true,
                'exceptionClass' => null,
                'payoutUuid' => $payout['uuid'],
                'amount' => (int) $payout['amount'],
            ];
            break;

        case 'changeRole':
            $memberships = new SellerMembershipService(
                new SellerRepository(),
                new SellerMembershipRepository(),
                new FixedSellerRoleAuthority()
            );
            $membership = $memberships->changeRole(
                $context,
                $tenant,
                (string) $args['sellerUuid'],
                (string) $args['userUuid'],
                (string) $args['role'],
                $args['actor'] ?? null
            );
            $out = ['ok' => true, 'changed' => true, 'role' => $membership['role'], 'exceptionClass' => null];
            break;

        default:
            throw new \RuntimeException("Unknown action: {$action}");
    }
} catch (MarketplaceActivationException $e) {
    $out = [
        'ok' => false,
        'exceptionClass' => $e::class,
        'unassignedCount' => $e->unassignedCount,
    ];
} catch (\Throwable $e) {
    $out = ['ok' => false, 'exceptionClass' => $e::class, 'message' => $e->getMessage()];
}

echo json_encode($out, JSON_THROW_ON_ERROR);
