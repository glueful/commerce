<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration;

use Glueful\Container\Definition\FactoryDefinition;
use Glueful\Container\Loader\DefaultServicesLoader;
use Glueful\Extensions\Commerce\Cart\CartPruner;
use Glueful\Extensions\Commerce\Cart\CartRepository;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\CommerceServiceProvider;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Database\Migrations\CreateCommerceCartTables;
use Glueful\Extensions\Commerce\Database\Migrations\CreateCommerceCatalogTables;
use Glueful\Extensions\Commerce\Database\Migrations\CreateCommerceDiscountTables;
use Glueful\Extensions\Commerce\Database\Migrations\CreateCommerceInventoryTables;
use Glueful\Extensions\Commerce\Database\Migrations\CreateCommerceOrderTables;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Http\Admin\AdminDiscountController;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Http\Admin\AdminProductController;
use Glueful\Extensions\Commerce\Http\Admin\AdminStockController;
use Glueful\Extensions\Commerce\Http\Storefront\CartController;
use Glueful\Extensions\Commerce\Http\Storefront\CheckoutController;
use Glueful\Extensions\Commerce\Http\Storefront\OrderController;
use Glueful\Extensions\Commerce\Http\Storefront\ProductController;
use Glueful\Extensions\Commerce\Inventory\InventoryService;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\ExpiryService;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Payments\OrderPaymentConfirmationHandler;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Shipping\ConfigShippingRateProvider;
use Glueful\Extensions\Commerce\Tax\FlatRateTaxCalculator;
use Glueful\Extensions\Commerce\Tenancy\TenantAdopter;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Payments\PaymentCollector;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantTableRegistry;

final class ServiceProviderWiringTest extends CommerceTestCase
{
    public function testServicesExposeEveryPublicCommerceDefinition(): void
    {
        $services = CommerceServiceProvider::services();

        foreach ($this->expectedServiceIds() as $id) {
            self::assertIsArray($services[$id] ?? null, "Missing service definition: {$id}");
            self::assertTrue($services[$id]['shared'], "Service must be shared: {$id}");
        }

        foreach ([
            CatalogService::class,
            InventoryService::class,
            DiscountService::class,
            CartService::class,
            CheckoutService::class,
            ExpiryService::class,
            OrderPaymentConfirmationHandler::class,
            ProductController::class,
            CartController::class,
            CheckoutController::class,
            OrderController::class,
            AdminProductController::class,
            AdminStockController::class,
            AdminDiscountController::class,
            AdminOrderController::class,
        ] as $id) {
            self::assertArrayHasKey('factory', $services[$id], "Missing factory: {$id}");
        }

        self::assertSame(FlatRateTaxCalculator::class, $services[TaxCalculator::class]['class']);
        self::assertSame(
            ConfigShippingRateProvider::class,
            $services[ShippingRateProvider::class]['class']
        );
    }

    public function testCommerceDoesNotBindSharedExtensionContracts(): void
    {
        $services = CommerceServiceProvider::services();

        self::assertArrayNotHasKey(PaymentCollector::class, $services);
        self::assertArrayNotHasKey(CurrentTenantResolver::class, $services);
        self::assertArrayNotHasKey(TenantTableRegistry::class, $services);
    }

    public function testServicesLoadThroughRealDefaultServicesLoaderInProductionMode(): void
    {
        $definitions = (new DefaultServicesLoader())->load(
            CommerceServiceProvider::services(),
            CommerceServiceProvider::class,
            prod: true
        );

        foreach ($this->expectedServiceIds() as $id) {
            self::assertArrayHasKey($id, $definitions);
        }

        self::assertInstanceOf(FactoryDefinition::class, $definitions[CatalogService::class]);
        self::assertInstanceOf(FactoryDefinition::class, $definitions[CheckoutService::class]);
    }

    public function testMigrationClassesAreInstantiable(): void
    {
        foreach ([
            CreateCommerceCatalogTables::class,
            CreateCommerceInventoryTables::class,
            CreateCommerceCartTables::class,
            CreateCommerceOrderTables::class,
            CreateCommerceDiscountTables::class,
        ] as $migration) {
            self::assertInstanceOf($migration, new $migration());
        }
    }

    public function testProviderMetadataComesFromComposerExtra(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);
        self::assertSame(
            $composer['extra']['glueful']['version'],
            CommerceServiceProvider::composerVersion()
        );

        $provider = new CommerceServiceProvider($this->contextContainer());
        self::assertSame('Commerce', $provider->getName());
        self::assertSame(CommerceServiceProvider::composerVersion(), $provider->getVersion());
    }

    /** @return list<class-string> */
    private function expectedServiceIds(): array
    {
        return [
            ProductRepository::class,
            VariantRepository::class,
            CatalogService::class,
            StockRepository::class,
            InventoryService::class,
            DiscountRepository::class,
            DiscountService::class,
            PricingEngine::class,
            CartRepository::class,
            CartService::class,
            CartPruner::class,
            TaxCalculator::class,
            ShippingRateProvider::class,
            OrderNumberGenerator::class,
            OrderRepository::class,
            CheckoutService::class,
            OrderPaymentService::class,
            TenantAdopter::class,
            ExpiryService::class,
            OrderPaymentConfirmationHandler::class,
            ProductController::class,
            CartController::class,
            CheckoutController::class,
            OrderController::class,
            AdminProductController::class,
            AdminStockController::class,
            AdminDiscountController::class,
            AdminOrderController::class,
        ];
    }
}
