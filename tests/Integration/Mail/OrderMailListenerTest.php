<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Mail;

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
use Glueful\Extensions\Commerce\Events\OrderFulfilled;
use Glueful\Extensions\Commerce\Events\OrderNoteAdded;
use Glueful\Extensions\Commerce\Events\OrderPaid;
use Glueful\Extensions\Commerce\Events\OrderPlaced;
use Glueful\Extensions\Commerce\Events\RefundCompleted;
use Glueful\Extensions\Commerce\Events\RefundFailed;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Mail\CommerceMailer;
use Glueful\Extensions\Commerce\Mail\NotificationCommerceMailer;
use Glueful\Extensions\Commerce\Mail\OrderMailListener;
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
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Commerce\Tests\Support\RecordingCommerceMailer;
use Glueful\Extensions\Commerce\Tests\Support\RecordingNotificationChannel;
use Glueful\Extensions\Commerce\Tests\Support\ThrowingCommerceMailer;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Support\DiagnosticsReport;
use Glueful\Notifications\Services\ChannelManager;
use Glueful\Notifications\Services\NotificationDispatcher;
use Glueful\Notifications\Services\NotificationService;
use Glueful\Notifications\Stores\NullNotificationStore;

final class OrderMailListenerTest extends CommerceTestCase
{
    public function testOrderPaidDispatchesOrderPaidTemplate(): void
    {
        $mailer = new RecordingCommerceMailer();
        $this->bindListener($mailer);

        ['order' => $order] = $this->placeAndPayOrder('SKU-MAIL-PAID', 5, 1, 1000);

        // placeAndPayOrder() places (order_placed) AND pays (order_paid) the order, so both
        // templates fire; isolate the one this test is about.
        $paidCalls = array_values(array_filter(
            $mailer->calls,
            static fn (array $call): bool => $call['template'] === 'order_paid'
        ));
        self::assertCount(1, $paidCalls);
        self::assertSame($order['uuid'], $paidCalls[0]['order']['uuid']);
    }

    public function testOrderPlacedDispatchesOrderPlacedTemplate(): void
    {
        $mailer = new RecordingCommerceMailer();
        $this->bindListener($mailer);

        [$token] = $this->seedCartWithLine('SKU-MAIL-PLACED', 5, 1, 1000);
        $placed = $this->checkout()->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        $placedCalls = array_values(array_filter(
            $mailer->calls,
            static fn (array $call): bool => $call['template'] === 'order_placed'
        ));
        self::assertCount(1, $placedCalls);
        self::assertSame($placed['order']['uuid'], $placedCalls[0]['order']['uuid']);
    }

    public function testRefundCompletedDispatchesOrderRefundedTemplate(): void
    {
        $mailer = new RecordingCommerceMailer();
        $this->bindListener($mailer);

        ['order' => $order] = $this->placeAndPayOrder('SKU-MAIL-REFUND', 5, 1, 1000);

        $refund = $this->refundService()->issue(
            $this->context,
            (string) $order['uuid'],
            new RefundInput(null, 'customer request', [], false),
            'idem-mail-refund-1'
        );
        self::assertSame('completed', $refund['status']);

        $refundedCalls = array_values(array_filter(
            $mailer->calls,
            static fn (array $call): bool => $call['template'] === 'order_refunded'
        ));
        self::assertCount(1, $refundedCalls);
        self::assertSame($order['uuid'], $refundedCalls[0]['order']['uuid']);
        self::assertSame((int) $order['grand_total'], (int) $refundedCalls[0]['payload']['amount']);
    }

    public function testRefundFailedHasNoListenerMapping(): void
    {
        $mailer = new RecordingCommerceMailer();
        $this->bindListener($mailer);

        $order = ['uuid' => 'ord000000001', 'order_number' => 'ORD-1', 'email' => 'buyer@example.com'];
        $refund = ['uuid' => 'ref000000001', 'amount' => 500, 'reason' => 'gateway declined'];

        $this->eventService()->dispatch(new RefundFailed($order, $refund));

        self::assertSame([], $mailer->calls, 'RefundFailed must never trigger a customer email.');
    }

