<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Extensions\Commerce\Marketplace\ChargebackIntegrityException;
use Glueful\Extensions\Commerce\Marketplace\ChargebackRepository;
use Glueful\Extensions\Commerce\Marketplace\ChargebackService;
use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\ReserveConsumptionService;
use Glueful\Extensions\Commerce\Marketplace\ReserveRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderRepository;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\ProviderChargebackEvent;

/**
 * Chargeback INGESTION + classification (design spec §2.4, MV5a Task 10):
 * {@see ChargebackService::ingest()}'s resolve/validate cascade, the
 * historical-partition gate (order's own `marketplace_partitioned`, never
 * current workspace activation), the event-first insert with its
 * `(tenant, provider, provider_event_id)` idempotency claim, and the exact
 * conflict-verify discipline. Posting (Task 11) is out of scope here -- every
 * chargeback-kind assertion below stops at classification/persistence. The
 * one `kind=reversal` case below now exercises Task 14's actual relation
 * resolution (design spec §2.10) since that seam is filled; the full
 * compensating-post behavior itself is covered by
 * {@see \Glueful\Extensions\Commerce\Tests\Integration\Marketplace\ChargebackReversalCompensationTest}.
 */
final class ChargebackIngestTest extends CommerceTestCase
{
    private const TENANT = '';

