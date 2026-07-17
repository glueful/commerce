<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Cart\CartRepository;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Catalog\AddonRepository;
use Glueful\Extensions\Commerce\Catalog\AttributeRepository;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\DownloadRepository;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\TagRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Http\Admin\AdminProductController;
use Glueful\Extensions\Commerce\Http\Admin\AdminReportController;
use Glueful\Extensions\Commerce\Http\DTOs\AddCartLineData;
use Glueful\Extensions\Commerce\Http\DTOs\CreateProductData;
use Glueful\Extensions\Commerce\Http\DTOs\ProductListQuery;
use Glueful\Extensions\Commerce\Http\DTOs\ProductVariantData;
use Glueful\Extensions\Commerce\Http\DTOs\ReportWindowQuery;
use Glueful\Extensions\Commerce\Http\Storefront\CartController;
use Glueful\Extensions\Commerce\Http\Storefront\ProductController;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Commerce\Reports\SalesReportRepository;
use Glueful\Extensions\Commerce\Support\DiagnosticsReport;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Commerce\Tests\Support\QueryLoggingPdoStatement;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Routing\Router;
use Symfony\Component\HttpFoundation\Request;

/**
 * The marketplace MV1 "optional" proof (design spec §6 "Regression gate";
 * plan Task 5): with the install master switch ABSENT/false -- the default,
 * see `config/commerce.php` -- Commerce must be byte-identical to a pre-MV1
 * install. Four independent proofs, mirroring the spec's own breakdown:
 *
 * 1. Route manifest: `routes.php`'s marketplace/seller groups are gated
 *    behind `if ($marketplaceEnabled)` blocks that are PURELY ADDITIVE (see
 *    `git diff` against the pre-MV1 commit) -- with the flag off, the
 *    compiled manifest must be the exact 122-route pre-MV1 set, byte for
 *    byte, and contain zero `/commerce/admin/marketplace` or
 *    `/commerce/seller` routes.
 * 2. Zero marketplace-table queries on ordinary request paths, instrumented
 *    with the `QueryLoggingPdoStatement` idiom `CatalogOwnershipTest`
 *    already established for admin product create -- extended here to
 *    storefront browse, cart add, checkout quote, admin product create AND
 *    update, and a reports endpoint.
 * 3. Public payload allowlist: `seller_uuid` never appears in a storefront
 *    product projection, even for a product a prior activation (since
 *    switched back off -- design spec §2.1's explicit exception, data stays
 *    coherent) already attributed to a seller. `StorefrontProductProjectionTest`
 *    pins the full allowlist including `seller_uuid` in its internal-fields
 *    list; this file adds one end-to-end confirmation seeded with a real
 *    non-null `seller_uuid`.
 * 4. Maintenance exceptions stay marketplace-aware regardless of the switch:
 *    `DiagnosticsReport` lists all three tables as present. The
 *    `commerce:tenancy:adopt` rekey side of this is already covered by
 *    `Tenancy\TenantAdopterTest::testAdoptRekeysMarketplaceTablesEvenWhenTheMasterSwitchIsOff()`
 *    -- not duplicated here.
 */
final class MarketplaceRegressionTest extends CommerceTestCase
{
    private const TENANT = '';

    // =====================================================================
    // 1. Route manifest: flag off == pre-MV1, byte for byte.
    // =====================================================================

