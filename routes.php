<?php

declare(strict_types=1);

use Glueful\Extensions\Commerce\Http\Storefront\AccountAddressController;
use Glueful\Extensions\Commerce\Http\Storefront\CartController;
use Glueful\Extensions\Commerce\Http\Storefront\CategoryController;
use Glueful\Extensions\Commerce\Http\Storefront\CheckoutController;
use Glueful\Extensions\Commerce\Http\Storefront\DownloadLinkController;
use Glueful\Extensions\Commerce\Http\Storefront\OrderController;
use Glueful\Extensions\Commerce\Http\Storefront\ProductController;
use Glueful\Extensions\Commerce\Http\Storefront\ReviewController;
use Glueful\Extensions\Commerce\Http\Admin\AdminAddonController;
use Glueful\Extensions\Commerce\Http\Admin\AdminAttributeController;
use Glueful\Extensions\Commerce\Http\Admin\AdminCategoryController;
use Glueful\Extensions\Commerce\Http\Admin\AdminCustomerController;
use Glueful\Extensions\Commerce\Http\Admin\AdminDiscountController;
use Glueful\Extensions\Commerce\Http\Admin\AdminDownloadController;
use Glueful\Extensions\Commerce\Http\Admin\AdminGrantController;
use Glueful\Extensions\Commerce\Http\Admin\AdminMarketplaceFinancialController;
use Glueful\Extensions\Commerce\Http\Admin\AdminMediaController;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Http\Admin\AdminPayoutController;
use Glueful\Extensions\Commerce\Http\Admin\AdminProductController;
use Glueful\Extensions\Commerce\Http\Admin\AdminRefundController;
use Glueful\Extensions\Commerce\Http\Admin\AdminReportController;
use Glueful\Extensions\Commerce\Http\Admin\AdminReserveController;
use Glueful\Extensions\Commerce\Http\Admin\AdminReviewController;
use Glueful\Extensions\Commerce\Http\Admin\AdminShippingClassController;
use Glueful\Extensions\Commerce\Http\Admin\AdminShippingZoneController;
use Glueful\Extensions\Commerce\Http\Admin\AdminStockController;
use Glueful\Extensions\Commerce\Http\Admin\AdminTagController;
use Glueful\Extensions\Commerce\Http\Admin\AdminTaxRateController;
use Glueful\Extensions\Commerce\Http\Admin\MarketplaceAdminController;
use Glueful\Extensions\Commerce\Http\Seller\SellerCatalogController;
use Glueful\Extensions\Commerce\Http\Seller\SellerFinancialController;
use Glueful\Extensions\Commerce\Http\Seller\SellerInventoryController;
use Glueful\Extensions\Commerce\Http\Seller\SellerMembershipController;
use Glueful\Extensions\Commerce\Http\Seller\SellerOrderController;
use Glueful\Routing\Router;

/** @var Router $router */

$context = $router->getContext();
$limits = (array) config($context, 'commerce.rate_limits', []);
$tenantMiddleware = (bool) config($context, 'commerce.tenancy.enabled', false) ? ['tenant'] : [];

$rate = static function (string $key, int $attempts, int $window = 60) use ($limits): string {
    $configured = $limits[$key] ?? null;
    if (is_array($configured)) {
        $attempts = (int) ($configured[0] ?? $attempts);
        $window = (int) ($configured[1] ?? $window);
    }

    return 'rate_limit:' . max(1, $attempts) . ',' . max(1, $window);
};

