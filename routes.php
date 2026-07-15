<?php

declare(strict_types=1);

use Glueful\Extensions\Commerce\Http\Storefront\CartController;
use Glueful\Extensions\Commerce\Http\Storefront\CheckoutController;
use Glueful\Extensions\Commerce\Http\Storefront\OrderController;
use Glueful\Extensions\Commerce\Http\Storefront\ProductController;
use Glueful\Extensions\Commerce\Http\Admin\AdminAddonController;
use Glueful\Extensions\Commerce\Http\Admin\AdminAttributeController;
use Glueful\Extensions\Commerce\Http\Admin\AdminCategoryController;
use Glueful\Extensions\Commerce\Http\Admin\AdminDiscountController;
use Glueful\Extensions\Commerce\Http\Admin\AdminMediaController;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Http\Admin\AdminProductController;
use Glueful\Extensions\Commerce\Http\Admin\AdminRefundController;
use Glueful\Extensions\Commerce\Http\Admin\AdminStockController;
use Glueful\Extensions\Commerce\Http\Admin\AdminTagController;
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
    $router->get('/products', [ProductController::class, 'index'])
        ->name('commerce.products.index');
    $router->get('/products/{slug}', [ProductController::class, 'show'])
        ->where('slug', '[a-z0-9-]+')
        ->name('commerce.products.show');

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

    $router->post('/products/{uuid}/media', [AdminMediaController::class, 'attach'])->middleware($write);
    $router->put('/products/{uuid}/media/order', [AdminMediaController::class, 'reorder'])->middleware($write);
    $router->patch('/media/{uuid}', [AdminMediaController::class, 'update'])->middleware($write);
    $router->delete('/media/{uuid}', [AdminMediaController::class, 'detach'])->middleware($write);

    $router->get('/categories', [AdminCategoryController::class, 'index'])->middleware($read);
    $router->post('/categories', [AdminCategoryController::class, 'store'])->middleware($write);
    $router->patch('/categories/{uuid}', [AdminCategoryController::class, 'update'])->middleware($write);
    $router->delete('/categories/{uuid}', [AdminCategoryController::class, 'destroy'])->middleware($write);
    $router->put('/products/{uuid}/categories', [AdminCategoryController::class, 'setForProduct'])->middleware($write);

    $router->get('/tags', [AdminTagController::class, 'index'])->middleware($read);
    $router->post('/tags', [AdminTagController::class, 'store'])->middleware($write);
    $router->delete('/tags/{uuid}', [AdminTagController::class, 'destroy'])->middleware($write);
    $router->put('/products/{uuid}/tags', [AdminTagController::class, 'setForProduct'])->middleware($write);

    $router->get('/attributes', [AdminAttributeController::class, 'index'])->middleware($read);
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
    $router->patch('/discounts/{uuid}', [AdminDiscountController::class, 'update'])->middleware($write);

    $router->get('/orders', [AdminOrderController::class, 'index'])->middleware($read);
    $router->get('/orders/{uuid}', [AdminOrderController::class, 'show'])->middleware($read);
    $router->post('/orders/{uuid}/cancel', [AdminOrderController::class, 'cancel'])->middleware($write);
    $router->post('/orders/{uuid}/mark-paid', [AdminOrderController::class, 'markPaid'])->middleware($write);
    $router->post('/orders/{uuid}/fulfill', [AdminOrderController::class, 'fulfill'])->middleware($write);
    $router->post('/orders/{uuid}/refunds', [AdminRefundController::class, 'store'])->middleware($write);
    $router->get('/orders/{uuid}/refunds', [AdminRefundController::class, 'index'])->middleware($read);
    $router->post('/orders/{uuid}/notes', [AdminOrderController::class, 'addNote'])->middleware($write);
    $router->get('/orders/{uuid}/invoice-data', [AdminOrderController::class, 'invoiceData'])->middleware($read);
});