    /**
     * Pinned pre-MV1 manifest (122 routes), captured from `routes.php` at
     * the commit immediately before the marketplace groups were added
     * (`git diff` from that commit confirms every marketplace/seller route
     * addition is purely inside a `if ($marketplaceEnabled)` block -- never
     * a change to a pre-existing route) and cross-checked by running this
     * exact `freshRouter()` walk against `commerce.marketplace.enabled`
     * left at its default. `METHOD path`, sorted.
     *
     * @var list<string>
     */
    private const PRE_MV1_ROUTE_MANIFEST = [
        'DELETE /commerce/account/addresses/{uuid}',
        'DELETE /commerce/admin/addons/{uuid}',
        'DELETE /commerce/admin/attribute-values/{uuid}',
        'DELETE /commerce/admin/attributes/{uuid}',
        'DELETE /commerce/admin/categories/{uuid}',
        'DELETE /commerce/admin/discounts/{uuid}',
        'DELETE /commerce/admin/downloads/{uuid}',
        'DELETE /commerce/admin/grants/{uuid}/refund-access-override',
        'DELETE /commerce/admin/media/{uuid}',
        'DELETE /commerce/admin/products/{uuid}',
        'DELETE /commerce/admin/reviews/{uuid}',
        'DELETE /commerce/admin/shipping/classes/{uuid}',
        'DELETE /commerce/admin/shipping/methods/{uuid}',
        'DELETE /commerce/admin/shipping/zones/{uuid}',
        'DELETE /commerce/admin/tags/{uuid}',
        'DELETE /commerce/admin/tax/rates/{uuid}',
        'DELETE /commerce/cart/discount',
        'DELETE /commerce/cart/lines/{uuid}',
        'GET /commerce/account/addresses',
        'GET /commerce/admin/attributes',
        'GET /commerce/admin/attributes/{uuid}',
        'GET /commerce/admin/categories',
        'GET /commerce/admin/categories/{uuid}',
        'GET /commerce/admin/customers',
        'GET /commerce/admin/customers/{key}',
        'GET /commerce/admin/discounts',
        'GET /commerce/admin/discounts/{uuid}',
        'GET /commerce/admin/orders',
        'GET /commerce/admin/orders/{uuid}',
        'GET /commerce/admin/orders/{uuid}/invoice-data',
        'GET /commerce/admin/orders/{uuid}/notes',
        'GET /commerce/admin/orders/{uuid}/refunds',
        'GET /commerce/admin/products',
        'GET /commerce/admin/products/{uuid}',
        'GET /commerce/admin/products/{uuid}/addons',
        'GET /commerce/admin/refunds',
        'GET /commerce/admin/refunds/{uuid}',
        'GET /commerce/admin/reports/customers',
        'GET /commerce/admin/reports/products',
        'GET /commerce/admin/reports/sales',
        'GET /commerce/admin/reports/stock',
        'GET /commerce/admin/reviews',
        'GET /commerce/admin/reviews/{uuid}',
        'GET /commerce/admin/shipping/classes',
        'GET /commerce/admin/shipping/classes/{uuid}',
        'GET /commerce/admin/shipping/methods/{uuid}',
        'GET /commerce/admin/shipping/zones',
        'GET /commerce/admin/shipping/zones/{uuid}',
        'GET /commerce/admin/shipping/zones/{uuid}/methods',
        'GET /commerce/admin/tags',
        'GET /commerce/admin/tags/{uuid}',
        'GET /commerce/admin/tax/rates',
        'GET /commerce/admin/tax/rates/{uuid}',
        'GET /commerce/admin/variants/{uuid}/downloads',
        'GET /commerce/cart',
        'GET /commerce/categories',
        'GET /commerce/downloads/{token}',
        'GET /commerce/orders',
        'GET /commerce/orders/{number}',
        'GET /commerce/orders/{number}/downloads',
        'GET /commerce/products',
        'GET /commerce/products/{slug}',
        'GET /commerce/products/{slug}/reviews',
        'PATCH /commerce/account/addresses/{uuid}',
        'PATCH /commerce/admin/addons/{uuid}',
        'PATCH /commerce/admin/attribute-values/{uuid}',
        'PATCH /commerce/admin/attributes/{uuid}',
        'PATCH /commerce/admin/categories/{uuid}',
        'PATCH /commerce/admin/discounts/{uuid}',
        'PATCH /commerce/admin/downloads/{uuid}',
        'PATCH /commerce/admin/media/{uuid}',
        'PATCH /commerce/admin/products/{uuid}',
        'PATCH /commerce/admin/shipping/classes/{uuid}',
        'PATCH /commerce/admin/shipping/methods/{uuid}',
        'PATCH /commerce/admin/shipping/zones/{uuid}',
        'PATCH /commerce/admin/tags/{uuid}',
        'PATCH /commerce/admin/tax/rates/{uuid}',
        'PATCH /commerce/admin/variants/{uuid}',
        'PATCH /commerce/cart/lines/{uuid}',
        'POST /commerce/account/addresses',
        'POST /commerce/admin/attributes',
        'POST /commerce/admin/attributes/{uuid}/values',
        'POST /commerce/admin/categories',
        'POST /commerce/admin/discounts',
        'POST /commerce/admin/grants/{uuid}/revoke',
        'POST /commerce/admin/orders/{uuid}/cancel',
        'POST /commerce/admin/orders/{uuid}/fulfill',
        'POST /commerce/admin/orders/{uuid}/mark-paid',
        'POST /commerce/admin/orders/{uuid}/notes',
        'POST /commerce/admin/orders/{uuid}/refunds',
        'POST /commerce/admin/products',
        'POST /commerce/admin/products/bulk-status',
        'POST /commerce/admin/products/{uuid}/addons',
        'POST /commerce/admin/products/{uuid}/media',
        'POST /commerce/admin/products/{uuid}/variants',
        'POST /commerce/admin/reviews',
        'POST /commerce/admin/reviews/bulk',
        'POST /commerce/admin/reviews/{uuid}/approve',
        'POST /commerce/admin/reviews/{uuid}/spam',
        'POST /commerce/admin/shipping/classes',
        'POST /commerce/admin/shipping/zones',
        'POST /commerce/admin/shipping/zones/{uuid}/methods',
        'POST /commerce/admin/stock/{variantUuid}/adjust',
        'POST /commerce/admin/tags',
        'POST /commerce/admin/tax/rates',
        'POST /commerce/admin/variants/bulk-price',
        'POST /commerce/admin/variants/{uuid}/downloads',
        'POST /commerce/cart',
        'POST /commerce/cart/discount',
        'POST /commerce/cart/lines',
        'POST /commerce/checkout',
        'POST /commerce/checkout/quote',
        'POST /commerce/orders/{number}/downloads/{grantUuid}/url',
        'POST /commerce/orders/{number}/payment',
        'POST /commerce/products/{slug}/reviews',
        'PUT /commerce/admin/grants/{uuid}/refund-access-override',
        'PUT /commerce/admin/products/{uuid}/attributes',
        'PUT /commerce/admin/products/{uuid}/categories',
        'PUT /commerce/admin/products/{uuid}/children',
        'PUT /commerce/admin/products/{uuid}/media/order',
        'PUT /commerce/admin/products/{uuid}/tags',
        'PUT /commerce/admin/shipping/zones/{uuid}/locations',
    ];

