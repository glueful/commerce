<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventDispatcher;
use Glueful\Events\EventService;
use Glueful\Events\ListenerProvider;
use Glueful\Extensions\Commerce\Cart\CartRepository;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Events\OrderNoteAdded;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Http\DTOs\CreateOrderNoteData;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Symfony\Component\HttpFoundation\Request;

final class OrderNoteEndpointTest extends CommerceTestCase
{
    public function testUnknownOrderReturns404AndNoEventRow(): void
    {
        try {
            $this->orderController()->addNote(
                new CreateOrderNoteData(body: 'hello', visibility: 'internal'),
                Request::create('/commerce/admin/orders/does-not-exist/notes', 'POST'),
                'does-not-exist'
            );
            self::fail('expected NotFoundException');
        } catch (NotFoundException $e) {
            self::assertNull(
                $this->connection->table('commerce_order_events')
                    ->where('order_uuid', '=', 'does-not-exist')
                    ->first()
            );
        }
    }

    public function testNotifyWithInternalVisibilityReturns422(): void
    {
        [$placed] = $this->placeOrder('SKU-NOTE-BAD', 3, 1);
        $orderUuid = (string) $placed['order']['uuid'];

        $response = $this->orderController()->addNote(
            new CreateOrderNoteData(body: 'internal only', visibility: 'internal', notify: true),
            Request::create('/commerce/admin/orders/' . $orderUuid . '/notes', 'POST'),
            $orderUuid
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertNull(
            $this->connection->table('commerce_order_events')
                ->where('order_uuid', '=', $orderUuid)
                ->where('type', '=', 'note.added')
                ->first()
        );
    }

    public function testCustomerNotePersistsWithActorAndVisibilityAndDispatchesWhenNotified(): void
    {
        [$placed] = $this->placeOrder('SKU-NOTE-OK', 3, 1);
        $orderUuid = (string) $placed['order']['uuid'];
        $captured = $this->bindEventCapture();

        $request = Request::create('/commerce/admin/orders/' . $orderUuid . '/notes', 'POST');
        $request->attributes->set('auth.user', new UserIdentity(uuid: 'noteactor001'));

        $response = $this->orderController()->addNote(
            new CreateOrderNoteData(body: 'shipped a day late, sorry!', visibility: 'customer', notify: true),
            $request,
            $orderUuid
        );

        self::assertSame(200, $response->getStatusCode());

        $event = $this->connection->table('commerce_order_events')
            ->where('order_uuid', '=', $orderUuid)
            ->where('type', '=', 'note.added')
            ->first();
        self::assertNotNull($event);
        self::assertSame('noteactor001', $event['actor_uuid']);
        self::assertSame('customer', $event['visibility']);

        self::assertCount(1, $captured->events);
        self::assertInstanceOf(OrderNoteAdded::class, $captured->events[0]);
        self::assertSame($orderUuid, (string) $captured->events[0]->order['uuid']);
        self::assertSame('shipped a day late, sorry!', $captured->events[0]->note['body']);
    }

    public function testNoteWithoutNotifyDoesNotDispatchEvent(): void
    {
        [$placed] = $this->placeOrder('SKU-NOTE-NONOTIFY', 3, 1);
        $orderUuid = (string) $placed['order']['uuid'];
        $captured = $this->bindEventCapture();

        $response = $this->orderController()->addNote(
            new CreateOrderNoteData(body: 'internal note', visibility: 'internal'),
            Request::create('/commerce/admin/orders/' . $orderUuid . '/notes', 'POST'),
            $orderUuid
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(0, $captured->events);
    }

    public function testShowIncludesEventsWithActorUuidAndVisibility(): void
    {
        [$placed] = $this->placeOrder('SKU-NOTE-SHOW', 3, 1);
        $orderUuid = (string) $placed['order']['uuid'];

        $request = Request::create('/commerce/admin/orders/' . $orderUuid . '/notes', 'POST');
        $request->attributes->set('auth.user', new UserIdentity(uuid: 'showactor001'));
        $this->orderController()->addNote(
            new CreateOrderNoteData(body: 'note for show', visibility: 'customer'),
            $request,
            $orderUuid
        );

        $response = $this->orderController()->show(
            Request::create('/commerce/admin/orders/' . $orderUuid, 'GET'),
            $orderUuid
        );
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        $events = $body['data']['events'];
        self::assertNotEmpty($events);
        $note = end($events);
        self::assertSame('note.added', $note['type']);
        self::assertSame('showactor001', $note['actor_uuid']);
        self::assertSame('customer', $note['visibility']);
    }

    /**
     * Binds a real EventService into the test container and returns a capture object whose
     * `events` list is appended to (in dispatch order) as OrderNoteAdded fires.
     */
    private function bindEventCapture(): object
    {
        $capture = new class {
            /** @var list<object> */
            public array $events = [];
        };
        $listeners = new ListenerProvider();
        $eventService = new EventService(new EventDispatcher($listeners), $listeners);
        $eventService->addListener(OrderNoteAdded::class, function (OrderNoteAdded $e) use ($capture): void {
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
            new SentinelTenantResolver()
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

    /** @return array<string,mixed> */
    private function json(\Symfony\Component\HttpFoundation\Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