$router->group(
    ['prefix' => '/commerce', 'middleware' => $tenantMiddleware],
    function (Router $router) use ($rate): void {
    // Products currently have NO rate middleware; `catalog` (Layer 6 Global
    // Constraints) is a new config key applied here for the first time, and
    // also covers the public category tree below.
    $catalogRate = $rate('catalog', 120);

    $router->get('/products', [ProductController::class, 'index'])
        ->middleware($catalogRate)
        ->name('commerce.products.index');
    $router->get('/products/{slug}', [ProductController::class, 'show'])
        ->where('slug', '[a-z0-9-]+')
        ->middleware($catalogRate)
        ->name('commerce.products.show');

    $router->get('/categories', [CategoryController::class, 'index'])
        ->middleware($catalogRate)
        ->name('commerce.categories.index');

    // Public review submit + approved list (Layer 6 Global Constraints,
    // storefront-reviews block): the POST uses the stricter `review_submit`
    // key; the GET reuses `catalogRate` (same key the product/category reads
    // above share).
    $router->post('/products/{slug}/reviews', [ReviewController::class, 'store'])
        ->where('slug', '[a-z0-9-]+')
        ->middleware($rate('review_submit', 5))
        ->name('commerce.products.reviews.store');
    $router->get('/products/{slug}/reviews', [ReviewController::class, 'index'])
        ->where('slug', '[a-z0-9-]+')
        ->middleware($catalogRate)
        ->name('commerce.products.reviews.index');

    $cartRate = $rate('cart', 60);
    $checkoutRate = $rate('checkout', 30);
    $orderRate = $rate('orders', 60);

    $router->post('/cart', [CartController::class, 'create'])->middleware($cartRate);
    $router->get('/cart', [CartController::class, 'show'])->middleware($cartRate);
    $router->post('/cart/lines', [CartController::class, 'addLine'])->middleware($cartRate);
    $router->patch('/cart/lines/{uuid}', [CartController::class, 'updateLine'])->middleware($cartRate);
    $router->delete('/cart/lines/{uuid}', [CartController::class, 'removeLine'])->middleware($cartRate);
    $router->post('/cart/discount', [CartController::class, 'applyDiscount'])->middleware($cartRate);
    $router->delete('/cart/discount', [CartController::class, 'removeDiscount'])->middleware($cartRate);

    $router->post('/checkout/quote', [CheckoutController::class, 'quote'])->middleware($checkoutRate);
    $router->post('/checkout', [CheckoutController::class, 'place'])->middleware($checkoutRate);

    $router->get('/orders/{number}', [OrderController::class, 'show'])->middleware($orderRate);
    $router->post('/orders/{number}/payment', [OrderController::class, 'retryPayment'])->middleware($orderRate);
    $router->get('/orders', [OrderController::class, 'mine'])->middleware(['auth', $orderRate]);
    $router->get('/orders/{number}/downloads', [OrderController::class, 'downloads'])->middleware($orderRate);
    $router->post('/orders/{number}/downloads/{grantUuid}/url', [OrderController::class, 'downloadUrl'])
        ->middleware($orderRate);

    $router->get('/downloads/{token}', [DownloadLinkController::class, 'show'])->middleware($rate('downloads', 60));
    }
);

$router->group([
    'prefix' => '/commerce/account',
    'middleware' => array_merge(['auth'], $tenantMiddleware),
], function (Router $router): void {
    $router->get('/addresses', [AccountAddressController::class, 'index']);
    $router->post('/addresses', [AccountAddressController::class, 'store']);
    $router->patch('/addresses/{uuid}', [AccountAddressController::class, 'update']);
    $router->delete('/addresses/{uuid}', [AccountAddressController::class, 'destroy']);
});

