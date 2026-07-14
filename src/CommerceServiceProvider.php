<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Cart\CartPruner;
use Glueful\Extensions\Commerce\Cart\CartRepository;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Http\Admin\AdminDiscountController;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Http\Admin\AdminProductController;
use Glueful\Extensions\Commerce\Http\Admin\AdminRefundController;
use Glueful\Extensions\Commerce\Http\Admin\AdminStockController;
use Glueful\Extensions\Commerce\Http\Storefront\CartController;
use Glueful\Extensions\Commerce\Http\Storefront\CheckoutController;
use Glueful\Extensions\Commerce\Http\Storefront\OrderController;
use Glueful\Extensions\Commerce\Http\Storefront\ProductController;
use Glueful\Extensions\Commerce\Inventory\InventoryService;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\ExpiryService;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundService;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Payments\OrderPaymentConfirmationHandler;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Shipping\ConfigShippingRateProvider;
use Glueful\Extensions\Commerce\Tenancy\FailClosedTenantResolver;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tenancy\TenantAdopter;
use Glueful\Extensions\Commerce\Tax\FlatRateTaxCalculator;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantTableRegistry;
use Glueful\Extensions\Contracts\Payments\PaymentCollector;
use Glueful\Extensions\Contracts\Payments\PaymentConfirmationHandler;
use Glueful\Extensions\Contracts\Payments\RefundCollector;
use Glueful\Extensions\ServiceProvider;
use Psr\Container\ContainerInterface;

final class CommerceServiceProvider extends ServiceProvider
{
    private static ?string $cachedVersion = null;

    public static function composerVersion(): string
    {
        if (self::$cachedVersion === null) {
            $composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);
            self::$cachedVersion = (string) ($composer['extra']['glueful']['version'] ?? '0.0.0');
        }

