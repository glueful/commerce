<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Mail;

use Glueful\Events\EventDispatcher;
use Glueful\Events\EventService;
use Glueful\Events\ListenerProvider;
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
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadGrantRepository;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadGrantService;
use Glueful\Extensions\Commerce\Orders\OrderFulfillmentService;
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
use Glueful\Extensions\Commerce\Support\TokenHasher;
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

    /**
     * Physical-order regression (design spec §6): the paid-email payload must stay
     * byte-identical to the pre-Layer-3 shape — no `downloads` key at all.
     */
    public function testPhysicalOrderPaidPayloadHasNoDownloadsKey(): void
    {
        $mailer = new RecordingCommerceMailer();
        $this->bindListener($mailer);

        $this->placeAndPayOrder('SKU-MAIL-PHYS', 5, 1, 1000);

        $paidCalls = array_values(array_filter(
            $mailer->calls,
            static fn (array $call): bool => $call['template'] === 'order_paid'
        ));
        self::assertCount(1, $paidCalls);
        self::assertSame(
            [],
            $paidCalls[0]['payload'],
            'physical order payload must be byte-identical to pre-Layer-3 output (no downloads key)'
        );
    }

    /**
     * Primary digital-delivery path (design spec §6): `onOrderPaid()` issues grants
     * FIRST, then passes only that call's raw tokens as deep links.
     */
    public function testOrderPaidForDigitalOrderCarriesDownloadLinksAndGrantsExist(): void
    {
        $mailer = new RecordingCommerceMailer();
        $this->bindListener($mailer);

        ['variant_uuid' => $variantUuid] = $this->seedDigitalProduct('SKU-MAIL-DL1');
        $this->seedDownload($variantUuid, 'dlmail000001', 'blobmail00001', 'Ebook.pdf');

        $placed = $this->placeDigitalOrder($variantUuid, 1);
        $orderUuid = (string) $placed['order']['uuid'];
        (new OrderPaymentService(new OrderRepository()))->markPaid($this->context, '', $orderUuid);

        $paidCalls = array_values(array_filter(
            $mailer->calls,
            static fn (array $call): bool => $call['template'] === 'order_paid'
        ));
        self::assertCount(1, $paidCalls);

        $downloads = $paidCalls[0]['payload']['downloads'] ?? null;
        self::assertIsArray($downloads);
        self::assertCount(1, $downloads);
        self::assertSame('Ebook.pdf', $downloads[0]['name']);
        self::assertIsString($downloads[0]['url']);
        self::assertStringContainsString('/commerce/downloads/', $downloads[0]['url']);

        // The url must carry the RAW token for a real, persisted grant on this order.
        $token = substr($downloads[0]['url'], (int) strrpos($downloads[0]['url'], '/') + 1);
        $grant = (new DownloadGrantRepository())->findByTokenHashGlobal($this->context, TokenHasher::hash($token));
        self::assertNotNull($grant);
        self::assertSame($orderUuid, $grant['order_uuid']);
        self::assertSame(1, $this->connection->table('commerce_download_grants')->count());
    }

    /**
     * Idempotent re-fire (design spec §6): existing grants are never assigned a
     * re-derived raw token, so a SECOND `OrderPaid` dispatch for the same
     * already-granted order yields a plain payload -- no `downloads` key at all.
     */
    public function testSecondOrderPaidDispatchForSameDigitalOrderCarriesNoDownloadLinks(): void
    {
        $mailer = new RecordingCommerceMailer();
        $this->bindListener($mailer);

        ['variant_uuid' => $variantUuid] = $this->seedDigitalProduct('SKU-MAIL-DL2');
        $this->seedDownload($variantUuid, 'dlmail000002', 'blobmail00002', 'Ebook2.pdf');

        $placed = $this->placeDigitalOrder($variantUuid, 1);
        $orderUuid = (string) $placed['order']['uuid'];
        (new OrderPaymentService(new OrderRepository()))->markPaid($this->context, '', $orderUuid);
        $order = (new OrderRepository())->findByUuid($this->context, '', $orderUuid);
        self::assertNotNull($order);

        // A second, independent OrderPaid dispatch for the SAME order -- simulating a
        // duplicate/idempotent re-fire on top of the one markPaid() already triggered.
        $this->eventService()->dispatch(new OrderPaid($order));

        $paidCalls = array_values(array_filter(
            $mailer->calls,
            static fn (array $call): bool => $call['template'] === 'order_paid'
        ));
        self::assertCount(2, $paidCalls);
        self::assertArrayHasKey('downloads', $paidCalls[0]['payload']);
        self::assertSame([], $paidCalls[1]['payload'], 'second dispatch must carry no downloads key/links');
        self::assertSame(1, $this->connection->table('commerce_download_grants')->count());
    }

    /**
     * Issuance-failure isolation (design spec §6): forcing
     * {@see DownloadGrantService::issueAndCollectForOrder()} to throw (here, via the
     * service's own overflow guard -- {@see DownloadGrantService} is `final`, so this
     * is the only way to force a genuine throw without a hand-rolled double) must
     * still let the plain paid email go out, with no grants persisted (design spec §3:
     * the overflow guard throws while deriving specs, strictly before any insert).
     */
    public function testDigitalOrderPaidWhenIssuanceThrowsStillSendsPlainEmailAndCreatesNoGrants(): void
    {
        $mailer = new RecordingCommerceMailer();
        $this->bindListener($mailer);

        ['variant_uuid' => $variantUuid] = $this->seedDigitalProduct('SKU-MAIL-DL3');
        $this->seedDownload($variantUuid, 'dlmail000003', 'blobmail00003', 'Ebook3.pdf', limit: PHP_INT_MAX);

        $placed = $this->placeDigitalOrder($variantUuid, 2);
        $orderUuid = (string) $placed['order']['uuid'];
        (new OrderPaymentService(new OrderRepository()))->markPaid($this->context, '', $orderUuid);

        $paidCalls = array_values(array_filter(
            $mailer->calls,
            static fn (array $call): bool => $call['template'] === 'order_paid'
        ));
        self::assertCount(1, $paidCalls, 'the paid email must still be sent even when issuance throws');
        self::assertSame([], $paidCalls[0]['payload']);
        self::assertSame(0, $this->connection->table('commerce_download_grants')->count());
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

    // =====================================================================
    // Nullable order email + the admin-origin confirmation toggle
    // (admin-order-creation cycle 2, Task 10; design Ruling 4/7, spec §2.5.9).
    // =====================================================================

    /** @return list<array{0:mixed}> */
    public static function unusableEmailProvider(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'whitespace only' => ["  \t "],
        ];
    }

    /**
     * Ruling 7, verbatim: "no email means no notification attempt". The guard is
     * ONE shared check inside `safeSend()`, so this is asserted across EVERY
     * lifecycle template at once rather than template by template -- a new
     * template added tomorrow inherits it by construction.
     *
     * @dataProvider unusableEmailProvider
     */
    public function testAnOrderWithNoUsableEmailEmitsZeroMailerCallsForEveryTemplate(mixed $email): void
    {
        $mailer = new RecordingCommerceMailer();
        $this->bindListener($mailer);

        // A COMPLETE raw row shape: every listener downstream of `safeSend()` --
        // notably the digital-grant issuance `onOrderPaid()` runs first -- reads
        // real order columns, so a half-built fixture would prove the guard only
        // by accident.
        $order = [
            'uuid' => 'ord000000001',
            'tenant_uuid' => '',
            'order_number' => 'ORD-1',
            'status' => 'paid',
            'email' => $email,
            'origin' => 'admin',
            'currency' => 'USD',
            'grand_total' => 1000,
        ];

        $this->eventService()->dispatch(new OrderPlaced($order));
        $this->eventService()->dispatch(new OrderPaid($order));
        $this->eventService()->dispatch(new OrderFulfilled($order));
        $this->eventService()->dispatch(new RefundCompleted($order, ['amount' => 500, 'reason' => 'operator']));
        $this->eventService()->dispatch(new OrderNoteAdded($order, [
            'body' => 'Ready for collection.',
            'visibility' => 'customer',
            'notify' => true,
        ]));

        self::assertSame([], $mailer->calls, 'an order with no usable email must never reach the mailer');
    }

    /**
     * The Complete Sale chain (design spec §2.8) simulated at the ENGINE level:
     * the two services Thallo's pack endpoint chains, invoked directly and in
     * order against a real anonymous walk-in order. Neither step may email
     * anybody, and both must still complete their own persisted transition --
     * proving the guard short-circuits the MAIL, never the sale.
     */
    public function testTheCompleteSaleChainOnAnAnonymousWalkInOrderEmitsZeroMailAndStillCompletes(): void
    {
        $mailer = new RecordingCommerceMailer();
        $this->bindListener($mailer);

        $orderUuid = $this->seedAdminOrder(null);

        (new OrderPaymentService(new OrderRepository()))->markPaid($this->context, '', $orderUuid);
        (new OrderFulfillmentService(new OrderRepository()))->fulfill($this->context, '', $orderUuid, null);

        self::assertSame([], $mailer->calls, 'Complete Sale on a null-email order must emit no mail at all');

        $row = $this->connection->table('commerce_orders')->where('uuid', '=', $orderUuid)->first();
        self::assertNotNull($row);
        self::assertSame('fulfilled', (string) $row['status']);
    }

    /**
     * The same chain on a walk-in order that DOES carry an email behaves exactly
     * as a storefront order does -- the guard is about the address, never about
     * the origin.
     */
    public function testTheCompleteSaleChainOnAWalkInOrderWithAnEmailSendsPaidAndFulfilled(): void
    {
        $mailer = new RecordingCommerceMailer();
        $this->bindListener($mailer);

        $orderUuid = $this->seedAdminOrder('walkin@example.com');

        (new OrderPaymentService(new OrderRepository()))->markPaid($this->context, '', $orderUuid);
        (new OrderFulfillmentService(new OrderRepository()))->fulfill($this->context, '', $orderUuid, null);

        self::assertSame(
            ['order_paid', 'order_fulfilled'],
            array_map(static fn (array $call): string => $call['template'], $mailer->calls)
        );
    }

    public function testTheAdminOriginConfirmationToggleGatesOrderPlacedAndNothingElse(): void
    {
        $mailer = new RecordingCommerceMailer();
        $this->bindListener($mailer);
        $this->context->overrideConfig('commerce.order_confirmation', false);

        $order = [
            'uuid' => 'ord000000001',
            'tenant_uuid' => '',
            'order_number' => 'ORD-1',
            'status' => 'paid',
            'email' => 'walkin@example.com',
            'origin' => 'admin',
            'currency' => 'USD',
            'grand_total' => 1000,
        ];

        $this->eventService()->dispatch(new OrderPlaced($order));
        self::assertSame([], $mailer->calls, 'the toggle off must suppress the admin-origin placement mail');

        // Every OTHER template is untouched by this toggle.
        $this->eventService()->dispatch(new OrderPaid($order));
        $this->eventService()->dispatch(new OrderFulfilled($order));
        self::assertSame(
            ['order_paid', 'order_fulfilled'],
            array_map(static fn (array $call): string => $call['template'], $mailer->calls)
        );
    }

    public function testTheAdminOriginConfirmationToggleDefaultsOnAndSendsThePlacementMail(): void
    {
        $mailer = new RecordingCommerceMailer();
        $this->bindListener($mailer);
        // No override at all -- the config default is what an existing install has.

        $this->eventService()->dispatch(new OrderPlaced([
            'uuid' => 'ord000000001',
            'order_number' => 'ORD-1',
            'email' => 'walkin@example.com',
            'origin' => 'admin',
        ]));

        self::assertCount(1, $mailer->calls);
        self::assertSame('order_placed', $mailer->calls[0]['template']);
    }

    public function testTheConfirmationToggleNeverGatesAStorefrontOrder(): void
    {
        $mailer = new RecordingCommerceMailer();
        $this->bindListener($mailer);
        $this->context->overrideConfig('commerce.order_confirmation', false);

        // Explicit storefront origin, and a legacy row carrying no origin at all
        // (which migration 022 backfilled to `storefront`) -- both must still send.
        $this->eventService()->dispatch(new OrderPlaced([
            'uuid' => 'ord000000001',
            'order_number' => 'ORD-1',
            'email' => 'buyer@example.com',
            'origin' => 'storefront',
        ]));
        $this->eventService()->dispatch(new OrderPlaced([
            'uuid' => 'ord000000002',
            'order_number' => 'ORD-2',
            'email' => 'buyer@example.com',
        ]));

        self::assertCount(2, $mailer->calls);
        self::assertSame(['order_placed', 'order_placed'], array_column($mailer->calls, 'template'));
    }

    /**
     * An anonymous walk-in order, finalized and awaiting payment -- the shape
     * `DraftFinalizationService` leaves behind. Inserted directly rather than
     * driven through finalize: this file's subject is the LISTENER, and the
     * finalize path has its own suite.
     */
    private function seedAdminOrder(?string $email): string
    {
        $uuid = 'walkinord' . substr(md5((string) $email . microtime()), 0, 3);
        $this->connection->table('commerce_orders')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => '',
            'order_number' => 'ORD-WALKIN',
            'status' => 'pending_payment',
            'email' => $email,
            'guest_token_hash' => null,
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
            'origin' => 'admin',
            'fulfillment_mode' => 'in_store',
            'placed_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return $uuid;
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
        // Real DownloadGrantService (not the lazy container fallback): every test in
        // this file goes through onOrderPaid(), and a real service correctly issues
        // nothing for a physical-only order (spec §3), so this is safe for every
        // existing physical-order test too.
        $listener = new OrderMailListener(
            $this->context,
            $mailer,
            new DownloadGrantService(new OrderRepository(), new DownloadGrantRepository())
        );
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

    /** @return array{product_uuid: string, variant_uuid: string} */
    private function seedDigitalProduct(string $sku): array
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
            'type' => 'digital',
            'status' => 'active',
            'variants' => [[
                'sku' => $sku,
                'option_values' => [],
                'price' => 500,
                'currency' => 'USD',
            ]],
        ]);

        return [
            'product_uuid' => (string) $product['uuid'],
            'variant_uuid' => (string) $product['variants'][0]['uuid'],
        ];
    }

    private function seedDownload(
        string $variantUuid,
        string $uuid,
        string $blobUuid,
        string $name,
        ?int $limit = null,
        ?int $expiry = null,
    ): void {
        $this->connection->table('commerce_downloads')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => '',
            'variant_uuid' => $variantUuid,
            'blob_uuid' => $blobUuid,
            'name' => $name,
            'download_limit' => $limit,
            'expiry_days' => $expiry,
            'position' => 0,
            'status' => 'active',
        ]);
    }

    /** @return array{order: array<string,mixed>} */
    private function placeDigitalOrder(string $variantUuid, int $quantity): array
    {
        ['cart' => $cart, 'token' => $token] = $this->cart()->create($this->context);
        $this->cart()->addLine($this->context, $cart, $variantUuid, $quantity);
        $placed = $this->checkout()->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        return ['order' => $placed['order']];
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