    public function testRouteManifestWithTheMasterSwitchOffIsByteIdenticalToPreMv1(): void
    {
        self::assertFalse(
            (bool) config($this->context, 'commerce.marketplace.enabled', false),
            'sanity: the master switch must default to off'
        );

        $router = $this->freshRouter();
        $manifest = [];
        foreach ($router->getAllRoutes() as $route) {
            $manifest[] = strtoupper((string) $route['method']) . ' ' . (string) $route['path'];
        }
        sort($manifest);

        self::assertSame(self::PRE_MV1_ROUTE_MANIFEST, $manifest);
        self::assertCount(122, $manifest);

        foreach ($manifest as $route) {
            self::assertDoesNotMatchRegularExpression('#^\S+ /commerce/admin/marketplace#', $route);
            self::assertDoesNotMatchRegularExpression('#^\S+ /commerce/seller#', $route);
        }
    }

    private function freshRouter(): Router
    {
        $this->bind(ApplicationContext::class, $this->context);
        $router = new Router($this->contextContainer());

        self::assertFalse(
            $router->wasLoadedFromCache(),
            'The route manifest must be built fresh, never loaded from an app route cache.'
        );

        require __DIR__ . '/../../../routes.php';

        return $router;
    }

    // =====================================================================
    // 2. Zero marketplace-table queries on ordinary request paths.
    // =====================================================================

    public function testStorefrontProductBrowseIssuesZeroMarketplaceTableQueries(): void
    {
        $this->seedLegacyProduct('regress-browse');

        $this->assertNoMarketplaceQueries(function (): void {
            $response = $this->productController()->index(new ProductListQuery());
            self::assertSame(200, $response->getStatusCode());
        });
    }

    public function testCartCreateAndAddLineIssueZeroMarketplaceTableQueries(): void
    {
        $product = $this->seedLegacyProduct('regress-cart');
        $variantUuid = (string) $product['variants'][0]['uuid'];
        $controller = new CartController($this->context, $this->cartService());

        $this->assertNoMarketplaceQueries(function () use ($controller, $variantUuid): void {
            $created = $controller->create(Request::create('/commerce/cart', 'POST'));
            self::assertSame(201, $created->getStatusCode());
            $token = (string) json_decode((string) $created->getContent(), true)['data']['token'];

            $request = Request::create('/commerce/cart/lines', 'POST');
            $request->headers->set('X-Cart-Token', $token);
            $response = $controller->addLine(new AddCartLineData($variantUuid, 1), $request);
            self::assertSame(200, $response->getStatusCode());
        });
    }

