<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Encryption\EncryptionService;
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
use Glueful\Extensions\Commerce\Console\PurgeApiKeyDenialsCommand;
use Glueful\Extensions\Commerce\Console\PurgeSellerWebhooksCommand;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Http\Admin\AdminProductController;
use Glueful\Extensions\Commerce\Http\Admin\AdminReportController;
use Glueful\Extensions\Commerce\Http\DTOs\AddCartLineData;
use Glueful\Extensions\Commerce\Http\DTOs\CreateProductData;
use Glueful\Extensions\Commerce\Http\DTOs\FulfillOrderData;
use Glueful\Extensions\Commerce\Http\DTOs\ProductListQuery;
use Glueful\Extensions\Commerce\Http\DTOs\ProductVariantData;
use Glueful\Extensions\Commerce\Http\DTOs\ReportWindowQuery;
use Glueful\Extensions\Commerce\Http\Middleware\InteractiveSessionMiddleware;
use Glueful\Extensions\Commerce\Http\Middleware\SellerMemberMiddleware;
use Glueful\Extensions\Commerce\Http\Seller\SellerFinancialController;
use Glueful\Extensions\Commerce\Http\Seller\SellerOrderController;
use Glueful\Extensions\Commerce\Http\Seller\SellerWebhookController;
use Glueful\Extensions\Commerce\Http\Storefront\CartController;
use Glueful\Extensions\Commerce\Http\Storefront\OrderController;
use Glueful\Extensions\Commerce\Http\Storefront\ProductController;
use Glueful\Extensions\Commerce\Invoices\ConfigSellerIdentityProvider;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Marketplace\FixedSellerRoleAuthority;
use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerPostingService;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceRefundGuard;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceWorkspaceLock;
use Glueful\Extensions\Commerce\Marketplace\PayoutAccountRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutException;
use Glueful\Extensions\Commerce\Marketplace\PayoutRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutService;
use Glueful\Extensions\Commerce\Marketplace\ReservePolicyEventRepository;
use Glueful\Extensions\Commerce\Marketplace\ReservePolicyService;
use Glueful\Extensions\Commerce\Marketplace\ReserveRepository;
use Glueful\Extensions\Commerce\Marketplace\ReserveService;
use Glueful\Extensions\Commerce\Marketplace\SellerApiKeyAuthorizer;
use Glueful\Extensions\Commerce\Marketplace\SellerApiKeyRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerBalanceService;
use Glueful\Extensions\Commerce\Marketplace\SellerLifecycleEventRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderFulfillmentService;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderPaymentConfirmation;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderService;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerService;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookDeliveryRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookDeliveryService;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookEndpointRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookEndpointService;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookEventRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookOutboxPublisher;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookPayloadProjector;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookSecretService;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundInput;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundService;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Commerce\Reports\SalesReportRepository;
use Glueful\Extensions\Commerce\Reports\SellerFinancialReportRepository;
use Glueful\Extensions\Commerce\Shipping\ShippingClassRepository;
use Glueful\Extensions\Commerce\Support\DiagnosticsReport;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tenancy\TenantAdopter;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Commerce\Tests\Support\QueryLoggingPdoStatement;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Helpers\Utils;
use Glueful\Http\Client;
use Glueful\Http\Exceptions\Handler as ExceptionHandler;
use Glueful\Http\Response;
use Glueful\Http\Security\SafeOutboundTargetResolver;
use Glueful\Routing\RouteMiddleware;
use Glueful\Routing\Router;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The marketplace "optional" proof (design spec §6/§7 "Regression gate";
 * MV1 plan Task 5, extended by MV2 plan Task 10): with the install master
 * switch ABSENT/false -- the default, see `config/commerce.php` -- Commerce
 * must be byte-identical to a pre-MV1 install. Independent proofs, mirroring
 * the spec's own breakdown:
 *
 * 1. Route manifest: `routes.php`'s marketplace/seller groups (MV1 AND MV2 --
 *    every seller-order/admin-seller-order route lives in the SAME
 *    `if ($marketplaceEnabled)` gate) are gated behind blocks that are PURELY
 *    ADDITIVE (see `git diff` against the pre-MV1 commit) -- with the flag
 *    off, the compiled manifest must be the exact 122-route pre-MV1 set,
 *    byte for byte, and contain zero `/commerce/admin/marketplace` or
 *    `/commerce/seller` routes.
 * 2. Zero marketplace-table queries on ordinary request paths, instrumented
 *    with the `QueryLoggingPdoStatement` idiom `CatalogOwnershipTest`
 *    already established for admin product create -- extended here to
 *    storefront browse, cart add, checkout quote, admin product create AND
 *    update, a reports endpoint, and (MV2 Task 10) a full non-partitioned
 *    checkout/payment/fulfill/cancel/projection sweep, matched against ALL
 *    EIGHT marketplace tables: the MV1 trio (`commerce_marketplace_settings`,
 *    `commerce_sellers`, `commerce_seller_memberships`), MV2's
 *    `commerce_seller_orders`, and (MV3 Task 12) the four new settlement
 *    tables (`commerce_marketplace_ledger`, `commerce_ledger_account_locks`,
 *    `commerce_commission_policy_events`, `commerce_payouts`) -- every table
 *    joins the SAME zero-query proof. MV3 also adds a refund lane to the
 *    checkout/payment/fulfill sweep (design spec §7: "checkout/payment/
 *    refund/fulfill" all post nothing and query nothing settlement-side for
 *    a non-partitioned order), since payment posting alone doesn't exercise
 *    {@see \Glueful\Extensions\Commerce\Marketplace\LedgerPostingService::postRefund()}'s
 *    own gate.
 * 3. Public payload allowlist: `seller_uuid` never appears in a storefront
 *    product projection, even for a product a prior activation (since
 *    switched back off -- design spec §2.1's explicit exception, data stays
 *    coherent) already attributed to a seller. `StorefrontProductProjectionTest`
 *    pins the full allowlist including `seller_uuid` in its internal-fields
 *    list; this file adds one end-to-end confirmation seeded with a real
 *    non-null `seller_uuid`. A non-partitioned CUSTOMER order projection is
 *    proven byte-identical the same way (MV2 Task 10): no `seller_groups` key.
 * 4. Maintenance exceptions stay marketplace-aware regardless of the switch:
 *    `DiagnosticsReport` lists all four tables (MV1 trio + MV2
 *    `commerce_seller_orders`) as present. The `commerce:tenancy:adopt`
 *    rekey side of this is already covered by
 *    `Tenancy\TenantAdopterTest::testAdoptRekeysMarketplaceTablesEvenWhenTheMasterSwitchIsOff()`
 *    and its MV2 `commerce_seller_orders` sibling -- not duplicated here.
 * 5. Partition invariance (design spec §7, MV2 Task 10): behavior follows the
 *    ORDER's own immutable `marketplace_partitioned` snapshot, never the
 *    workspace's CURRENT `activeFor()` state -- a partitioned order stays
 *    child-aware and keeps exposing `seller_groups` after the workspace
 *    later deactivates; a non-partitioned order stays byte-identical
 *    regardless of any LATER activation.
 */
final class MarketplaceRegressionTest extends CommerceTestCase
{
    private const TENANT = '';

    /** MV3 Task 12 (design spec §3.2-§3.5): the four new settlement tables. */
    private const MV3_SETTLEMENT_TABLES = [
        'commerce_marketplace_ledger',
        'commerce_ledger_account_locks',
        'commerce_commission_policy_events',
        'commerce_payouts',
    ];

    /** MV4 Task 11 (design spec §3.2): the brand-new payout-destination table. */
    private const MV4_PAYOUT_ACCOUNTS_TABLE = 'commerce_seller_payout_accounts';

    /** MV5a Task 17 (design spec §3.2/§3.3): the four new reserve/chargeback tables. */
    private const MV5A_TABLES = [
        'commerce_seller_reserves',
        'commerce_reserve_policy_events',
        'commerce_chargebacks',
        'commerce_chargeback_lines',
    ];

    /** MV5b Task 7 (design spec §3): the brand-new lifecycle-audit table. */
    private const MV5B_LIFECYCLE_TABLE = 'commerce_seller_lifecycle_events';

    /** MV5c-1 Task 7 (design spec §3): the three brand-new seller-API-key tables. */
    private const MV5C1_TABLES = [
        'commerce_seller_api_keys',
        'commerce_seller_api_key_credentials',
        'commerce_seller_api_key_events',
    ];

    /** MV5c-2 Task 8 (design spec §3): the five brand-new seller-webhook tables. */
    private const MV5C2_TABLES = [
        'commerce_seller_webhook_endpoints',
        'commerce_seller_webhook_secrets',
        'commerce_seller_webhook_events',
        'commerce_seller_webhook_deliveries',
        'commerce_seller_webhook_endpoint_events',
    ];

    private int $webhookEndpointSeq = 0;

    // =====================================================================
    // 1. Route manifest: flag off == pre-MV1, byte for byte.
    // =====================================================================

    /**
     * Pinned pre-MV1 manifest (129 routes; grown additively at 1.5.0/1.6.0), captured from `routes.php` at
     * the commit immediately before the marketplace groups were added
     * (`git diff` from that commit confirms every marketplace/seller route
     * addition is purely inside a `if ($marketplaceEnabled)` block -- never
     * a change to a pre-existing route) and cross-checked by running this
     * exact `freshRouter()` walk against `commerce.marketplace.enabled`
     * left at its default. `METHOD path`, sorted. Updated for Task A6
     * (1.5.0): +6 `GET .../products/{uuid}/{categories,tags,attributes,
     * media,children,stock}` per-product read endpoints -- purely additive
     * to the non-marketplace surface, unrelated to the marketplace flag this
     * test guards.
     *
     * @var list<string>
     */
    private const PRE_MV1_ROUTE_MANIFEST = [
        'DELETE /commerce/account/addresses/{uuid}',
        'DELETE /commerce/account/wishlist/{productUuid}',
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
        'GET /commerce/account/wishlist',
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
        'GET /commerce/admin/products/{uuid}/attributes',
        'GET /commerce/admin/products/{uuid}/categories',
        'GET /commerce/admin/products/{uuid}/children',
        'GET /commerce/admin/products/{uuid}/media',
        'GET /commerce/admin/products/{uuid}/orders',
        'GET /commerce/admin/products/{uuid}/stock',
        'GET /commerce/admin/products/{uuid}/tags',
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
        'POST /commerce/account/wishlist',
        'POST /commerce/account/wishlist/import',
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
        self::assertCount(133, $manifest);

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

        // MV3 Task 12: the expanded 8-table zero-query proof -- the MV1 trio
        // plus MV2's commerce_seller_orders plus the four MV3 settlement
        // tables. MV4 Task 11 (GATES) adds a 9th: commerce_seller_payout_accounts
        // -- no ordinary request path may ever touch the payout-destination
        // table or its retry/reconcile sweep indexes either. MV5a Task 17
        // (GATES) adds the FOUR new risk/chargeback tables: no ordinary
        // (non-payout/non-chargeback/non-reserve) request path may ever
        // touch a reserve or chargeback table either. MV5b Task 7 (GATES)
        // adds the new lifecycle-audit table: no ordinary storefront/cart/
        // checkout/admin/report/payment/refund/fulfill/cancel/projection
        // path may ever touch it either -- it is written ONLY by an explicit
        // operator suspend/reactivate/close call, never incidentally. MV5c-1
        // Task 7 (GATES) adds the three new seller-API-key tables: no
        // ordinary path above touches them either -- they are written/read
        // ONLY by the seller-API-key surface + the per-request authorizer,
        // and the authorizer itself is a proven zero-query no-op for every
        // one of these session-authenticated paths (design spec §6 --
        // `auth_method != 'api_key'` short-circuits before any key-table
        // query; see `SellerApiKeyAuthorizer::authorize()`). MV5c-2 Task 8
        // (GATES) adds the five new seller-webhook tables: no ordinary path
        // above touches them either -- they are written ONLY by a REAL
        // `SellerWebhookOutboxPublisher::capture()` call a business service
        // was explicitly wired with, and `capture()` itself is a proven
        // zero-query no-op while the master switch is off (design spec §6;
        // see `SellerWebhookOutboxTest::testMasterOffCaptureIsAZeroQueryNoOp()`
        // and this file's own `testMasterOff...WebhookPublisherWired...()` below).
        $marketplaceTables = [
            'commerce_marketplace_settings',
            'commerce_sellers',
            'commerce_seller_memberships',
            'commerce_seller_orders',
            'commerce_marketplace_ledger',
            'commerce_ledger_account_locks',
            'commerce_commission_policy_events',
            'commerce_payouts',
            self::MV4_PAYOUT_ACCOUNTS_TABLE,
            ...self::MV5A_TABLES,
            self::MV5B_LIFECYCLE_TABLE,
            ...self::MV5C1_TABLES,
            ...self::MV5C2_TABLES,
        ];
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
    // 2b. MV2 Task 10: the full non-partitioned checkout/payment/fulfill/
    //     cancel/projection sweep, each independently wrapped in the SAME
    //     4-table zero-query instrumentation as above.
    // =====================================================================

    public function testCheckoutPlaceOrderIssuesZeroMarketplaceTableQueries(): void
    {
        $product = $this->seedLegacyProduct('regress-place-order');
        $variantUuid = (string) $product['variants'][0]['uuid'];

        ['cart' => $cart, 'token' => $token] = $this->cartService()->create($this->context);
        $this->cartService()->addLine($this->context, $cart, $variantUuid, 1);

        $this->assertNoMarketplaceQueries(function () use ($token): void {
            $placed = $this->checkoutService()->placeOrder(
                $this->context,
                $token,
                $this->buyer(),
                $this->addresses(),
                'std'
            );
            self::assertFalse((bool) $placed['order']['marketplace_partitioned']);
        });
    }

    public function testMarkPaidIssuesZeroMarketplaceTableQueries(): void
    {
        $order = $this->placeNonPartitionedOrder()['order'];

        $this->assertNoMarketplaceQueries(function () use ($order): void {
            $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);
        });

        self::assertSame('paid', $this->orderRow((string) $order['uuid'])['status']);
    }

    /**
     * MV3 Task 12: closes the "checkout/payment/refund/fulfill" quartet the
     * spec §7 gate names -- {@see \Glueful\Extensions\Commerce\Marketplace\LedgerPostingService::postRefund()}
     * has its OWN gate on the order's `marketplace_partitioned` flag,
     * independent of {@see \Glueful\Extensions\Commerce\Marketplace\LedgerPostingService::postSale()}'s,
     * so the payment-side zero-query proof above does not exercise it.
     * `refundService()` is wired WITH both marketplace collaborators
     * (`MarketplaceRefundGuard`, `LedgerPostingService`) so this proves the
     * REAL branch condition, never merely a missing collaborator -- the same
     * discipline `PaymentPostingTest::
     * testNonPartitionedPaidTransitionExecutesZeroLedgerAndLockQueriesAndPostsNothing()`
     * already established for `markPaid()`.
     */
    public function testAdminRefundIssuesZeroMarketplaceTableQueries(): void
    {
        $order = $this->placeNonPartitionedOrder()['order'];
        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);

