<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Definition\FactoryDefinition;
use Glueful\Container\Loader\DefaultServicesLoader;
use Glueful\Extensions\Commerce\Cart\CartPruner;
use Glueful\Extensions\Commerce\Cart\CartRepository;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\ReviewRepository;
use Glueful\Extensions\Commerce\Catalog\ReviewService;
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
use Glueful\Extensions\Commerce\Http\Storefront\CategoryController;
use Glueful\Extensions\Commerce\Http\Storefront\CheckoutController;
use Glueful\Extensions\Commerce\Http\Storefront\OrderController;
use Glueful\Extensions\Commerce\Http\Storefront\ProductController;
use Glueful\Extensions\Commerce\Http\Storefront\ReviewController;
use Glueful\Extensions\Commerce\Http\DTOs\StoreReviewData;
use Glueful\Extensions\Commerce\Inventory\InventoryService;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\ExpiryService;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Payments\OrderPaymentConfirmationHandler;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tenancy\TenantAdopter;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Payments\PaymentCollector;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantTableRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

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
            CategoryController::class,
            ReviewController::class,
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

        // TaxCalculator::class is the DelegatingTaxCalculator(Db, FlatRate)
        // chain built by a factory (design spec §4), not a plain class
        // definition -- see CommerceServiceProvider::makeTaxCalculator().
        self::assertSame(
            [CommerceServiceProvider::class, 'makeTaxCalculator'],
            $services[TaxCalculator::class]['factory']
        );
        // ShippingRateProvider::class is the DelegatingShippingRateProvider(Db,
        // Config) chain built by a factory (design spec §4), not a plain class
        // definition -- see CommerceServiceProvider::makeShippingRateProvider().
        self::assertSame(
            [CommerceServiceProvider::class, 'makeShippingRateProvider'],
            $services[ShippingRateProvider::class]['factory']
        );
    }

    public function testCommerceDoesNotBindSharedExtensionContracts(): void
    {
        $services = CommerceServiceProvider::services();

        self::assertArrayNotHasKey(PaymentCollector::class, $services);
        self::assertArrayNotHasKey(CurrentTenantResolver::class, $services);
        self::assertArrayNotHasKey(TenantTableRegistry::class, $services);
    }

    /**
     * `makeAdminDiscountController` (Layer 6 Task 3) was previously missing the
     * `DiscountService` binding the show/update/delete actions delegate to --
     * this proves the factory-built controller resolves and actually exercises
     * the shared service (not just that a definition array entry exists).
     */
    public function testMakeAdminDiscountControllerFactoryResolvesTheSharedDiscountService(): void
    {
        $this->bind(ApplicationContext::class, $this->context);
        $this->bind(DiscountRepository::class, new DiscountRepository());
        $this->bind(
            DiscountService::class,
            new DiscountService(new DiscountRepository(), new SentinelTenantResolver())
        );

        $controller = CommerceServiceProvider::makeAdminDiscountController($this->contextContainer());

        $this->connection->table('commerce_discounts')->insert([
            'uuid' => 'discwiring01',
            'tenant_uuid' => '',
            'code' => 'WIRING',
            'type' => 'percentage',
            'value' => 500,
        ]);

        $response = $controller->show(Request::create('/x'), 'discwiring01');
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * `Http\Storefront\CategoryController` (Layer 6 Task 5) is a brand-new
     * controller -- this proves its factory actually resolves a working
     * `CategoryRepository` (not just that a definition array entry exists) by
     * exercising the real public tree endpoint end to end.
     */
    public function testMakeCategoryControllerFactoryResolvesTheSharedCategoryRepository(): void
    {
        $this->bind(ApplicationContext::class, $this->context);
        $this->bind(CategoryRepository::class, new CategoryRepository());

        $controller = CommerceServiceProvider::makeCategoryController($this->contextContainer());

        $this->connection->table('commerce_categories')->insert([
            'uuid' => 'catwiring001',
            'tenant_uuid' => '',
            'slug' => 'wiring',
            'name' => 'Wiring',
        ]);

        $response = $controller->index(Request::create('/x'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['wiring'], array_column($this->json($response)['data'], 'slug'));
    }

    /**
     * `Http\Storefront\ReviewController` (Layer 6 Task 6) is a brand-new
     * controller -- this proves its factory actually resolves a working
     * `ReviewService` (not just that a definition array entry exists) by
     * exercising the real public submit endpoint end to end.
     */
    public function testMakeReviewControllerFactoryResolvesTheSharedReviewService(): void
    {
        $this->bind(ApplicationContext::class, $this->context);
        $this->bind(ReviewRepository::class, new ReviewRepository());
        $this->bind(ProductRepository::class, new ProductRepository());
        $this->bind(
            ReviewService::class,
            new ReviewService(new ReviewRepository(), new ProductRepository(), new SentinelTenantResolver())
        );

        $controller = CommerceServiceProvider::makeReviewController($this->contextContainer());

        $this->connection->table('commerce_products')->insert([
            'uuid' => 'prodwiring01',
            'tenant_uuid' => '',
            'slug' => 'wiring-product',
            'name' => 'Wiring Product',
            'type' => 'physical',
            'status' => 'active',
        ]);

        $response = $controller->store(
            new StoreReviewData(rating: 5, body: 'Great.', author_name: 'Jane', author_email: 'jane@example.com'),
            Request::create('/x', 'POST'),
            'wiring-product'
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['status' => 'pending'], $this->json($response)['data']);
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
            \Glueful\Extensions\Commerce\Wishlist\WishlistService::class,
            \Glueful\Extensions\Commerce\Orders\GuestOrderClaimService::class,
            \Glueful\Extensions\Commerce\Http\Storefront\AccountWishlistController::class,
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
            CategoryController::class,
            ReviewController::class,
            CartController::class,
            CheckoutController::class,
            OrderController::class,
            AdminProductController::class,
            AdminStockController::class,
            AdminDiscountController::class,
            AdminOrderController::class,
        ];
    }

    /** @return array<string,mixed> */
    private function json(HttpResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
