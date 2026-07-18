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
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Http\DTOs\FulfillOrderData;
use Glueful\Extensions\Commerce\Http\DTOs\FulfillSellerOrderData;
use Glueful\Extensions\Commerce\Invoices\ConfigSellerIdentityProvider;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderFulfillmentService;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderPaymentConfirmation;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Symfony\Component\HttpFoundation\Request;

/**
 * Operator order surfaces over `marketplace_partitioned` orders (design spec
 * §6.2/§2.9, MV2 Task 9): `show()` gains a `seller_orders` breakdown ONLY
 * when partitioned (operator-trusted, includes an unconfirmed child too --
 * operator sees everything, unlike the seller/customer-facing surfaces);
 * the new `POST …/{uuid}/seller-orders/{sellerOrderUuid}/fulfill` endpoint
 * lets an operator fulfill ANY child; the partitioned `POST …/{uuid}/fulfill`
 * becomes the operator fan-out; a non-partitioned order's `fulfill()` stays
 * byte-identical to pre-MV2.
 */
final class AdminOrderMarketplaceTest extends CommerceTestCase
{
    private const TENANT = '';

    // -----------------------------------------------------------------
    // show() breakdown
    // -----------------------------------------------------------------

    public function testShowIncludesSellerOrderBreakdownOnlyWhenPartitioned(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $this->seedSeller('sellerBBBB01', 'Seller B');
        $productX = $this->seedProduct('breakdown-x', 1000, 'sellerAAAA01');
        $productY = $this->seedProduct('breakdown-y', 2000, 'sellerBBBB01');

        $order = $this->placeTwoSellerOrder($productX, $productY);

        $response = $this->adminController()->show(
            Request::create('/commerce/admin/orders/' . $order['uuid'], 'GET'),
            (string) $order['uuid']
        );
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        $sellerOrders = $body['data']['seller_orders'];
        self::assertCount(2, $sellerOrders, 'the breakdown includes the still-unconfirmed child too');

        foreach ($sellerOrders as $row) {
            self::assertArrayHasKey('seller_uuid', $row, 'operator surface is full-visibility');
            self::assertArrayHasKey('allocated_subtotal', $row);
            self::assertArrayHasKey('allocated_discount', $row);
            self::assertArrayHasKey('allocated_shipping_discount', $row);
            self::assertArrayHasKey('allocated_shipping', $row);
            self::assertArrayHasKey('allocated_tax', $row);
            self::assertArrayHasKey('attributed_total', $row);
            self::assertArrayHasKey('fulfillment_status', $row);
            self::assertArrayHasKey('status', $row);
            self::assertNull($row['confirmed_at'], 'not yet paid -- proves the unconfirmed child is included');
        }
    }

    public function testShowNeverIncludesSellerOrdersKeyWhenNonPartitioned(): void
    {
        $order = $this->placeNonPartitionedOrder();

        $response = $this->adminController()->show(
            Request::create('/commerce/admin/orders/' . $order['uuid'], 'GET'),
            (string) $order['uuid']
        );
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayNotHasKey('seller_orders', $body['data']);
    }

    // -----------------------------------------------------------------
    // Operator seller-order fulfill
    // -----------------------------------------------------------------

    public function testOperatorSellerOrderFulfillTransitionsAnyChildAndRollsUp(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $this->seedSeller('sellerBBBB01', 'Seller B');
        $productX = $this->seedProduct('opfulfill-x', 1000, 'sellerAAAA01');
        $productY = $this->seedProduct('opfulfill-y', 2000, 'sellerBBBB01');

        $order = $this->placeTwoSellerOrder($productX, $productY);
        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);
        [$childA, $childB] = $this->sellerOrdersFor((string) $order['uuid']);

        $controller = $this->adminController();

