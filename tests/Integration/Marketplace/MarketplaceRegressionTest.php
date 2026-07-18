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
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Http\Admin\AdminProductController;
use Glueful\Extensions\Commerce\Http\Admin\AdminReportController;
use Glueful\Extensions\Commerce\Http\DTOs\AddCartLineData;
use Glueful\Extensions\Commerce\Http\DTOs\CreateProductData;
use Glueful\Extensions\Commerce\Http\DTOs\FulfillOrderData;
use Glueful\Extensions\Commerce\Http\DTOs\ProductListQuery;
use Glueful\Extensions\Commerce\Http\DTOs\ProductVariantData;
use Glueful\Extensions\Commerce\Http\DTOs\ReportWindowQuery;
use Glueful\Extensions\Commerce\Http\Storefront\CartController;
use Glueful\Extensions\Commerce\Http\Storefront\OrderController;
use Glueful\Extensions\Commerce\Http\Storefront\ProductController;
use Glueful\Extensions\Commerce\Invoices\ConfigSellerIdentityProvider;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerPostingService;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceRefundGuard;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceWorkspaceLock;
use Glueful\Extensions\Commerce\Marketplace\PayoutException;
use Glueful\Extensions\Commerce\Marketplace\PayoutRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutService;
use Glueful\Extensions\Commerce\Marketplace\SellerBalanceService;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderPaymentConfirmation;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerService;
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
use Glueful\Extensions\Commerce\Shipping\ShippingClassRepository;
use Glueful\Extensions\Commerce\Support\DiagnosticsReport;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tenancy\TenantAdopter;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Commerce\Tests\Support\QueryLoggingPdoStatement;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Routing\Router;
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

        // MV3 Task 12: the expanded 8-table zero-query proof -- the MV1 trio
        // plus MV2's commerce_seller_orders plus the four MV3 settlement
        // tables. MV4 Task 11 (GATES) adds a 9th: commerce_seller_payout_accounts
        // -- no ordinary request path may ever touch the payout-destination
        // table or its retry/reconcile sweep indexes either.
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
        $service = new PayoutService($payouts, $ledger, new LedgerAccountLock(), $balances);

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
        $service = new PayoutService($payouts, $ledger, new LedgerAccountLock(), $balances);

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
        return new SellerService(new SellerRepository(), new SellerMembershipRepository());
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

    /**
     * MV2 Task 10: wired WITH the marketplace collaborators (unlike
     * `checkoutService()` above, which is byte-identical to the pre-MV2
     * constructor arity) -- used only by the partition-invariance test that
     * needs a genuinely partitioned checkout.
     */
    private function marketplaceCheckoutService(): CheckoutService
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
            new SellerOrderRepository()
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
}