$router->group([
    'prefix' => '/commerce/admin',
    'middleware' => array_merge(['auth'], $tenantMiddleware),
], function (Router $router): void {
    $read = 'require_scope:commerce:read';
    $write = 'require_scope:commerce:write';

    $router->get('/products', [AdminProductController::class, 'index'])->middleware($read);
    $router->post('/products', [AdminProductController::class, 'store'])->middleware($write);
    $router->get('/products/{uuid}', [AdminProductController::class, 'show'])->middleware($read);
    $router->patch('/products/{uuid}', [AdminProductController::class, 'update'])->middleware($write);
    $router->post('/products/{uuid}/variants', [AdminProductController::class, 'storeVariant'])->middleware($write);
    $router->patch('/variants/{uuid}', [AdminProductController::class, 'updateVariant'])->middleware($write);
    $router->put('/products/{uuid}/children', [AdminProductController::class, 'setChildren'])->middleware($write);
    $router->delete('/products/{uuid}', [AdminProductController::class, 'destroy'])->middleware($write);
    $router->post('/products/bulk-status', [AdminProductController::class, 'bulkStatus'])->middleware($write);
    $router->post('/variants/bulk-price', [AdminProductController::class, 'bulkPrice'])->middleware($write);

    $router->get('/variants/{uuid}/downloads', [AdminDownloadController::class, 'index'])->middleware($read);
    $router->post('/variants/{uuid}/downloads', [AdminDownloadController::class, 'attach'])->middleware($write);
    $router->patch('/downloads/{uuid}', [AdminDownloadController::class, 'update'])->middleware($write);
    $router->delete('/downloads/{uuid}', [AdminDownloadController::class, 'detach'])->middleware($write);

    $router->post('/grants/{uuid}/revoke', [AdminGrantController::class, 'revoke'])->middleware($write);
    $router->put('/grants/{uuid}/refund-access-override', [AdminGrantController::class, 'setOverride'])
        ->middleware($write);
    $router->delete('/grants/{uuid}/refund-access-override', [AdminGrantController::class, 'clearOverride'])
        ->middleware($write);

    $router->get('/customers', [AdminCustomerController::class, 'index'])->middleware($read);
    $router->get('/customers/{key}', [AdminCustomerController::class, 'show'])->middleware($read);

    $router->post('/products/{uuid}/media', [AdminMediaController::class, 'attach'])->middleware($write);
    $router->put('/products/{uuid}/media/order', [AdminMediaController::class, 'reorder'])->middleware($write);
    $router->patch('/media/{uuid}', [AdminMediaController::class, 'update'])->middleware($write);
    $router->delete('/media/{uuid}', [AdminMediaController::class, 'detach'])->middleware($write);

    $router->get('/categories', [AdminCategoryController::class, 'index'])->middleware($read);
    $router->get('/categories/{uuid}', [AdminCategoryController::class, 'show'])->middleware($read);
    $router->post('/categories', [AdminCategoryController::class, 'store'])->middleware($write);
    $router->patch('/categories/{uuid}', [AdminCategoryController::class, 'update'])->middleware($write);
    $router->delete('/categories/{uuid}', [AdminCategoryController::class, 'destroy'])->middleware($write);
    $router->put('/products/{uuid}/categories', [AdminCategoryController::class, 'setForProduct'])->middleware($write);

    $router->get('/tags', [AdminTagController::class, 'index'])->middleware($read);
    $router->get('/tags/{uuid}', [AdminTagController::class, 'show'])->middleware($read);
    $router->post('/tags', [AdminTagController::class, 'store'])->middleware($write);
    $router->patch('/tags/{uuid}', [AdminTagController::class, 'update'])->middleware($write);
    $router->delete('/tags/{uuid}', [AdminTagController::class, 'destroy'])->middleware($write);
    $router->put('/products/{uuid}/tags', [AdminTagController::class, 'setForProduct'])->middleware($write);

    $router->get('/attributes', [AdminAttributeController::class, 'index'])->middleware($read);
    $router->get('/attributes/{uuid}', [AdminAttributeController::class, 'show'])->middleware($read);
    $router->post('/attributes', [AdminAttributeController::class, 'store'])->middleware($write);
    $router->patch('/attributes/{uuid}', [AdminAttributeController::class, 'update'])->middleware($write);
    $router->delete('/attributes/{uuid}', [AdminAttributeController::class, 'destroy'])->middleware($write);
    $router->post('/attributes/{uuid}/values', [AdminAttributeController::class, 'storeValue'])->middleware($write);
    $router->patch('/attribute-values/{uuid}', [AdminAttributeController::class, 'updateValue'])
        ->middleware($write);
    $router->delete('/attribute-values/{uuid}', [AdminAttributeController::class, 'destroyValue'])
        ->middleware($write);
    $router->put('/products/{uuid}/attributes', [AdminAttributeController::class, 'setForProduct'])
        ->middleware($write);

    $router->get('/products/{uuid}/addons', [AdminAddonController::class, 'index'])->middleware($read);
    $router->post('/products/{uuid}/addons', [AdminAddonController::class, 'store'])->middleware($write);
    $router->patch('/addons/{uuid}', [AdminAddonController::class, 'update'])->middleware($write);
    $router->delete('/addons/{uuid}', [AdminAddonController::class, 'destroy'])->middleware($write);

    $router->post('/stock/{variantUuid}/adjust', [AdminStockController::class, 'adjust'])->middleware($write);

    $router->get('/discounts', [AdminDiscountController::class, 'index'])->middleware($read);
    $router->post('/discounts', [AdminDiscountController::class, 'store'])->middleware($write);
    $router->get('/discounts/{uuid}', [AdminDiscountController::class, 'show'])->middleware($read);
    $router->patch('/discounts/{uuid}', [AdminDiscountController::class, 'update'])->middleware($write);
    $router->delete('/discounts/{uuid}', [AdminDiscountController::class, 'destroy'])->middleware($write);

    $router->get('/orders', [AdminOrderController::class, 'index'])->middleware($read);
    $router->get('/orders/{uuid}', [AdminOrderController::class, 'show'])->middleware($read);
    $router->post('/orders/{uuid}/cancel', [AdminOrderController::class, 'cancel'])->middleware($write);
    $router->post('/orders/{uuid}/mark-paid', [AdminOrderController::class, 'markPaid'])->middleware($write);
    $router->post('/orders/{uuid}/fulfill', [AdminOrderController::class, 'fulfill'])->middleware($write);
    $router->post('/orders/{uuid}/refunds', [AdminRefundController::class, 'store'])->middleware($write);
    $router->get('/orders/{uuid}/refunds', [AdminRefundController::class, 'index'])->middleware($read);
    $router->post('/orders/{uuid}/notes', [AdminOrderController::class, 'addNote'])->middleware($write);
    $router->get('/orders/{uuid}/notes', [AdminOrderController::class, 'notes'])->middleware($read);
    $router->get('/orders/{uuid}/invoice-data', [AdminOrderController::class, 'invoiceData'])->middleware($read);

    $router->get('/refunds', [AdminRefundController::class, 'list'])->middleware($read);
    $router->get('/refunds/{uuid}', [AdminRefundController::class, 'show'])->middleware($read);

    $router->get('/reviews', [AdminReviewController::class, 'index'])->middleware($read);
    $router->get('/reviews/{uuid}', [AdminReviewController::class, 'show'])->middleware($read);
    $router->post('/reviews', [AdminReviewController::class, 'store'])->middleware($write);
    $router->post('/reviews/{uuid}/approve', [AdminReviewController::class, 'approve'])->middleware($write);
    $router->post('/reviews/{uuid}/spam', [AdminReviewController::class, 'spam'])->middleware($write);
    $router->delete('/reviews/{uuid}', [AdminReviewController::class, 'destroy'])->middleware($write);
    $router->post('/reviews/bulk', [AdminReviewController::class, 'bulk'])->middleware($write);

    $router->get('/shipping/zones', [AdminShippingZoneController::class, 'index'])->middleware($read);
    $router->get('/shipping/zones/{uuid}', [AdminShippingZoneController::class, 'show'])->middleware($read);
    $router->post('/shipping/zones', [AdminShippingZoneController::class, 'store'])->middleware($write);
    $router->patch('/shipping/zones/{uuid}', [AdminShippingZoneController::class, 'update'])->middleware($write);
    $router->delete('/shipping/zones/{uuid}', [AdminShippingZoneController::class, 'destroy'])->middleware($write);
    $router->put('/shipping/zones/{uuid}/locations', [AdminShippingZoneController::class, 'setLocations'])
        ->middleware($write);
    $router->get('/shipping/zones/{uuid}/methods', [AdminShippingZoneController::class, 'indexMethods'])
        ->middleware($read);
    $router->post('/shipping/zones/{uuid}/methods', [AdminShippingZoneController::class, 'storeMethod'])
        ->middleware($write);
    $router->get('/shipping/methods/{uuid}', [AdminShippingZoneController::class, 'showMethod'])
        ->middleware($read);
    $router->patch('/shipping/methods/{uuid}', [AdminShippingZoneController::class, 'updateMethod'])
        ->middleware($write);
    $router->delete('/shipping/methods/{uuid}', [AdminShippingZoneController::class, 'destroyMethod'])
        ->middleware($write);

    $router->get('/shipping/classes', [AdminShippingClassController::class, 'index'])->middleware($read);
    $router->get('/shipping/classes/{uuid}', [AdminShippingClassController::class, 'show'])->middleware($read);
    $router->post('/shipping/classes', [AdminShippingClassController::class, 'store'])->middleware($write);
    $router->patch('/shipping/classes/{uuid}', [AdminShippingClassController::class, 'update'])->middleware($write);
    $router->delete('/shipping/classes/{uuid}', [AdminShippingClassController::class, 'destroy'])
        ->middleware($write);

    $router->get('/tax/rates', [AdminTaxRateController::class, 'index'])->middleware($read);
    $router->get('/tax/rates/{uuid}', [AdminTaxRateController::class, 'show'])->middleware($read);
    $router->post('/tax/rates', [AdminTaxRateController::class, 'store'])->middleware($write);
    $router->patch('/tax/rates/{uuid}', [AdminTaxRateController::class, 'update'])->middleware($write);
    $router->delete('/tax/rates/{uuid}', [AdminTaxRateController::class, 'destroy'])->middleware($write);

    $router->get('/reports/sales', [AdminReportController::class, 'sales'])->middleware($read);
    $router->get('/reports/products', [AdminReportController::class, 'products'])->middleware($read);
    $router->get('/reports/customers', [AdminReportController::class, 'customers'])->middleware($read);
    $router->get('/reports/stock', [AdminReportController::class, 'stock'])->middleware($read);
});