        $first = $controller->fulfillSellerOrder(
            new FulfillSellerOrderData('UPS', 'TRACK-A', 'https://track.example/a'),
            Request::create(
                '/commerce/admin/orders/' . $order['uuid'] . '/seller-orders/' . $childA['uuid'] . '/fulfill',
                'POST'
            ),
            (string) $order['uuid'],
            (string) $childA['uuid']
        );
        self::assertSame(200, $first->getStatusCode());
        self::assertSame('paid', $this->orderRow((string) $order['uuid'])['status']);
        self::assertSame('partial', $this->orderRow((string) $order['uuid'])['fulfillment_status']);

        $second = $controller->fulfillSellerOrder(
            new FulfillSellerOrderData('FedEx', 'TRACK-B', 'https://track.example/b'),
            Request::create(
                '/commerce/admin/orders/' . $order['uuid'] . '/seller-orders/' . $childB['uuid'] . '/fulfill',
                'POST'
            ),
            (string) $order['uuid'],
            (string) $childB['uuid']
        );
        self::assertSame(200, $second->getStatusCode());
        self::assertSame('fulfilled', $this->orderRow((string) $order['uuid'])['status']);
        self::assertSame('fulfilled', $this->orderRow((string) $order['uuid'])['fulfillment_status']);

        $refreshedA = $this->sellerOrdersFor((string) $order['uuid'])[0];
        self::assertSame('fulfilled', $refreshedA['fulfillment_status']);
        self::assertSame('UPS', $refreshedA['carrier']);
    }

    public function testOperatorSellerOrderFulfillOfACanceledChildIs409(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $product = $this->seedProduct('opfulfill-cancel-x', 1000, 'sellerAAAA01');

        $order = $this->placeOneSellerOrder($product);
        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);
        $child = $this->sellerOrdersFor((string) $order['uuid'])[0];

        $this->connection->table('commerce_seller_orders')
            ->where('uuid', '=', $child['uuid'])
            ->update(['status' => 'canceled']);

        $response = $this->adminController()->fulfillSellerOrder(
            new FulfillSellerOrderData(null, null, null),
            Request::create(
                '/commerce/admin/orders/' . $order['uuid'] . '/seller-orders/' . $child['uuid'] . '/fulfill',
                'POST'
            ),
            (string) $order['uuid'],
            (string) $child['uuid']
        );

        self::assertSame(409, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Parent fulfill(): partitioned fan-out vs. non-partitioned unchanged
    // -----------------------------------------------------------------

    public function testPartitionedParentFulfillFansOutToEveryChild(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $this->seedSeller('sellerBBBB01', 'Seller B');
        $productX = $this->seedProduct('fanout-http-x', 1000, 'sellerAAAA01');
        $productY = $this->seedProduct('fanout-http-y', 2000, 'sellerBBBB01');

        $order = $this->placeTwoSellerOrder($productX, $productY);
        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);

        $response = $this->adminController()->fulfill(
            new FulfillOrderData('OPERATOR-FANOUT-REF'),
            Request::create('/commerce/admin/orders/' . $order['uuid'] . '/fulfill', 'POST'),
            (string) $order['uuid']
        );
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('fulfilled', $body['data']['status']);
        self::assertSame('fulfilled', $body['data']['fulfillment_status']);

        foreach ($this->sellerOrdersFor((string) $order['uuid']) as $child) {
            self::assertSame('fulfilled', $child['fulfillment_status'], 'every child must be fulfilled by the fan-out');
        }
    }

    public function testIndependentParentOnlyFulfillOnAPartitionedOrderIsRejected(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $product = $this->seedProduct('independent-reject-x', 1000, 'sellerAAAA01');

        // Deliberately NOT paid: the parent is still `pending_payment`, so a
        // "parent-only" fulfillment write (the pre-MV2 direct transition this
        // endpoint used to perform unconditionally) is never valid for a
        // partitioned order -- it must go through the fan-out, whose own
        // parent CAS rejects the premature paid -> fulfilled transition via
        // the SAME `OrderStateMachine` guard/`\DomainException` -> 409 idiom
        // every other admin order mutation already uses.
        $order = $this->placeOneSellerOrder($product);
        self::assertSame('pending_payment', $this->orderRow((string) $order['uuid'])['status']);

        $response = $this->adminController()->fulfill(
            new FulfillOrderData('too-early'),
            Request::create('/commerce/admin/orders/' . $order['uuid'] . '/fulfill', 'POST'),
            (string) $order['uuid']
        );

        self::assertContains($response->getStatusCode(), [409, 422]);
        self::assertSame('pending_payment', $this->orderRow((string) $order['uuid'])['status']);
        foreach ($this->sellerOrdersFor((string) $order['uuid']) as $child) {
            self::assertSame('unfulfilled', $child['fulfillment_status'], 'the rejected write must not touch children');
        }
    }

    public function testNonPartitionedAdminFulfillIsUnchanged(): void
    {
        $order = $this->placeNonPartitionedOrder();
        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);

        $response = $this->adminController()->fulfill(
            new FulfillOrderData('classic-ref'),
            Request::create('/commerce/admin/orders/' . $order['uuid'] . '/fulfill', 'POST'),
            (string) $order['uuid']
        );
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('fulfilled', $body['data']['status']);
        self::assertSame('fulfilled', $body['data']['fulfillment_status']);
        self::assertArrayNotHasKey('seller_orders', $body['data']);
        self::assertSame(0, $this->connection->table('commerce_seller_orders')->count());
    }

    // -----------------------------------------------------------------
    // Helpers (mirrors FulfillmentRollupTest's harness conventions)
    // -----------------------------------------------------------------

    private function adminController(): AdminOrderController
    {
        return new AdminOrderController(
            $this->context,
            new OrderRepository(),
            new StockRepository(),
            $this->paymentService(),
            new SentinelTenantResolver(),
            new RefundRepository(),
            new ConfigSellerIdentityProvider(),
            new SellerOrderRepository(),
            new SellerOrderFulfillmentService(new OrderRepository(), new SellerOrderRepository())
        );
    }

    private function paymentService(): OrderPaymentService
    {
        return new OrderPaymentService(new OrderRepository(), new SellerOrderPaymentConfirmation());
    }

    /** @return array<string,mixed> the placed parent order */
    private function placeOneSellerOrder(array $product): array
    {
        ['cart' => $cart, 'token' => $token] = $this->cart()->create($this->context);
        $this->cart()->addLine($this->context, $cart, (string) $product['variants'][0]['uuid'], 1);

        $placed = $this->checkoutPartitioned()
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        return $placed['order'];
    }

    /** @return array<string,mixed> the placed parent order */
    private function placeTwoSellerOrder(array $productX, array $productY): array
    {
        $cartService = $this->cart();
        ['cart' => $cart, 'token' => $token] = $cartService->create($this->context);
        $cart = $cartService->addLine($this->context, $cart, (string) $productX['variants'][0]['uuid'], 1);
        $cartService->addLine($this->context, $cart, (string) $productY['variants'][0]['uuid'], 1);

        $placed = $this->checkoutPartitioned()
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        return $placed['order'];
    }

    /** @return array<string,mixed> the placed parent order */
    private function placeNonPartitionedOrder(): array
    {
        $catalog = new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            $this->tenantResolver(),
            new StockRepository()
        );
        $product = $catalog->createProduct($this->context, [
            'slug' => 'non-partitioned-admin-x-' . bin2hex(random_bytes(3)),
            'name' => 'non-partitioned-admin-x',
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => 'NONPARTADMINX' . strtoupper(bin2hex(random_bytes(3))),
                'option_values' => [],
                'price' => 1000,
                'currency' => 'USD',
            ]],
        ]);
        (new StockRepository())->increment($this->context, self::TENANT, (string) $product['variants'][0]['uuid'], 10);

        ['cart' => $cart, 'token' => $token] = $this->cart()->create($this->context);
        $this->cart()->addLine($this->context, $cart, (string) $product['variants'][0]['uuid'], 1);

        $placed = $this->checkoutPlain()
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        return $placed['order'];
    }

    private function checkoutPartitioned(): CheckoutService
    {
        return new CheckoutService(
            $this->cart(),
            new DiscountRepository(),
            new DiscountService(new DiscountRepository(), $this->tenantResolver()),
            new StockRepository(),
            new PricingEngine(),
            $this->fakeShipping(),
            $this->aggregateTax(),
            new OrderNumberGenerator(),
            new OrderRepository(),
            new DownloadRepository(),
            new ManualPaymentCollector(),
            $this->tenantResolver(),
            new MarketplaceMode(),
            new SellerRepository(),
            new ProductRepository(),
            new SellerOrderRepository()
        );
    }

    private function checkoutPlain(): CheckoutService
    {
        return new CheckoutService(
            $this->cart(),
            new DiscountRepository(),
            new DiscountService(new DiscountRepository(), $this->tenantResolver()),
            new StockRepository(),
            new PricingEngine(),
            $this->fakeShipping(),
            $this->aggregateTax(),
            new OrderNumberGenerator(),
            new OrderRepository(),
            new DownloadRepository(),
            new ManualPaymentCollector(),
            $this->tenantResolver()
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
            $this->tenantResolver()
        );
    }

    private function tenantResolver(): CurrentTenantResolver
    {
        return new SentinelTenantResolver();
    }

    private function activateMarketplace(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['marketplace' => ['enabled' => true]]);
        $this->connection->table('commerce_marketplace_settings')->insert([
            'uuid' => 'mktsettings2',
            'tenant_uuid' => self::TENANT,
            'status' => 'active',
        ]);
    }

    private function seedSeller(string $uuid, string $name, string $status = 'active'): void
    {
        $this->connection->table('commerce_sellers')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT,
            'slug' => strtolower($uuid),
            'name' => $name,
            'status' => $status,
        ]);
    }

    /** @return array<string,mixed> */
    private function seedProduct(string $slug, int $price, string $sellerUuid, int $stock = 100): array
    {
        $catalog = new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            $this->tenantResolver(),
            new StockRepository()
        );
        $product = $catalog->createProduct($this->context, [
            'slug' => $slug,
            'name' => $slug,
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => strtoupper(str_replace('-', '', $slug)),
                'option_values' => [],
                'price' => $price,
                'currency' => 'USD',
            ]],
        ]);
        (new StockRepository())->increment(
            $this->context,
            self::TENANT,
            (string) $product['variants'][0]['uuid'],
            $stock
        );

        $this->connection->table('commerce_products')
            ->where('uuid', '=', $product['uuid'])
            ->update(['seller_uuid' => $sellerUuid]);

        return $product;
    }

    private function fakeShipping(): ShippingRateProvider
    {
        return new class implements ShippingRateProvider {
            public function quote(ApplicationContext $context, array $lines, array $shippingAddress): array
            {
                return [new ShippingQuote('std', 'Standard', 500)];
            }
        };
    }

    private function aggregateTax(): TaxCalculator
    {
        return new class implements TaxCalculator {
            public function quote(ApplicationContext $context, int $taxableAmount, array $shippingAddress): TaxQuote
            {
                return new TaxQuote(0);
            }
        };
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

    /** @return array<string,mixed> */
    private function orderRow(string $uuid): array
    {
        $row = $this->connection->table('commerce_orders')->where('uuid', '=', $uuid)->first();
        self::assertNotNull($row);

        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function sellerOrdersFor(string $orderUuid): array
    {
        return $this->connection->table('commerce_seller_orders')
            ->where('order_uuid', '=', $orderUuid)
            ->orderBy('partition_number', 'ASC')
            ->get();
    }

    /** @return array<string,mixed> */
    private function json(\Symfony\Component\HttpFoundation\Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
