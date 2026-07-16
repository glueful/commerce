<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Orders;

use Glueful\Events\EventDispatcher;
use Glueful\Events\EventService;
use Glueful\Events\ListenerProvider;
use Glueful\Extensions\Commerce\Cart\CartRepository;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\DownloadRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Events\OrderFulfilled;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Http\DTOs\FulfillOrderData;
use Glueful\Extensions\Commerce\Invoices\ConfigSellerIdentityProvider;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Symfony\Component\HttpFoundation\Request;

final class OrderFulfilledDispatchTest extends CommerceTestCase
{
    public function testFulfillingAPaidOrderDispatchesOrderFulfilledExactlyOnce(): void
    {
        $captured = $this->bindEventCapture();
        [$placed] = $this->placeOrder('SKU-FULFILL-DISPATCH', 3, 1);
        $orderUuid = (string) $placed['order']['uuid'];
        (new OrderPaymentService(new OrderRepository()))->markPaid($this->context, '', $orderUuid);

        $response = $this->orderController()->fulfill(
            new FulfillOrderData(tracking_ref: 'TRACK-DISPATCH'),
            Request::create('/commerce/admin/orders/' . $orderUuid . '/fulfill', 'POST'),
            $orderUuid
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $captured->events);
        self::assertInstanceOf(OrderFulfilled::class, $captured->events[0]);
        self::assertSame($orderUuid, (string) $captured->events[0]->order['uuid']);
        self::assertSame('fulfilled', $captured->events[0]->order['fulfillment_status']);
        self::assertSame('TRACK-DISPATCH', $captured->events[0]->order['tracking_ref']);
    }

    public function testFulfillingAnUnknownOrderIsANotFound404NotA500(): void
    {
        $captured = $this->bindEventCapture();

        try {
            $this->orderController()->fulfill(
                new FulfillOrderData(tracking_ref: null),
                Request::create('/commerce/admin/orders/nosuchorder1/fulfill', 'POST'),
                'nosuchorder1'
            );
            self::fail('Expected NotFoundException for an unknown order.');
        } catch (\Glueful\Http\Exceptions\Client\NotFoundException $e) {
            self::assertSame('Resource not found.', $e->getMessage());
        }

        self::assertCount(0, $captured->events);
    }

    public function testCancelingAnOrderDispatchesNoOrderFulfilledEvent(): void
    {
        $captured = $this->bindEventCapture();
        [$placed] = $this->placeOrder('SKU-CANCEL-DISPATCH', 3, 1);
        $orderUuid = (string) $placed['order']['uuid'];

        $response = $this->orderController()->cancel(
            Request::create('/commerce/admin/orders/' . $orderUuid . '/cancel', 'POST'),
            $orderUuid
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(0, $captured->events);
    }

    /**
     * Binds a real EventService into the test container and returns a capture object whose
     * `events` list is appended to (in dispatch order) as OrderFulfilled fires. An object is
     * used (rather than an array by reference) since PHP copies arrays on return.
     */
    private function bindEventCapture(): object
    {
        $capture = new class {
            /** @var list<object> */
            public array $events = [];
        };
        $listeners = new ListenerProvider();
        $eventService = new EventService(new EventDispatcher($listeners), $listeners);
        $eventService->addListener(OrderFulfilled::class, function (OrderFulfilled $e) use ($capture): void {
            $capture->events[] = $e;
        });
        $this->bind(EventService::class, $eventService);

        return $capture;
    }

    private function orderController(): AdminOrderController
    {
        return new AdminOrderController(
            $this->context,
            new OrderRepository(),
            new StockRepository(),
            new OrderPaymentService(new OrderRepository()),
            new SentinelTenantResolver(),
            new RefundRepository(),
            new ConfigSellerIdentityProvider()
        );
    }

    /** @return array{0: array{order: array<string,mixed>, guest_token: string, payment: array<string,mixed>}, 1: string} */
    private function placeOrder(string $sku, int $stock, int $quantity): array
    {
        $variantUuid = $this->seedVariant($sku, $stock, 1000);
        ['cart' => $cart, 'token' => $token] = $this->cart()->create($this->context);
        $this->cart()->addLine($this->context, $cart, $variantUuid, $quantity);

        $placed = $this->checkout()->placeOrder(
            $this->context,
            $token,
            ['email' => 'buyer@example.com', 'user_uuid' => null],
            ['shipping' => ['country' => 'US'], 'billing' => ['country' => 'US']],
            'std'
        );

        return [$placed, $variantUuid];
    }

    private function seedVariant(string $sku, int $stock, int $price): string
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

        return $variantUuid;
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
}