    public function testOrderNoteAddedWithNotifySendsOrderNoteTemplate(): void
    {
        $mailer = new RecordingCommerceMailer();
        $this->bindListener($mailer);

        $order = ['uuid' => 'ord000000001', 'order_number' => 'ORD-1', 'email' => 'buyer@example.com'];
        $note = ['body' => 'Your shipment is delayed by one day.', 'visibility' => 'customer', 'notify' => true];

        $this->eventService()->dispatch(new OrderNoteAdded($order, $note));

        self::assertCount(1, $mailer->calls);
        self::assertSame('order_note', $mailer->calls[0]['template']);
        self::assertSame($note, $mailer->calls[0]['payload']);
    }

    public function testOrderNoteAddedWithoutNotifySendsNothing(): void
    {
        $mailer = new RecordingCommerceMailer();
        $this->bindListener($mailer);

        $order = ['uuid' => 'ord000000001', 'order_number' => 'ORD-1', 'email' => 'buyer@example.com'];
        $note = ['body' => 'Internal note only.', 'visibility' => 'internal', 'notify' => false];

        $this->eventService()->dispatch(new OrderNoteAdded($order, $note));

        self::assertSame([], $mailer->calls);
    }

    public function testThrowingMailerDoesNotBreakRefundCompletion(): void
    {
        $this->bindListener(new ThrowingCommerceMailer());

        ['order' => $order] = $this->placeAndPayOrder('SKU-MAIL-THROW', 5, 1, 1000);

        $refund = $this->refundService()->issue(
            $this->context,
            (string) $order['uuid'],
            new RefundInput(null, 'customer request', [], false),
            'idem-mail-throw-1'
        );

        self::assertSame('completed', $refund['status']);
        $persisted = $this->connection->table('commerce_refunds')
            ->where('order_uuid', '=', $order['uuid'])
            ->first();
        self::assertNotNull($persisted, 'Refund must persist even when the bound mailer throws.');
        self::assertSame('completed', $persisted['status']);
    }

    /**
     * {@see \Glueful\Events\EventDispatcher} already isolates listener faults on its own
     * (it catches \Throwable per-listener so one broken listener can't starve the rest of
     * the chain), which means the two tests above pass even without any try/catch inside
     * OrderMailListener itself. This test calls the listener's handler methods directly —
     * bypassing the dispatcher entirely — to prove OrderMailListener::safeSend() carries
     * its OWN exception guard (spec §6/step 5), independent of that framework safety net.
     */
    public function testListenerHandlersSwallowMailerExceptionsOnTheirOwn(): void
    {
        $listener = new OrderMailListener($this->context, new ThrowingCommerceMailer());
        $order = ['uuid' => 'ord000000001', 'order_number' => 'ORD-1', 'email' => 'buyer@example.com'];
        $refund = ['amount' => 500, 'reason' => 'operator note'];
        $note = ['body' => 'hello', 'visibility' => 'customer', 'notify' => true];

        $listener->onOrderPlaced(new OrderPlaced($order));
        $listener->onOrderPaid(new OrderPaid($order));
        $listener->onOrderFulfilled(new OrderFulfilled($order));
        $listener->onRefundCompleted(new RefundCompleted($order, $refund));
        $listener->onOrderNoteAdded(new OrderNoteAdded($order, $note));

        // Reaching this line without an uncaught exception is the assertion.
        self::assertTrue(true);
    }

    public function testMasterSwitchOffSendsNothingEvenWithAnActiveEmailChannelPresent(): void
    {
        $channel = new RecordingNotificationChannel(available: true);
        $this->bindNotificationStack($channel);
        // Master switch defaults to off; no config override needed.

        (new NotificationCommerceMailer())->send($this->context, 'order_paid', $this->minimalOrder(), []);

        self::assertSame([], $channel->sent, 'Master switch off must short-circuit before any send.');
        self::assertSame('disabled', DiagnosticsReport::build($this->context)['email']);
    }

    public function testActiveEmailChannelReceivesTheNotificationViaTheDefaultMailer(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['email' => ['enabled' => true]]);
        $channel = new RecordingNotificationChannel(available: true);
        $this->bindNotificationStack($channel);

        (new NotificationCommerceMailer())->send($this->context, 'order_paid', $this->minimalOrder(), []);

