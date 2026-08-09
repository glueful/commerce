<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Cart\CartRepository;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\DownloadRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Http\DTOs\CheckoutPlaceData;
use Glueful\Extensions\Commerce\Http\DTOs\OrderListQuery;
use Glueful\Extensions\Commerce\Http\Storefront\CartController;
use Glueful\Extensions\Commerce\Http\Storefront\CheckoutController;
use Glueful\Extensions\Commerce\Http\Storefront\OrderController;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Symfony\Component\HttpFoundation\Request;

final class StorefrontTest extends CommerceTestCase
{
    public function testCartCreateReturnsRawTokenOnceAndHeaderShowsCart(): void
    {
        $create = $this->cartController()->create(Request::create('/commerce/cart', 'POST'));
        $body = $this->json($create);

        self::assertSame(201, $create->getStatusCode());
        self::assertSame(40, strlen((string) $body['data']['token']));
        self::assertArrayNotHasKey('token_hash', $body['data']['cart']);

        $show = Request::create('/commerce/cart', 'GET');
        $show->headers->set('X-Cart-Token', (string) $body['data']['token']);

        self::assertSame(200, $this->cartController()->show($show)->getStatusCode());
    }

    public function testOrderLookupIsEnumerationSafe(): void
    {
        $placed = $this->placeSimpleOrder();
        $number = (string) $placed['order']['order_number'];

        $req = Request::create("/commerce/orders/{$number}", 'GET');
        $req->headers->set('X-Order-Token', 'wrong-token');

        try {
            $this->orderController()->show($req, $number);
            self::fail('expected NotFoundException');
        } catch (NotFoundException $a) {
            try {
                $this->orderController()->show($req, 'ORD-999999');
                self::fail('expected NotFoundException');
            } catch (NotFoundException $b) {
                self::assertSame($a->getMessage(), $b->getMessage());
            }
        }
    }

    public function testQueryStringOrderTokenIsIgnored(): void
    {
        $placed = $this->placeSimpleOrder();
        $number = (string) $placed['order']['order_number'];
        $req = Request::create("/commerce/orders/{$number}?guest_token={$placed['guest_token']}", 'GET');

        $this->expectException(NotFoundException::class);
        $this->orderController()->show($req, $number);
    }

    public function testRightOrderHeaderTokenShowsOrder(): void
    {
        $placed = $this->placeSimpleOrder();
        $number = (string) $placed['order']['order_number'];
        $req = Request::create("/commerce/orders/{$number}", 'GET');
        $req->headers->set('X-Order-Token', (string) $placed['guest_token']);

        $res = $this->orderController()->show($req, $number);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame($number, $this->json($res)['data']['order_number']);
    }

