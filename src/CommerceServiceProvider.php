<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Cart\CartRepository;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
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
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Payments\OrderPaymentConfirmationHandler;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Shipping\ConfigShippingRateProvider;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tax\FlatRateTaxCalculator;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Payments\PaymentCollector;
use Glueful\Extensions\Contracts\Payments\PaymentConfirmationHandler;
use Glueful\Extensions\ServiceProvider;
use Psr\Container\ContainerInterface;

final class CommerceServiceProvider extends ServiceProvider
{
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
        ];
    }

    public static function makeCatalogService(ContainerInterface $container): CatalogService
    {
        $tenantResolver = $container->has(CurrentTenantResolver::class)
            ? $container->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();

        if (!$tenantResolver instanceof CurrentTenantResolver) {
            throw new \RuntimeException('Configured tenant resolver does not implement CurrentTenantResolver.');
        }

        return new CatalogService(
            $container->get(ProductRepository::class),
            $container->get(VariantRepository::class),
            $tenantResolver,
            $container->get(StockRepository::class)
        );
    }

    public static function makeInventoryService(ContainerInterface $container): InventoryService
    {
        $tenantResolver = $container->has(CurrentTenantResolver::class)
            ? $container->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();

        if (!$tenantResolver instanceof CurrentTenantResolver) {
            throw new \RuntimeException('Configured tenant resolver does not implement CurrentTenantResolver.');
        }

        return new InventoryService(
            $container->get(StockRepository::class),
            $tenantResolver
        );
    }

    public static function makeDiscountService(ContainerInterface $container): DiscountService
    {
        $tenantResolver = $container->has(CurrentTenantResolver::class)
            ? $container->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();

        if (!$tenantResolver instanceof CurrentTenantResolver) {
            throw new \RuntimeException('Configured tenant resolver does not implement CurrentTenantResolver.');
        }

        return new DiscountService(
            $container->get(DiscountRepository::class),
            $tenantResolver
        );
    }

    public static function makeCartService(ContainerInterface $container): CartService
    {
        $tenantResolver = $container->has(CurrentTenantResolver::class)
            ? $container->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();

        if (!$tenantResolver instanceof CurrentTenantResolver) {
            throw new \RuntimeException('Configured tenant resolver does not implement CurrentTenantResolver.');
        }

        return new CartService(
            $container->get(CartRepository::class),
            $container->get(VariantRepository::class),
            $container->get(ProductRepository::class),
            $container->get(StockRepository::class),
            $container->get(DiscountRepository::class),
            $container->get(PricingEngine::class),
            $tenantResolver
        );
    }

    public static function makeCheckoutService(ContainerInterface $container): CheckoutService
    {
        $tenantResolver = $container->has(CurrentTenantResolver::class)
            ? $container->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
        if (!$tenantResolver instanceof CurrentTenantResolver) {
            throw new \RuntimeException('Configured tenant resolver does not implement CurrentTenantResolver.');
        }

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
            $tenantResolver
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

    public static function makeExpiryService(ContainerInterface $container): ExpiryService
    {
        $tenantResolver = $container->has(CurrentTenantResolver::class)
            ? $container->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
        if (!$tenantResolver instanceof CurrentTenantResolver) {
            throw new \RuntimeException('Configured tenant resolver does not implement CurrentTenantResolver.');
        }

        return new ExpiryService(
            $container->get(OrderRepository::class),
            $container->get(StockRepository::class),
            $tenantResolver
        );
    }

    public static function makeOrderPaymentConfirmationHandler(
        ContainerInterface $container
    ): OrderPaymentConfirmationHandler {
        $tenantResolver = $container->has(CurrentTenantResolver::class)
            ? $container->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
        if (!$tenantResolver instanceof CurrentTenantResolver) {
            throw new \RuntimeException('Configured tenant resolver does not implement CurrentTenantResolver.');
        }

        return new OrderPaymentConfirmationHandler(
            $container->get(OrderRepository::class),
            $container->get(OrderPaymentService::class),
            $tenantResolver
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
            self::tenantResolver($container)
        );
    }

    public function getDescription(): string
    {
        return 'Commerce primitives: products, carts, orders, inventory, discounts, checkout, payments.';
    }

    public function register(ApplicationContext $context): void
    {
        $this->mergeConfig('commerce', require __DIR__ . '/../config/commerce.php');
    }

    public function boot(ApplicationContext $context): void
    {
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
    }

    private function bootEnv(): string
    {
        return (string) ($_ENV['APP_ENV'] ?? (getenv('APP_ENV') !== false ? getenv('APP_ENV') : 'production'));
    }

    private static function tenantResolver(ContainerInterface $container): CurrentTenantResolver
    {
        $tenantResolver = $container->has(CurrentTenantResolver::class)
            ? $container->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
        if (!$tenantResolver instanceof CurrentTenantResolver) {
            throw new \RuntimeException('Configured tenant resolver does not implement CurrentTenantResolver.');
        }

        return $tenantResolver;
    }
}
