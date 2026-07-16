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
use Glueful\Extensions\Commerce\Http\Admin\AdminMediaController;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Http\Admin\AdminProductController;
use Glueful\Extensions\Commerce\Http\Admin\AdminRefundController;
use Glueful\Extensions\Commerce\Http\Admin\AdminReportController;
use Glueful\Extensions\Commerce\Http\Admin\AdminReviewController;
use Glueful\Extensions\Commerce\Http\Admin\AdminShippingClassController;
use Glueful\Extensions\Commerce\Http\Admin\AdminShippingZoneController;
use Glueful\Extensions\Commerce\Http\Admin\AdminStockController;
use Glueful\Extensions\Commerce\Http\Admin\AdminTagController;
use Glueful\Extensions\Commerce\Http\Admin\AdminTaxRateController;
use Glueful\Routing\Router;

/** @var Router $router */

$context = $router->getContext();
$limits = (array) config($context, 'commerce.rate_limits', []);

$rate = static function (string $key, int $attempts, int $window = 60) use ($limits): string {
    $configured = $limits[$key] ?? null;
    if (is_array($configured)) {
        $attempts = (int) ($configured[0] ?? $attempts);
        $window = (int) ($configured[1] ?? $window);
    }

    return 'rate_limit:' . max(1, $attempts) . ',' . max(1, $window);
};

$router->group(['prefix' => '/commerce'], function (Router $router) use ($rate): void {
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
});

$router->group(['prefix' => '/commerce/account', 'middleware' => ['auth']], function (Router $router): void {
    $router->get('/addresses', [AccountAddressController::class, 'index']);
    $router->post('/addresses', [AccountAddressController::class, 'store']);
    $router->patch('/addresses/{uuid}', [AccountAddressController::class, 'update']);
    $router->delete('/addresses/{uuid}', [AccountAddressController::class, 'destroy']);
});

$router->group(['prefix' => '/commerce/admin', 'middleware' => ['auth']], function (Router $router): void {
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
