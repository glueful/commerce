<?php

declare(strict_types=1);

use Glueful\Extensions\Commerce\Http\Storefront\CartController;
use Glueful\Extensions\Commerce\Http\Storefront\CheckoutController;
use Glueful\Extensions\Commerce\Http\Storefront\OrderController;
use Glueful\Extensions\Commerce\Http\Storefront\ProductController;
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