    public function testCheckoutQuoteIssuesZeroMarketplaceTableQueries(): void
    {
        $product = $this->seedLegacyProduct('regress-checkout');
        $variantUuid = (string) $product['variants'][0]['uuid'];

        $created = $this->cartService()->create($this->context);
        $cart = $this->cartService()->addLine($this->context, $created['cart'], $variantUuid, 1);

        $this->assertNoMarketplaceQueries(function () use ($cart): void {
            $quote = $this->checkoutService()->quote($this->context, $cart, ['country' => 'US'], 'std');
            self::assertIsArray($quote);
        });
    }

    public function testAdminProductCreateIssuesZeroMarketplaceTableQueries(): void
    {
        $this->assertNoMarketplaceQueries(function (): void {
            $response = $this->adminController()->store(
                new CreateProductData(
                    slug: 'regress-admin-create',
                    name: 'Regress Admin Create',
                    type: 'physical',
                    status: 'active',
                    variants: [new ProductVariantData(sku: 'REGRESS-ADMIN-CREATE', price: 1000, currency: 'USD')],
                ),
                Request::create('/x', 'POST')
            );
            self::assertSame(201, $response->getStatusCode());
        });
    }

    public function testAdminProductUpdateIssuesZeroMarketplaceTableQueries(): void
    {
        $product = $this->seedLegacyProduct('regress-admin-update');

        $this->assertNoMarketplaceQueries(function () use ($product): void {
            $request = Request::create('/x', 'PATCH', [], [], [], [], json_encode(
                ['name' => 'Regress Admin Update Renamed'],
                JSON_THROW_ON_ERROR
            ));
            $request->headers->set('Content-Type', 'application/json');
            $response = $this->adminController()->update($request, $product['uuid']);
            self::assertSame(200, $response->getStatusCode());
        });
    }

    public function testAdminSalesReportIssuesZeroMarketplaceTableQueries(): void
    {
        $this->assertNoMarketplaceQueries(function (): void {
            $response = (new AdminReportController(
                $this->context,
                new SalesReportRepository(),
                $this->fixedTenant()
            ))->sales(
                new ReportWindowQuery(from: '2026-01-01', to: '2026-01-02', group: 'day'),
                Request::create('/x', 'GET')
            );
            self::assertSame(200, $response->getStatusCode());
        });
    }

    /** @param callable(): void $exercise */
    private function assertNoMarketplaceQueries(callable $exercise): void
    {
        $pdo = $this->connection->getPDO();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [QueryLoggingPdoStatement::class]);
        QueryLoggingPdoStatement::$queries = [];

        $exercise();

        self::assertNotEmpty(QueryLoggingPdoStatement::$queries, 'sanity: the call itself must run some queries');

