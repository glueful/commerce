<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Marketplace\ChargebackRepository;
use Glueful\Extensions\Commerce\Marketplace\ChargebackService;
use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerPostingService;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceRefundGuard;
use Glueful\Extensions\Commerce\Marketplace\ReserveConsumptionService;
use Glueful\Extensions\Commerce\Marketplace\ReserveRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderRepository;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundInput;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundService;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Commerce\Tests\Support\QueryLoggingPdoStatement;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\ProviderChargebackEvent;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;

/**
 * Refund reserve-first + debt semantics, and the cap-symmetry fix (design
 * spec §2.5/§2.6, MV5a Task 12):
 *
 *  1. {@see LedgerPostingService::postRefund()} now consumes a seller's
 *     unreleased `commerce_seller_reserves` FIRST -- for the NET seller
 *     liability `max(0, refund_debit - commission_reversal)`, under the
 *     seller's already-claimed account lock -- before its refund_debit /
 *     commission_reversal postings land. The full debit still posts
 *     unchanged; the net effect may drive `available` negative (debt via
 *     {@see LedgerRepository::balanceComponents()}'s `debt` component),
 *     mirroring {@see ChargebackService::postAttributedLines()}'s identical
 *     reserve-first step on the chargeback side (proven in
 *     {@see ChargebackReversalTest}).
 *
 *  2. REVIEW-FOUND symmetry gap: the refund cap's "prior completed / remaining"
 *     derivation ({@see LedgerPostingService}'s `R_before`, and {@see
 *     MarketplaceRefundGuard::completedAmountByLine()}'s auto-expand estimate)
 *     now ALSO subtracts prior POSTED `commerce_chargeback_lines` for the SAME
 *     order line (reusing {@see ChargebackRepository::priorPostedChargedBackByLine()}),
 *     so a refund landing AFTER a chargeback on the same line can never
 *     double-reverse commission or merchandise the chargeback already
 *     reversed -- symmetric to {@see ChargebackService}'s own cap, which
 *     already subtracts prior refunds AND prior chargebacks.
 */
final class RefundReserveFirstTest extends CommerceTestCase
{
    private const TENANT = '';

