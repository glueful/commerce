<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\PaymentLinkRepository;
use Glueful\Extensions\Commerce\Orders\PaymentLinkService;
use Glueful\Extensions\Commerce\Payments\OrderPaymentConfirmationHandler;
use Glueful\Extensions\Commerce\Payments\OrderPayable;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\PaymentConfirmation;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;

/**
 * Payment-links Task 8 (design spec §2.2, "Terminal transitions"): order paid =>
 * link `consumed`, EAGERLY where the paid transition is observed, and lazily on
 * resolve (Task 6). Plus the settlement question the whole supersession chain
 * exists to answer.
 *
 * ## Where "eagerly" is
 *
 * {@see OrderPaymentService::markPaid()} is the engine's ONE paid transition and
 * the sole dispatcher of `OrderPaid`; both callers (the provider confirmation
 * handler and the admin mark-paid endpoint) route through it. Consumption is
 * therefore done INSIDE its transaction rather than from an `OrderPaid`
 * listener: the event is dispatched after-commit and fault-isolated, and is not
 * dispatched at all when no `EventService` is bound, so a listener would make a
 * custody transition best-effort. In the transaction it commits or rolls back
 * with the paid CAS itself.
 *
 * ## The supersession settlement
 *
 * Payvia 2.6 supersedes a dead hosted session and opens a new one; Task 3 made
 * webhook confirmation reference-addressable so each settles its OWN attempt.
 * What Commerce owes that chain is the LAST word: a late settlement arriving for
 * the OLD Stripe session, after the new one already paid the order, must be
 * refused by the paid-order CAS -- not double-applied, not silently dropped.
 * The fixture below carries two real-shaped `checkout.session.completed`
 * payloads for exactly that pair.
 */
final class PaymentLinkTerminalTransitionTest extends CommerceTestCase
{
    private const TENANT = '';
    private const ORDER = 'termorder001';
    private const ACTOR = 'termactor001';

    // =====================================================================
    // Eager consumption
    // =====================================================================

    public function testMarkingAnOrderPaidConsumesItsActiveLinkEagerly(): void
    {
        $this->seedOrder();
        $this->seedLink('termlink0001');

        $this->payments()->markPaid($this->context, self::TENANT, self::ORDER);

        self::assertSame(PaymentLinkRepository::STATUS_CONSUMED, $this->linkStatus('termlink0001'));
        self::assertNotNull($this->linkRow('termlink0001')['consumed_at']);
    }

    public function testEagerConsumptionNeverResurrectsOrRelabelsATerminalLink(): void
    {
        $this->seedOrder();
        $this->seedLink('termlink0002', status: PaymentLinkRepository::STATUS_REVOKED);
        $this->seedLink('termlink0003', status: PaymentLinkRepository::STATUS_EXPIRED);
        $this->seedLink('termlink0004');

        $this->payments()->markPaid($this->context, self::TENANT, self::ORDER);

        self::assertSame(PaymentLinkRepository::STATUS_REVOKED, $this->linkStatus('termlink0002'));
        self::assertSame(PaymentLinkRepository::STATUS_EXPIRED, $this->linkStatus('termlink0003'));
        self::assertSame(PaymentLinkRepository::STATUS_CONSUMED, $this->linkStatus('termlink0004'));
    }

    public function testEagerConsumptionIsScopedToTheTenantAndTheOrder(): void
    {
        $this->seedOrder();
        $this->seedOrder('termorder002');
        $this->seedLink('termlink0005', orderUuid: 'termorder002');
        $this->seedLink('termlink0006', tenant: 'termtenantB1');

        $this->payments()->markPaid($this->context, self::TENANT, self::ORDER);

        self::assertSame(PaymentLinkRepository::STATUS_ACTIVE, $this->linkStatus('termlink0005'));
        self::assertSame(PaymentLinkRepository::STATUS_ACTIVE, $this->linkStatus('termlink0006'));
    }

    public function testAPaidOrderWithNoLinksIsAnUneventfulNoOp(): void
    {
        $this->seedOrder();

        $this->payments()->markPaid($this->context, self::TENANT, self::ORDER);

        self::assertSame('paid', $this->statusOf(self::ORDER));
        self::assertSame([], $this->connection->table('commerce_payment_links')->get());
    }