    private OrderRepository $orders;
    private ChargebackRepository $chargebacks;
    private ChargebackService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orders = new OrderRepository();
        $this->chargebacks = new ChargebackRepository();
        $this->service = new ChargebackService(
            $this->orders,
            $this->chargebacks,
            new SellerOrderRepository(),
            new LedgerRepository(),
            new LedgerAccountLock(),
            new ReserveConsumptionService(new ReserveRepository(), new LedgerRepository())
        );
    }

    /** @param array<string,mixed> $overrides */
    private function seedOrder(array $overrides = []): array
    {
        $tenant = (string) ($overrides['tenant_uuid'] ?? self::TENANT);
        unset($overrides['tenant_uuid']);
        $uuid = (string) ($overrides['uuid'] ?? 'orderCB000001');
        unset($overrides['uuid']);

        $row = array_merge([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_number' => 'ORD-' . $uuid,
            'status' => 'paid',
            'marketplace_partitioned' => true,
            'email' => 'buyer@example.com',
            'guest_token_hash' => hash('sha256', $uuid),
            'currency' => 'USD',
            'subtotal' => 5000,
            'grand_total' => 5000,
        ], $overrides);

        $this->orders->insert($this->context, $row);

        return $this->orders->findByUuid($this->context, $tenant, $uuid) ?? [];
    }

    /** @param array<string,mixed> $overrides */
    private function chargebackEvent(array $overrides = []): ProviderChargebackEvent
    {
        $currency = (string) ($overrides['currency'] ?? 'USD');
        $payableOverrides = $overrides['payable'] ?? [];

        $payable = new PayableReference(
            (string) ($payableOverrides['type'] ?? 'commerce_order'),
            (string) ($payableOverrides['id'] ?? 'orderCB000001'),
            (int) ($payableOverrides['amount'] ?? 5000),
            (string) ($payableOverrides['currency'] ?? $currency),
        );

        return new ProviderChargebackEvent(
            (string) ($overrides['tenantUuid'] ?? self::TENANT),
            (string) ($overrides['provider'] ?? 'stripe'),
            (string) ($overrides['providerEventId'] ?? 'evt_' . bin2hex(random_bytes(6))),
            (string) ($overrides['paymentReference'] ?? 'pay_ref_1'),
            $payable,
            (int) ($overrides['amount'] ?? 5000),
            $currency,
            $overrides['reasonCode'] ?? 'fraudulent',
            (string) ($overrides['occurredAt'] ?? '2026-07-01T12:00:00Z'),
            (string) ($overrides['kind'] ?? ProviderChargebackEvent::KIND_CHARGEBACK),
            $overrides['relatedEventId'] ?? null,
        );
    }

    /** @return list<array<string,mixed>> */
    private function chargebackRows(): array
    {
        return $this->connection->table('commerce_chargebacks')->get();
    }

    // -----------------------------------------------------------------
    // A resolvable, partitioned, FULL chargeback with the order resolved.
    // This fixture seeds NO `commerce_seller_orders`/`commerce_order_lines` at
    // all, so Task 11's auto-expand (filled into this SAME ingest() seam)
    // legitimately finds zero seller allocations and the ENTIRE amount posts
    // as an explicit marketplace-funded remainder (design spec §2.5
    // "exceeds total seller allocations") -- `posted`, not `received`.
    // -----------------------------------------------------------------

    public function testFullChargebackOnPartitionedOrderPersistsReceivedWithResolvedOrder(): void
    {
        $order = $this->seedOrder(['uuid' => 'orderCB000001', 'grand_total' => 5000]);
        self::assertTrue((bool) $order['marketplace_partitioned']);

        $result = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_full_1',
            'amount' => 5000,
            'payable' => ['id' => 'orderCB000001', 'amount' => 5000],
        ]));

        self::assertSame('posted', $result['status']);
        self::assertSame('orderCB000001', $result['order_uuid']);
        self::assertSame(5000, (int) $result['amount']);
        self::assertSame('stripe', $result['provider']);
        self::assertSame('evt_full_1', $result['provider_event_id']);
        self::assertSame('chargeback', $result['kind']);
        self::assertNull($result['related_chargeback_uuid']);
        self::assertCount(1, $this->chargebackRows());
    }

    // -----------------------------------------------------------------
    // A partial chargeback (amount < grand_total) with no attribution
    // lines commits `awaiting_attribution`.
    // -----------------------------------------------------------------

    public function testPartialChargebackWithoutLinesPersistsAwaitingAttribution(): void
    {
        $this->seedOrder(['uuid' => 'orderCB000002', 'grand_total' => 5000]);

        $result = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_partial_1',
            'amount' => 2000,
            'payable' => ['id' => 'orderCB000002', 'amount' => 5000],
        ]));

        self::assertSame('awaiting_attribution', $result['status']);
        self::assertSame('orderCB000002', $result['order_uuid']);
        self::assertSame(2000, (int) $result['amount']);
    }

    // -----------------------------------------------------------------
    // Unresolvable/incoherent events STILL persist event-first, but as
    // `integrity_hold` -- never guessed into a posting.
    // -----------------------------------------------------------------

    public function testUnsupportedPayableTypePersistsIntegrityHoldWithNoResolvedOrder(): void
    {
        $result = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_badtype_1',
            'payable' => ['type' => 'commerce_subscription', 'id' => 'sub00000001', 'amount' => 5000],
        ]));

        self::assertSame('integrity_hold', $result['status']);
        self::assertNull($result['order_uuid']);
        self::assertCount(1, $this->chargebackRows());
    }

    public function testUnknownOrderPersistsIntegrityHoldWithNoResolvedOrder(): void
    {
        $result = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_unknown_1',
            'payable' => ['id' => 'orderDOESNOTEXIST', 'amount' => 5000],
        ]));

        self::assertSame('integrity_hold', $result['status']);
        self::assertNull($result['order_uuid']);
    }

    public function testCurrencyMismatchBetweenEventAndOrderPersistsIntegrityHold(): void
    {
        $this->seedOrder(['uuid' => 'orderCB000003', 'currency' => 'USD', 'grand_total' => 5000]);

        $result = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_currency_1',
            'currency' => 'EUR',
            'amount' => 5000,
            'payable' => ['id' => 'orderCB000003', 'amount' => 5000],
        ]));

        self::assertSame('integrity_hold', $result['status']);
        self::assertSame('orderCB000003', $result['order_uuid'], 'the order WAS resolved -- only currency disagreed.');
    }

    public function testAmountExceedingOrderGrandTotalPersistsIntegrityHold(): void
    {
        $this->seedOrder(['uuid' => 'orderCB000004', 'grand_total' => 5000]);

        $result = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_overamount_1',
            'amount' => 9999,
            'payable' => ['id' => 'orderCB000004', 'amount' => 9999],
        ]));

        self::assertSame('integrity_hold', $result['status']);
        self::assertSame('orderCB000004', $result['order_uuid']);
    }

    // -----------------------------------------------------------------
    // A non-partitioned order is outside MV5a entirely -- an explicit
    // ignored/no-op result, and NO commerce_chargebacks row.
    // -----------------------------------------------------------------

    public function testNonPartitionedOrderIsIgnoredAndInsertsNoChargebackRow(): void
    {
        $this->seedOrder(['uuid' => 'orderCB000005', 'marketplace_partitioned' => false, 'grand_total' => 5000]);

        $result = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_nonpart_1',
            'amount' => 5000,
            'payable' => ['id' => 'orderCB000005', 'amount' => 5000],
        ]));

        self::assertSame('ignored', $result['status']);
        self::assertSame('order_not_partitioned', $result['ignored_reason']);
        self::assertNull($result['chargeback']);
        self::assertCount(0, $this->chargebackRows());
    }

    // -----------------------------------------------------------------
    // Event-first idempotency: identical replay = verified no-op (one
    // row, existing returned); conflicting replay = integrity exception,
    // never a silent skip and never a second row.
    // -----------------------------------------------------------------

    public function testDuplicateProviderEventIdWithIdenticalPayloadIsAVerifiedNoOp(): void
    {
        $this->seedOrder(['uuid' => 'orderCB000006', 'grand_total' => 5000]);

        $event = $this->chargebackEvent([
            'providerEventId' => 'evt_dup_1',
            'amount' => 2000,
            'payable' => ['id' => 'orderCB000006', 'amount' => 5000],
        ]);

        $first = $this->service->ingest($this->context, $event);
        $second = $this->service->ingest($this->context, $event);

        self::assertSame($first['uuid'], $second['uuid']);
        self::assertSame($first['status'], $second['status']);
        self::assertCount(1, $this->chargebackRows());
    }

    public function testDuplicateProviderEventIdWithConflictingPayloadThrowsIntegrityException(): void
    {
        $this->seedOrder(['uuid' => 'orderCB000007', 'grand_total' => 5000]);

        $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_conflict_1',
            'amount' => 2000,
            'payable' => ['id' => 'orderCB000007', 'amount' => 5000],
        ]));

        $this->expectException(ChargebackIntegrityException::class);

        // Same provider + provider_event_id, DIFFERENT amount -- an integrity
        // failure, never a silent skip, never a second row.
        $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_conflict_1',
            'amount' => 3000,
            'payable' => ['id' => 'orderCB000007', 'amount' => 5000],
        ]));
    }

    public function testDuplicateProviderEventIdNeverInsertsASecondRowEvenOnConflict(): void
    {
        $this->seedOrder(['uuid' => 'orderCB000008', 'grand_total' => 5000]);

        $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_conflict_2',
            'amount' => 2000,
            'payable' => ['id' => 'orderCB000008', 'amount' => 5000],
        ]));

        try {
            $this->service->ingest($this->context, $this->chargebackEvent([
                'providerEventId' => 'evt_conflict_2',
                'reasonCode' => 'a-different-reason-code',
                'amount' => 2000,
                'payable' => ['id' => 'orderCB000008', 'amount' => 5000],
            ]));
            self::fail('expected a ChargebackIntegrityException');
        } catch (ChargebackIntegrityException) {
            // expected
        }

        self::assertCount(1, $this->chargebackRows());
    }

    // -----------------------------------------------------------------
    // Historical partition authority (design spec §2.11): a partitioned
    // order keeps ingesting after the marketplace workspace deactivates
    // (or was never activated for this test's config at all) -- current
    // activation is IRRELEVANT, only the order's own immutable flag.
    // -----------------------------------------------------------------

    public function testPartitionedOrderStillIngestsAfterWorkspaceDeactivation(): void
    {
        // No `commerce_marketplace_settings` row is seeded at all here (the
        // marketplace workspace is, if anything, LESS active than
        // "deactivated" -- it was never turned on for this test), proving
        // ChargebackService::ingest() never reads current activation state,
        // only the order's own historical marketplace_partitioned marker.
        $this->seedOrder(['uuid' => 'orderCB000009', 'marketplace_partitioned' => true, 'grand_total' => 5000]);

        $result = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_deactivated_1',
            'amount' => 5000,
            'payable' => ['id' => 'orderCB000009', 'amount' => 5000],
        ]));

        self::assertSame('posted', $result['status'], 'Task 11: no seller orders => fully marketplace-funded');
        self::assertSame('orderCB000009', $result['order_uuid']);
    }

    // -----------------------------------------------------------------
    // tenantUuid='' (single-store mode) is accepted, never rejected, once
    // ownership/order resolution validates against that same tenant.
    // -----------------------------------------------------------------

    public function testEmptyStringTenantIsAcceptedWhenOrderOwnershipValidates(): void
    {
        self::assertSame('', self::TENANT);
        $this->seedOrder(['uuid' => 'orderCB000010', 'tenant_uuid' => '', 'grand_total' => 5000]);

        $result = $this->service->ingest($this->context, $this->chargebackEvent([
            'tenantUuid' => '',
            'providerEventId' => 'evt_singlestore_1',
            'amount' => 5000,
            'payable' => ['id' => 'orderCB000010', 'amount' => 5000],
        ]));

        self::assertSame('posted', $result['status'], 'Task 11: no seller orders => fully marketplace-funded');
        self::assertSame('orderCB000010', $result['order_uuid']);
    }

    // -----------------------------------------------------------------
    // A non-'' tenant is scoped correctly too -- proving ownership
    // resolution isn't accidentally hard-coded to the sentinel tenant.
    // -----------------------------------------------------------------

    public function testNonEmptyTenantOrderResolutionIsTenantScoped(): void
    {
        $this->seedOrder(['uuid' => 'orderCB000011', 'tenant_uuid' => 'tenXYZ00001', 'grand_total' => 5000]);

        // The SAME order uuid does not exist for a DIFFERENT tenant.
        $crossTenant = $this->service->ingest($this->context, $this->chargebackEvent([
            'tenantUuid' => 'tenOTHER001',
            'providerEventId' => 'evt_crosstenant_1',
            'amount' => 5000,
            'payable' => ['id' => 'orderCB000011', 'amount' => 5000],
        ]));
        self::assertSame('integrity_hold', $crossTenant['status']);
        self::assertNull($crossTenant['order_uuid']);

        $ownTenant = $this->service->ingest($this->context, $this->chargebackEvent([
            'tenantUuid' => 'tenXYZ00001',
            'providerEventId' => 'evt_owntenant_1',
            'amount' => 5000,
            'payable' => ['id' => 'orderCB000011', 'amount' => 5000],
        ]));
        self::assertSame('posted', $ownTenant['status'], 'Task 11: no seller orders => fully marketplace-funded');
        self::assertSame('orderCB000011', $ownTenant['order_uuid']);
    }

    // -----------------------------------------------------------------
    // A `kind=reversal` event runs the same resolve/validate/event-first
    // pipeline, then (MV5a Task 14, design spec §2.10) tries to correlate
    // `relatedEventId` to an original chargeback. `evt_original_1` was never
    // ingested here, so the relation is genuinely UNKNOWN -- persisted
    // event-first as `received` first, exactly like any other reversal, then
    // immediately transitioned to `integrity_hold` with `related_chargeback_uuid`
    // left `null` forever (never a guessed uuid). The full relation-resolved /
    // compensating-post paths are covered by
    // {@see \Glueful\Extensions\Commerce\Tests\Integration\Marketplace\ChargebackReversalCompensationTest}.
    // -----------------------------------------------------------------

    public function testReversalKindWithUnknownRelatedEventBecomesIntegrityHold(): void
    {
        $this->seedOrder(['uuid' => 'orderCB000012', 'grand_total' => 5000]);

        $result = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_reversal_1',
            'amount' => 2000,
            'kind' => ProviderChargebackEvent::KIND_REVERSAL,
            'relatedEventId' => 'evt_original_1',
            'payable' => ['id' => 'orderCB000012', 'amount' => 5000],
        ]));

        self::assertSame('integrity_hold', $result['status']);
        self::assertSame('reversal', $result['kind']);
        self::assertNull(
            $result['related_chargeback_uuid'],
            'evt_original_1 does not correlate to any ingested chargeback -- never a guessed uuid.'
        );
    }
}