    private OrderRepository $orders;
    private RefundRepository $refunds;
    private ChargebackRepository $chargebacks;
    private LedgerRepository $ledgerRepo;
    private ReserveRepository $reserveRepo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orders = new OrderRepository();
        $this->refunds = new RefundRepository();
        $this->chargebacks = new ChargebackRepository();
        $this->ledgerRepo = new LedgerRepository();
        $this->reserveRepo = new ReserveRepository();
    }

    // -----------------------------------------------------------------
    // Fixture helpers (mirror ChargebackReversalTest's identically-shaped
    // hand-built fixtures, so both sides of the symmetric reserve/chargeback
    // cap can be exercised without full checkout/catalog machinery).
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function seedOrder(array $overrides = []): array
    {
        $uuid = (string) ($overrides['uuid'] ?? 'orderRFX0001');
        unset($overrides['uuid']);

        $row = array_merge([
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT,
            'order_number' => 'ORD-' . $uuid,
            'status' => 'paid',
            'marketplace_partitioned' => true,
            'email' => 'buyer@example.com',
            'guest_token_hash' => hash('sha256', $uuid),
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
        ], $overrides);

        $this->orders->insert($this->context, $row);

        return $this->orders->findByUuid($this->context, self::TENANT, $uuid) ?? [];
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function seedSellerOrder(array $overrides): array
    {
        $row = array_merge([
            'tenant_uuid' => self::TENANT,
            'seller_name_snapshot' => 'Seller',
            'partition_number' => 1,
            'seller_reference' => (string) $overrides['uuid'] . '-ref',
            'tax_attribution_method' => 'proportional',
            'allocated_discount' => 0,
            'allocated_shipping_discount' => 0,
            'allocated_shipping' => 0,
            'allocated_tax' => 0,
            'commission_amount' => 0,
            'confirmed_at' => '2026-01-01 00:00:00',
            'status' => 'open',
            'fulfillment_status' => 'unfulfilled',
            'revision' => 0,
        ], $overrides);

        $this->connection->table('commerce_seller_orders')->insert($row);

        return $row;
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function seedOrderLine(array $overrides): array
    {
        $uuid = (string) $overrides['uuid'];

        $row = array_merge([
            'variant_uuid' => 'variant' . substr(md5($uuid), 0, 8),
            'product_name' => 'Product ' . $uuid,
            'sku' => 'SKU-' . $uuid,
            'option_values' => '[]',
            'unit_price' => (int) ($overrides['line_total'] ?? 0),
            'quantity' => 1,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'commission_basis' => 0,
            'commission_amount' => 0,
            'seller_uuid' => null,
        ], $overrides);

        $this->connection->table('commerce_order_lines')->insert($row);

        return $row;
    }

    private function seedSaleLedger(
        string $orderUuid,
        string $sellerUuid,
        string $currency,
        int $saleCredit,
        int $commissionDebit
    ): void {
        $this->ledgerRepo->post($this->context, self::TENANT, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller($sellerUuid),
            'seller_uuid' => $sellerUuid,
            'currency' => $currency,
            'entry_type' => 'sale_credit',
            'amount' => $saleCredit,
            'order_uuid' => $orderUuid,
            'idempotency_key' => "{$orderUuid}:{$sellerUuid}:sale_credit",
        ]);
        if ($commissionDebit > 0) {
            $this->ledgerRepo->post($this->context, self::TENANT, [
                'account_kind' => 'seller',
                'account_key' => LedgerRepository::accountKeyForSeller($sellerUuid),
                'seller_uuid' => $sellerUuid,
                'currency' => $currency,
                'entry_type' => 'commission_debit',
                'amount' => -$commissionDebit,
                'order_uuid' => $orderUuid,
                'idempotency_key' => "{$orderUuid}:{$sellerUuid}:commission_debit",
            ]);
        }
    }

    private function seedHeldReserve(
        string $uuid,
        string $sellerUuid,
        string $currency,
        int $amount,
        string $sellerOrderUuid
    ): void {
        $this->reserveRepo->insertRollingHold($this->context, self::TENANT, [
            'uuid' => $uuid,
            'seller_uuid' => $sellerUuid,
            'currency' => $currency,
            'seller_order_uuid' => $sellerOrderUuid,
            'amount' => $amount,
            'reserve_bps_snapshot' => 250,
            'reserve_days_snapshot' => 7,
            'held_at' => gmdate('Y-m-d H:i:s', time() - 86400),
            'release_at' => gmdate('Y-m-d H:i:s', time() - 3600),
        ]);

        $this->ledgerRepo->post($this->context, self::TENANT, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller($sellerUuid),
            'seller_uuid' => $sellerUuid,
            'currency' => $currency,
            'entry_type' => 'reserve_hold',
            'amount' => -$amount,
            'seller_order_uuid' => $sellerOrderUuid,
            'payout_uuid' => null,
            'reserve_uuid' => $uuid,
            'idempotency_key' => "{$sellerOrderUuid}:{$sellerUuid}:reserve_hold",
        ]);
    }

    /** @param array<string,mixed> $overrides */
    private function chargebackEvent(array $overrides = []): ProviderChargebackEvent
    {
        $currency = (string) ($overrides['currency'] ?? 'USD');
        $payableOverrides = $overrides['payable'] ?? [];

        $payable = new PayableReference(
            (string) ($payableOverrides['type'] ?? 'commerce_order'),
            (string) ($payableOverrides['id'] ?? 'orderRFX0001'),
            (int) ($payableOverrides['amount'] ?? 1000),
            (string) ($payableOverrides['currency'] ?? $currency),
        );

        return new ProviderChargebackEvent(
            (string) ($overrides['tenantUuid'] ?? self::TENANT),
            (string) ($overrides['provider'] ?? 'stripe'),
            (string) ($overrides['providerEventId'] ?? 'evt_' . bin2hex(random_bytes(6))),
            (string) ($overrides['paymentReference'] ?? 'pay_ref_1'),
            $payable,
            (int) ($overrides['amount'] ?? 1000),
            $currency,
            $overrides['reasonCode'] ?? 'fraudulent',
            (string) ($overrides['occurredAt'] ?? '2026-07-01T12:00:00Z'),
            (string) ($overrides['kind'] ?? ProviderChargebackEvent::KIND_CHARGEBACK),
            $overrides['relatedEventId'] ?? null,
        );
    }

    private function ledgerPostingService(): LedgerPostingService
    {
        return new LedgerPostingService(
            $this->ledgerRepo,
            new LedgerAccountLock(),
            null,
            new ReserveConsumptionService($this->reserveRepo, $this->ledgerRepo),
            $this->chargebacks
        );
    }

    private function chargebackService(): ChargebackService
    {
        return new ChargebackService(
            $this->orders,
            $this->chargebacks,
            new SellerOrderRepository(),
            $this->ledgerRepo,
            new LedgerAccountLock(),
            new ReserveConsumptionService($this->reserveRepo, $this->ledgerRepo)
        );
    }

    /** Fully wired -- the real Task 12 production shape (reserve consumption + chargeback-aware cap, both directions). */
    private function refundService(): RefundService
    {
        return new RefundService(
            $this->orders,
            $this->refunds,
            new StockRepository(),
            $this->tenantResolver(),
            null,
            new MarketplaceRefundGuard($this->refunds, $this->chargebacks),
            $this->ledgerPostingService()
        );
    }

    private function tenantResolver(): CurrentTenantResolver
    {
        return new SentinelTenantResolver();
    }

    /** @return array<string,mixed> */
    private function reserveRow(string $uuid): array
    {
        return $this->connection->table('commerce_seller_reserves')->where('uuid', '=', $uuid)->first();
    }

    /** @return list<array<string,mixed>> ordered oldest-first */
    private function ledgerRowsForRefund(string $refundUuid): array
    {
        return $this->connection->table('commerce_marketplace_ledger')
            ->where('refund_uuid', '=', $refundUuid)
            ->orderBy('id', 'ASC')
            ->get();
    }

    /** @return list<array<string,mixed>> ordered oldest-first */
    private function ledgerRowsForChargeback(string $chargebackUuid): array
    {
        return $this->connection->table('commerce_marketplace_ledger')
            ->where('chargeback_uuid', '=', $chargebackUuid)
            ->orderBy('id', 'ASC')
            ->get();
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    private function rowForSellerAndType(array $rows, string $sellerUuid, string $entryType): array
    {
        foreach ($rows as $row) {
            if ((string) $row['seller_uuid'] === $sellerUuid && (string) $row['entry_type'] === $entryType) {
                return $row;
            }
        }

        self::fail("No ledger row for seller '{$sellerUuid}' entry_type '{$entryType}'.");
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    private function rowForAccountKeyAndType(array $rows, string $accountKey, string $entryType): array
    {
        foreach ($rows as $row) {
            if ((string) $row['account_key'] === $accountKey && (string) $row['entry_type'] === $entryType) {
                return $row;
            }
        }

        self::fail("No ledger row for account_key '{$accountKey}' entry_type '{$entryType}'.");
    }

    /** @return array<string,mixed> */
    private function orderRow(string $uuid): array
    {
        $row = $this->connection->table('commerce_orders')->where('uuid', '=', $uuid)->first();
        self::assertNotNull($row);

        return $row;
    }

    // -----------------------------------------------------------------
    // 1. Reserve-first: a refund with an existing SUFFICIENT held reserve
    //    consumes EXACTLY the net seller liability (refund_debit minus
    //    commission_reversal) -- reserved drops by exactly that amount,
    //    available is fully cushioned (unchanged, never over-released).
    // -----------------------------------------------------------------

    public function testRefundWithExistingReserveConsumesExactlyTheNetLiabilityAndCushionsAvailable(): void
    {
        $this->seedOrder(['uuid' => 'orderRSV0001', 'grand_total' => 1000]);
        $this->seedSellerOrder([
            'uuid' => 'selordRSV001',
            'order_uuid' => 'orderRSV0001',
            'seller_uuid' => 'sellerRSV001',
            'currency' => 'USD',
            'subtotal' => 1000,
            'attributed_total' => 1000,
        ]);
        $line = $this->seedOrderLine([
            'uuid' => 'lineRSV00001',
            'order_uuid' => 'orderRSV0001',
            'seller_uuid' => 'sellerRSV001',
            'line_total' => 1000,
            'commission_basis' => 1000,
            'commission_amount' => 100,
        ]);
        $this->seedSaleLedger('orderRSV0001', 'sellerRSV001', 'USD', 1000, 100);
        $this->seedHeldReserve('resvRSV00001', 'sellerRSV001', 'USD', 500, 'selordRSV001');

        $balanceBefore = $this->ledgerRepo->balanceComponents(
            $this->context,
            self::TENANT,
            LedgerRepository::accountKeyForSeller('sellerRSV001'),
            'USD'
        );
        self::assertSame(400, $balanceBefore['available'], 'sanity: 1000 sale - 100 commission - 500 reserve hold');
        self::assertSame(500, $balanceBefore['reserved']);

        $refund = $this->refundService()->issue(
            $this->context,
            'orderRSV0001',
            new RefundInput(300, 'reserve-first', [
                ['order_line_uuid' => (string) $line['uuid'], 'quantity' => 1, 'amount' => 300],
            ], false),
            'idem-reserve-first-1'
        );

        self::assertSame('completed', $refund['status']);
        $refundUuid = (string) $refund['uuid'];

        // debit = deltaR = 300 (basis 1000, R_before 0); reversal = target(100,1000,300)
        // = floor(100*300+500,1000) = 30; netLiability = 300 - 30 = 270 -- EXACTLY what
        // must be consumed from the reserve first, never the full 300 debit nor the
        // full 500 reserve.
        $ledger = $this->ledgerRowsForRefund($refundUuid);
        self::assertSame(
            ['reserve_release', 'refund_debit', 'commission_reversal'],
            array_column($ledger, 'entry_type'),
            'the reserve consumption must post BEFORE the refund_debit/commission_reversal'
        );
        self::assertSame(270, (int) $ledger[0]['amount']);
        self::assertSame('resvRSV00001', $ledger[0]['reserve_uuid']);
        self::assertSame($refundUuid, $ledger[0]['refund_uuid']);
        self::assertNull($ledger[0]['chargeback_uuid']);
        self::assertSame(-300, (int) $ledger[1]['amount']);
        self::assertSame(30, (int) $ledger[2]['amount']);

        $reserveAfter = $this->reserveRow('resvRSV00001');
        self::assertSame('held', $reserveAfter['status'], 'only partially consumed (270 of 500) -- stays held');
        self::assertNull($reserveAfter['closed_at']);
        self::assertSame(
            230,
            $this->ledgerRepo->remainingForReserve($this->context, self::TENANT, 'resvRSV00001')
        );

        $balanceAfter = $this->ledgerRepo->balanceComponents(
            $this->context,
            self::TENANT,
            LedgerRepository::accountKeyForSeller('sellerRSV001'),
            'USD'
        );
        self::assertSame(
            $balanceBefore['reserved'] - 270,
            $balanceAfter['reserved'],
            'reserved must drop by EXACTLY the consumed net liability (270), never the full debit (300)'
        );
        self::assertSame(
            $balanceBefore['available'],
            $balanceAfter['available'],
            'available must be fully cushioned (unchanged) -- the reserve absorbed the entire net liability, '
                . 'proving no over-release: only 270 of the available 500 reserve was drawn down'
        );
        self::assertSame(0, $balanceAfter['debt']);
    }

    // -----------------------------------------------------------------
    // 2. Debt: a refund whose net liability exceeds reserve + available
    //    exhausts the reserve, then drives `available` negative -- the debt
    //    component surfaces the shortfall, and the full debit still posts
    //    uncapped (never truncated to the shortfall).
    // -----------------------------------------------------------------

    public function testRefundLiabilityExceedingReservePlusAvailableExhaustsReserveThenDrivesDebt(): void
    {
        $this->seedOrder(['uuid' => 'orderDBT0001', 'grand_total' => 1000]);
        $this->seedSellerOrder([
            'uuid' => 'selordDBT001',
            'order_uuid' => 'orderDBT0001',
            'seller_uuid' => 'sellerDBT001',
            'currency' => 'USD',
            'subtotal' => 1000,
            'attributed_total' => 1000,
        ]);
        $line = $this->seedOrderLine([
            'uuid' => 'lineDBT00001',
            'order_uuid' => 'orderDBT0001',
            'seller_uuid' => 'sellerDBT001',
            'line_total' => 1000,
            'commission_basis' => 1000,
            'commission_amount' => 100,
        ]);
        $this->seedSaleLedger('orderDBT0001', 'sellerDBT001', 'USD', 1000, 100);
        $this->seedHeldReserve('resvDBT00001', 'sellerDBT001', 'USD', 150, 'selordDBT001');

        // The seller already withdrew their full available proceeds (1000 - 100
        // commission - 150 reserve = 750) via a completed payout BEFORE the refund
        // arrives -- available is now exactly 0, mirroring the identical setup
        // ChargebackReversalTest's own debt test uses on the chargeback side.
        $this->connection->table('commerce_marketplace_ledger')->insert([
            'uuid' => 'ledgerRFXPAYOUT1',
            'tenant_uuid' => self::TENANT,
            'account_key' => LedgerRepository::accountKeyForSeller('sellerDBT001'),
            'account_kind' => 'seller',
            'seller_uuid' => 'sellerDBT001',
            'currency' => 'USD',
            'entry_type' => 'payout_debit',
            'amount' => -750,
            'order_uuid' => 'orderDBT0001',
            'payout_uuid' => 'payoutRFXDBT01',
            'idempotency_key' => 'payoutRFXDBT01:payout_debit',
        ]);
        $balanceBefore = $this->ledgerRepo->balanceComponents(
            $this->context,
            self::TENANT,
            LedgerRepository::accountKeyForSeller('sellerDBT001'),
            'USD'
        );
        self::assertSame(0, $balanceBefore['available'], 'sanity: the payout drained available to exactly 0');
        self::assertSame(150, $balanceBefore['reserved']);

        $refund = $this->refundService()->issue(
            $this->context,
            'orderDBT0001',
            new RefundInput(1000, 'full remaining, drives debt', [
                ['order_line_uuid' => (string) $line['uuid'], 'quantity' => 1, 'amount' => 1000],
            ], false),
            'idem-debt-1'
        );

        self::assertSame('completed', $refund['status']);
        self::assertSame('refunded', $this->orderRow('orderDBT0001')['status']);
        $refundUuid = (string) $refund['uuid'];

        // debit = deltaR = 1000 (full basis); reversal = target(100,1000,1000) = 100;
        // netLiability = 900. Reserve only holds 150 -- fully exhausted -- and the
        // shortfall (750) drives available negative.
        $ledger = $this->ledgerRowsForRefund($refundUuid);
        self::assertSame(
            ['reserve_release', 'refund_debit', 'commission_reversal'],
            array_column($ledger, 'entry_type')
        );
        self::assertSame(
            150,
            (int) $ledger[0]['amount'],
            'only the total held reserve (150) is consumed, never the full 900 liability'
        );
        self::assertSame(
            -1000,
            (int) $ledger[1]['amount'],
            'the debit posts in FULL, never truncated to the shortfall'
        );
        self::assertSame(100, (int) $ledger[2]['amount']);

        $reserveAfter = $this->reserveRow('resvDBT00001');
        self::assertSame('consumed', $reserveAfter['status']);
        self::assertNotNull($reserveAfter['closed_at']);

        $balanceAfter = $this->ledgerRepo->balanceComponents(
            $this->context,
            self::TENANT,
            LedgerRepository::accountKeyForSeller('sellerDBT001'),
            'USD'
        );
        self::assertSame(
            -750,
            $balanceAfter['available'],
            '0 (before) + 150 (reserve release) - 1000 (debit) + 100 (reversal) = -750'
        );
        self::assertSame(750, $balanceAfter['debt'], 'debt = max(0, -available)');
        self::assertSame(0, $balanceAfter['reserved'], 'the reserve is fully exhausted');
    }

    // -----------------------------------------------------------------
    // 3. CAP SYMMETRY (the review finding): a partial CHARGEBACK posts on a
    //    line first, THEN a refund on the SAME line whose own cash would,
    //    combined with the prior chargeback, exceed the line's original
    //    basis -- the refund's own delta_R/commission_reversal must be
    //    CAPPED against the COMBINED remaining (prior refunds + prior
    //    chargebacks), never against prior refunds alone. Proves the
    //    marketplace-loss scenario (double-reversed commission, over-1x
    //    merchandise reversal) is now prevented.
    // -----------------------------------------------------------------

    public function testRefundAfterPriorChargebackOnSameLineCapsAgainstTheCombinedRemainingNeverDoubleReversing(): void
    {
        $this->seedOrder(['uuid' => 'orderSYM0001', 'grand_total' => 1000]);
        $this->seedSellerOrder([
            'uuid' => 'selordSYM001',
            'order_uuid' => 'orderSYM0001',
            'seller_uuid' => 'sellerSYM001',
            'currency' => 'USD',
            'subtotal' => 1000,
            'attributed_total' => 1000,
        ]);
        $line = $this->seedOrderLine([
            'uuid' => 'lineSYM00001',
            'order_uuid' => 'orderSYM0001',
            'seller_uuid' => 'sellerSYM001',
            'line_total' => 1000,
            'commission_basis' => 1000,
            'commission_amount' => 100,
        ]);
        $this->seedSaleLedger('orderSYM0001', 'sellerSYM001', 'USD', 1000, 100);

        // Step 1: a partial chargeback of 600 posts against the line first --
        // reverses 60 of the line's own 100 commission (target(100,1000,600) = 60).
        $ingested = $this->chargebackService()->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_sym_partial_1',
            'amount' => 600,
            'payable' => ['id' => 'orderSYM0001', 'amount' => 1000],
        ]));
        self::assertSame('awaiting_attribution', $ingested['status']);
        $chargebackUuid = (string) $ingested['uuid'];

        $posted = $this->chargebackService()->attributeAndPost($this->context, self::TENANT, $chargebackUuid, [
            ['order_line_uuid' => (string) $line['uuid'], 'amount' => 600],
        ]);
        self::assertSame('posted', $posted['status']);

        $chargebackLedger = $this->ledgerRowsForChargeback($chargebackUuid);
        $chargebackDebit = $this->rowForSellerAndType($chargebackLedger, 'sellerSYM001', 'chargeback_debit');
        $chargebackReversal = $this->rowForSellerAndType($chargebackLedger, 'sellerSYM001', 'commission_reversal');
        self::assertSame(-600, (int) $chargebackDebit['amount']);
        self::assertSame(60, (int) $chargebackReversal['amount'], 'sanity: target(100,1000,600) = 60');

        // Step 2: a refund of 600 on the SAME line. BEFORE the Task 12 fix,
        // postRefund()'s R_before would only see prior COMPLETED refunds (zero
        // here), so delta_R would be the full 600 and commission_reversal would be
        // target(100,1000,600) - 0 = 60 -- 60 (chargeback) + 60 (refund) = 120,
        // EXCEEDING the line's own 100 original commission by 20 (a marketplace
        // loss), and 600 (chargeback) + 600 (refund) = 1200 merchandise reversed
        // against a 1000 basis (an extra 200 improperly re-debited from the
        // seller). AFTER the fix, R_before folds in the prior POSTED chargeback
        // (600), so delta_R caps at 400 (1000 - 600 remaining) and the reversal is
        // capped at 40 (100 - 60 remaining commission).
        $refund = $this->refundService()->issue(
            $this->context,
            'orderSYM0001',
            new RefundInput(600, 'refund after prior chargeback on same line', [
                ['order_line_uuid' => (string) $line['uuid'], 'quantity' => 1, 'amount' => 600],
            ], false),
            'idem-cap-symmetry-1'
        );
        self::assertSame('completed', $refund['status']);
        $refundUuid = (string) $refund['uuid'];

        $refundLedger = $this->ledgerRowsForRefund($refundUuid);
        $refundDebit = $this->rowForSellerAndType($refundLedger, 'sellerSYM001', 'refund_debit');
        $refundReversal = $this->rowForSellerAndType($refundLedger, 'sellerSYM001', 'commission_reversal');
        $marketplaceDebit = $this->rowForAccountKeyAndType($refundLedger, 'marketplace', 'refund_debit');

        self::assertSame(
            -400,
            (int) $refundDebit['amount'],
            'delta_R must cap at the COMBINED remaining basis (1000 - 600 prior chargeback = 400), '
                . 'never the raw 600 line amount'
        );
        self::assertSame(
            40,
            (int) $refundReversal['amount'],
            'commission_reversal must cap at the COMBINED remaining commission (100 - 60 = 40)'
        );
        self::assertSame(
            -200,
            (int) $marketplaceDebit['amount'],
            'the 200 of refund cash that could not be attributed to the already-charged-back line '
                . '(600 line amount - 400 capped delta_R) is marketplace-funded, never pulled from the seller twice'
        );

        // The money-critical invariants: commission reversed across BOTH events
        // never exceeds the line's own original commission, and merchandise
        // reversed against the seller across BOTH events never exceeds the line's
        // own original basis.
        self::assertSame(
            100,
            (int) $chargebackReversal['amount'] + (int) $refundReversal['amount'],
            'total commission reversed across the chargeback (60) and the refund (40) must equal EXACTLY '
                . "the line's own original commission (100), never exceed it"
        );
        self::assertSame(
            1000,
            abs((int) $chargebackDebit['amount']) + abs((int) $refundDebit['amount']),
            'total merchandise reversed against the seller across the chargeback (600) and the refund (400) '
                . "must equal EXACTLY the line's own original basis (1000), never exceed it"
        );

        // The refund's own internal invariant still holds: seller debit +
        // marketplace debit sums exactly to the refund's own cash amount.
        self::assertSame(
            (int) $refund['amount'],
            abs((int) $refundDebit['amount']) + abs((int) $marketplaceDebit['amount'])
        );
    }

    // -----------------------------------------------------------------
    // 4. Existing behavior otherwise unchanged: a refund with NO reserve and
    //    NO prior chargeback, through the FULLY Task-12-wired RefundService,
    //    posts byte-identically to the pre-Task-12 shape (no reserve_release
    //    row at all; refund_debit/commission_reversal amounts unaffected).
    // -----------------------------------------------------------------

    public function testRefundWithNoReserveAndNoPriorChargebackIsByteIdenticalToPreTask12Behavior(): void
    {
        $this->seedOrder(['uuid' => 'orderNOCH0001', 'grand_total' => 1000]);
        $this->seedSellerOrder([
            'uuid' => 'selordNOCH001',
            'order_uuid' => 'orderNOCH0001',
            'seller_uuid' => 'sellerNOCH001',
            'currency' => 'USD',
            'subtotal' => 1000,
            'attributed_total' => 1000,
        ]);
        $line = $this->seedOrderLine([
            'uuid' => 'lineNOCH00001',
            'order_uuid' => 'orderNOCH0001',
            'seller_uuid' => 'sellerNOCH001',
            'line_total' => 1000,
            'commission_basis' => 1000,
            'commission_amount' => 100,
        ]);
        $this->seedSaleLedger('orderNOCH0001', 'sellerNOCH001', 'USD', 1000, 100);

        $refund = $this->refundService()->issue(
            $this->context,
            'orderNOCH0001',
            new RefundInput(300, 'no reserve, no chargeback', [
                ['order_line_uuid' => (string) $line['uuid'], 'quantity' => 1, 'amount' => 300],
            ], false),
            'idem-unchanged-1'
        );
        self::assertSame('completed', $refund['status']);

        $ledger = $this->ledgerRowsForRefund((string) $refund['uuid']);
        self::assertSame(
            ['refund_debit', 'commission_reversal'],
            array_column($ledger, 'entry_type'),
            'no held reserve exists, so consume() must post NO reserve_release row at all'
        );
        self::assertSame(-300, (int) $ledger[0]['amount']);
        self::assertSame(30, (int) $ledger[1]['amount'], 'target(100,1000,300) = 30, unaffected by the Task 12 wiring');
    }

    // -----------------------------------------------------------------
    // 5. Non-marketplace refund untouched: a non-partitioned order's refund
    //    still executes ZERO ledger/lock/reserve/chargeback queries through
    //    the fully Task-12-wired RefundService -- the gate is the order's
    //    OWN marketplace_partitioned flag, never a missing collaborator.
    // -----------------------------------------------------------------

    public function testNonPartitionedRefundStillExecutesZeroMarketplaceQueriesUnderFullTask12Wiring(): void
    {
        $orderUuid = 'orderNPFX0001';
        $this->orders->insert($this->context, [
            'uuid' => $orderUuid,
            'tenant_uuid' => self::TENANT,
            'order_number' => 'ORD-' . $orderUuid,
            'status' => 'paid',
            'marketplace_partitioned' => false,
            'email' => 'buyer@example.com',
            'guest_token_hash' => hash('sha256', $orderUuid),
            'currency' => 'USD',
            'subtotal' => 500,
            'grand_total' => 500,
        ]);
        $this->connection->table('commerce_order_lines')->insert([
            'uuid' => 'lineNPFX00001',
            'order_uuid' => $orderUuid,
            'variant_uuid' => 'variantNPFX01',
            'product_name' => 'Non-partitioned product',
            'sku' => 'NPFXSKU1',
            'option_values' => '[]',
            'unit_price' => 500,
            'quantity' => 1,
            'line_total' => 500,
            'seller_uuid' => null,
            'commission_basis' => 0,
            'commission_amount' => 0,
        ]);

        $pdo = $this->connection->getPDO();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [QueryLoggingPdoStatement::class]);
        QueryLoggingPdoStatement::$queries = [];

        $refund = $this->refundService()->issue(
            $this->context,
            $orderUuid,
            new RefundInput(null, 'non-partitioned, full', [], false),
            'idem-nonpart-fx-1'
        );

        self::assertSame('completed', $refund['status']);
        self::assertNotEmpty(QueryLoggingPdoStatement::$queries, 'sanity: issue() must run some queries');
        foreach (QueryLoggingPdoStatement::$queries as $sql) {
            self::assertStringNotContainsString('commerce_marketplace_ledger', $sql);
            self::assertStringNotContainsString('commerce_ledger_account_locks', $sql);
            self::assertStringNotContainsString('commerce_seller_reserves', $sql);
            self::assertStringNotContainsString('commerce_chargebacks', $sql);
            self::assertStringNotContainsString('commerce_chargeback_lines', $sql);
        }

        self::assertSame(0, $this->connection->table('commerce_marketplace_ledger')->count());
        self::assertSame(0, $this->connection->table('commerce_ledger_account_locks')->count());
    }
}