        $marketplaceTables = ['commerce_marketplace_settings', 'commerce_sellers', 'commerce_seller_memberships'];
        foreach (QueryLoggingPdoStatement::$queries as $sql) {
            foreach ($marketplaceTables as $table) {
                self::assertStringNotContainsString(
                    $table,
                    $sql,
                    "an ordinary request path must issue ZERO marketplace-table queries while the master switch "
                        . "is off; saw: {$sql}"
                );
            }
        }
    }

    // =====================================================================
    // 3. Public payload allowlist: seller_uuid absent everywhere.
    // =====================================================================

    /**
     * Seeds a product carrying a REAL, non-null `seller_uuid` directly (the
     * design spec §2.1 scenario: data attributed while the workspace was
     * active, surviving a later switch-off) and confirms the storefront
     * projection strips it from both `index()` and `show()` regardless --
     * the projection layer's allowlist, not the switch, is what keeps it
     * off the public surface. `StorefrontProductProjectionTest` pins the
     * same absence at the field-list level; this is the end-to-end version.
     */
    public function testPublicStorefrontPayloadsNeverCarrySellerUuidEvenWhenOneIsAttributed(): void
    {
        $sellerUuid = 'regresssellr1';
        $this->connection->table('commerce_products')->insert([
            'uuid' => 'regresssrpd1',
            'tenant_uuid' => self::TENANT,
            'slug' => 'regress-seller-leak',
            'name' => 'Regress Seller Leak',
            'type' => 'physical',
            'status' => 'active',
            'seller_uuid' => $sellerUuid,
        ]);

        $controller = $this->productController();

        $indexBody = json_decode(
            (string) $controller->index(new ProductListQuery())->getContent(),
            true
        );
        self::assertCount(1, $indexBody['data']);
        self::assertArrayNotHasKey('seller_uuid', $indexBody['data'][0]);
        self::assertStringNotContainsString($sellerUuid, (string) json_encode($indexBody, JSON_THROW_ON_ERROR));

        $showBody = json_decode(
            (string) $controller->show(Request::create('/x'), 'regress-seller-leak')->getContent(),
            true
        );
        self::assertArrayNotHasKey('seller_uuid', $showBody['data']);
        self::assertStringNotContainsString($sellerUuid, (string) json_encode($showBody, JSON_THROW_ON_ERROR));
    }

    // =====================================================================
    // 4. Maintenance exceptions stay marketplace-aware with the switch off.
    // =====================================================================

    public function testDiagnosticsReportListsAllThreeMarketplaceTablesAsPresentWithTheSwitchOff(): void
    {
        self::assertFalse((bool) config($this->context, 'commerce.marketplace.enabled', false));

        $present = DiagnosticsReport::build($this->context)['database']['commerce_tables_present'];

        foreach (['commerce_marketplace_settings', 'commerce_sellers', 'commerce_seller_memberships'] as $table) {
            self::assertArrayHasKey($table, $present);
            self::assertTrue(
                $present[$table],
                "DiagnosticsReport must list {$table} as present regardless of the switch"
            );
        }
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /** @return array<string,mixed> product row with one variant, tenant-sentinel */
    private function seedLegacyProduct(string $slug): array
    {
        $product = $this->legacyCatalog()->createProduct($this->context, [
            'slug' => $slug,
            'name' => $slug,
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => strtoupper(str_replace('-', '', $slug)),
                'option_values' => [],
                'price' => 1000,
                'currency' => 'USD',
            ]],
        ]);
        (new StockRepository())->increment($this->context, self::TENANT, (string) $product['variants'][0]['uuid'], 100);

        return $product;
    }

    private function legacyCatalog(): CatalogService
    {
        return new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            $this->fixedTenant(),
            new StockRepository(),
            new ProductChildrenRepository()
        );
    }

    private function productController(): ProductController
    {
        return new ProductController(
            $this->context,
            new ProductRepository(),
            new VariantRepository(),
            $this->fixedTenant(),
            new ProductMediaRepository(),
            new CategoryRepository(),
            new TagRepository(),
            new AttributeRepository(),
            new ProductChildrenRepository(),
            new AddonRepository()
        );
    }

    private function adminController(): AdminProductController
    {
        return new AdminProductController(
            $this->context,
            $this->legacyCatalog(),
            new ProductRepository(),
            new VariantRepository(),
            $this->fixedTenant()
        );
    }

    private function cartService(): CartService
    {
        return new CartService(
            new CartRepository(),
            new VariantRepository(),
            new ProductRepository(),
            new StockRepository(),
            new DiscountRepository(),
            new PricingEngine(),
            $this->fixedTenant(),
            new AddonRepository()
        );
    }

    private function checkoutService(): CheckoutService
    {
        return new CheckoutService(
            $this->cartService(),
            new DiscountRepository(),
            new DiscountService(new DiscountRepository(), $this->fixedTenant()),
            new StockRepository(),
            new PricingEngine(),
            $this->fakeShipping(),
            $this->fakeTax(),
            new OrderNumberGenerator(),
            new OrderRepository(),
            new DownloadRepository(),
            new ManualPaymentCollector(),
            $this->fixedTenant()
        );
    }

    private function fakeShipping(): ShippingRateProvider
    {
        return new class implements ShippingRateProvider {
            public function quote(ApplicationContext $context, array $lines, array $shippingAddress): array
            {
                return [new ShippingQuote('std', 'Standard', 0)];
            }
        };
    }

    private function fakeTax(): TaxCalculator
    {
        return new class implements TaxCalculator {
            public function quote(ApplicationContext $context, int $grandTotal, array $shippingAddress): TaxQuote
            {
                return new TaxQuote(0);
            }
        };
    }

    private function fixedTenant(): CurrentTenantResolver
    {
        return new SentinelTenantResolver();
    }
}
