<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Orders;

use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\PaymentLinkRepository;
use Glueful\Extensions\Commerce\Orders\PaymentSessionExposureDecision;
use Glueful\Extensions\Commerce\Orders\PaymentSessionExposureException;
use Glueful\Extensions\Commerce\Orders\PaymentSessionExposureGuard;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * Payment-links Task 8 (design spec §2.2, "Expiry/cancel integration"): the ONE
 * authority every non-draft cancellation path consults.
 *
 * The policy, stated once:
 *  - no guard-relevant link            => allow (the ordinary sweep applies);
 *  - an ACTIVE, UNEXPIRED, never-initiated link => automatic cancellation is
 *    BLOCKED (a customer may still be about to pay) but an operator may cancel
 *    without acknowledging anything -- no money is in flight;
 *  - ANY link, whatever its status, with `provider_session_issued_at` set =>
 *    automatic cancellation is BLOCKED and an operator cancellation requires
 *    `accept_late_payment_risk=true`, which records `payment_session_risk_accepted`;
 *  - an UNINITIATED expired/revoked link => back to the ordinary sweep.
 */
final class PaymentSessionExposureGuardTest extends CommerceTestCase
{
    private const TENANT = 'guardtenA001';
    private const ORDER = 'guardorder01';
    private const ACTOR = 'guardactor01';