    /**
     * Admin-order-creation cycle 2, Task 6 (design spec §2.6): an admin-created order
     * mints no guest-access credential, so `guest_token_hash` is NULL on that row.
     * {@see OrderController::tokenMatches()}'s `isset($order['guest_token_hash'])`
     * already returns false for a NULL array value with no code change required --
     * this pins that a NULL row genuinely grants no guest access, for any header
     * value including one that would otherwise coincidentally hash-match an empty
     * credential.
     */
    public function testNullGuestTokenHashGrantsNoGuestAccess(): void
    {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => 'walkinnoacc1',
            'tenant_uuid' => '',
            'order_number' => 'ORD-WALKINNOACC',
            'status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'email' => null,
            'user_uuid' => null,
            'guest_token_hash' => null,
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
        ]);

        $req = Request::create('/commerce/orders/ORD-WALKINNOACC', 'GET');
        $req->headers->set('X-Order-Token', 'some-header-value');

        $this->expectException(NotFoundException::class);
        $this->orderController()->show($req, 'ORD-WALKINNOACC');
    }

    public function testCheckoutShortStockReturnsConflictShape(): void
    {
        [$token, $variantUuid] = $this->seedCartWithLine('SKU-HTTP-SHORT', 1, 1, 1000);
        $cart = $this->cart()->byToken($this->context, $token);
        self::assertNotNull($cart);
        $line = $this->connection->table('commerce_cart_lines')
            ->where('cart_uuid', '=', $cart['uuid'])
            ->first();
        self::assertNotNull($line);
        $this->connection->table('commerce_cart_lines')
            ->where('uuid', '=', $line['uuid'])
            ->update(['quantity' => 2]);

        $request = Request::create('/commerce/checkout', 'POST', [], [], [], [], json_encode([
            'buyer' => ['email' => 'buyer@example.com'],
            'addresses' => ['shipping' => ['country' => 'US'], 'billing' => ['country' => 'US']],
            'shipping_method' => 'std',
        ], JSON_THROW_ON_ERROR));
        $request->headers->set('X-Cart-Token', $token);
        $request->headers->set('Content-Type', 'application/json');

        $response = $this->checkoutController()->place(
            new CheckoutPlaceData(
                buyer: ['email' => 'buyer@example.com'],
                addresses: ['shipping' => ['country' => 'US'], 'billing' => ['country' => 'US']],
                shipping_method: 'std'
            ),
            $request
        );
        $body = $this->json($response);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame($variantUuid, $body['error']['details']['short'][0]['variant_uuid']);
        self::assertSame('SKU-HTTP-SHORT', $body['error']['details']['short'][0]['sku']);
    }

    public function testMyOrdersListIsPaginatedAndScopedToCurrentUser(): void
    {
        $this->placeSimpleOrder('SKU-MINE-A', 'user-a');
        $this->placeSimpleOrder('SKU-MINE-B', 'user-a');
        $this->placeSimpleOrder('SKU-MINE-C', 'user-b');

        $request = Request::create('/commerce/orders?page=1&per_page=1', 'GET');
        $request->attributes->set('user', ['uuid' => 'user-a']);

        $response = $this->orderController()->mine(new OrderListQuery(page: 1, per_page: 1), $request);
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $body['current_page']);
        self::assertSame(1, $body['per_page']);
        self::assertSame(2, $body['total']);
        self::assertCount(1, $body['data']);
        self::assertSame('user-a', $body['data'][0]['user_uuid']);
    }

    /** @return array{order: array<string,mixed>, guest_token: string, payment: array<string,mixed>} */
    private function placeSimpleOrder(string $sku = 'SKU-HTTP', ?string $userUuid = null): array
    {
        [$token] = $this->seedCartWithLine($sku, 5, 1, 1000);

        return $this->checkout()->placeOrder(
            $this->context,
            $token,
            ['email' => 'buyer@example.com', 'user_uuid' => $userUuid],
            ['shipping' => ['country' => 'US'], 'billing' => ['country' => 'US']],
            'std'
        );
    }

    private function cartController(): CartController
    {
        return new CartController($this->context, $this->cart());
    }

    private function checkoutController(): CheckoutController
    {
        return new CheckoutController($this->context, $this->cart(), $this->checkout());
    }

    private function orderController(): OrderController
    {
        return new OrderController(
            $this->context,
            new OrderRepository(),
            $this->checkout(),
            new SentinelTenantResolver(),
            new RefundRepository()
        );
    }

    private function checkout(): CheckoutService
    {
        return new CheckoutService(
            $this->cart(),
            new DiscountRepository(),
            new DiscountService(new DiscountRepository(), new SentinelTenantResolver()),
            new StockRepository(),
            new PricingEngine(),
            $this->shipping(),
            $this->tax(),
            new OrderNumberGenerator(),
            new OrderRepository(),
            new DownloadRepository(),
            new ManualPaymentCollector(),
            new SentinelTenantResolver()
        );
    }

    private function cart(): CartService
    {
        return new CartService(
            new CartRepository(),
            new VariantRepository(),
            new ProductRepository(),
            new StockRepository(),
            new DiscountRepository(),
            new PricingEngine(),
            new SentinelTenantResolver()
        );
    }

    /** @return array{0: string, 1: string} */
    private function seedCartWithLine(string $sku, int $stock, int $quantity, int $price): array
    {
        $catalog = new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            new SentinelTenantResolver(),
            new StockRepository()
        );
        $product = $catalog->createProduct($this->context, [
            'slug' => strtolower($sku),
            'name' => $sku,
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => $sku,
                'option_values' => [],
                'price' => $price,
                'currency' => 'USD',
            ]],
        ]);
        $variantUuid = (string) $product['variants'][0]['uuid'];
        (new StockRepository())->increment($this->context, '', $variantUuid, $stock);
        ['cart' => $cart, 'token' => $token] = $this->cart()->create($this->context);
        $this->cart()->addLine($this->context, $cart, $variantUuid, $quantity);

        return [$token, $variantUuid];
    }

    private function shipping(): ShippingRateProvider
    {
        return new class implements ShippingRateProvider {
            public function quote(ApplicationContext $context, array $lines, array $shippingAddress): array
            {
                return [new ShippingQuote('std', 'Standard', 500)];
            }
        };
    }

    private function tax(): TaxCalculator
    {
        return new class implements TaxCalculator {
            public function quote(ApplicationContext $context, int $taxableAmount, array $shippingAddress): TaxQuote
            {
                return new TaxQuote(0);
            }
        };
    }

    /** @return array<string,mixed> */
    private function json(\Symfony\Component\HttpFoundation\Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