        $this->assertNoMarketplaceQueries(function () use ($order): void {
            $refund = $this->refundService()->issue(
                $this->context,
                (string) $order['uuid'],
                new RefundInput(null, 'regress full refund', [], false),
                'idem-regress-refund-1'
            );
            self::assertSame('completed', $refund['status']);
        });

        self::assertSame('refunded', $this->orderRow((string) $order['uuid'])['status']);
        self::assertSame(0, $this->connection->table('commerce_marketplace_ledger')->count());
    }

    public function testAdminFulfillIssuesZeroMarketplaceTableQueries(): void
    {
        $order = $this->placeNonPartitionedOrder()['order'];
        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);

        $this->assertNoMarketplaceQueries(function () use ($order): void {
            $response = $this->marketplaceAdminOrderController()->fulfill(
                new FulfillOrderData(tracking_ref: 'TRACK-REGRESS-1'),
                Request::create('/x', 'POST'),
                (string) $order['uuid']
            );
            self::assertSame(200, $response->getStatusCode());
        });

        self::assertSame('fulfilled', $this->orderRow((string) $order['uuid'])['status']);
    }

    public function testAdminCancelIssuesZeroMarketplaceTableQueries(): void
    {
        $order = $this->placeNonPartitionedOrder()['order'];

        $this->assertNoMarketplaceQueries(function () use ($order): void {
            $response = $this->marketplaceAdminOrderController()->cancel(
                Request::create('/x', 'POST'),
                (string) $order['uuid']
            );
            self::assertSame(200, $response->getStatusCode());
        });

        self::assertSame('canceled', $this->orderRow((string) $order['uuid'])['status']);
    }

    /**
     * Closes the loop on `StorefrontSellerGroupsProjectionTest`'s own
     * byte-identical assertion (no `seller_groups` key) by proving it under
     * the SAME query-instrumented harness as every other path above: the
     * customer order projection for a non-partitioned order never touches a
     * marketplace table, and never carries `seller_groups`.
     */
    public function testCustomerOrderProjectionIssuesZeroMarketplaceTableQueriesAndIsByteIdentical(): void
    {
        $placed = $this->placeNonPartitionedOrder();
        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $placed['order']['uuid']);
        $order = $placed['order'];
        $number = (string) $order['order_number'];

        $request = Request::create("/commerce/orders/{$number}", 'GET');
        $request->headers->set('X-Order-Token', (string) $placed['guest_token']);

        $body = null;
        $this->assertNoMarketplaceQueries(function () use ($request, $number, &$body): void {
            $response = $this->orderController()->show($request, $number);
            self::assertSame(200, $response->getStatusCode());
            $body = json_decode((string) $response->getContent(), true);
        });

        self::assertArrayNotHasKey('seller_groups', $body['data']);
        self::assertSame(0, $this->connection->table('commerce_seller_orders')->count());
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

    public function testDiagnosticsReportListsAllFourMarketplaceTablesAsPresentWithTheSwitchOff(): void
    {
        self::assertFalse((bool) config($this->context, 'commerce.marketplace.enabled', false));

        $present = DiagnosticsReport::build($this->context)['database']['commerce_tables_present'];

        // MV1 trio + the MV2 commerce_seller_orders table (design spec §7):
        // maintenance surfaces stay marketplace-aware regardless of the switch.
        $marketplaceTables = [
            'commerce_marketplace_settings',
            'commerce_sellers',
            'commerce_seller_memberships',
            'commerce_seller_orders',
        ];
        foreach ($marketplaceTables as $table) {
            self::assertArrayHasKey($table, $present);
            self::assertTrue(
                $present[$table],
                "DiagnosticsReport must list {$table} as present regardless of the switch"
            );
        }
    }

    /**
     * MV3 Task 12: the four settlement tables (design spec §3.7) join the
     * SAME maintenance-surface guarantee as the MV1/MV2 quartet above --
     * `DiagnosticsReport` lists them as present regardless of the master
     * switch.
     */
    public function testDiagnosticsReportListsAllFourMv3SettlementTablesAsPresentWithTheSwitchOff(): void
    {
        self::assertFalse((bool) config($this->context, 'commerce.marketplace.enabled', false));

        $present = DiagnosticsReport::build($this->context)['database']['commerce_tables_present'];

        foreach (self::MV3_SETTLEMENT_TABLES as $table) {
            self::assertArrayHasKey($table, $present);
            self::assertTrue(
                $present[$table],
                "DiagnosticsReport must list {$table} as present regardless of the switch"
            );
        }
    }

    /**
     * MV3 Task 12: `commerce:tenancy:adopt` rekeys the four new settlement
     * tables too -- {@see \Glueful\Extensions\Commerce\Support\DiagnosticsReport::tenantTables()}
     * already lists them unconditionally (design spec §3.7), so
     * {@see \Glueful\Extensions\Commerce\Tenancy\TenantAdopter} picks them up
     * mechanically; this pins that behaviorally, mirroring
     * `Tenancy\TenantAdopterTest::testAdoptRekeysMarketplaceTablesEvenWhenTheMasterSwitchIsOff()`
     * exactly, switch off (the default).
     */
    public function testTenantAdoptRekeysAllFourMv3SettlementTablesEvenWhenTheMasterSwitchIsOff(): void
    {
        self::assertFalse(
            (bool) config($this->context, 'commerce.marketplace.enabled', false),
            'this test relies on the master switch being off (the default)'
        );

        $this->connection->table('commerce_marketplace_ledger')->insert([
            'uuid' => 'mv3adoptldg1',
            'tenant_uuid' => '',
            'account_key' => 'seller:mv3adoptsel1',
            'account_kind' => 'seller',
            'seller_uuid' => 'mv3adoptsel1',
            'currency' => 'USD',
            'entry_type' => 'sale_credit',
            'amount' => 1000,
            'idempotency_key' => 'mv3-adopt-ledger-1',
        ]);
        $this->connection->table('commerce_ledger_account_locks')->insert([
            'tenant_uuid' => '',
            'account_key' => 'seller:mv3adoptsel1',
            'currency' => 'USD',
        ]);
        $this->connection->table('commerce_commission_policy_events')->insert([
            'uuid' => 'mv3adoptevt1',
            'tenant_uuid' => '',
            'subject_kind' => 'seller',
            'subject_uuid' => 'mv3adoptsel1',
            'actor_uuid' => 'mv3adoptop01',
            'before_policy' => json_encode(['kind' => null, 'bps' => null, 'fixed' => null], JSON_THROW_ON_ERROR),
            'after_policy' => json_encode(
                ['kind' => 'percentage', 'bps' => 500, 'fixed' => null],
                JSON_THROW_ON_ERROR
            ),
        ]);
        $this->connection->table('commerce_payouts')->insert([
            'uuid' => 'mv3adoptpay1',
            'tenant_uuid' => '',
            'seller_uuid' => 'mv3adoptsel1',
            'currency' => 'USD',
            'amount' => 500,
            'external_ref' => 'mv3-adopt-payout-ref',
            'created_by' => 'mv3adoptop01',
            'idempotency_key' => 'mv3-adopt-payout-1',
        ]);

        $result = (new TenantAdopter())->adopt($this->context, 'tenantMV30099');

        foreach (self::MV3_SETTLEMENT_TABLES as $table) {
            self::assertSame(1, $result['tables'][$table], "{$table} must report exactly 1 rekeyed row");
            self::assertSame(
                0,
                $this->connection->table($table)->where('tenant_uuid', '=', '')->count(),
                "{$table} must have no sentinel rows left behind"
            );
            self::assertSame(
                1,
                $this->connection->table($table)->where('tenant_uuid', '=', 'tenantMV30099')->count(),
                "{$table} row must be rekeyed to the adopted tenant"
            );
        }
    }

    // =====================================================================
    // 5. Admin read-modify-write round trip: `seller_uuid` never breaks a
    //    GET -> mutate -> PATCH-the-whole-object cycle (final-review Important
    //    finding). Every admin product payload carries `seller_uuid` since
    //    migration 011, master switch or not -- AdminProductController::update()
    //    must silently drop it, never 422 a client that only ever echoed back
    //    what it was given.
    // =====================================================================

    public function testAdminReadModifyWriteRoundTripSucceedsWithTheSwitchOff(): void
    {
        $product = $this->seedLegacyProduct('regress-rmw-off');
        $controller = $this->adminController();

        $getResponse = $controller->show(Request::create('/x'), $product['uuid']);
        self::assertSame(200, $getResponse->getStatusCode());
        $payload = json_decode((string) $getResponse->getContent(), true)['data'];
        self::assertArrayHasKey('seller_uuid', $payload, 'sanity: migration 011 always carries the column');
        self::assertNull($payload['seller_uuid']);

        $patchResponse = $controller->update(
            $this->patchRequest($this->productEditableFields($payload)),
            $product['uuid']
        );

        self::assertSame(200, $patchResponse->getStatusCode());
        $row = $this->connection->table('commerce_products')->where('uuid', '=', $product['uuid'])->first();
        self::assertSame($product['name'], $row['name']);
        self::assertSame($product['slug'], $row['slug']);
        self::assertNull($row['seller_uuid']);
    }

    public function testAdminReadModifyWriteRoundTripEchoingTheSameSellerUuidSucceedsWhenAttributed(): void
    {
        $this->enableMarketplace();
        $this->activateWorkspace(self::TENANT);
        $seller = $this->seedActiveSeller('regress-rmw-owner');
        $product = $this->seedAttributedProduct('regress-rmw-on', $seller['uuid']);
        $controller = $this->marketplaceAdminController();

        $getResponse = $controller->show(Request::create('/x'), $product['uuid']);
        self::assertSame(200, $getResponse->getStatusCode());
        $payload = json_decode((string) $getResponse->getContent(), true)['data'];
        self::assertSame($seller['uuid'], $payload['seller_uuid']);

        $patchResponse = $controller->update(
            $this->patchRequest($this->productEditableFields($payload)),
            $product['uuid']
        );

        self::assertSame(200, $patchResponse->getStatusCode());
        $row = $this->connection->table('commerce_products')->where('uuid', '=', $product['uuid'])->first();
        self::assertSame($seller['uuid'], $row['seller_uuid'], 'attribution must survive echoing it back unchanged');
        self::assertSame($product['name'], $row['name']);
    }

    public function testAdminUpdateAttemptingADifferentSellerUuidIsIgnoredAttributionUnchanged(): void
    {
        $this->enableMarketplace();
        $this->activateWorkspace(self::TENANT);
        $owner = $this->seedActiveSeller('regress-rmw-real-owner');
        $intruder = $this->seedActiveSeller('regress-rmw-other-seller');
        $product = $this->seedAttributedProduct('regress-rmw-hijack', $owner['uuid']);
        $controller = $this->marketplaceAdminController();

        $patchResponse = $controller->update(
            $this->patchRequest([
                'name' => 'Regress RMW Hijack Renamed',
                'seller_uuid' => $intruder['uuid'],
            ]),
            $product['uuid']
        );

        self::assertSame(200, $patchResponse->getStatusCode());
        $row = $this->connection->table('commerce_products')->where('uuid', '=', $product['uuid'])->first();
        self::assertSame(
            $owner['uuid'],
            $row['seller_uuid'],
            'a body-supplied seller_uuid must never move attribution, even when the update otherwise succeeds'
        );
        self::assertSame('Regress RMW Hijack Renamed', $row['name'], 'every other field in the payload still applies');
    }

    // =====================================================================
    // 6. Partition invariance (design spec §7, MV2 Task 10): behavior
    //    follows the ORDER's own immutable marketplace_partitioned snapshot,
    //    never the workspace's current activeFor() state.
    // =====================================================================

    /**
     * An order placed while the workspace was ACTIVE keeps child-aware
     * fulfillment and keeps exposing `seller_groups` even AFTER the
     * workspace is later deactivated (deactivation is non-destructive,
     * design spec §2.3) -- the order's own `marketplace_partitioned` flag,
     * set once at placement, is what governs, not the workspace's current
     * `activeFor()`.
     */
    public function testPartitionedOrderStaysChildAwareAndKeepsSellerGroupsAfterTheWorkspaceDeactivates(): void
    {
        $this->enableMarketplace();
        $this->activateWorkspace(self::TENANT);
        $sellerA = $this->seedActiveSeller('regress-inv-seller-a');
        $sellerB = $this->seedActiveSeller('regress-inv-seller-b');
        $productA = $this->seedAttributedProduct('regress-inv-prod-a', $sellerA['uuid']);
        $productB = $this->seedAttributedProduct('regress-inv-prod-b', $sellerB['uuid']);
        $stock = new StockRepository();
        $stock->increment($this->context, self::TENANT, (string) $productA['variants'][0]['uuid'], 10);
        $stock->increment($this->context, self::TENANT, (string) $productB['variants'][0]['uuid'], 10);

        $cartService = $this->cartService();
        ['cart' => $cart, 'token' => $token] = $cartService->create($this->context);
        $cart = $cartService->addLine($this->context, $cart, (string) $productA['variants'][0]['uuid'], 1);
        $cartService->addLine($this->context, $cart, (string) $productB['variants'][0]['uuid'], 1);

        $placed = $this->marketplaceCheckoutService()
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');
        $order = $placed['order'];
        self::assertTrue((bool) $order['marketplace_partitioned']);

        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);

        // Deactivate the workspace -- non-destructive, so history stays intact.
        $this->connection->table('commerce_marketplace_settings')
            ->where('tenant_uuid', '=', self::TENANT)
            ->update(['status' => 'disabled']);
        self::assertFalse((new MarketplaceMode())->activeFor($this->context, self::TENANT));

        self::assertCount(
            2,
            $this->connection->table('commerce_seller_orders')
                ->where('order_uuid', '=', (string) $order['uuid'])
                ->get()
        );

        // Fulfillment stays child-aware: the operator fan-out still transitions
        // every child and rolls the parent up to fulfilled.
        $response = $this->marketplaceAdminOrderController()->fulfill(
            new FulfillOrderData(tracking_ref: 'TRACK-INVARIANCE-1'),
            Request::create('/x', 'POST'),
            (string) $order['uuid']
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('fulfilled', $this->orderRow((string) $order['uuid'])['status']);
        foreach (
            $this->connection->table('commerce_seller_orders')
                ->where('order_uuid', '=', (string) $order['uuid'])
                ->get() as $child
        ) {
            self::assertSame('fulfilled', $child['fulfillment_status']);
        }

        // The customer projection still exposes seller_groups.
        $request = Request::create('/commerce/orders/' . $order['order_number'], 'GET');
        $request->headers->set('X-Order-Token', (string) $placed['guest_token']);
        $body = json_decode(
            (string) $this->orderController()->show($request, (string) $order['order_number'])->getContent(),
            true
        );
        self::assertArrayHasKey('seller_groups', $body['data']);
        self::assertCount(2, $body['data']['seller_groups']);
    }

    /**
     * The mirror image: a NON-partitioned order (placed while the master
     * switch was off) stays byte-identical -- zero seller-table queries, no
     * `seller_groups`, direct (non-fan-out) parent fulfill -- even after the
     * workspace is LATER installed and activated. Later activation must
     * never retroactively re-partition an already-placed order.
     */
    public function testNonPartitionedOrderStaysByteIdenticalRegardlessOfLaterActivation(): void
    {
        $placed = $this->placeNonPartitionedOrder();
        $order = $placed['order'];
        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);

        $this->enableMarketplace();
        $this->activateWorkspace(self::TENANT);
        self::assertTrue((new MarketplaceMode())->activeFor($this->context, self::TENANT));

        $this->assertNoMarketplaceQueries(function () use ($order): void {
            $response = $this->marketplaceAdminOrderController()->fulfill(
                new FulfillOrderData(tracking_ref: 'TRACK-INVARIANCE-2'),
                Request::create('/x', 'POST'),
                (string) $order['uuid']
            );
            self::assertSame(200, $response->getStatusCode());
        });
        self::assertSame('fulfilled', $this->orderRow((string) $order['uuid'])['status']);

        $request = Request::create('/commerce/orders/' . $order['order_number'], 'GET');
        $request->headers->set('X-Order-Token', (string) $placed['guest_token']);
        $body = json_decode(
            (string) $this->orderController()->show($request, (string) $order['order_number'])->getContent(),
            true
        );
        self::assertArrayNotHasKey('seller_groups', $body['data']);
        self::assertSame(0, $this->connection->table('commerce_seller_orders')->count());
    }

    // =====================================================================
    // 7. MV4 Task 11 GATES (design spec §2.9/§7): marketplace off / no
    //    `PayoutCollector` bound -- the manual `record()` path stays
    //    byte-identical to its pre-MV4 (MV3) shape, the provider saga path is
    //    cleanly inert (never a fatal error, never a trace in the ledger),
    //    no MV4 route leaks into the manifest, the folded schema default
    //    keeps an old-shaped insert reading back paid/manual, and the new
    //    `commerce_seller_payout_accounts` table joins the SAME maintenance
    //    guarantees the MV1-MV3 tables already have (§4).
    // =====================================================================

    public function testManualPayoutRecordStaysByteIdenticalToItsPreMv4ShapeWithNoCollectorBound(): void
    {
        self::assertFalse((bool) config($this->context, 'commerce.marketplace.enabled', false));

        $ledger = new LedgerRepository();
        $payouts = new PayoutRepository();
        $balances = new SellerBalanceService($ledger);
        // No PayoutCollector, no PayoutAccountService -- exactly the "marketplace off,
        // provider unbound" host configuration this whole file proves invariance under;
        // the manual path never reads either collaborator.
        $service = new PayoutService($payouts, $ledger, new LedgerAccountLock(), $balances, new SellerRepository());

        $seller = 'regressplr001';
        $ledger->post($this->context, self::TENANT, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller($seller),
            'seller_uuid' => $seller,
            'currency' => 'USD',
            'entry_type' => 'sale_credit',
            'amount' => 5000,
            'order_uuid' => 'regressplord1',
            'idempotency_key' => 'regressplord1:' . $seller . ':sale_credit',
        ]);

        $payout = $service->record(
            $this->context,
            self::TENANT,
            $seller,
            'USD',
            2000,
            'idem-regress-payout-off',
            'ext-regress-payout-off',
            'a regression note',
            'operatorregr1'
        );

        // The EXACT pre-MV4 (MV3) field set -- unchanged values, unchanged types.
        self::assertSame($seller, $payout['seller_uuid']);
        self::assertSame('USD', $payout['currency']);
        self::assertSame(2000, (int) $payout['amount']);
        self::assertSame('ext-regress-payout-off', $payout['external_ref']);
        self::assertSame('a regression note', $payout['note']);
        self::assertSame('operatorregr1', $payout['created_by']);
        self::assertSame('idem-regress-payout-off', $payout['idempotency_key']);

        // The folded MV4 columns are now written EXPLICITLY (design spec §3.1) but stay
        // at their inert/manual defaults -- never a provider-shaped row.
        self::assertSame('manual', $payout['method']);
        self::assertSame('paid', $payout['status']);
        self::assertNotNull($payout['completed_at']);

        $persisted = $payouts->findByUuid($this->context, self::TENANT, (string) $payout['uuid']);
        self::assertNotNull($persisted);
        self::assertSame('manual', $persisted['method']);
        self::assertSame('paid', $persisted['status']);
        self::assertNull($persisted['provider']);
        self::assertNull($persisted['provider_ref']);
        self::assertNull($persisted['destination_ref']);
        self::assertFalse((bool) $persisted['retryable']);
        self::assertSame(0, (int) $persisted['attempt_count']);
    }

    public function testProviderPayoutPathIsCleanlyInertAndUnreachableWithNoCollectorBound(): void
    {
        $ledger = new LedgerRepository();
        $payouts = new PayoutRepository();
        $balances = new SellerBalanceService($ledger);
        $service = new PayoutService($payouts, $ledger, new LedgerAccountLock(), $balances, new SellerRepository());

        $seller = 'regressplr002';
        $ledger->post($this->context, self::TENANT, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller($seller),
            'seller_uuid' => $seller,
            'currency' => 'USD',
            'entry_type' => 'sale_credit',
            'amount' => 5000,
            'order_uuid' => 'regressplord2',
            'idempotency_key' => 'regressplord2:' . $seller . ':sale_credit',
        ]);

        $threw = null;
        try {
            $service->execute($this->context, self::TENANT, $seller, 'USD', 1000, 'operatorregr2');
        } catch (PayoutException $e) {
            $threw = $e;
        }
        self::assertInstanceOf(
            PayoutException::class,
            $threw,
            'the provider saga must be cleanly unreachable (a plain 422-mapped DomainException), never a fatal error.'
        );
        self::assertStringContainsString('No payout provider is configured', $threw->getMessage());

        // Inert: no row, no hold -- the provider path leaves no trace whatsoever.
        self::assertSame(
            0,
            $this->connection->table('commerce_payouts')->where('seller_uuid', '=', $seller)->count()
        );
        self::assertSame(
            0,
            $this->connection->table('commerce_marketplace_ledger')
                ->where('seller_uuid', '=', $seller)
                ->where('entry_type', '=', 'reserve_hold')
                ->count()
        );
    }

    public function testProviderPayoutRoutesNeverLeakIntoTheManifestWithTheMasterSwitchOff(): void
    {
        self::assertFalse((bool) config($this->context, 'commerce.marketplace.enabled', false));

        $router = $this->freshRouter();
        $paths = array_map(static fn (array $route): string => (string) $route['path'], $router->getAllRoutes());

        foreach (
            [
                '/commerce/admin/marketplace/payouts/execute',
                '/commerce/admin/marketplace/payouts/{uuid}/retry',
                '/commerce/admin/marketplace/payouts/accounts',
                '/commerce/admin/marketplace/payouts/accounts/sync',
                '/commerce/seller/{sellerUuid}/payouts/accounts',
            ] as $mv4Route
        ) {
            self::assertNotContains(
                $mv4Route,
                $paths,
                "{$mv4Route} must not leak into the manifest with the master switch off."
            );
        }
    }

    public function testOldShapedPayoutInsertOmittingTheNewProviderColumnsStillReadsBackPaidAndManual(): void
    {
        // §3.1: the folded default keeps ANY historical/pre-MV4-shaped insert -- one that
        // never even knew these columns existed -- reading back exactly as a completed
        // manual payout, regardless of the master switch.
        $this->connection->table('commerce_payouts')->insert([
            'uuid' => 'regressoldsh1',
            'tenant_uuid' => self::TENANT,
            'seller_uuid' => 'regressoldsl1',
            'currency' => 'USD',
            'amount' => 4200,
            'external_ref' => 'wire-regress-old',
            'created_by' => 'operatorregr3',
            'idempotency_key' => 'idem-regress-old-shape',
            // Deliberately omits status/method/provider/retryable/attempt_count/... entirely.
        ]);

        $row = $this->connection->table('commerce_payouts')->where('uuid', '=', 'regressoldsh1')->first();
        self::assertNotNull($row);
        self::assertSame('paid', $row['status']);
        self::assertSame('manual', $row['method']);
        self::assertNull($row['provider']);
        self::assertFalse((bool) $row['retryable']);
        self::assertSame(0, (int) $row['attempt_count']);
    }

    public function testDiagnosticsReportListsSellerPayoutAccountsTableAsPresentWithTheSwitchOff(): void
    {
        self::assertFalse((bool) config($this->context, 'commerce.marketplace.enabled', false));

        $present = DiagnosticsReport::build($this->context)['database']['commerce_tables_present'];

        self::assertArrayHasKey(self::MV4_PAYOUT_ACCOUNTS_TABLE, $present);
        self::assertTrue(
            $present[self::MV4_PAYOUT_ACCOUNTS_TABLE],
            'DiagnosticsReport must list commerce_seller_payout_accounts as present regardless of the switch'
        );
    }

    public function testTenantAdoptRekeysSellerPayoutAccountsEvenWhenTheMasterSwitchIsOff(): void
    {
        self::assertFalse(
            (bool) config($this->context, 'commerce.marketplace.enabled', false),
            'this test relies on the master switch being off (the default)'
        );

        $this->connection->table(self::MV4_PAYOUT_ACCOUNTS_TABLE)->insert([
            'uuid' => 'mv4adoptac01',
            'tenant_uuid' => '',
            'seller_uuid' => 'mv4adoptsl01',
            'provider' => 'default',
            'account_ref' => 'acct-mv4-adopt',
        ]);

        $result = (new TenantAdopter())->adopt($this->context, 'tenantMV40099');

        self::assertSame(1, $result['tables'][self::MV4_PAYOUT_ACCOUNTS_TABLE], 'exactly 1 rekeyed row');
        self::assertSame(
            0,
            $this->connection->table(self::MV4_PAYOUT_ACCOUNTS_TABLE)->where('tenant_uuid', '=', '')->count(),
            'no sentinel rows left behind'
        );
        self::assertSame(
            1,
            $this->connection->table(self::MV4_PAYOUT_ACCOUNTS_TABLE)
                ->where('tenant_uuid', '=', 'tenantMV40099')
                ->count(),
            'row must be rekeyed to the adopted tenant'
        );
    }

    // =====================================================================
    // 8. MV5a Task 17 GATES (design spec §2.11/§7): marketplace off, or the
    //    folded 0/0 reserve-policy default, produce zero reserve holds and
    //    zero chargeback rows; no MV5a route leaks into the manifest; the
    //    manual-payout response shape stays byte-identical; all four new
    //    tables join the SAME maintenance guarantees the MV1-MV4 tables
    //    already have (§4).
    // =====================================================================

    /**
     * A full marketplace-OFF order lifecycle -- pay, refund, and a manual
     * payout for an unrelated seller -- must leave every one of the four new
     * MV5a tables completely empty. `assertNoMarketplaceQueries()` above
     * already proves this indirectly (zero QUERIES against these tables
     * across every ordinary/checkout/payment/refund/fulfill/cancel path);
     * this closes the loop with a direct row-COUNT assertion across a real
     * pay+refund+payout sequence, mirroring this file's existing
     * `self::assertSame(0, $this->connection->table('commerce_marketplace_ledger')->count());`
     * convention in `testAdminRefundIssuesZeroMarketplaceTableQueries()`.
     */
    public function testMarketplaceOffProducesZeroReserveHoldsAndZeroChargebackRowsAcrossAFullOrderLifecycle(): void
    {
        self::assertFalse((bool) config($this->context, 'commerce.marketplace.enabled', false));

        $order = $this->placeNonPartitionedOrder()['order'];
        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);
        $refund = $this->refundService()->issue(
            $this->context,
            (string) $order['uuid'],
            new RefundInput(null, 'regress mv5a off refund', [], false),
            'idem-regress-mv5a-off-refund'
        );
        self::assertSame('completed', $refund['status']);

        $ledger = new LedgerRepository();
        $seller = 'regressmv5a01';
        $ledger->post($this->context, self::TENANT, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller($seller),
            'seller_uuid' => $seller,
            'currency' => 'USD',
            'entry_type' => 'sale_credit',
            'amount' => 5000,
            'order_uuid' => 'regressmv5aord',
            'idempotency_key' => 'regressmv5aord:' . $seller . ':sale_credit',
        ]);
        $payoutService = new PayoutService(
            new PayoutRepository(),
            $ledger,
            new LedgerAccountLock(),
            new SellerBalanceService($ledger),
            new SellerRepository()
        );
        $payoutService->record(
            $this->context,
            self::TENANT,
            $seller,
            'USD',
            1000,
            'idem-regress-mv5a-off-payout',
            'ext-regress-mv5a-off',
            null,
            'operatorregr4'
        );

        foreach (self::MV5A_TABLES as $table) {
            self::assertSame(
                0,
                $this->connection->table($table)->count(),
                "{$table} must have zero rows across a full pay/refund/payout lifecycle with the switch off"
            );
        }
        self::assertSame(
            0,
            $this->connection->table('commerce_marketplace_ledger')
                ->where('entry_type', '=', 'reserve_hold')
                ->count(),
            'zero reserve holds must ever post with the master switch off'
        );
    }

    /**
     * The folded `010` defaults (`reserve_bps=0`, `reserve_days=0` -- design
     * spec §2.1/§3.1: an UNCONFIGURED workspace, never having called
     * `ReservePolicyService::setWorkspace()`) must keep a real, fully settled
     * seller reserve-free -- even with the {@see ReserveService} collaborator
     * GENUINELY wired into {@see LedgerPostingService} (never merely absent,
     * mirroring this file's own "prove the REAL branch condition" discipline
     * for the MV2/MV3 zero-query proofs above).
     */
    public function testFoldedReservePolicyDefaultsKeepSettledSellersReserveFreeWithMarketplaceEnabled(): void
    {
        $this->enableMarketplace();
        $this->activateWorkspace(self::TENANT);
        $seller = $this->seedActiveSeller('regress-reserve-free');
        $product = $this->seedAttributedProduct('regress-reserve-free-prod', $seller['uuid']);
        (new StockRepository())->increment(
            $this->context,
            self::TENANT,
            (string) $product['variants'][0]['uuid'],
            10
        );

        $cartService = $this->cartService();
        ['cart' => $cart, 'token' => $token] = $cartService->create($this->context);
        $cartService->addLine($this->context, $cart, (string) $product['variants'][0]['uuid'], 1);

        $placed = $this->marketplaceCheckoutService()
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');
        $order = $placed['order'];
        self::assertTrue((bool) $order['marketplace_partitioned']);

        // Sanity: the workspace has never had an explicit reserve policy set --
        // it resolves to the folded 010 default (0 bps / 0 days), design spec
        // §2.1's "inert by construction" posture for an unconfigured install.
        $settings = $this->connection->table('commerce_marketplace_settings')
            ->where('tenant_uuid', '=', self::TENANT)
            ->first();
        self::assertNotNull($settings);
        self::assertSame(0, (int) $settings['reserve_bps']);
        self::assertSame(0, (int) $settings['reserve_days']);

        $this->reserveWiredPaymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);

        self::assertSame('paid', $this->orderRow((string) $order['uuid'])['status']);
        self::assertGreaterThan(
            0,
            $this->connection->table('commerce_marketplace_ledger')
                ->where('entry_type', '=', 'sale_credit')
                ->count(),
            'sanity: settlement actually posted'
        );
        self::assertSame(
            0,
            $this->connection->table('commerce_seller_reserves')->count(),
            'the folded 0/0 default must keep a settled seller reserve-free even with ReserveService wired'
        );
        self::assertSame(
            0,
            $this->connection->table('commerce_marketplace_ledger')
                ->where('entry_type', '=', 'reserve_hold')
                ->count()
        );
    }

    /**
     * The manual `PayoutService::record()` response row -- the ONE surface
     * MV5a's new debt gate ({@see PayoutService::refuseIfInDebt()}) sits in
     * front of -- must carry EXACTLY its pre-MV5a field set when no debt
     * exists (the master-switch-off / reserve-inert posture): no new field
     * (`debt`, `reserved`, ...) leaked onto the flat payout row.
     */
    public function testManualPayoutResponseRowStaysByteIdenticalToItsPreMv5aFieldSetWithNoDebt(): void
    {
        self::assertFalse((bool) config($this->context, 'commerce.marketplace.enabled', false));

        $ledger = new LedgerRepository();
        $seller = 'regressmv5ajs1';
        $ledger->post($this->context, self::TENANT, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller($seller),
            'seller_uuid' => $seller,
            'currency' => 'USD',
            'entry_type' => 'sale_credit',
            'amount' => 5000,
            'order_uuid' => 'regressmv5ajord',
            'idempotency_key' => 'regressmv5ajord:' . $seller . ':sale_credit',
        ]);
        $payoutService = new PayoutService(
            new PayoutRepository(),
            $ledger,
            new LedgerAccountLock(),
            new SellerBalanceService($ledger),
            new SellerRepository()
        );

        $payout = $payoutService->record(
            $this->context,
            self::TENANT,
            $seller,
            'USD',
            1000,
            'idem-regress-mv5a-json-payout',
            'ext-regress-mv5a-json',
            null,
            'operatorregr5'
        );

        self::assertSame(
            [
                'uuid', 'tenant_uuid', 'seller_uuid', 'currency', 'amount', 'external_ref', 'note', 'created_by',
                'idempotency_key', 'method', 'status', 'completed_at',
            ],
            array_keys($payout),
            'the manual payout row shape must be byte-identical to its pre-MV5a field set -- no new field leaked'
        );
    }

    public function testReserveChargebackRoutesNeverLeakIntoTheManifestWithTheMasterSwitchOff(): void
    {
        self::assertFalse((bool) config($this->context, 'commerce.marketplace.enabled', false));

        $router = $this->freshRouter();
        $paths = array_map(static fn (array $route): string => (string) $route['path'], $router->getAllRoutes());

        foreach (
            [
                '/commerce/admin/marketplace/settings/reserves',
                '/commerce/admin/marketplace/sellers/{uuid}/reserve-policy',
                '/commerce/admin/marketplace/chargebacks',
                '/commerce/admin/marketplace/chargebacks/{uuid}/attribution',
                '/commerce/admin/marketplace/reserves/holds',
                '/commerce/admin/marketplace/reserves/{uuid}/release',
                '/commerce/admin/marketplace/sellers/{uuid}/debt/forgive',
                '/commerce/admin/marketplace/sellers/{uuid}/reserves',
                '/commerce/seller/{sellerUuid}/financials/reserves',
            ] as $mv5aRoute
        ) {
            self::assertNotContains(
                $mv5aRoute,
                $paths,
                "{$mv5aRoute} must not leak into the manifest with the master switch off."
            );
        }
    }

    public function testDiagnosticsReportListsAllFourMv5aTablesAsPresentWithTheSwitchOff(): void
    {
        self::assertFalse((bool) config($this->context, 'commerce.marketplace.enabled', false));

        $present = DiagnosticsReport::build($this->context)['database']['commerce_tables_present'];

        foreach (self::MV5A_TABLES as $table) {
            self::assertArrayHasKey($table, $present);
            self::assertTrue(
                $present[$table],
                "DiagnosticsReport must list {$table} as present regardless of the switch"
            );
        }
    }

    /**
     * MV5a Task 17: `commerce:tenancy:adopt` rekeys the four new
     * reserve/chargeback tables too -- {@see DiagnosticsReport::tenantTables()}
     * already lists them unconditionally (design spec §3.2/§3.3), so
     * {@see TenantAdopter} picks them up mechanically; this pins that
     * behaviorally, mirroring the MV3/MV4 siblings above exactly, switch off
     * (the default).
     */
    public function testTenantAdoptRekeysAllFourMv5aTablesEvenWhenTheMasterSwitchIsOff(): void
    {
        self::assertFalse(
            (bool) config($this->context, 'commerce.marketplace.enabled', false),
            'this test relies on the master switch being off (the default)'
        );

        $now = $this->connection->getDriver()->formatDateTime();

        $this->connection->table('commerce_seller_reserves')->insert([
            'uuid' => 'mv5adoptrsv1',
            'tenant_uuid' => '',
            'seller_uuid' => 'mv5adoptsel1',
            'currency' => 'USD',
            'source_kind' => 'manual',
            'idempotency_key' => 'mv5-adopt-reserve-1',
            'amount' => 500,
            'reserve_bps_snapshot' => 0,
            'reserve_days_snapshot' => 0,
            'held_at' => $now,
        ]);
        $this->connection->table('commerce_reserve_policy_events')->insert([
            'uuid' => 'mv5adoptevt1',
            'tenant_uuid' => '',
            'subject_kind' => 'workspace',
            'subject_uuid' => 'mv5adoptwsp1',
            'actor_uuid' => 'mv5adoptop01',
            'before_policy' => json_encode(['reserve_bps' => 0, 'reserve_days' => 0], JSON_THROW_ON_ERROR),
            'after_policy' => json_encode(['reserve_bps' => 250, 'reserve_days' => 14], JSON_THROW_ON_ERROR),
        ]);
        $this->connection->table('commerce_chargebacks')->insert([
            'uuid' => 'mv5adoptcb01',
            'tenant_uuid' => '',
            'provider' => 'payvia',
            'provider_event_id' => 'mv5-adopt-evt-1',
            'payment_reference' => 'mv5-adopt-pay-1',
            'amount' => 2500,
            'currency' => 'USD',
            'occurred_at' => $now,
        ]);
        $this->connection->table('commerce_chargeback_lines')->insert([
            'uuid' => 'mv5adoptcbl1',
            'tenant_uuid' => '',
            'chargeback_uuid' => 'mv5adoptcb01',
            'order_line_uuid' => 'mv5adoptoln1',
            'seller_uuid' => 'mv5adoptsel1',
            'amount' => 2500,
        ]);

        $result = (new TenantAdopter())->adopt($this->context, 'tenantMV5a099');

        foreach (self::MV5A_TABLES as $table) {
            self::assertSame(1, $result['tables'][$table], "{$table} must report exactly 1 rekeyed row");
            self::assertSame(
                0,
                $this->connection->table($table)->where('tenant_uuid', '=', '')->count(),
                "{$table} must have no sentinel rows left behind"
            );
            self::assertSame(
                1,
                $this->connection->table($table)->where('tenant_uuid', '=', 'tenantMV5a099')->count(),
                "{$table} row must be rekeyed to the adopted tenant"
            );
        }
    }

    // =====================================================================
    // 9. MV5b Task 7 GATES (design spec §5/§7): no suspension ⇒ byte-identical
    //    storefront listing/search/direct-read + cart + payout behavior; the
    //    centralized buyer-availability predicate (§2.3) is a genuine no-op
    //    for a sellerless product and a product owned by an ACTIVE seller;
    //    the shared findLive*() admin/internal reads stay unfiltered even
    //    for a SUSPENDED seller's product; the new lifecycle-audit table
    //    joins the SAME maintenance guarantees the MV1-MV5a tables already
    //    have (§4); the lifecycle-history read is tenant-bound and paginated
    //    (§4/§6). The zero-new-query half of this gate is closed above --
    //    self::MV5B_LIFECYCLE_TABLE now joins assertNoMarketplaceQueries()'s
    //    table list, so every ordinary path exercised in sections 2/2b above
    //    is ALSO proven to never touch it.
    // =====================================================================

    /**
     * Design spec §2.3: the centralized buyer-availability predicate
     * (`seller_uuid IS NULL OR seller.status = 'active'`) never excludes a
     * sellerless (ordinary, non-marketplace-store) product, and never
     * excludes a product owned by a seller that is currently `active` --
     * with marketplace GENUINELY enabled and the predicate GENUINELY
     * reachable (never merely a missing collaborator), both products stay
     * present across storefront listing, direct read, and cart add, exactly
     * as pre-MV5b.
     */
    public function testSellerActivePredicateIsANoOpForSellerlessAndActiveSellerProductsAcrossStorefrontAndCart(): void
    {
        $this->enableMarketplace();
        $this->activateWorkspace(self::TENANT);
        $seller = $this->seedActiveSeller('regress-mv5b-noop-seller');
        // seedLegacyProduct() already stocks its variant (100 units); seedAttributedProduct() does not.
        $sellerlessProduct = $this->seedLegacyProduct('regress-mv5b-noop-sellerless');
        $sellerProduct = $this->seedAttributedProduct('regress-mv5b-noop-attributed', $seller['uuid']);
        (new StockRepository())->increment(
            $this->context,
            self::TENANT,
            (string) $sellerProduct['variants'][0]['uuid'],
            10
        );

        $controller = $this->productController();

        $indexBody = json_decode((string) $controller->index(new ProductListQuery())->getContent(), true);
        $slugs = array_column($indexBody['data'], 'slug');
        self::assertContains('regress-mv5b-noop-sellerless', $slugs, 'a sellerless product must never be excluded');
        self::assertContains(
            'regress-mv5b-noop-attributed',
            $slugs,
            "an ACTIVE seller's product must never be excluded"
        );

        self::assertSame(
            200,
            $controller->show(Request::create('/x'), 'regress-mv5b-noop-sellerless')->getStatusCode()
        );
        self::assertSame(
            200,
            $controller->show(Request::create('/x'), 'regress-mv5b-noop-attributed')->getStatusCode()
        );

        $cartService = $this->cartService();
        ['cart' => $cart, 'token' => $token] = $cartService->create($this->context);
        $cartService->addLine($this->context, $cart, (string) $sellerlessProduct['variants'][0]['uuid'], 1);
        $cartService->addLine($this->context, $cart, (string) $sellerProduct['variants'][0]['uuid'], 1);

        self::assertSame(
            2,
            $this->connection->table('commerce_cart_lines')->where('cart_uuid', '=', $cart['uuid'])->count(),
            'both lines must add successfully -- the predicate never rejects a sellerless or active-seller product'
        );
    }

    /**
     * Design spec §2.3: `findLiveByUuid()`/`findLiveBySlug()` stay
     * tombstone-only and deliberately carry NO seller-status predicate --
     * they remain the shared read for admin/catalog-mutation/importer/
     * relationship/inventory/media/download/attribution paths, which must
     * keep reaching a SUSPENDED seller's products. Only the dedicated
     * buyer-availability reads exclude them.
     */
    public function testFindLiveReadsStayUnfilteredForASuspendedSellersProductWhileBuyerReadsExcludeIt(): void
    {
        $this->enableMarketplace();
        $this->activateWorkspace(self::TENANT);
        $seller = $this->seedActiveSeller('regress-mv5b-findlive-seller');
        $product = $this->seedAttributedProduct('regress-mv5b-findlive-prod', $seller['uuid']);

        $this->sellerService()->suspend(
            $this->context,
            self::TENANT,
            $seller['uuid'],
            'Regression findLive* probe.',
            'operatorregr6'
        );

        $products = new ProductRepository();

        $byUuid = $products->findLiveByUuid($this->context, self::TENANT, (string) $product['uuid']);
        self::assertNotNull($byUuid, 'findLiveByUuid() must remain unfiltered for a suspended seller\'s product');
        $bySlug = $products->findLiveBySlug($this->context, self::TENANT, (string) $product['slug']);
        self::assertNotNull($bySlug, 'findLiveBySlug() must remain unfiltered for a suspended seller\'s product');

        self::assertNull(
            $products->findBuyerAvailableByUuid($this->context, self::TENANT, (string) $product['uuid']),
            'the dedicated buyer-availability read must exclude a suspended seller\'s product'
        );
        self::assertNull(
            $products->findBuyerAvailableBySlug($this->context, self::TENANT, (string) $product['slug'])
        );
    }

    /**
     * The manual payout row shape (design spec §2.7's new revision-claim gate
     * sits in front of, but never alters) must stay byte-identical to its
     * pre-MV5b field set for a REAL, currently-`active` seller -- not merely
     * the "untracked seller_uuid" case {@see testManualPayoutResponseRowStaysByteIdenticalToItsPreMv5aFieldSetWithNoDebt()}
     * already proves.
     */
    public function testManualPayoutRecordStaysByteIdenticalWhenARealActiveSellerExists(): void
    {
        $seller = $this->seedActiveSeller('regress-mv5b-payout-seller');

        $ledger = new LedgerRepository();
        $ledger->post($this->context, self::TENANT, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller($seller['uuid']),
            'seller_uuid' => $seller['uuid'],
            'currency' => 'USD',
            'entry_type' => 'sale_credit',
            'amount' => 5000,
            'order_uuid' => 'regressmv5border',
            'idempotency_key' => 'regressmv5border:' . $seller['uuid'] . ':sale_credit',
        ]);
        $payoutService = new PayoutService(
            new PayoutRepository(),
            $ledger,
            new LedgerAccountLock(),
            new SellerBalanceService($ledger),
            new SellerRepository()
        );

        $payout = $payoutService->record(
            $this->context,
            self::TENANT,
            $seller['uuid'],
            'USD',
            1000,
            'idem-regress-mv5b-payout',
            'ext-regress-mv5b-payout',
            null,
            'operatorregr7'
        );

        self::assertSame(
            [
                'uuid', 'tenant_uuid', 'seller_uuid', 'currency', 'amount', 'external_ref', 'note', 'created_by',
                'idempotency_key', 'method', 'status', 'completed_at',
            ],
            array_keys($payout),
            'the manual payout row shape must stay byte-identical for a real, active seller too -- no new field'
        );
        self::assertSame('paid', $payout['status']);
        self::assertSame('manual', $payout['method']);
    }

    public function testDiagnosticsReportListsSellerLifecycleEventsTableAsPresentWithTheSwitchOff(): void
    {
        self::assertFalse((bool) config($this->context, 'commerce.marketplace.enabled', false));

        $present = DiagnosticsReport::build($this->context)['database']['commerce_tables_present'];

        self::assertArrayHasKey(self::MV5B_LIFECYCLE_TABLE, $present);
        self::assertTrue(
            $present[self::MV5B_LIFECYCLE_TABLE],
            'DiagnosticsReport must list commerce_seller_lifecycle_events as present regardless of the switch'
        );
    }

    /**
     * MV5b Task 7: `commerce:tenancy:adopt` rekeys the new lifecycle-audit
     * table too -- {@see DiagnosticsReport::tenantTables()} already lists it
     * unconditionally (design spec §3), so {@see TenantAdopter} picks it up
     * mechanically; this pins that behaviorally, mirroring the MV3/MV4/MV5a
     * siblings above exactly, switch off (the default).
     */
    public function testTenantAdoptRekeysSellerLifecycleEventsEvenWhenTheMasterSwitchIsOff(): void
    {
        self::assertFalse(
            (bool) config($this->context, 'commerce.marketplace.enabled', false),
            'this test relies on the master switch being off (the default)'
        );

        $this->connection->table(self::MV5B_LIFECYCLE_TABLE)->insert([
            'uuid' => 'mv5badoptev1',
            'tenant_uuid' => '',
            'seller_uuid' => 'mv5badoptsl1',
            'from_status' => 'active',
            'to_status' => 'suspended',
            'actor_uuid' => 'mv5badoptop1',
            'reason' => 'Regression adopt probe.',
        ]);

        $result = (new TenantAdopter())->adopt($this->context, 'tenantMV5b099');

        self::assertSame(1, $result['tables'][self::MV5B_LIFECYCLE_TABLE], 'exactly 1 rekeyed row');
        self::assertSame(
            0,
            $this->connection->table(self::MV5B_LIFECYCLE_TABLE)->where('tenant_uuid', '=', '')->count(),
            'no sentinel rows left behind'
        );
        self::assertSame(
            1,
            $this->connection->table(self::MV5B_LIFECYCLE_TABLE)
                ->where('tenant_uuid', '=', 'tenantMV5b099')
                ->count(),
            'row must be rekeyed to the adopted tenant'
        );
    }

    /**
     * Design spec §4/§6: the operator lifecycle-history read is tenant-bound
     * and paginated, newest-first -- a repository-level pin of the SAME
     * guarantee {@see SellerLifecycleSurfaceTest} already proves end to end
     * over a real route, closing the loop within this file's own regression
     * harness.
     */
    public function testSellerLifecycleHistoryIsPaginatedNewestFirstAndTenantBound(): void
    {
        $seller = $this->seedActiveSeller('regress-mv5b-history-seller');
        $events = new SellerLifecycleEventRepository();

        for ($i = 1; $i <= 3; $i++) {
            $events->insert($this->context, self::TENANT, [
                'uuid' => 'regressmv5bh' . $i,
                'seller_uuid' => $seller['uuid'],
                'from_status' => 'active',
                'to_status' => 'suspended',
                'actor_uuid' => 'operatorregr8',
                'reason' => "Regression history event {$i}.",
            ]);
        }
        // A different-tenant event for the SAME seller uuid must never leak in.
        $events->insert($this->context, 'otherTenantMV5b', [
            'uuid' => 'regressmv5bhx',
            'seller_uuid' => $seller['uuid'],
            'from_status' => 'active',
            'to_status' => 'suspended',
            'actor_uuid' => 'operatorregr9',
            'reason' => 'Cross-tenant event that must never leak in.',
        ]);

        $page1 = $events->paginatedForSeller($this->context, self::TENANT, $seller['uuid'], 1, 2);
        self::assertSame(3, $page1['total'], 'total must be tenant-bound (excludes the cross-tenant row)');
        self::assertCount(2, $page1['items']);
        self::assertSame('Regression history event 3.', $page1['items'][0]['reason'], 'newest first');
        self::assertSame('Regression history event 2.', $page1['items'][1]['reason']);

        $page2 = $events->paginatedForSeller($this->context, self::TENANT, $seller['uuid'], 2, 2);
        self::assertSame(3, $page2['total']);
        self::assertCount(1, $page2['items']);
        self::assertSame('Regression history event 1.', $page2['items'][0]['reason']);
    }

    // =====================================================================
    // 10. MV5c-1 Task 7 GATES (design spec §2.7/§2.10/§2.11/§6): the three
    //    new seller-API-key tables join the SAME maintenance guarantees the
    //    MV1-MV5b tables already have (§4); no new route leaks into the
    //    manifest with the master switch off; immediate revalidation for a
    //    KEY specifically (not merely a session, already proven by
    //    `SellerApiKeyAuthTest`, MV5c-1 Task 4) -- a suspended seller's key
    //    reaches ONLY the same 5 `allow_suspended` routes a session reaches
    //    (`SuspendedSellerAuthorizationTest`, MV5b Task 5), and a closed
    //    seller's key reaches NOTHING (its membership is deactivated by a
    //    real `close()`, design spec §2.11); the retention-cleanup command.
    //    Off-invariance's other two legs -- the authorizer's zero-query
    //    no-op for `auth_method != 'api_key'` and a non-Commerce framework
    //    key getting no seller authority -- are already proven end to end by
    //    `SellerApiKeyAuthTest::testSessionRequestIssuesZeroKeyTableQueries()`/
    //    `testNonCommerceFrameworkKeyOwnedByAnActiveSellerMemberIsStillDenied()`
    //    (MV5c-1 Task 4); not duplicated here.
    // =====================================================================

    public function testSellerApiKeyRoutesNeverLeakIntoTheManifestWithTheMasterSwitchOff(): void
    {
        self::assertFalse((bool) config($this->context, 'commerce.marketplace.enabled', false));

        $router = $this->freshRouter();
        $paths = array_map(static fn (array $route): string => (string) $route['path'], $router->getAllRoutes());

        foreach (
            [
                '/commerce/seller/{sellerUuid}/api-keys',
                '/commerce/seller/{sellerUuid}/api-keys/{lineageUuid}/rotate',
                '/commerce/seller/{sellerUuid}/api-keys/{lineageUuid}/revoke',
            ] as $mv5c1Route
        ) {
            self::assertNotContains(
                $mv5c1Route,
                $paths,
                "{$mv5c1Route} must not leak into the manifest with the master switch off."
            );
        }
    }

    public function testDiagnosticsReportListsAllThreeSellerApiKeyTablesAsPresentWithTheSwitchOff(): void
    {
        self::assertFalse((bool) config($this->context, 'commerce.marketplace.enabled', false));

        $present = DiagnosticsReport::build($this->context)['database']['commerce_tables_present'];

        foreach (self::MV5C1_TABLES as $table) {
            self::assertArrayHasKey($table, $present);
            self::assertTrue(
                $present[$table],
                "DiagnosticsReport must list {$table} as present regardless of the switch"
            );
        }
    }

    /**
     * MV5c-1 Task 7: `commerce:tenancy:adopt` rekeys all three new
     * seller-API-key tables too -- {@see DiagnosticsReport::tenantTables()}
     * already lists them unconditionally (design spec §3), so
     * {@see TenantAdopter} picks them up mechanically; this pins that
     * behaviorally, mirroring the MV3/MV4/MV5a/MV5b siblings above exactly,
     * switch off (the default).
     */
    public function testTenantAdoptRekeysAllThreeSellerApiKeyTablesEvenWhenTheMasterSwitchIsOff(): void
    {
        self::assertFalse(
            (bool) config($this->context, 'commerce.marketplace.enabled', false),
            'this test relies on the master switch being off (the default)'
        );

        $this->connection->table('commerce_seller_api_keys')->insert([
            'uuid' => 'mv5c1adptln1',
            'tenant_uuid' => '',
            'seller_uuid' => 'mv5c1adptsl1',
            'subject_user_uuid' => 'mv5c1adptsu1',
            'declared_scopes' => json_encode(['commerce.seller.orders.read'], JSON_THROW_ON_ERROR),
            'name' => 'Adopt probe key',
            'status' => 'active',
            'current_credential_uuid' => 'mv5c1adptcr1',
            'created_by' => 'mv5c1adptsu1',
        ]);
        $this->connection->table('commerce_seller_api_key_credentials')->insert([
            'uuid' => 'mv5c1adptcr1',
            'tenant_uuid' => '',
            'lineage_uuid' => 'mv5c1adptln1',
            'framework_key_uuid' => 'mv5c1adptfk1',
            'generation' => 1,
            'relationship' => 'current',
        ]);
        $this->connection->table('commerce_seller_api_key_events')->insert([
            'uuid' => 'mv5c1adptev1',
            'tenant_uuid' => '',
            'lineage_uuid' => 'mv5c1adptln1',
            'seller_uuid' => 'mv5c1adptsl1',
            'subject_user_uuid' => 'mv5c1adptsu1',
            'action' => 'created',
            'actor_uuid' => 'mv5c1adptsu1',
        ]);

        $result = (new TenantAdopter())->adopt($this->context, 'tenantMV5c1099');

        foreach (self::MV5C1_TABLES as $table) {
            self::assertSame(1, $result['tables'][$table], "{$table} must report exactly 1 rekeyed row");
            self::assertSame(
                0,
                $this->connection->table($table)->where('tenant_uuid', '=', '')->count(),
                "{$table} must have no sentinel rows left behind"
            );
            self::assertSame(
                1,
                $this->connection->table($table)->where('tenant_uuid', '=', 'tenantMV5c1099')->count(),
                "{$table} row must be rekeyed to the adopted tenant"
            );
        }
    }

    /**
     * Design spec §2.7/§2.11: suspension takes effect on the VERY NEXT
     * key-authenticated request -- the key reaches ONLY the same 5
     * `allow_suspended` routes a session reaches (MV5b), through the SAME
     * `SellerMemberMiddleware` lifecycle gate a session goes through, no
     * key-specific suspension logic. The key declares every capability the 5
     * routes need (`orders.read`/`orders.fulfill`/`reports.read`) so every
     * denial proven below is genuinely the LIFECYCLE gate, never merely a
     * missing declared scope.
     */
    public function testSuspendedSellerApiKeyReachesOnlyTheFiveAllowSuspendedRoutesImmediately(): void
    {
        $this->enableMarketplace();
        $this->activateWorkspace(self::TENANT);
        $seller = $this->seedActiveSeller('regress-mv5c1-susp-key');
        $product = $this->seedAttributedProduct('regress-mv5c1-susp-key-p', $seller['uuid']);
        (new StockRepository())->increment($this->context, self::TENANT, (string) $product['variants'][0]['uuid'], 10);

        $cartService = $this->cartService();
        ['cart' => $cart, 'token' => $token] = $cartService->create($this->context);
        $cartService->addLine($this->context, $cart, (string) $product['variants'][0]['uuid'], 1);
        $placed = $this->marketplaceCheckoutService()
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');
        $order = $placed['order'];
        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);
        $sellerOrder = $this->connection->table('commerce_seller_orders')
            ->where('order_uuid', '=', $order['uuid'])->first();

        $ownerUuid = $this->ownerUuidFor($seller['uuid']);
        $scopes = [
            'commerce.seller.orders.read',
            'commerce.seller.orders.fulfill',
            'commerce.seller.reports.read',
        ];
        $this->seedApiKeyBinding($seller['uuid'], $ownerUuid, $scopes, 'fwKeyMv5c1S1');

        $this->sellerService()->suspend(
            $this->context,
            self::TENANT,
            $seller['uuid'],
            'MV5c-1 key regression probe.',
            'operatorMv5c'
        );

        $router = $this->freshSellerKeyRouter();
        $key = fn (string $method, string $uri, array $body = []): Request => $this->apiKeyRequestFor(
            $ownerUuid,
            'fwKeyMv5c1S1',
            $scopes,
            $method,
            $uri,
            $body
        );

        self::assertSame(
            200,
            $this->dispatch($router, $key('GET', "/commerce/seller/{$seller['uuid']}/orders"))->getStatusCode(),
            'orders.read (list) must stay reachable while suspended'
        );
        self::assertSame(
            200,
            $this->dispatch(
                $router,
                $key('GET', "/commerce/seller/{$seller['uuid']}/orders/{$sellerOrder['uuid']}")
            )->getStatusCode(),
            'orders.read (show) must stay reachable while suspended'
        );
        self::assertSame(
            200,
            $this->dispatch($router, $key(
                'POST',
                "/commerce/seller/{$seller['uuid']}/orders/{$sellerOrder['uuid']}/fulfill",
                ['carrier' => 'UPS', 'tracking_number' => 'MV5C1TRACK1', 'tracking_url' => null]
            ))->getStatusCode(),
            'orders.fulfill must stay reachable while suspended'
        );
        self::assertSame(
            200,
            $this->dispatch(
                $router,
                $key('GET', "/commerce/seller/{$seller['uuid']}/financials/balance")
            )->getStatusCode(),
            'reports.read (balance) must stay reachable while suspended'
        );
        self::assertSame(
            200,
            $this->dispatch(
                $router,
                $key('GET', "/commerce/seller/{$seller['uuid']}/financials/reserves")
            )->getStatusCode(),
            'reports.read (reserves) must stay reachable while suspended'
        );

        // An UNMARKED route using a capability the key's OWN scope already
        // satisfies (reports.read) still fails closed -- proving this is the
        // LIFECYCLE gate refusing, never merely a scope check.
        self::assertSame(
            409,
            $this->dispatch(
                $router,
                $key('GET', "/commerce/seller/{$seller['uuid']}/financials/report")
            )->getStatusCode(),
            'an unmarked route must be 409 for a suspended seller\'s key, even one its own scope satisfies'
        );
    }

    /**
     * Design spec §2.11: "a closed seller's keys reach nothing (deactivated
     * memberships)" -- a real {@see SellerService::close()} deactivates
     * every membership row for the seller, so the key's subject no longer
     * has ANY active membership at all; the non-revealing 404 fires at
     * `SellerMemberMiddleware` step 2 for EVERY route, read or mutation
     * alike -- never the closed-seller "reads stay" allowance a SESSION
     * still gets (that allowance is reached only via an ACTIVE membership,
     * which a key-holder no longer has once closed).
     */
    public function testClosedSellerApiKeyReachesNothingBecauseItsMembershipIsDeactivated(): void
    {
        $this->enableMarketplace();
        $this->activateWorkspace(self::TENANT);
        $seller = $this->seedActiveSeller('regress-mv5c1-closed-key');
        // No product attributed -- close() carries a live-products guard.

        $ownerUuid = $this->ownerUuidFor($seller['uuid']);
        // Declares BOTH capabilities the two probes below need -- the point of
        // this test is that a deactivated MEMBERSHIP refuses even a request
        // the key's own declared scope satisfies, never a scope mismatch.
        $scopes = ['commerce.seller.orders.read', 'commerce.seller.orders.fulfill'];
        $this->seedApiKeyBinding($seller['uuid'], $ownerUuid, $scopes, 'fwKeyMv5c1C1');

        $this->sellerService()->close(
            $this->context,
            self::TENANT,
            $seller['uuid'],
            'MV5c-1 key regression probe.',
            'operatorMv5c'
        );

        $router = $this->freshSellerKeyRouter();

        $readResponse = $this->dispatch($router, $this->apiKeyRequestFor(
            $ownerUuid,
            'fwKeyMv5c1C1',
            $scopes,
            'GET',
            "/commerce/seller/{$seller['uuid']}/orders"
        ));
        self::assertSame(404, $readResponse->getStatusCode(), 'a closed seller\'s key must reach NOTHING, not even a read');

        $mutateResponse = $this->dispatch($router, $this->apiKeyRequestFor(
            $ownerUuid,
            'fwKeyMv5c1C1',
            $scopes,
            'POST',
            "/commerce/seller/{$seller['uuid']}/orders/irrelevantOrd1/fulfill",
            ['carrier' => 'UPS', 'tracking_number' => 'MV5C1TRACK2', 'tracking_url' => null]
        ));
        self::assertSame(404, $mutateResponse->getStatusCode());
    }

    /**
     * Design spec §2.10: {@see PurgeApiKeyDenialsCommand} deletes ONLY
     * `auth_denied` rows older than the configured retention window
     * (default 90 days, `commerce.marketplace.api_keys.auth_denied_retention_days`)
     * -- a fresh `auth_denied` row survives, a permanent mutation event
     * (`created`/`rotated`/`revoked`) is NEVER touched regardless of age, and
     * the sweep is a single cross-tenant statement (no per-tenant retention
     * override exists to honor).
     */
    public function testPurgeApiKeyDenialsCommandDeletesOnlyStaleAuthDeniedRowsHonoringDefaultRetention(): void
    {
        self::assertSame(
            90,
            (int) config($this->context, 'commerce.marketplace.api_keys.auth_denied_retention_days', 90),
            'sanity: the default retention window must be 90 days'
        );

        $staleCreatedAt = gmdate('Y-m-d H:i:s', strtotime('-91 days'));

        // Fresh auth_denied row (well within retention) -- must survive.
        $this->connection->table('commerce_seller_api_key_events')->insert([
            'uuid' => 'mv5c1purgev1',
            'tenant_uuid' => self::TENANT,
            'lineage_uuid' => 'mv5c1purgeln',
            'seller_uuid' => 'mv5c1purgesl',
            'subject_user_uuid' => 'mv5c1purgesu',
            'action' => 'auth_denied',
            'reason_code' => 'seller_mismatch',
            'bucket_start' => gmdate('Y-m-d H:i:00'),
        ]);
        // Stale auth_denied row (past the default 90-day retention) -- must be purged.
        $this->connection->table('commerce_seller_api_key_events')->insert([
            'uuid' => 'mv5c1purgev2',
            'tenant_uuid' => self::TENANT,
            'lineage_uuid' => 'mv5c1purgeln',
            'seller_uuid' => 'mv5c1purgesl',
            'subject_user_uuid' => 'mv5c1purgesu',
            'action' => 'auth_denied',
            'reason_code' => 'scope_drift',
            'bucket_start' => gmdate('Y-m-d H:i:00', strtotime('-91 days')),
            'created_at' => $staleCreatedAt,
        ]);
        // A permanent mutation event, ALSO 91 days old -- action != auth_denied,
        // so it must NEVER be touched by this sweep regardless of age.
        $this->connection->table('commerce_seller_api_key_events')->insert([
            'uuid' => 'mv5c1purgev3',
            'tenant_uuid' => self::TENANT,
            'lineage_uuid' => 'mv5c1purgeln',
            'seller_uuid' => 'mv5c1purgesl',
            'subject_user_uuid' => 'mv5c1purgesu',
            'action' => 'created',
            'actor_uuid' => 'mv5c1purgesu',
            'created_at' => $staleCreatedAt,
        ]);
        // A different tenant's equally-stale auth_denied row -- the sweep is
        // cross-tenant by design (design spec §2.10), so this must ALSO be purged.
        $this->connection->table('commerce_seller_api_key_events')->insert([
            'uuid' => 'mv5c1purgev4',
            'tenant_uuid' => 'otherTenantK',
            'lineage_uuid' => 'mv5c1purgeln',
            'seller_uuid' => 'mv5c1purgesl',
            'subject_user_uuid' => 'mv5c1purgesu',
            'action' => 'auth_denied',
            'reason_code' => 'capability_denied',
            'bucket_start' => gmdate('Y-m-d H:i:00', strtotime('-91 days')),
            'created_at' => $staleCreatedAt,
        ]);

        $command = new PurgeApiKeyDenialsCommand($this->context->getContainer(), $this->context);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertSame(
            1,
            $this->connection->table('commerce_seller_api_key_events')->where('uuid', '=', 'mv5c1purgev1')->count(),
            'a fresh auth_denied row must survive'
        );
        self::assertSame(
            0,
            $this->connection->table('commerce_seller_api_key_events')->where('uuid', '=', 'mv5c1purgev2')->count(),
            'a stale auth_denied row must be purged'
        );
        self::assertSame(
            1,
            $this->connection->table('commerce_seller_api_key_events')->where('uuid', '=', 'mv5c1purgev3')->count(),
            'a permanent mutation event must never be touched by retention, regardless of age'
        );
        self::assertSame(
            0,
            $this->connection->table('commerce_seller_api_key_events')->where('uuid', '=', 'mv5c1purgev4')->count(),
            'the sweep is cross-tenant -- a different tenant\'s stale auth_denied row is purged too'
        );
    }

    // =====================================================================
    // 11. MV5c-2 Task 8 GATES (design spec §2.4/§2.6/§3/§6): no new route
    //    leaks into the manifest with the master switch off; the five new
    //    seller-webhook tables join the SAME maintenance guarantees the
    //    MV1-MV5c-1 tables already have (§4) -- confirming they are already
    //    registered from Task 2/the zero-query table list above; a REAL
    //    `SellerWebhookOutboxPublisher`, genuinely wired into a business
    //    branch, is a zero-webhook-table-query no-op while the master switch
    //    is off and stays byte-identical; an ACTIVE marketplace with no
    //    matching endpoint runs exactly one bounded probe and writes
    //    nothing; the per-seller isolation poison-string proof at the REAL
    //    checkout BRANCH level, closed by a REAL seller HTTP delivery-history
    //    read (`SellerWebhookController::deliveries()`) that never leaks a
    //    poison marker either; the retention purge command.
    // =====================================================================

    public function testSellerWebhookRoutesNeverLeakIntoTheManifestWithTheMasterSwitchOff(): void
    {
        self::assertFalse((bool) config($this->context, 'commerce.marketplace.enabled', false));

        $router = $this->freshRouter();
        $paths = array_map(static fn (array $route): string => (string) $route['path'], $router->getAllRoutes());

        foreach (
            [
                '/commerce/seller/{sellerUuid}/webhooks',
                '/commerce/seller/{sellerUuid}/webhooks/{uuid}',
                '/commerce/seller/{sellerUuid}/webhooks/{uuid}/rotate-secret',
                '/commerce/seller/{sellerUuid}/webhooks/{uuid}/disable',
                '/commerce/seller/{sellerUuid}/webhooks/{uuid}/enable',
                '/commerce/seller/{sellerUuid}/webhooks/{uuid}/deliveries',
                '/commerce/seller/{sellerUuid}/webhooks/{uuid}/deliveries/{deliveryUuid}/replay',
            ] as $mv5c2Route
        ) {
            self::assertNotContains(
                $mv5c2Route,
                $paths,
                "{$mv5c2Route} must not leak into the manifest with the master switch off."
            );
        }
    }

    /**
     * Confirms the five new tables are ALREADY registered from Task 2 (they
     * already joined `assertNoMarketplaceQueries()`'s table list above) --
     * this is the SAME `DiagnosticsReport::tenantTables()`-driven maintenance
     * guarantee every prior MV's own table set already carries.
     */
    public function testDiagnosticsReportListsAllFiveSellerWebhookTablesAsPresentWithTheSwitchOff(): void
    {
        self::assertFalse((bool) config($this->context, 'commerce.marketplace.enabled', false));

        $present = DiagnosticsReport::build($this->context)['database']['commerce_tables_present'];

        foreach (self::MV5C2_TABLES as $table) {
            self::assertArrayHasKey($table, $present);
            self::assertTrue(
                $present[$table],
                "DiagnosticsReport must list {$table} as present regardless of the switch"
            );
        }
    }

    /**
     * MV5c-2 Task 8: `commerce:tenancy:adopt` rekeys all five new
     * seller-webhook tables too -- {@see DiagnosticsReport::tenantTables()}
     * already lists them unconditionally (confirmed already registered from
     * Task 2), so {@see TenantAdopter} picks them up mechanically; this pins
     * that behaviorally, mirroring the MV3-MV5c-1 siblings above exactly,
     * switch off (the default).
     */
    public function testTenantAdoptRekeysAllFiveSellerWebhookTablesEvenWhenTheMasterSwitchIsOff(): void
    {
        self::assertFalse(
            (bool) config($this->context, 'commerce.marketplace.enabled', false),
            'this test relies on the master switch being off (the default)'
        );

        $this->connection->table('commerce_seller_webhook_endpoints')->insert([
            'uuid' => 'mv5c2adpep1',
            'tenant_uuid' => '',
            'seller_uuid' => 'mv5c2adpsl1',
            'url' => 'https://example.test/hook',
            'subscribed_events' => json_encode(['order.placed'], JSON_THROW_ON_ERROR),
            'status' => 'active',
            'created_by' => 'creatorADP01',
        ]);
        $this->connection->table('commerce_seller_webhook_secrets')->insert([
            'uuid' => 'mv5c2adpsc1',
            'tenant_uuid' => '',
            'endpoint_uuid' => 'mv5c2adpep1',
            'secret_ciphertext' => 'ciphertext-placeholder',
            'relationship' => 'current',
        ]);
        $this->connection->table('commerce_seller_webhook_events')->insert([
            'uuid' => 'mv5c2adpev1',
            'tenant_uuid' => '',
            'seller_uuid' => 'mv5c2adpsl1',
            'event_type' => 'order.placed',
            'payload' => json_encode(['order_uuid' => 'mv5c2adpord'], JSON_THROW_ON_ERROR),
            'occurred_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->connection->table('commerce_seller_webhook_deliveries')->insert([
            'uuid' => 'mv5c2adpdl1',
            'tenant_uuid' => '',
            'endpoint_uuid' => 'mv5c2adpep1',
            'webhook_event_uuid' => 'mv5c2adpev1',
            'seller_uuid' => 'mv5c2adpsl1',
            'status' => 'pending',
        ]);
        $this->connection->table('commerce_seller_webhook_endpoint_events')->insert([
            'uuid' => 'mv5c2adpae1',
            'tenant_uuid' => '',
            'endpoint_uuid' => 'mv5c2adpep1',
            'seller_uuid' => 'mv5c2adpsl1',
            'action' => 'register',
            'actor_uuid' => 'creatorADP01',
        ]);

        $result = (new TenantAdopter())->adopt($this->context, 'tenantMV5c2099');

        foreach (self::MV5C2_TABLES as $table) {
            self::assertSame(1, $result['tables'][$table], "{$table} must report exactly 1 rekeyed row");
            self::assertSame(
                0,
                $this->connection->table($table)->where('tenant_uuid', '=', '')->count(),
                "{$table} must have no sentinel rows left behind"
            );
            self::assertSame(
                1,
                $this->connection->table($table)->where('tenant_uuid', '=', 'tenantMV5c2099')->count(),
                "{$table} row must be rekeyed to the adopted tenant"
            );
        }
    }

    /**
     * Design spec §6: unlike the earlier `assertNoMarketplaceQueries()`
     * scenarios above (which all use a null-webhooks `checkoutService()`, so
     * `capture()` never even runs), this wires a REAL
     * `SellerWebhookOutboxPublisher` into a REAL checkout with the master
     * switch OFF -- proving `capture()`'s own off-invariance guard fires
     * genuinely, not merely because no collaborator was ever attached, and
     * that the placed order stays byte-identical (same shape/fields as the
     * non-partitioned baseline every other master-off checkout test in this
     * file already pins).
     */
    public function testMasterOffCheckoutWithARealSellerWebhookPublisherWiredIssuesZeroWebhookTableQueriesAndStaysByteIdentical(): void
    {
        self::assertFalse((bool) config($this->context, 'commerce.marketplace.enabled', false));

        $product = $this->seedLegacyProduct('regress-mv5c2-off-webhook');
        $cartService = $this->cartService();
        ['cart' => $cart, 'token' => $token] = $cartService->create($this->context);
        $cartService->addLine($this->context, $cart, (string) $product['variants'][0]['uuid'], 1);

        $placed = null;
        $this->assertNoMarketplaceQueries(function () use ($token, &$placed): void {
            $placed = $this->checkoutService($this->sellerWebhookOutboxPublisher())
                ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');
        });

        self::assertFalse((bool) $placed['order']['marketplace_partitioned']);
        self::assertArrayNotHasKey('seller_groups', $placed['order']);
        self::assertSame(0, $this->connection->table('commerce_seller_webhook_events')->count());
        self::assertSame(0, $this->connection->table('commerce_seller_webhook_deliveries')->count());
    }

    /**
     * Design spec §6/§2.4: an ACTIVE marketplace with NO matching endpoint
     * for the participating seller permits at most ONE bounded indexed
     * subscription probe and writes nothing -- run here through a REAL
     * partitioned checkout branch (never a directly-constructed publisher
     * call, closing the loop `SellerWebhookOutboxTest`'s own identical-intent
     * unit already proves at the publisher level).
     */
    public function testActiveMarketplaceSellerWithNoMatchingWebhookEndpointRunsExactlyOneProbeAndWritesNothing(): void
    {
        $this->enableMarketplace();
        $this->activateWorkspace(self::TENANT);
        $seller = $this->seedActiveSeller('regress-mv5c2-noep-seller');
        $product = $this->seedAttributedProduct('regress-mv5c2-noep-prod', $seller['uuid']);
        (new StockRepository())->increment(
            $this->context,
            self::TENANT,
            (string) $product['variants'][0]['uuid'],
            10
        );
        // Deliberately no webhook endpoint registered for this seller at all.

        $cartService = $this->cartService();
        ['cart' => $cart, 'token' => $token] = $cartService->create($this->context);
        $cartService->addLine($this->context, $cart, (string) $product['variants'][0]['uuid'], 1);

        $pdo = $this->connection->getPDO();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [QueryLoggingPdoStatement::class]);
        QueryLoggingPdoStatement::$queries = [];

        $placed = $this->marketplaceCheckoutService($this->sellerWebhookOutboxPublisher())
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');
        self::assertTrue((bool) $placed['order']['marketplace_partitioned']);

        $endpointSelects = array_values(array_filter(
            QueryLoggingPdoStatement::$queries,
            static fn (string $sql): bool => str_starts_with($sql, 'SELECT')
                && str_contains($sql, 'commerce_seller_webhook_endpoints')
        ));
        self::assertCount(1, $endpointSelects, 'exactly one bounded indexed subscription probe');
        self::assertSame(0, $this->connection->table('commerce_seller_webhook_events')->count());
        self::assertSame(0, $this->connection->table('commerce_seller_webhook_deliveries')->count());
    }

    /**
     * The flagship security proof, run at TWO levels together (design spec
     * §2.3, T8 brief "at the BRANCH level ... any surface"): (1) the durable
     * event snapshot a REAL, genuinely-partitioned checkout writes for seller
     * A carries NONE of seller B's poison marker/uuid/name, and (2) seller
     * A's OWN JWT-interactive delivery-history HTTP read
     * ({@see SellerWebhookController::deliveries()}) -- a completely
     * independent surface, reached through the real `interactive_session` +
     * `commerce_seller` route gate -- never leaks it either, even though its
     * sanitized projection never includes the payload at all (defense in
     * depth: the absence holds at the outermost boundary too, not merely
     * inside the database row).
     */
    public function testMultiSellerOrderPlacedWebhookIsolatesEachSellersPoisonDataAtTheCheckoutBranchAndAcrossTheSellerDeliveryHistorySurface(): void
    {
        $this->enableMarketplace();
        $this->activateWorkspace(self::TENANT);
        $sellerA = $this->seedActiveSeller('regress-mv5c2-iso-a');
        $sellerB = $this->seedActiveSeller('regress-mv5c2-iso-b-poison9f3a');
        $productA = $this->seedAttributedProduct('regress-mv5c2-iso-prod-a', $sellerA['uuid']);
        $productB = $this->seedAttributedProduct('regress-mv5c2-iso-prod-b-poisonb2c7', $sellerB['uuid']);
        $stock = new StockRepository();
        $stock->increment($this->context, self::TENANT, (string) $productA['variants'][0]['uuid'], 10);
        $stock->increment($this->context, self::TENANT, (string) $productB['variants'][0]['uuid'], 10);

        $endpointA = $this->seedWebhookEndpoint($sellerA['uuid'], ['order.placed']);
        $this->seedWebhookEndpoint($sellerB['uuid'], ['order.placed']);

        $cartService = $this->cartService();
        ['cart' => $cart, 'token' => $token] = $cartService->create($this->context);
        $cartService->addLine($this->context, $cart, (string) $productA['variants'][0]['uuid'], 1);
        $cartService->addLine($this->context, $cart, (string) $productB['variants'][0]['uuid'], 1);

        $this->marketplaceCheckoutService($this->sellerWebhookOutboxPublisher())
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        // (1) BRANCH-LEVEL proof: the durable event snapshot itself.
        $eventA = $this->connection->table('commerce_seller_webhook_events')
            ->where('seller_uuid', '=', $sellerA['uuid'])->first();
        self::assertNotNull($eventA);
        $rawA = (string) $eventA['payload'];
        self::assertStringNotContainsString('poison9f3a', $rawA);
        self::assertStringNotContainsString('poisonb2c7', $rawA);
        self::assertStringNotContainsString($sellerB['uuid'], $rawA);

        // Seller B legitimately keeps its OWN data -- this is an isolation
        // proof, never a "nothing is ever captured" false negative.
        $eventB = $this->connection->table('commerce_seller_webhook_events')
            ->where('seller_uuid', '=', $sellerB['uuid'])->first();
        self::assertNotNull($eventB);
        self::assertStringContainsString('poisonb2c7', (string) $eventB['payload']);

        // (2) ANY-SURFACE proof: seller A's own JWT-interactive delivery
        // history HTTP read never leaks seller B's poison marker either.
        $router = $this->freshSellerWebhookRouter();
        $ownerA = $this->ownerUuidFor($sellerA['uuid']);
        $response = $this->dispatch($router, $this->jwtWebhookRequestFor(
            $ownerA,
            'GET',
            "/commerce/seller/{$sellerA['uuid']}/webhooks/{$endpointA}/deliveries"
        ));
        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertStringNotContainsString('poison9f3a', $body);
        self::assertStringNotContainsString('poisonb2c7', $body);
        self::assertStringNotContainsString($sellerB['uuid'], $body);
        $rows = json_decode($body, true)['data'];
        self::assertCount(1, $rows, 'seller A must see exactly its own delivery, never seller B\'s');
    }

    /**
     * Design spec §2.7/§2.9, T8 brief retention matrix: purges `delivered` +
     * `dead_letter` + `canceled` deliveries (and their now-orphaned event
     * snapshots) older than `commerce.marketplace.webhooks.retention_days`;
     * KEEPS `paused` and `delivering` (including one whose crash-safe claim
     * lease has ALREADY EXPIRED -- only the recovery sweep may touch that,
     * never this command) regardless of age; a FRESH `dead_letter` well
     * within the window survives; a tombstoned endpoint + its audit trail are
     * NEVER touched (their own longer, separate audit policy); the sweep is
     * cross-tenant, scoped per-DELETE by tenant.
     */
    public function testPurgeSellerWebhooksCommandPurgesStaleTerminalRowsButKeepsPendingPausedDeliveringAndTombstones(): void
    {
        self::assertSame(
            90,
            (int) config($this->context, 'commerce.marketplace.webhooks.retention_days', 90),
            'sanity: the default retention window must be 90 days'
        );

        $staleAt = gmdate('Y-m-d H:i:s', strtotime('-91 days'));
        $freshNow = gmdate('Y-m-d H:i:s');
        $seller = 'mv5c2rtsl01';

        // A tombstoned endpoint -- stale, but NEVER purged by this command.
        $endpointUuid = 'mv5c2rtep01';
        $this->connection->table('commerce_seller_webhook_endpoints')->insert([
            'uuid' => $endpointUuid,
            'tenant_uuid' => self::TENANT,
            'seller_uuid' => $seller,
            'url' => 'https://example.test/hook',
            'subscribed_events' => json_encode(['order.placed'], JSON_THROW_ON_ERROR),
            'status' => 'deleted',
            'deleted_at' => $staleAt,
            'created_by' => 'creatorRT001',
        ]);
        $this->connection->table('commerce_seller_webhook_endpoint_events')->insert([
            'uuid' => 'mv5c2rtae01',
            'tenant_uuid' => self::TENANT,
            'endpoint_uuid' => $endpointUuid,
            'seller_uuid' => $seller,
            'action' => 'delete',
            'actor_uuid' => 'operatorRT01',
            'created_at' => $staleAt,
        ]);

        // Stale `delivered`: its ONLY delivery -- purged, and (once orphaned)
        // its event snapshot too.
        [$eventDelivered, $deliveredUuid] = $this->seedRetentionDelivery(
            $endpointUuid,
            $seller,
            'delivered',
            $staleAt,
            $staleAt
        );

        // Stale-dated event, but `paused` (kept) -- so the event must stay
        // too (still referenced).
        [$eventPaused, $pausedUuid] = $this->seedRetentionDelivery(
            $endpointUuid,
            $seller,
            'paused',
            $staleAt,
            $staleAt,
            ['pause_reason' => 'seller_suspended']
        );

        // `delivering` with an EXPIRED claim lease -- must NEVER be purged;
        // only the recovery sweep may touch it.
        [$eventDelivering, $deliveringUuid] = $this->seedRetentionDelivery(
            $endpointUuid,
            $seller,
            'delivering',
            $staleAt,
            $staleAt,
            ['claim_token' => 'staletoken1', 'claim_expires_at' => $staleAt]
        );

        // A FRESH dead_letter (well within retention) -- must survive.
        [, $freshDeadLetterUuid] = $this->seedRetentionDelivery(
            $endpointUuid,
            $seller,
            'dead_letter',
            $freshNow,
            $freshNow
        );

        // A DIFFERENT tenant's equally-stale `delivered` row -- also purged
        // (cross-tenant sweep, each DELETE scoped by its own tenant_uuid).
        $otherTenant = 'otherTenantW';
        $otherEndpoint = 'mv5c2rtepX1';
        $this->connection->table('commerce_seller_webhook_endpoints')->insert([
            'uuid' => $otherEndpoint,
            'tenant_uuid' => $otherTenant,
            'seller_uuid' => 'mv5c2rtslX1',
            'url' => 'https://example.test/hook',
            'subscribed_events' => json_encode(['order.placed'], JSON_THROW_ON_ERROR),
            'status' => 'active',
            'created_by' => 'creatorRTX01',
        ]);
        [$otherEvent, $otherDeliveredUuid] = $this->seedRetentionDelivery(
            $otherEndpoint,
            'mv5c2rtslX1',
            'delivered',
            $staleAt,
            $staleAt,
            [],
            $otherTenant
        );

        $command = new PurgeSellerWebhooksCommand($this->context->getContainer(), $this->context);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);
        self::assertSame(0, $exitCode);

        // Stale delivered -> purged, and its now-orphaned event snapshot too.
        self::assertSame(
            0,
            $this->connection->table('commerce_seller_webhook_deliveries')->where('uuid', '=', $deliveredUuid)->count()
        );
        self::assertSame(
            0,
            $this->connection->table('commerce_seller_webhook_events')->where('uuid', '=', $eventDelivered)->count()
        );

        // Paused (kept) -- and its still-referenced event snapshot too.
        self::assertSame(
            1,
            $this->connection->table('commerce_seller_webhook_deliveries')->where('uuid', '=', $pausedUuid)->count()
        );
        self::assertSame(
            1,
            $this->connection->table('commerce_seller_webhook_events')->where('uuid', '=', $eventPaused)->count()
        );

        // Delivering with an EXPIRED lease (kept) -- retention never touches it.
        self::assertSame(
            1,
            $this->connection->table('commerce_seller_webhook_deliveries')
                ->where('uuid', '=', $deliveringUuid)->count()
        );
        self::assertSame(
            1,
            $this->connection->table('commerce_seller_webhook_events')->where('uuid', '=', $eventDelivering)->count()
        );

        // Fresh dead_letter (kept) -- well within retention.
        self::assertSame(
            1,
            $this->connection->table('commerce_seller_webhook_deliveries')
                ->where('uuid', '=', $freshDeadLetterUuid)->count()
        );

        // Cross-tenant stale delivered row -- also purged.
        self::assertSame(
            0,
            $this->connection->table('commerce_seller_webhook_deliveries')
                ->where('uuid', '=', $otherDeliveredUuid)->count()
        );
        self::assertSame(
            0,
            $this->connection->table('commerce_seller_webhook_events')->where('uuid', '=', $otherEvent)->count()
        );

        // The tombstoned endpoint row + its audit trail are NEVER touched.
        $endpointRow = $this->connection->table('commerce_seller_webhook_endpoints')
            ->withTrashed()
            ->where('uuid', '=', $endpointUuid)
            ->first();
        self::assertNotNull($endpointRow);
        self::assertSame('deleted', $endpointRow['status']);
        self::assertSame(
            1,
            $this->connection->table('commerce_seller_webhook_endpoint_events')
                ->where('uuid', '=', 'mv5c2rtae01')->count()
        );
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

    /** @return array<string,mixed> product row attributed to $sellerUuid via the real create path */
    private function seedAttributedProduct(string $slug, string $sellerUuid): array
    {
        return $this->marketplaceCatalog()->createProduct($this->context, [
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
        ], $sellerUuid);
    }

    /**
     * The subset of an admin product GET payload a well-behaved client
     * echoes back on a full-object PATCH -- the documented
     * {@see \Glueful\Extensions\Commerce\Http\DTOs\UpdateProductData} fields
     * plus `seller_uuid` (present on every payload since migration 011).
     * Deliberately excludes internal bookkeeping columns
     * (`id`/`catalog_revision`/`rating_sum`/timestamps) and the nested
     * `variants` list, which live behind their own endpoints.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function productEditableFields(array $payload): array
    {
        return array_intersect_key($payload, array_flip([
            'slug', 'name', 'description', 'type', 'status', 'options', 'metadata', 'tax_class', 'seller_uuid',
        ]));
    }

    /** @param array<string,mixed> $body */
    private function patchRequest(array $body): Request
    {
        $request = Request::create('/x', 'PATCH', [], [], [], [], json_encode($body, JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');

        return $request;
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

    private function marketplaceCatalog(): CatalogService
    {
        return new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            $this->fixedTenant(),
            new StockRepository(),
            new ProductChildrenRepository(),
            new ShippingClassRepository(),
            new MarketplaceMode(),
            new MarketplaceWorkspaceLock(),
            new SellerRepository()
        );
    }

    private function enableMarketplace(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['marketplace' => ['enabled' => true]]);
    }

    private function activateWorkspace(string $tenant): void
    {
        $this->connection->table('commerce_marketplace_settings')->insert([
            'uuid' => substr('actv' . md5($tenant), 0, 12),
            'tenant_uuid' => $tenant,
            'status' => 'active',
        ]);
    }

    /** @return array<string,mixed> */
    private function seedActiveSeller(string $slug, string $tenant = self::TENANT): array
    {
        return $this->sellerService()->create(
            $this->context,
            $tenant,
            $slug,
            ucfirst(str_replace('-', ' ', $slug)),
            null,
            'ownerUser' . substr(md5($slug), 0, 3)
        );
    }

    private function sellerService(): SellerService
    {
        return new SellerService(
            new SellerRepository(),
            new SellerMembershipRepository(),
            new SellerLifecycleEventRepository()
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

    private function marketplaceAdminController(): AdminProductController
    {
        return new AdminProductController(
            $this->context,
            $this->marketplaceCatalog(),
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

    /**
     * MV5c-2 Task 8: the optional trailing `$webhooks` param (mirrors
     * `SellerWebhookOutboxTest::checkout()`'s identical convention) lets a
     * gate test wire a REAL `SellerWebhookOutboxPublisher` in without
     * disturbing any of this method's many existing null-webhooks callers --
     * `CheckoutService`'s own `captureOrderPlaced()` no-ops entirely
     * (`$this->webhooks === null` guard) when omitted, so every pre-existing
     * assertion in this file is byte-for-byte unaffected.
     */
    private function checkoutService(?SellerWebhookOutboxPublisher $webhooks = null): CheckoutService
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
            $this->fixedTenant(),
            null,
            null,
            null,
            null,
            null,
            $webhooks
        );
    }

    /**
     * MV2 Task 10: wired WITH the marketplace collaborators (unlike
     * `checkoutService()` above, which is byte-identical to the pre-MV2
     * constructor arity) -- used only by the partition-invariance test that
     * needs a genuinely partitioned checkout. MV5c-2 Task 8: the optional
     * trailing `$webhooks` param mirrors `checkoutService()`'s identical
     * convention immediately above.
     */
    private function marketplaceCheckoutService(?SellerWebhookOutboxPublisher $webhooks = null): CheckoutService
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
            $this->fixedTenant(),
            new MarketplaceMode(),
            new SellerRepository(),
            new ProductRepository(),
            new SellerOrderRepository(),
            null,
            $webhooks
        );
    }

    /**
     * Wired WITH the `SellerOrderPaymentConfirmation` collaborator so the
     * MV2 Task 10 zero-query proofs exercise the REAL branch condition
     * (the order's own `marketplace_partitioned` flag) rather than merely a
     * missing collaborator.
     */
    /**
     * MV3 Task 12: also wired WITH `SellerOrderRepository` +
     * `LedgerPostingService` (design spec §2.7) -- like
     * `SellerOrderPaymentConfirmation` above, this is what makes the
     * zero-query proofs exercise the REAL `marketplace_partitioned` branch
     * rather than a merely-absent collaborator.
     */
    private function paymentService(): OrderPaymentService
    {
        return new OrderPaymentService(
            new OrderRepository(),
            new SellerOrderPaymentConfirmation(),
            null,
            new SellerOrderRepository(),
            $this->ledgerPostingService()
        );
    }

    private function ledgerPostingService(): LedgerPostingService
    {
        return new LedgerPostingService(new LedgerRepository(), new LedgerAccountLock());
    }

    /**
     * MV5a Task 17 (GATES): the ONLY `paymentService()` variant in this file that
     * ALSO wires the {@see ReserveService} collaborator into
     * {@see LedgerPostingService}, against a REAL {@see ReservePolicyService} reading
     * whatever policy is actually persisted -- used exclusively by
     * {@see self::testFoldedReservePolicyDefaultsKeepSettledSellersReserveFreeWithMarketplaceEnabled()}
     * to prove the folded `010` `0`/`0` default keeps a settled seller reserve-free
     * even with the REAL reserve-hold branch genuinely reachable, never merely absent.
     */
    private function reserveWiredPaymentService(): OrderPaymentService
    {
        return new OrderPaymentService(
            new OrderRepository(),
            new SellerOrderPaymentConfirmation(),
            null,
            new SellerOrderRepository(),
            $this->reserveWiredLedgerPostingService()
        );
    }

    private function reserveWiredLedgerPostingService(): LedgerPostingService
    {
        return new LedgerPostingService(
            new LedgerRepository(),
            new LedgerAccountLock(),
            new ReserveService(
                new ReservePolicyService(
                    new SellerRepository(),
                    new MarketplaceWorkspaceLock(),
                    new ReservePolicyEventRepository()
                ),
                new ReserveRepository(),
                new LedgerRepository()
            )
        );
    }

    /** MV3 Task 12: wired WITH both marketplace collaborators -- see {@see paymentService()}. */
    private function refundService(): RefundService
    {
        return new RefundService(
            new OrderRepository(),
            new RefundRepository(),
            new StockRepository(),
            $this->fixedTenant(),
            null,
            new MarketplaceRefundGuard(new RefundRepository()),
            $this->ledgerPostingService()
        );
    }

    private function marketplaceAdminOrderController(): AdminOrderController
    {
        return new AdminOrderController(
            $this->context,
            new OrderRepository(),
            new StockRepository(),
            $this->paymentService(),
            $this->fixedTenant(),
            new RefundRepository(),
            new ConfigSellerIdentityProvider(),
            new SellerOrderRepository()
        );
    }

    private function orderController(): OrderController
    {
        return new OrderController(
            $this->context,
            new OrderRepository(),
            $this->checkoutService(),
            $this->fixedTenant(),
            new RefundRepository()
        );
    }

    /** @return array{order: array<string,mixed>, guest_token: string, payment: array<string,mixed>} */
    private function placeNonPartitionedOrder(): array
    {
        $product = $this->seedLegacyProduct('regress-nonpart-order');
        $cartService = $this->cartService();
        ['cart' => $cart, 'token' => $token] = $cartService->create($this->context);
        $cartService->addLine($this->context, $cart, (string) $product['variants'][0]['uuid'], 1);

        return $this->checkoutService()->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');
    }

    /** @return array<string,mixed> */
    private function orderRow(string $uuid): array
    {
        $row = $this->connection->table('commerce_orders')->where('uuid', '=', $uuid)->first();
        self::assertNotNull($row);

        return $row;
    }

    /** @return array{email: string, user_uuid: null} */
    private function buyer(): array
    {
        return ['email' => 'buyer@example.com', 'user_uuid' => null];
    }

    /** @return array{shipping: array{country: string}, billing: array{country: string}} */
    private function addresses(): array
    {
        return ['shipping' => ['country' => 'US'], 'billing' => ['country' => 'US']];
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

    // -----------------------------------------------------------------
    // MV5c-1 Task 7 helpers: a minimal, LOCAL real-router harness for the
    // two key-lifecycle tests above -- deliberately NOT a full
    // `CommerceRouterTestCase` (this file's own class hierarchy predates it
    // and every OTHER test above relies on this file's own plain
    // `CommerceTestCase` base + its OWN `freshRouter()`/`fixedTenant()`
    // conventions) -- binds only what those two tests actually dispatch to:
    // `commerce_seller` (the REAL {@see SellerMemberMiddleware}, wired
    // identically to `CommerceRouterTestCase::buildSellerMiddleware()`),
    // `SellerOrderController`, `SellerFinancialController`, and a
    // pass-through `auth` stub (this suite pre-sets every auth-relevant
    // request ATTRIBUTE directly via {@see self::apiKeyRequestFor()} --
    // mirroring {@see SellerApiKeyAuthTest}'s own
    // `testPrincipalMismatchDoesNotOverwriteTheAuthenticatedUserAttribute()`
    // convention -- rather than simulating framework header parsing).
    // -----------------------------------------------------------------

    private function freshSellerKeyRouter(): Router
    {
        $this->bind(ApplicationContext::class, $this->context);
        $this->bind('auth', new class implements RouteMiddleware {
            public function handle(Request $request, callable $next, mixed ...$params): mixed
            {
                return $next($request);
            }
        });
        $this->bind('commerce_seller', new SellerMemberMiddleware(
            $this->context,
            new SellerRepository(),
            new SellerMembershipRepository(),
            new FixedSellerRoleAuthority(),
            new MarketplaceMode(),
            $this->fixedTenant(),
            new SellerApiKeyAuthorizer(new SellerApiKeyRepository())
        ));

        $orders = new OrderRepository();
        $sellerOrders = new SellerOrderRepository();
        $this->bind(SellerOrderController::class, new SellerOrderController(
            $this->context,
            new SellerOrderService($sellerOrders, $orders, $this->fixedTenant()),
            new SellerOrderFulfillmentService($orders, $sellerOrders),
            $this->fixedTenant()
        ));
        $this->bind(SellerFinancialController::class, new SellerFinancialController(
            $this->context,
            new SellerFinancialReportRepository(),
            new SellerBalanceService(new LedgerRepository()),
            new PayoutRepository(),
            new MarketplaceMode(),
            $this->fixedTenant(),
            new PayoutAccountRepository(),
            // Explicit -- never lazily `app()`-resolved (see the controller's
            // own `reserveService()`) -- this suite's lightweight container
            // has no `ReserveService` binding at all. Mirrors this file's own
            // `reserveWiredLedgerPostingService()` wiring above.
            new ReserveService(
                new ReservePolicyService(
                    new SellerRepository(),
                    new MarketplaceWorkspaceLock(),
                    new ReservePolicyEventRepository()
                ),
                new ReserveRepository(),
                new LedgerRepository()
            )
        ));

        $router = new Router($this->contextContainer());
        require __DIR__ . '/../../../routes.php';

        return $router;
    }

    private function dispatch(Router $router, Request $request): Response
    {
        try {
            $response = $router->dispatch($request);
        } catch (\Throwable $e) {
            $response = (new ExceptionHandler())->handle($e, $request);
        }

        return $response instanceof Response ? $response : new Response((string) $response);
    }

    /**
     * Pre-sets every attribute the framework's real `ApiKeyAuthenticationProvider`/
     * `AuthMiddleware` would set for a genuinely authenticated API-key
     * request, directly on the `Request` (see this section's own docblock
     * for why -- mirrors `SellerApiKeyAuthTest`'s identical convention).
     *
     * @param list<string> $scopes
     * @param array<string,mixed> $body
     */
    private function apiKeyRequestFor(
        string $subjectUuid,
        string $frameworkKeyUuid,
        array $scopes,
        string $method,
        string $uri,
        array $body = []
    ): Request {
        $content = $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR);
        $request = Request::create($uri, $method, [], [], [], [], $content);
        if ($content !== null) {
            $request->headers->set('Content-Type', 'application/json');
        }
        $request->attributes->set('auth_method', 'api_key');
        $request->attributes->set('user_id', $subjectUuid);
        $request->attributes->set('api_key_scopes', $scopes);
        $request->attributes->set('api_key_uuid', $frameworkKeyUuid);
        $request->attributes->set('user', ['uuid' => $subjectUuid]);

        return $request;
    }

    /** The seller's `seller_owner` membership uuid, as actually persisted (never recomputed). */
    private function ownerUuidFor(string $sellerUuid): string
    {
        $membership = $this->connection->table('commerce_seller_memberships')
            ->where('seller_uuid', '=', $sellerUuid)
            ->where('role', '=', 'seller_owner')
            ->first();
        self::assertNotNull($membership, "no seller_owner membership found for seller {$sellerUuid}");

        return (string) $membership['user_uuid'];
    }

    /**
     * Seeds a lineage + its ONE credential row directly via
     * {@see SellerApiKeyRepository} (bypassing `SellerApiKeyService::create()`,
     * which needs the framework's OWN `api_keys` table -- this file's
     * lightweight container has no such table) -- mirrors
     * `SellerApiKeyAuthTest::seedKeyBinding()`'s identical convention; this
     * suite only cares about the per-request authorization/lifecycle
     * contract, never CREATE itself.
     *
     * @param list<string> $declaredScopes
     */
    private function seedApiKeyBinding(
        string $sellerUuid,
        string $subjectUuid,
        array $declaredScopes,
        string $frameworkKeyUuid
    ): void {
        $repo = new SellerApiKeyRepository();
        $lineageUuid = Utils::generateNanoID();
        $credentialUuid = Utils::generateNanoID();

        $repo->insertLineage($this->context, self::TENANT, [
            'uuid' => $lineageUuid,
            'seller_uuid' => $sellerUuid,
            'subject_user_uuid' => $subjectUuid,
            'declared_scopes' => $declaredScopes,
            'name' => 'MV5c-1 regression key',
            'status' => 'active',
            'current_credential_uuid' => $credentialUuid,
            'expires_at' => null,
            'created_by' => $subjectUuid,
        ]);
        $repo->insertCredential($this->context, self::TENANT, [
            'uuid' => $credentialUuid,
            'lineage_uuid' => $lineageUuid,
            'framework_key_uuid' => $frameworkKeyUuid,
            'generation' => 1,
            'relationship' => 'current',
        ]);
    }

    // -----------------------------------------------------------------
    // MV5c-2 Task 8 helpers.
    // -----------------------------------------------------------------

    private function sellerWebhookOutboxPublisher(): SellerWebhookOutboxPublisher
    {
        return new SellerWebhookOutboxPublisher(
            new MarketplaceMode(),
            new SellerRepository(),
            new SellerWebhookEndpointRepository(),
            new SellerWebhookEventRepository(),
            new SellerWebhookDeliveryRepository(),
            new SellerWebhookPayloadProjector()
        );
    }

    /** @return string the seeded endpoint uuid */
    private function seedWebhookEndpoint(string $sellerUuid, array $events, string $status = 'active'): string
    {
        $uuid = 'whep' . str_pad((string) (++$this->webhookEndpointSeq), 8, '0', STR_PAD_LEFT);
        $this->connection->table('commerce_seller_webhook_endpoints')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT,
            'seller_uuid' => $sellerUuid,
            'url' => 'https://example.test/hook',
            'subscribed_events' => json_encode($events, JSON_THROW_ON_ERROR),
            'status' => $status,
            'revision' => 0,
            'consecutive_failures' => 0,
            'created_by' => 'creatorWH0001',
        ]);

        return $uuid;
    }

    /**
     * Seeds one event snapshot + one delivery row directly (deterministic
     * SQLite fixture -- mirrors {@see PurgeApiKeyDenialsCommand}'s own
     * regression test's direct-insert convention).
     *
     * @param array<string,mixed> $deliveryOverrides
     * @return array{0: string, 1: string} [event uuid, delivery uuid]
     */
    private function seedRetentionDelivery(
        string $endpointUuid,
        string $sellerUuid,
        string $status,
        string $occurredAt,
        string $updatedAt,
        array $deliveryOverrides = [],
        string $tenant = self::TENANT
    ): array {
        $seq = ++$this->webhookEndpointSeq;
        $eventUuid = 'whev' . str_pad((string) $seq, 8, '0', STR_PAD_LEFT);
        $deliveryUuid = 'whdl' . str_pad((string) $seq, 8, '0', STR_PAD_LEFT);

        $this->connection->table('commerce_seller_webhook_events')->insert([
            'uuid' => $eventUuid,
            'tenant_uuid' => $tenant,
            'seller_uuid' => $sellerUuid,
            'event_type' => 'order.placed',
            'payload' => json_encode(['order_uuid' => 'ord' . $seq], JSON_THROW_ON_ERROR),
            'occurred_at' => $occurredAt,
        ]);
        $this->connection->table('commerce_seller_webhook_deliveries')->insert(array_merge([
            'uuid' => $deliveryUuid,
            'tenant_uuid' => $tenant,
            'endpoint_uuid' => $endpointUuid,
            'webhook_event_uuid' => $eventUuid,
            'seller_uuid' => $sellerUuid,
            'status' => $status,
            'attempts' => 1,
            'updated_at' => $updatedAt,
        ], $deliveryOverrides));

        return [$eventUuid, $deliveryUuid];
    }

    /**
     * A minimal, LOCAL real-router harness for the seller-webhook management
     * surface -- mirrors {@see self::freshSellerKeyRouter()}'s identical
     * "deliberately NOT a full `CommerceRouterTestCase`" shape immediately
     * above, extended with `interactive_session` (the JWT-interactive-only
     * gate {@see \Glueful\Extensions\Commerce\Http\Seller\SellerWebhookController}
     * runs behind) and a fully-wired REAL {@see SellerWebhookController},
     * mirroring {@see \Glueful\Extensions\Commerce\Tests\Support\CommerceRouterTestCase::buildSellerWebhookController()}'s
     * exact collaborator graph.
     */
    private function freshSellerWebhookRouter(): Router
    {
        $this->bind(ApplicationContext::class, $this->context);
        $this->bind('auth', new class implements RouteMiddleware {
            public function handle(Request $request, callable $next, mixed ...$params): mixed
            {
                return $next($request);
            }
        });
        $this->bind('interactive_session', new InteractiveSessionMiddleware());
        $this->bind('commerce_seller', new SellerMemberMiddleware(
            $this->context,
            new SellerRepository(),
            new SellerMembershipRepository(),
            new FixedSellerRoleAuthority(),
            new MarketplaceMode(),
            $this->fixedTenant(),
            new SellerApiKeyAuthorizer(new SellerApiKeyRepository())
        ));
        $this->bind(SellerWebhookController::class, $this->buildSellerWebhookControllerForRegression());

        $router = new Router($this->contextContainer());
        require __DIR__ . '/../../../routes.php';

        return $router;
    }

    private function buildSellerWebhookControllerForRegression(): SellerWebhookController
    {
        $endpoints = new SellerWebhookEndpointRepository();
        $deliveries = new SellerWebhookDeliveryRepository();
        $resolver = new SafeOutboundTargetResolver(static fn (string $host): array => ['1.1.1.1']);
        $secrets = new SellerWebhookSecretService($endpoints, $this->webhookEncryptionService());

        $endpointService = new SellerWebhookEndpointService(
            new SellerRepository(),
            new SellerMembershipRepository(),
            $endpoints,
            $deliveries,
            new FixedSellerRoleAuthority(),
            $secrets,
            $resolver
        );

        $httpClient = new Client(
            new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 500])),
            new NullLogger(),
            $this->context,
            $resolver
        );
        $deliveryService = new SellerWebhookDeliveryService(
            new SellerRepository(),
            $endpoints,
            $deliveries,
            new SellerWebhookEventRepository(),
            $secrets,
            $httpClient
        );

        return new SellerWebhookController(
            $this->context,
            $endpointService,
            $endpoints,
            $deliveries,
            $deliveryService,
            $this->fixedTenant()
        );
    }

    /**
     * Mirrors {@see \Glueful\Extensions\Commerce\Tests\Support\CommerceRouterTestCase::webhookEncryptionService()}'s
     * identical process-lifetime-cached fixed-key convention.
     */
    private function webhookEncryptionService(): EncryptionService
    {
        static $encryption = null;
        if ($encryption === null) {
            $this->context->overrideConfig('encryption.key', 'base64:' . base64_encode(str_repeat('k', 32)));
            $encryption = new EncryptionService($this->context);
        }

        return $encryption;
    }

    /** @param array<string,mixed> $body */
    private function jwtWebhookRequestFor(string $userUuid, string $method, string $uri, array $body = []): Request
    {
        $content = $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR);
        $request = Request::create($uri, $method, [], [], [], [], $content);
        if ($content !== null) {
            $request->headers->set('Content-Type', 'application/json');
        }
        $request->attributes->set('auth_provider', 'jwt');
        $request->attributes->set('user_id', $userUuid);
        $request->attributes->set('user', ['uuid' => $userUuid]);

        return $request;
    }
}