// Marketplace MV1 (design spec §2.1/§2.8): the WHOLE group registers ONLY when
// the install master switch is on -- mirrors the `$tenantMiddleware` config
// read above, never `MarketplaceMode::installEnabled()` (routes.php has no
// container-resolved instance to call it on, and the semantics are identical:
// `commerce.marketplace.enabled` only, zero database reads). These are
// OPERATOR FOUNDATION surfaces (seller/membership CRUD) -- usable while a
// workspace is INACTIVE (design spec §2.3) -- so, unlike future seller-member
// surfaces, there is no additional `activeFor()`/per-workspace gate here.
// Nested under the SAME `/commerce/admin` stack, preserving the identical
// `auth -> optional tenant -> require_scope:commerce:read|write` composition
// order the group above uses.
$marketplaceEnabled = (bool) config($context, 'commerce.marketplace.enabled', false);

if ($marketplaceEnabled) {
    $router->group([
        'prefix' => '/commerce/admin',
        'middleware' => array_merge(['auth'], $tenantMiddleware),
    ], function (Router $router): void {
        $read = 'require_scope:commerce:read';
        $write = 'require_scope:commerce:write';

        $router->get('/marketplace/sellers', [MarketplaceAdminController::class, 'indexSellers'])
            ->middleware($read);
        $router->post('/marketplace/sellers', [MarketplaceAdminController::class, 'storeSeller'])
            ->middleware($write);
        $router->get('/marketplace/sellers/{uuid}', [MarketplaceAdminController::class, 'showSeller'])
            ->middleware($read);
        $router->patch('/marketplace/sellers/{uuid}', [MarketplaceAdminController::class, 'updateSeller'])
            ->middleware($write);
        $router->post('/marketplace/sellers/{uuid}/suspend', [MarketplaceAdminController::class, 'suspendSeller'])
            ->middleware($write);
        $router->post(
            '/marketplace/sellers/{uuid}/reactivate',
            [MarketplaceAdminController::class, 'reactivateSeller']
        )->middleware($write);
        $router->post('/marketplace/sellers/{uuid}/close', [MarketplaceAdminController::class, 'closeSeller'])
            ->middleware($write);

        $router->get(
            '/marketplace/sellers/{uuid}/memberships',
            [MarketplaceAdminController::class, 'indexMemberships']
        )->middleware($read);
        $router->post(
            '/marketplace/sellers/{uuid}/memberships',
            [MarketplaceAdminController::class, 'storeMembership']
        )->middleware($write);
        $router->patch(
            '/marketplace/sellers/{uuid}/memberships/{userUuid}',
            [MarketplaceAdminController::class, 'updateMembership']
        )->middleware($write);
        $router->delete(
            '/marketplace/sellers/{uuid}/memberships/{userUuid}',
            [MarketplaceAdminController::class, 'destroyMembership']
        )->middleware($write);

        // Activation + catalog attribution (Task 3, design spec §2.2/§2.7):
        // also OPERATOR FOUNDATION surfaces -- activation config precedes
        // activation itself, and adopt/transfer are the promised
        // inactive-mode repair surface (design spec §2.3) -- so, like the
        // seller/membership routes above, there is no additional
        // `activeFor()` gate here either.
        $router->post('/marketplace/activate', [MarketplaceAdminController::class, 'activate'])
            ->middleware($write);
        $router->post('/marketplace/deactivate', [MarketplaceAdminController::class, 'deactivate'])
            ->middleware($write);
        $router->post(
            '/marketplace/products/{uuid}/assign',
            [MarketplaceAdminController::class, 'assignSeller']
        )->middleware($write);

        // Commission-policy authority (design spec §2.3, MV3 Task 4): product/
        // seller commission ride the EXISTING `/products/{uuid}` and
        // `/marketplace/sellers/{uuid}` update routes above (CatalogService::
        // updateProduct()/SellerService::update() route commission fields
        // through CommissionPolicyService); the workspace-settings level has no
        // prior update surface, so it gets a dedicated route here.
        $router->patch(
            '/marketplace/settings/commission',
            [MarketplaceAdminController::class, 'updateWorkspaceCommission']
        )->middleware($write);

        // Manual payouts + operator adjustments (design spec §2.10/§6.1, MV3 Task 9): both
        // POST-only, write-gated, ledger-backed settlement mutations -- gated the SAME way as
        // every other route in this group (marketplace enabled), since neither has any
        // meaning without a seller account that could ever carry a ledger balance.
        $router->post('/marketplace/payouts', [AdminPayoutController::class, 'storePayout'])
            ->middleware($write);
        $router->post('/marketplace/adjustments', [AdminPayoutController::class, 'storeAdjustment'])
            ->middleware($write);

        // Provider-payout surfaces (design spec §2.3/§2.6/§2.7, MV4 Task 10): single-seller
        // execute, retry-a-specific-payout, and account attach/sync -- gated the SAME way as
        // the manual payout/adjustment routes above. Deliberately NO batch endpoint (CLI-only,
        // design spec §2.6) and NO reverse/clawback endpoint (provider-reported only, §2.8).
        $router->post('/marketplace/payouts/execute', [AdminPayoutController::class, 'executePayout'])
            ->middleware($write);
        $router->post('/marketplace/payouts/{uuid}/retry', [AdminPayoutController::class, 'retryPayout'])
            ->middleware($write);
        $router->post('/marketplace/payouts/accounts', [AdminPayoutController::class, 'attachPayoutAccount'])
            ->middleware($write);
        $router->post(
            '/marketplace/payouts/accounts/sync',
            [AdminPayoutController::class, 'syncPayoutAccount']
        )->middleware($write);

        // Operator financial surfaces (design spec §6.1, MV3 Task 11): the
        // marketplace account's own summary plus any seller's balance/report --
        // read-only, gated the SAME way as every other route in this group.
        $router->get(
            '/marketplace/financials/summary',
            [AdminMarketplaceFinancialController::class, 'marketplaceSummary']
        )->middleware($read);
        $router->get(
            '/marketplace/sellers/{uuid}/balance',
            [AdminMarketplaceFinancialController::class, 'sellerBalance']
        )->middleware($read);
        $router->get(
            '/marketplace/sellers/{uuid}/report',
            [AdminMarketplaceFinancialController::class, 'sellerReport']
        )->middleware($read);

        // Reserve/chargeback/debt surfaces (design spec §6, MV5a Task 16): reserve
        // policy (workspace + per-seller), chargeback ingestion + partial attribution,
        // manual reserve hold/release, audited debt forgiveness, and a read of any
        // seller's reserves + debt -- gated the SAME way as every other route in this
        // group. Manual hold and debt forgiveness REQUIRE the HTTP `Idempotency-Key`
        // header (AdminReserveController checks it itself, mirroring
        // AdminRefundController::store()); every route derives its tenant from the
        // resolved CurrentTenantResolver, never a request-body field (design spec §6
        // tenant binding). Deliberately NO "reverse a chargeback" route anywhere --
        // reversals are provider-reported only (design spec §2.10), delivered
        // exclusively through ProviderChargebackListener.
        $router->patch(
            '/marketplace/settings/reserves',
            [AdminReserveController::class, 'updateWorkspacePolicy']
        )->middleware($write);
        $router->patch(
            '/marketplace/sellers/{uuid}/reserve-policy',
            [AdminReserveController::class, 'updateSellerPolicy']
        )->middleware($write);

        $router->post('/marketplace/chargebacks', [AdminReserveController::class, 'ingestChargeback'])
            ->middleware($write);
        $router->post(
            '/marketplace/chargebacks/{uuid}/attribution',
            [AdminReserveController::class, 'attributeChargeback']
        )->middleware($write);

        $router->post('/marketplace/reserves/holds', [AdminReserveController::class, 'manualHold'])
            ->middleware($write);
        $router->post('/marketplace/reserves/{uuid}/release', [AdminReserveController::class, 'manualRelease'])
            ->middleware($write);

        $router->post(
            '/marketplace/sellers/{uuid}/debt/forgive',
            [AdminReserveController::class, 'forgiveDebt']
        )->middleware($write);
        $router->get(
            '/marketplace/sellers/{uuid}/reserves',
            [AdminReserveController::class, 'sellerReserves']
        )->middleware($read);

        // Marketplace MV2 (design spec §6.2, Task 9): operator fulfills ANY
        // seller order directly. Gated the SAME way as every other route in
        // this group -- with the install master switch off, a
        // `marketplace_partitioned` order can never exist (nothing ever wrote
        // one), so this endpoint has nothing to do; keeping it out of the
        // manifest preserves `MarketplaceRegressionTest`'s pre-MV1
        // byte-identical route-manifest proof exactly like the sibling
        // `/marketplace/*` routes above already do.
        $router->post(
            '/orders/{uuid}/seller-orders/{sellerOrderUuid}/fulfill',
            [AdminOrderController::class, 'fulfillSellerOrder']
        )->middleware($write);
    });
}