        return self::$cachedVersion;
    }

    /**
     * Commerce binds nothing under shared contract ids. Factories that need the
     * tenant resolver or payment collector resolve the shared contract if bound,
     * otherwise construct an inline fallback.
     *
     * @return array<string, mixed>
     */
    public static function services(): array
    {
        return [
            ProductRepository::class => [
                'class' => ProductRepository::class,
                'shared' => true,
            ],
            VariantRepository::class => [
                'class' => VariantRepository::class,
                'shared' => true,
            ],
            CatalogService::class => [
                'factory' => [self::class, 'makeCatalogService'],
                'shared' => true,
            ],
            StockRepository::class => [
                'class' => StockRepository::class,
                'shared' => true,
            ],
            InventoryService::class => [
                'factory' => [self::class, 'makeInventoryService'],
                'shared' => true,
            ],
            DiscountRepository::class => [
                'class' => DiscountRepository::class,
                'shared' => true,
            ],
            DiscountService::class => [
                'factory' => [self::class, 'makeDiscountService'],
                'shared' => true,
            ],
            PricingEngine::class => [
                'class' => PricingEngine::class,
                'shared' => true,
            ],
            CartRepository::class => [
                'class' => CartRepository::class,
                'shared' => true,
            ],
            CartService::class => [
                'factory' => [self::class, 'makeCartService'],
                'shared' => true,
            ],
            CartPruner::class => [
                'class' => CartPruner::class,
                'shared' => true,
            ],
            TaxCalculator::class => [
                'class' => FlatRateTaxCalculator::class,
                'shared' => true,
            ],
            ShippingRateProvider::class => [
                'class' => ConfigShippingRateProvider::class,
                'shared' => true,
            ],
            OrderNumberGenerator::class => [
                'class' => OrderNumberGenerator::class,
                'shared' => true,
            ],
            OrderRepository::class => [
                'class' => OrderRepository::class,
                'shared' => true,
            ],
            CheckoutService::class => [
                'factory' => [self::class, 'makeCheckoutService'],
                'shared' => true,
            ],
            OrderPaymentService::class => [
                'class' => OrderPaymentService::class,
                'shared' => true,
                'autowire' => true,
            ],
            RefundRepository::class => [
                'class' => RefundRepository::class,
                'shared' => true,
            ],
            RefundService::class => [
                'factory' => [self::class, 'makeRefundService'],
                'shared' => true,
            ],
            TenantAdopter::class => [
                'class' => TenantAdopter::class,
                'shared' => true,
            ],
            ExpiryService::class => [
                'factory' => [self::class, 'makeExpiryService'],
                'shared' => true,
            ],
            OrderPaymentConfirmationHandler::class => [
                'factory' => [self::class, 'makeOrderPaymentConfirmationHandler'],
                'shared' => true,
                'tags' => [PaymentConfirmationHandler::CONTAINER_TAG],
            ],
            ProductController::class => [
                'factory' => [self::class, 'makeProductController'],
                'shared' => true,
            ],
            CartController::class => [
                'factory' => [self::class, 'makeCartController'],
                'shared' => true,
            ],
            CheckoutController::class => [
                'factory' => [self::class, 'makeCheckoutController'],
                'shared' => true,
            ],
            OrderController::class => [
                'factory' => [self::class, 'makeOrderController'],
                'shared' => true,
            ],
            AdminProductController::class => [
                'factory' => [self::class, 'makeAdminProductController'],
                'shared' => true,
            ],
            AdminStockController::class => [
                'factory' => [self::class, 'makeAdminStockController'],
                'shared' => true,
            ],
            AdminDiscountController::class => [
                'factory' => [self::class, 'makeAdminDiscountController'],
                'shared' => true,
            ],
            AdminOrderController::class => [
                'factory' => [self::class, 'makeAdminOrderController'],
                'shared' => true,
            ],
            AdminRefundController::class => [
                'factory' => [self::class, 'makeAdminRefundController'],
                'shared' => true,
            ],
        ];
    }

    public static function makeCatalogService(ContainerInterface $container): CatalogService
    {
        return new CatalogService(
            $container->get(ProductRepository::class),
            $container->get(VariantRepository::class),
            self::tenantResolver($container),
            $container->get(StockRepository::class)
        );
    }

    public static function makeInventoryService(ContainerInterface $container): InventoryService
    {
        return new InventoryService(
            $container->get(StockRepository::class),
            self::tenantResolver($container)
        );
    }

    public static function makeDiscountService(ContainerInterface $container): DiscountService
    {
        return new DiscountService(
            $container->get(DiscountRepository::class),
            self::tenantResolver($container)
        );
    }

    public static function makeCartService(ContainerInterface $container): CartService
    {
        return new CartService(
            $container->get(CartRepository::class),
            $container->get(VariantRepository::class),
            $container->get(ProductRepository::class),
            $container->get(StockRepository::class),
            $container->get(DiscountRepository::class),
            $container->get(PricingEngine::class),
            self::tenantResolver($container)
        );
    }

    public static function makeCheckoutService(ContainerInterface $container): CheckoutService
    {
        return new CheckoutService(
            $container->get(CartService::class),
            $container->get(DiscountRepository::class),
            $container->get(DiscountService::class),
            $container->get(StockRepository::class),
            $container->get(PricingEngine::class),
            $container->get(ShippingRateProvider::class),
            $container->get(TaxCalculator::class),
            $container->get(OrderNumberGenerator::class),
            $container->get(OrderRepository::class),
            self::makePaymentCollector($container),
            self::tenantResolver($container)
        );
    }

    public static function makePaymentCollector(ContainerInterface $container): PaymentCollector
    {
        $collector = $container->has(PaymentCollector::class)
            ? $container->get(PaymentCollector::class)
            : new ManualPaymentCollector();

        if (!$collector instanceof PaymentCollector) {
            throw new \RuntimeException('Configured payment collector does not implement PaymentCollector.');
        }

        return $collector;
    }

    public static function makeTenantResolver(
        ContainerInterface $container,
        ApplicationContext $context
    ): CurrentTenantResolver {
        if (!(bool) config($context, 'commerce.tenancy.enabled', false)) {
            return new SentinelTenantResolver();
        }

        if (!$container->has(CurrentTenantResolver::class)) {
            throw new \RuntimeException(
                'commerce.tenancy.enabled requires a bound CurrentTenantResolver (install glueful/tenancy).'
            );
        }

        $tenantResolver = $container->get(CurrentTenantResolver::class);
        if (!$tenantResolver instanceof CurrentTenantResolver) {
            throw new \RuntimeException('Configured tenant resolver does not implement CurrentTenantResolver.');
        }

        return new FailClosedTenantResolver($tenantResolver);
    }

    public static function makeRefundService(ContainerInterface $container): RefundService
    {
        return new RefundService(
            $container->get(OrderRepository::class),
            $container->get(RefundRepository::class),
            $container->get(StockRepository::class),
            self::tenantResolver($container),
            self::makeRefundCollector($container)
        );
    }

    private static function makeRefundCollector(ContainerInterface $container): ?RefundCollector
    {
        if (!$container->has(RefundCollector::class)) {
            return null;
        }

        $collector = $container->get(RefundCollector::class);
        if (!$collector instanceof RefundCollector) {
            throw new \RuntimeException('Configured refund collector does not implement RefundCollector.');
        }

        return $collector;
    }

    public static function makeExpiryService(ContainerInterface $container): ExpiryService
    {
        return new ExpiryService(
            $container->get(OrderRepository::class),
            $container->get(StockRepository::class),
            self::tenantResolver($container)
        );
    }

    public static function makeOrderPaymentConfirmationHandler(
        ContainerInterface $container
    ): OrderPaymentConfirmationHandler {
        return new OrderPaymentConfirmationHandler(
            $container->get(OrderRepository::class),
            $container->get(OrderPaymentService::class),
            self::tenantResolver($container)
        );
    }

    public static function makeProductController(ContainerInterface $container): ProductController
    {
        return new ProductController(
            $container->get(ApplicationContext::class),
            $container->get(ProductRepository::class),
            $container->get(VariantRepository::class),
            self::tenantResolver($container)
        );
    }

    public static function makeCartController(ContainerInterface $container): CartController
    {
        return new CartController(
            $container->get(ApplicationContext::class),
            $container->get(CartService::class)
        );
    }

    public static function makeCheckoutController(ContainerInterface $container): CheckoutController
    {
        return new CheckoutController(
            $container->get(ApplicationContext::class),
            $container->get(CartService::class),
            $container->get(CheckoutService::class)
        );
    }

    public static function makeOrderController(ContainerInterface $container): OrderController
    {
        return new OrderController(
            $container->get(ApplicationContext::class),
            $container->get(OrderRepository::class),
            $container->get(CheckoutService::class),
            self::tenantResolver($container),
            $container->get(RefundRepository::class)
        );
    }

    public static function makeAdminProductController(ContainerInterface $container): AdminProductController
    {
        return new AdminProductController(
            $container->get(ApplicationContext::class),
            $container->get(CatalogService::class),
            $container->get(ProductRepository::class),
            $container->get(VariantRepository::class),
            self::tenantResolver($container)
        );
    }

    public static function makeAdminStockController(ContainerInterface $container): AdminStockController
    {
        return new AdminStockController(
            $container->get(ApplicationContext::class),
            $container->get(InventoryService::class)
        );
    }

    public static function makeAdminDiscountController(ContainerInterface $container): AdminDiscountController
    {
        return new AdminDiscountController(
            $container->get(ApplicationContext::class),
            $container->get(DiscountRepository::class),
            self::tenantResolver($container)
        );
    }

    public static function makeAdminOrderController(ContainerInterface $container): AdminOrderController
    {
        return new AdminOrderController(
            $container->get(ApplicationContext::class),
            $container->get(OrderRepository::class),
            $container->get(StockRepository::class),
            $container->get(OrderPaymentService::class),
            self::tenantResolver($container)
        );
    }

    public static function makeAdminRefundController(ContainerInterface $container): AdminRefundController
    {
        return new AdminRefundController(
            $container->get(ApplicationContext::class),
            $container->get(OrderRepository::class),
            $container->get(RefundRepository::class),
            $container->get(RefundService::class),
            self::tenantResolver($container)
        );
    }

    public function getDescription(): string
    {
        return 'Commerce primitives: products, carts, orders, inventory, discounts, checkout, payments.';
    }

    public function getName(): string
    {
        return 'Commerce';
    }

    public function getVersion(): string
    {
        return self::composerVersion();
    }

    public function register(ApplicationContext $context): void
    {
        $this->mergeConfig('commerce', require __DIR__ . '/../config/commerce.php');
    }

    public function boot(ApplicationContext $context): void
    {
        try {
            $container = container($context);
            if ($container->has(TenantTableRegistry::class)) {
                $registry = $container->get(TenantTableRegistry::class);
                if ($registry instanceof TenantTableRegistry) {
                    $registry->register(\Glueful\Extensions\Commerce\Support\DiagnosticsReport::tenantTables());
                }
            }
        } catch (\Throwable $e) {
            error_log('[Commerce] Failed to register tenant tables: ' . $e->getMessage());
            if ($this->bootEnv() !== 'production') {
                throw $e;
            }
        }

        try {
            $this->loadRoutesFrom(__DIR__ . '/../routes.php');
        } catch (\Throwable $e) {
            error_log('[Commerce] Failed to load routes: ' . $e->getMessage());
            if ($this->bootEnv() !== 'production') {
                throw $e;
            }
        }

        try {
            $this->loadMigrationsFrom(__DIR__ . '/../migrations', MigrationPriority::DEPENDENT, 'glueful/commerce');
        } catch (\Throwable $e) {
            error_log('[Commerce] Failed to register migrations: ' . $e->getMessage());
            if ($this->bootEnv() !== 'production') {
                throw $e;
            }
        }

        try {
            $this->app->get(\Glueful\Extensions\ExtensionManager::class)->registerMeta(self::class, [
                'slug' => 'commerce',
                'name' => $this->getName(),
                'version' => $this->getVersion(),
                'description' => $this->getDescription(),
            ]);
        } catch (\Throwable $e) {
            error_log('[Commerce] Failed to register extension metadata: ' . $e->getMessage());
        }

        try {
            $this->discoverCommands('Glueful\\Extensions\\Commerce\\Console', __DIR__ . '/Console');
        } catch (\Throwable $e) {
            error_log('[Commerce] Failed to discover commands: ' . $e->getMessage());
            if ($this->bootEnv() !== 'production') {
                throw $e;
            }
        }
    }

    private function bootEnv(): string
    {
        return (string) ($_ENV['APP_ENV'] ?? (getenv('APP_ENV') !== false ? getenv('APP_ENV') : 'production'));
    }

    private static function tenantResolver(ContainerInterface $container): CurrentTenantResolver
    {
        $context = $container->get(ApplicationContext::class);
        if (!$context instanceof ApplicationContext) {
            throw new \RuntimeException('ApplicationContext service is required to resolve commerce tenancy.');
        }

        return self::makeTenantResolver($container, $context);
    }
}