    /**
     * The eager transition rides the paid transaction: when the transaction
     * rolls back, the link is still active. (The `$afterPaidHook` seam already
     * exists on `OrderPaymentService` for exactly this kind of proof.)
     */
    public function testARolledBackPaidTransitionLeavesTheLinkUntouched(): void
    {
        $this->seedOrder();
        $this->seedLink('termlink0007');

        $service = new OrderPaymentService(
            new OrderRepository(),
            null,
            static function (): void {
                throw new \RuntimeException('ledger unavailable');
            }
        );

        try {
            $service->markPaid($this->context, self::TENANT, self::ORDER);
            self::fail('the hook must abort the transaction');
        } catch (\RuntimeException) {
            // expected
        }

        self::assertSame('pending_payment', $this->statusOf(self::ORDER));
        self::assertSame(PaymentLinkRepository::STATUS_ACTIVE, $this->linkStatus('termlink0007'));
    }

    // =====================================================================
    // Lazy consumption still applies to whatever the eager pass missed
    // =====================================================================

    public function testResolveStillConsumesLazilyForAnOrderPaidOutsideMarkPaid(): void
    {
        $this->seedOrder();
        $service = $this->links();
        $token = $service->mint($this->context, self::TENANT, self::ORDER, null, self::ACTOR, $this->at('08:00:00'))['rawToken'];

        // A paid transition that never went through markPaid() at all.
        $this->connection->table('commerce_orders')->where('uuid', '=', self::ORDER)->update(['status' => 'paid']);

        $view = $service->resolveByToken($this->context, $token, $this->at('09:00:00'));

        self::assertNotNull($view);
        self::assertSame(PaymentLinkRepository::STATUS_CONSUMED, $view->linkStatus);
        self::assertSame(
            PaymentLinkRepository::STATUS_CONSUMED,
            (string) $this->currentLinkRow()['status'],
            'the lazy transition is persisted, not merely displayed',
        );
    }

    // =====================================================================
    // The real Stripe supersession settlement
    // =====================================================================

    public function testALateSettlementForTheSupersededStripeSessionIsRefusedByThePaidOrderCas(): void
    {
        $this->seedOrder();
        $this->seedLink('termlink0008', issuedAt: '2026-08-11 08:20:00');

        [$superseded, $current] = $this->stripeSupersessionFixture();
        $handler = $this->confirmationHandler();

        // The CURRENT session settles: the order is paid once and its link consumed.
        $handler->confirmed($this->context, $this->payable(), $this->confirmationFrom($current));

        self::assertSame('paid', $this->statusOf(self::ORDER));
        self::assertSame(PaymentLinkRepository::STATUS_CONSUMED, $this->linkStatus('termlink0008'));
        self::assertSame(['order.paid.marker'], $this->markers());

        // The OLD, superseded session settles LATE. Payvia closed its own retired
        // attempt (Task 3); Commerce must refuse to apply it to an order that is
        // already paid.
        $handler->confirmed($this->context, $this->payable(), $this->confirmationFrom($superseded));

        self::assertSame('paid', $this->statusOf(self::ORDER), 'the paid CAS refuses the late settlement');
        self::assertSame(['order.paid.marker'], $this->markers(), 'no second paid transition');
        self::assertSame(
            ['status:paid', 'payment_late_rejected'],
            $this->nonMarkerEventTypes(),
            'the late settlement is audited after the one real paid transition, never applied',
        );

        $rejection = $this->eventOfType('payment_late_rejected');
        self::assertSame((string) $superseded['data']['object']['payment_intent'], $rejection['payload']['reference']);
        self::assertSame(PaymentLinkRepository::STATUS_CONSUMED, $this->linkStatus('termlink0008'));
    }