// Seller-scoped surfaces (design spec §2.5/§2.8, MV1 Task 4): config-gated the
// SAME way as the marketplace admin group above. `auth` [+ `tenant` when
// tenancy is enabled] composes EXACTLY like the admin/account groups do --
// `array_merge(['auth'], $tenantMiddleware)` -- with `commerce_seller:<capability>`
// added per route (sentinel mode: `auth -> commerce_seller` only, no tenant
// hop). `mine` carries NO `commerce_seller` middleware: it has no
// `{sellerUuid}` route resource to authorize against, it lists the caller's
// OWN active memberships.
if ($marketplaceEnabled) {
    $router->group([
        'prefix' => '/commerce/seller',
        'middleware' => array_merge(['auth'], $tenantMiddleware),
    ], function (Router $router): void {
        $catalogRead = 'commerce_seller:commerce.seller.catalog.read';
        $catalogWrite = 'commerce_seller:commerce.seller.catalog.write';
        $inventoryRead = 'commerce_seller:commerce.seller.inventory.read';
        $inventoryWrite = 'commerce_seller:commerce.seller.inventory.write';
        $membersManage = 'commerce_seller:commerce.seller.members.manage';
        $ordersRead = 'commerce_seller:commerce.seller.orders.read';
        $ordersFulfill = 'commerce_seller:commerce.seller.orders.fulfill';
        $reportsRead = 'commerce_seller:commerce.seller.reports.read';
        $payoutsRead = 'commerce_seller:commerce.seller.payouts.read';

        $router->get('/mine', [SellerMembershipController::class, 'mine']);

        $router->get('/{sellerUuid}/products', [SellerCatalogController::class, 'index'])
            ->middleware($catalogRead);
        $router->post('/{sellerUuid}/products', [SellerCatalogController::class, 'store'])
            ->middleware($catalogWrite);
        $router->get('/{sellerUuid}/products/{uuid}', [SellerCatalogController::class, 'show'])
            ->middleware($catalogRead);
        $router->patch('/{sellerUuid}/products/{uuid}', [SellerCatalogController::class, 'update'])
            ->middleware($catalogWrite);
        $router->post('/{sellerUuid}/products/{uuid}/variants', [SellerCatalogController::class, 'storeVariant'])
            ->middleware($catalogWrite);

        $router->get('/{sellerUuid}/variants/{variantUuid}/stock', [SellerInventoryController::class, 'show'])
            ->middleware($inventoryRead);
        $router->post(
            '/{sellerUuid}/variants/{variantUuid}/stock/adjust',
            [SellerInventoryController::class, 'adjust']
        )->middleware($inventoryWrite);

        $router->get('/{sellerUuid}/members', [SellerMembershipController::class, 'index'])
            ->middleware($membersManage);
        $router->post('/{sellerUuid}/members', [SellerMembershipController::class, 'store'])
            ->middleware($membersManage);
        $router->patch('/{sellerUuid}/members/{userUuid}', [SellerMembershipController::class, 'update'])
            ->middleware($membersManage);
        $router->delete('/{sellerUuid}/members/{userUuid}', [SellerMembershipController::class, 'destroy'])
            ->middleware($membersManage);

        // Seller order surfaces (design spec §6.1/§2.12, MV2 Task 8): the
        // confirmed_at payment-confirmation gate lives at the
        // SellerOrderService/SellerOrderFulfillmentService layer, never here --
        // this middleware only authorizes the caller against the route
        // {sellerUuid}, exactly like every other seller-scoped route above.
        $router->get('/{sellerUuid}/orders', [SellerOrderController::class, 'index'])
            ->middleware($ordersRead);
        $router->get('/{sellerUuid}/orders/{sellerOrderUuid}', [SellerOrderController::class, 'show'])
            ->middleware($ordersRead);
        $router->post('/{sellerUuid}/orders/{sellerOrderUuid}/fulfill', [SellerOrderController::class, 'fulfill'])
            ->middleware($ordersFulfill);

        // Seller financial surfaces (design spec §6.2, MV3 Task 11): windowed
        // report, live balance + components, payouts, and the effective
        // commission policy -- all read-only, own seller only. `reports.read`
        // gates report/balance/commission-policy; `payouts.read` gates payouts
        // (design spec §6.2 -- commission-policy read folds into `reports.read`).
        $router->get('/{sellerUuid}/financials/report', [SellerFinancialController::class, 'report'])
            ->middleware($reportsRead);
        $router->get('/{sellerUuid}/financials/balance', [SellerFinancialController::class, 'balance'])
            ->middleware($reportsRead);
        // Reserves + upcoming releases + debt (design spec §6, MV5a Task 16): SANITIZED
        // allow-list projection, own seller only -- same gate as the balance/report
        // reads above.
        $router->get('/{sellerUuid}/financials/reserves', [SellerFinancialController::class, 'reserves'])
            ->middleware($reportsRead);
        $router->get('/{sellerUuid}/payouts', [SellerFinancialController::class, 'payouts'])
            ->middleware($payoutsRead);
        // Payout-DESTINATION readiness (design spec §6.2/§2.7, MV4 Task 10): provider/state/
        // last-synced only -- NEVER the opaque account_ref. Read-only, own seller only, same
        // capability gate as the payouts list above.
        $router->get('/{sellerUuid}/payouts/accounts', [SellerFinancialController::class, 'payoutAccounts'])
            ->middleware($payoutsRead);
        $router->get('/{sellerUuid}/commission-policy', [SellerFinancialController::class, 'commissionPolicy'])
            ->middleware($reportsRead);
    });
}