        self::assertCount(1, $channel->sent);
        self::assertSame('active', DiagnosticsReport::build($this->context)['email']);
    }

    public function testNoEmailChannelRegisteredIsANoOpAndDiagnosticsReportsInactive(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['email' => ['enabled' => true]]);
        // No 'email' channel registered on the ChannelManager at all.
        $channelManager = new ChannelManager();
        $dispatcher = new NotificationDispatcher($channelManager);
        $this->bind(NotificationDispatcher::class, $dispatcher);
        $this->bind(NotificationService::class, new NotificationService($dispatcher, new NullNotificationStore()));

        // Must not throw even though no email channel exists.
        (new NotificationCommerceMailer())->send($this->context, 'order_paid', $this->minimalOrder(), []);

        self::assertSame('inactive', DiagnosticsReport::build($this->context)['email']);
    }

    public function testUnavailableEmailChannelIsANoOpAndDiagnosticsReportsInactive(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['email' => ['enabled' => true]]);
        $channel = new RecordingNotificationChannel(available: false);
        $this->bindNotificationStack($channel);

        (new NotificationCommerceMailer())->send($this->context, 'order_paid', $this->minimalOrder(), []);

        self::assertSame([], $channel->sent);
        self::assertSame('inactive', DiagnosticsReport::build($this->context)['email']);
    }

    public function testPerTemplateSwitchOffSkipsOnlyThatTemplate(): void
    {
        $this->context->mergeConfigDefaults('commerce', [
            'email' => ['enabled' => true, 'templates' => ['order_paid' => false]],
        ]);
        $channel = new RecordingNotificationChannel(available: true);
        $this->bindNotificationStack($channel);

        (new NotificationCommerceMailer())->send($this->context, 'order_paid', $this->minimalOrder(), []);
        self::assertSame([], $channel->sent, 'order_paid template is disabled and must not send.');

        (new NotificationCommerceMailer())->send($this->context, 'order_placed', $this->minimalOrder(), []);
        self::assertCount(1, $channel->sent, 'order_placed template remains enabled by default.');
    }

    private function bindListener(CommerceMailer $mailer): void
    {
        $this->bind(CommerceMailer::class, $mailer);
        $listener = new OrderMailListener($this->context, $mailer);
        $listeners = new ListenerProvider();
        $eventService = new EventService(new EventDispatcher($listeners), $listeners);
        $eventService->addListener(OrderPlaced::class, [$listener, 'onOrderPlaced']);
        $eventService->addListener(OrderPaid::class, [$listener, 'onOrderPaid']);
        $eventService->addListener(OrderFulfilled::class, [$listener, 'onOrderFulfilled']);
        $eventService->addListener(RefundCompleted::class, [$listener, 'onRefundCompleted']);
        $eventService->addListener(OrderNoteAdded::class, [$listener, 'onOrderNoteAdded']);
        // RefundFailed is deliberately never registered (spec §9: never emails the customer).
        $this->bind(EventService::class, $eventService);
    }

    private function eventService(): EventService
    {
        return $this->contextContainer()->get(EventService::class);
    }

    private function bindNotificationStack(RecordingNotificationChannel $channel): void
    {
        $channelManager = new ChannelManager();
        $channelManager->registerChannel($channel);
        $dispatcher = new NotificationDispatcher($channelManager);
        $this->bind(NotificationDispatcher::class, $dispatcher);
        $this->bind(NotificationService::class, new NotificationService($dispatcher, new NullNotificationStore()));
    }

    /** @return array<string,mixed> */
    private function minimalOrder(): array
    {
        return [
            'uuid' => 'ord000000001',
            'order_number' => 'ORD-1',
            'email' => 'buyer@example.com',
            'currency' => 'USD',
            'grand_total' => 1000,
        ];
    }

    private function refundService(): RefundService
    {
        return new RefundService(
            new OrderRepository(),
            new RefundRepository(),
            new StockRepository(),
            new SentinelTenantResolver()
        );
    }

    /**
     * Places, pays, and returns an order with a single tracked line.
     *
     * @return array{order: array<string,mixed>, variantUuid: string, lineUuid: string}
     */
    private function placeAndPayOrder(string $sku, int $stock, int $quantity, int $price): array
    {
        [$token, $variantUuid] = $this->seedCartWithLine($sku, $stock, $quantity, $price);
        $placed = $this->checkout()->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');
        $orderUuid = (string) $placed['order']['uuid'];

        (new OrderPaymentService(new OrderRepository()))->markPaid($this->context, '', $orderUuid);
        $order = (new OrderRepository())->findByUuid($this->context, '', $orderUuid);
        self::assertNotNull($order);

        $line = $this->connection->table('commerce_order_lines')->where('order_uuid', '=', $orderUuid)->first();
        self::assertNotNull($line);

        return ['order' => $order, 'variantUuid' => $variantUuid, 'lineUuid' => (string) $line['uuid']];
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