    public function testTheFixtureCarriesTwoDistinctRealShapedStripeSessions(): void
    {
        [$superseded, $current] = $this->stripeSupersessionFixture();

        foreach ([$superseded, $current] as $event) {
            self::assertSame('checkout.session.completed', $event['type']);
            self::assertSame('checkout.session', $event['data']['object']['object']);
            self::assertSame('complete', $event['data']['object']['status']);
            self::assertSame('paid', $event['data']['object']['payment_status']);
            self::assertStringStartsWith('cs_', (string) $event['data']['object']['id']);
            self::assertStringStartsWith('pi_', (string) $event['data']['object']['payment_intent']);
        }

        self::assertNotSame(
            $superseded['data']['object']['payment_intent'],
            $current['data']['object']['payment_intent'],
            'a supersession is exactly two different provider references',
        );
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /** @return array{0: array<string,mixed>, 1: array<string,mixed>} */
    private function stripeSupersessionFixture(): array
    {
        $path = __DIR__ . '/fixtures/stripe_supersession_settlements.json';
        self::assertFileExists($path);
        /** @var array{superseded: array<string,mixed>, current: array<string,mixed>} $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return [$decoded['superseded'], $decoded['current']];
    }

    /** @param array<string,mixed> $event */
    private function confirmationFrom(array $event): PaymentConfirmation
    {
        /** @var array<string,mixed> $object */
        $object = $event['data']['object'];

        return new PaymentConfirmation(
            'paid',
            (string) $object['payment_intent'],
            (int) $object['amount_total'],
            strtoupper((string) $object['currency']),
            $object
        );
    }

    private function payable(): PayableReference
    {
        return new PayableReference(OrderPayable::TYPE, self::ORDER, 1000, 'USD', 'Order ORD-TERM-1');
    }

    private function confirmationHandler(): OrderPaymentConfirmationHandler
    {
        return new OrderPaymentConfirmationHandler(
            new OrderRepository(),
            $this->payments(),
            new SentinelTenantResolver()
        );
    }

    private function payments(): OrderPaymentService
    {
        return new OrderPaymentService(
            new OrderRepository(),
            null,
            function (ApplicationContext $context, string $tenant, string $orderUuid): void {
                // A marker row proves how many times the paid transition ran.
                $this->orders()->recordEvent($context, $orderUuid, 'order.paid.marker');
            }
        );
    }

    private function links(): PaymentLinkService
    {
        return new PaymentLinkService(
            new OrderRepository(),
            new PaymentLinkRepository(),
            new class implements CurrentTenantResolver {
                public function tenantUuid(ApplicationContext $context): string
                {
                    return '';
                }
            }
        );
    }

    private function orders(): OrderRepository
    {
        return new OrderRepository();
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-11 ' . $time, new \DateTimeZone('UTC'));
    }

    /** @return list<string> */
    private function markers(): array
    {
        return array_values(array_filter(
            $this->eventTypes(),
            static fn (string $type): bool => $type === 'order.paid.marker'
        ));
    }

    /** @return list<string> */
    private function nonMarkerEventTypes(): array
    {
        return array_values(array_filter(
            $this->eventTypes(),
            static fn (string $type): bool => $type !== 'order.paid.marker'
        ));
    }

    /** @return array<string,mixed> */
    private function eventOfType(string $type): array
    {
        foreach ($this->orders()->eventsForOrder($this->context, self::TENANT, self::ORDER) as $row) {
            if ((string) $row['type'] === $type) {
                return $row;
            }
        }

        self::fail("no {$type} event recorded");
    }

    /** @return list<string> */
    private function eventTypes(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['type'],
            $this->orders()->eventsForOrder($this->context, self::TENANT, self::ORDER)
        );
    }

    private function statusOf(string $orderUuid): string
    {
        $row = $this->connection->table('commerce_orders')->where('uuid', '=', $orderUuid)->first();
        self::assertNotNull($row);

        return (string) $row['status'];
    }

    private function linkStatus(string $linkUuid): string
    {
        return (string) $this->linkRow($linkUuid)['status'];
    }

    /** @return array<string,mixed> */
    private function linkRow(string $linkUuid): array
    {
        $row = $this->connection->table('commerce_payment_links')->where('uuid', '=', $linkUuid)->first();
        self::assertNotNull($row);

        return $row;
    }

    /** @return array<string,mixed> */
    private function currentLinkRow(): array
    {
        $rows = $this->connection->table('commerce_payment_links')->orderBy('id', 'ASC')->get();
        self::assertNotSame([], $rows);

        return $rows[count($rows) - 1];
    }

    private function seedOrder(string $orderUuid = self::ORDER): void
    {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => $orderUuid,
            'tenant_uuid' => self::TENANT,
            'order_number' => 'ORD-TERM-' . substr($orderUuid, -3),
            'status' => 'pending_payment',
            'origin' => 'admin',
            'fulfillment_status' => 'unfulfilled',
            'marketplace_partitioned' => false,
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
            'placed_at' => '2026-08-11 08:00:00',
        ]);
    }

    private function seedLink(
        string $uuid,
        string $orderUuid = self::ORDER,
        string $status = PaymentLinkRepository::STATUS_ACTIVE,
        ?string $issuedAt = null,
        string $tenant = self::TENANT
    ): void {
        $this->connection->table('commerce_payment_links')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_uuid' => $orderUuid,
            'token_hash' => hash('sha256', $uuid),
            'status' => $status,
            'expires_at' => '2026-08-18 08:00:00',
            'created_by' => self::ACTOR,
            'initiation_count' => 0,
            'provider_session_issued_at' => $issuedAt,
            'created_at' => '2026-08-11 08:00:00',
        ]);
    }
}