    private PaymentLinkRepository $links;
    private OrderRepository $orders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->links = new PaymentLinkRepository();
        $this->orders = new OrderRepository();
    }

    // =====================================================================
    // decide()
    // =====================================================================

    public function testAnOrderWithNoLinksAtAllIsPlainlyCancelable(): void
    {
        $this->seedOrder();

        $decision = $this->guard()->decide($this->context, self::TENANT, $this->order(), $this->at('09:00:00'));

        self::assertSame(PaymentSessionExposureDecision::REASON_NONE, $decision->reason);
        self::assertTrue($decision->permitsAutomaticCancellation());
        self::assertFalse($decision->requiresRiskAcknowledgement());
        self::assertTrue($decision->permitsOperatorCancellation(false));
    }

    public function testAnActiveUnexpiredUninitiatedLinkBlocksTheSweepButNotAnOperator(): void
    {
        $this->seedOrder();
        $this->seedLink('guardlink001', expiresAt: '2026-08-18 08:00:00');

        $decision = $this->guard()->decide($this->context, self::TENANT, $this->order(), $this->at('09:00:00'));

        self::assertSame(PaymentSessionExposureDecision::REASON_ACTIVE_LINK, $decision->reason);
        self::assertFalse($decision->permitsAutomaticCancellation());
        self::assertFalse($decision->requiresRiskAcknowledgement());
        self::assertTrue($decision->permitsOperatorCancellation(false));
    }

    public function testAnUninitiatedLapsedLinkReturnsTheOrderToTheOrdinarySweep(): void
    {
        $this->seedOrder();
        $this->seedLink('guardlink002', expiresAt: '2026-08-11 08:30:00');

        $decision = $this->guard()->decide($this->context, self::TENANT, $this->order(), $this->at('09:00:00'));

        self::assertSame(PaymentSessionExposureDecision::REASON_NONE, $decision->reason);
        self::assertTrue($decision->permitsAutomaticCancellation());
    }

    public function testAnUninitiatedRevokedLinkReturnsTheOrderToTheOrdinarySweep(): void
    {
        $this->seedOrder();
        $this->seedLink('guardlink003', expiresAt: '2026-08-18 08:00:00', status: PaymentLinkRepository::STATUS_REVOKED);

        $decision = $this->guard()->decide($this->context, self::TENANT, $this->order(), $this->at('09:00:00'));

        self::assertSame(PaymentSessionExposureDecision::REASON_NONE, $decision->reason);
        self::assertTrue($decision->permitsAutomaticCancellation());
    }

    /**
     * "ANY link with `provider_session_issued_at` NOT NULL blocks automatic
     * cancellation REGARDLESS of link status" -- money may already be in flight
     * behind a revoked, expired, or consumed link just as easily as an active one.
     *
     * @dataProvider everyLinkStatus
     */
    public function testAnIssuedSessionBlocksAutomaticCancellationWhateverTheLinkStatus(string $status): void
    {
        $this->seedOrder();
        $this->seedLink(
            'guardlink004',
            // Deliberately LAPSED, so the "active and unexpired" branch cannot be
            // what is doing the blocking.
            expiresAt: '2026-08-11 08:30:00',
            status: $status,
            issuedAt: '2026-08-11 08:20:00'
        );

        $decision = $this->guard()->decide($this->context, self::TENANT, $this->order(), $this->at('09:00:00'));

        self::assertSame(PaymentSessionExposureDecision::REASON_SESSION_EXPOSED, $decision->reason, $status);
        self::assertFalse($decision->permitsAutomaticCancellation(), $status);
        self::assertTrue($decision->requiresRiskAcknowledgement(), $status);
        self::assertFalse($decision->permitsOperatorCancellation(false), $status);
        self::assertTrue($decision->permitsOperatorCancellation(true), $status);
    }

    /** @return array<string, array{string}> */
    public static function everyLinkStatus(): array
    {
        return [
            'active' => [PaymentLinkRepository::STATUS_ACTIVE],
            'revoked' => [PaymentLinkRepository::STATUS_REVOKED],
            'expired' => [PaymentLinkRepository::STATUS_EXPIRED],
            'consumed' => [PaymentLinkRepository::STATUS_CONSUMED],
        ];
    }

    public function testExposureOutranksAMerelyActiveSiblingLink(): void
    {
        $this->seedOrder();
        $this->seedLink('guardlink005', expiresAt: '2026-08-11 08:30:00', status: PaymentLinkRepository::STATUS_REVOKED, issuedAt: '2026-08-11 08:20:00');
        $this->seedLink('guardlink006', expiresAt: '2026-08-18 08:00:00');

        $decision = $this->guard()->decide($this->context, self::TENANT, $this->order(), $this->at('09:00:00'));

        self::assertSame(PaymentSessionExposureDecision::REASON_SESSION_EXPOSED, $decision->reason);
        self::assertSame('guardlink005', $decision->linkUuid);
    }

    /**
     * "until PAYMENT or an explicit operator cancellation" -- once the order is
     * no longer awaiting payment the late-payment risk is resolved, so the guard
     * stops blocking even though the exposure stamp is never cleared.
     */
    public function testAnExposedLinkStopsBlockingOnceTheOrderIsNoLongerAwaitingPayment(): void
    {
        $this->seedOrder(status: 'paid');
        $this->seedLink('guardlink007', expiresAt: '2026-08-18 08:00:00', status: PaymentLinkRepository::STATUS_CONSUMED, issuedAt: '2026-08-11 08:20:00');

        $decision = $this->guard()->decide($this->context, self::TENANT, $this->order(), $this->at('09:00:00'));

        self::assertSame(PaymentSessionExposureDecision::REASON_NONE, $decision->reason);
        self::assertTrue($decision->permitsOperatorCancellation(false));
    }

    public function testAnotherTenantsExposedLinkIsInvisibleToThisTenantsGuard(): void
    {
        $this->seedOrder();
        $this->seedLink('guardlink008', expiresAt: '2026-08-18 08:00:00', issuedAt: '2026-08-11 08:20:00', tenant: 'guardtenB002');

        $decision = $this->guard()->decide($this->context, self::TENANT, $this->order(), $this->at('09:00:00'));

        self::assertSame(PaymentSessionExposureDecision::REASON_NONE, $decision->reason);
    }

    // =====================================================================
    // authorizeOperatorCancellation()
    // =====================================================================

    public function testAnUnacknowledgedOperatorCancellationOfAnExposedOrderIsRefusedAndAuditsNothing(): void
    {
        $this->seedOrder();
        $this->seedLink('guardlink009', expiresAt: '2026-08-18 08:00:00', issuedAt: '2026-08-11 08:20:00');

        try {
            $this->guard()->authorizeOperatorCancellation(
                $this->context,
                self::TENANT,
                $this->order(),
                false,
                self::ACTOR,
                $this->at('09:00:00')
            );
            self::fail('an exposed order must refuse an unacknowledged operator cancellation');
        } catch (PaymentSessionExposureException $e) {
            self::assertSame(PaymentSessionExposureException::RISK_UNACKNOWLEDGED, $e->errorCode);
            self::assertInstanceOf(\DomainException::class, $e);
        }

        self::assertSame([], $this->eventTypes());
    }

    public function testAnAcknowledgedOperatorCancellationRecordsTheRiskEventWithActorAndTime(): void
    {
        $this->seedOrder();
        $this->seedLink('guardlink010', expiresAt: '2026-08-18 08:00:00', issuedAt: '2026-08-11 08:20:00');

        $decision = $this->guard()->authorizeOperatorCancellation(
            $this->context,
            self::TENANT,
            $this->order(),
            true,
            self::ACTOR,
            $this->at('09:00:00')
        );

        self::assertSame(PaymentSessionExposureDecision::REASON_SESSION_EXPOSED, $decision->reason);
        self::assertSame([PaymentSessionExposureGuard::RISK_ACCEPTED_EVENT], $this->eventTypes());

        $event = $this->orders->eventsForOrder($this->context, self::TENANT, self::ORDER)[0];
        self::assertSame(self::ACTOR, $event['actor_uuid']);
        self::assertSame('2026-08-11 09:00:00', $event['payload']['accepted_at']);
        self::assertSame('guardlink010', $event['payload']['link_uuid']);
        self::assertStringNotContainsString('token', json_encode($event, JSON_THROW_ON_ERROR));
    }

    public function testAnAcknowledgementIsNotRecordedWhenNothingWasExposed(): void
    {
        $this->seedOrder();
        $this->seedLink('guardlink011', expiresAt: '2026-08-18 08:00:00');

        $this->guard()->authorizeOperatorCancellation(
            $this->context,
            self::TENANT,
            $this->order(),
            true,
            self::ACTOR,
            $this->at('09:00:00')
        );

        self::assertSame([], $this->eventTypes(), 'nothing was exposed, so there is no risk to record');
    }

    public function testTheDecisionProjectionCarriesNoTokenAndOnlyTheThreeExposureFacts(): void
    {
        $this->seedOrder();
        $this->seedLink('guardlink012', expiresAt: '2026-08-18 08:00:00', issuedAt: '2026-08-11 08:20:00');

        $projected = $this->guard()->decide($this->context, self::TENANT, $this->order(), $this->at('09:00:00'))->toArray();

        self::assertSame(
            ['blocks_automatic_cancellation', 'reason', 'requires_risk_acknowledgement'],
            $this->sortedKeys($projected)
        );
        self::assertTrue($projected['blocks_automatic_cancellation']);
        self::assertTrue($projected['requires_risk_acknowledgement']);
        self::assertSame(PaymentSessionExposureDecision::REASON_SESSION_EXPOSED, $projected['reason']);
    }

    public function testTheReasonVocabularyIsClosed(): void
    {
        self::assertSame(
            ['none', 'active_link', 'session_exposed'],
            PaymentSessionExposureDecision::REASONS
        );
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function guard(): PaymentSessionExposureGuard
    {
        return new PaymentSessionExposureGuard($this->links, $this->orders);
    }

    /** @return array<string,mixed> */
    private function order(): array
    {
        $order = $this->orders->findByUuid($this->context, self::TENANT, self::ORDER, true);
        self::assertNotNull($order);

        return $order;
    }

    /** @return list<string> */
    private function eventTypes(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['type'],
            $this->orders->eventsForOrder($this->context, self::TENANT, self::ORDER)
        );
    }

    /** @param array<string,mixed> $payload @return list<string> */
    private function sortedKeys(array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys);

        return $keys;
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-11 ' . $time, new \DateTimeZone('UTC'));
    }

    private function seedOrder(string $status = 'pending_payment'): void
    {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => self::ORDER,
            'tenant_uuid' => self::TENANT,
            'order_number' => 'ORD-GUARD-1',
            'status' => $status,
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
        string $expiresAt,
        string $status = PaymentLinkRepository::STATUS_ACTIVE,
        ?string $issuedAt = null,
        string $tenant = self::TENANT
    ): void {
        $this->connection->table('commerce_payment_links')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_uuid' => self::ORDER,
            'token_hash' => hash('sha256', $uuid),
            'status' => $status,
            'expires_at' => $expiresAt,
            'created_by' => self::ACTOR,
            'initiation_count' => 0,
            'provider_session_issued_at' => $issuedAt,
            'created_at' => '2026-08-11 08:00:00',
        ]);
    }
}
