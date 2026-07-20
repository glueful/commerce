<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Extensions\Commerce\Marketplace\ChargebackAttributionException;
use Glueful\Extensions\Commerce\Marketplace\ChargebackRepository;
use Glueful\Extensions\Commerce\Marketplace\ChargebackService;
use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerException;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\ReserveConsumptionService;
use Glueful\Extensions\Commerce\Marketplace\ReserveRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderRepository;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\ProviderChargebackEvent;

/**
 * Chargeback attribution + ledger reversal + reserve-first + negative balance
 * (design spec §2.5/§2.6, MV5a Task 11): {@see ChargebackService}'s full-
 * chargeback auto-expand (via {@see \Glueful\Extensions\Commerce\Support\LargestRemainder}),
 * partial-attribution posting via {@see ChargebackService::attributeAndPost()},
 * the proportional per-seller reversal (`chargeback_debit` incl. shipping/tax,
 * `commission_reversal` merchandise-capped), reserve-first consumption via
 * {@see ReserveConsumptionService}, the resulting negative-balance debt, the
 * marketplace-funded unattributable remainder, and whole-transaction atomicity.
 */
final class ChargebackReversalTest extends CommerceTestCase
{
    private const TENANT = '';

    private OrderRepository $orders;
    private ChargebackRepository $chargebacks;
    private LedgerRepository $ledgerRepo;
    private ReserveRepository $reserveRepo;
    private ChargebackService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orders = new OrderRepository();
        $this->chargebacks = new ChargebackRepository();
        $this->ledgerRepo = new LedgerRepository();
        $this->reserveRepo = new ReserveRepository();
        $this->service = new ChargebackService(
            $this->orders,
            $this->chargebacks,
            new SellerOrderRepository(),
            $this->ledgerRepo,
            new LedgerAccountLock(),
            new ReserveConsumptionService($this->reserveRepo, $this->ledgerRepo)
        );
    }

    // -----------------------------------------------------------------
    // Fixture helpers
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function seedOrder(array $overrides = []): array
    {
        $uuid = (string) ($overrides['uuid'] ?? 'orderCBXXXX1');
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
            (string) ($payableOverrides['id'] ?? 'orderCBXXXX1'),
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

    /** @return array<string,mixed>|null */
    private function chargebackRow(string $uuid): ?array
    {
        return $this->chargebacks->findByUuid($this->context, self::TENANT, $uuid);
    }

    /** @return list<array<string,mixed>> */
    private function chargebackLinesFor(string $chargebackUuid): array
    {
        return $this->connection->table('commerce_chargeback_lines')
            ->where('chargeback_uuid', '=', $chargebackUuid)
            ->orderBy('order_line_uuid', 'ASC')
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

    private function seedRefund(
        string $uuid,
        string $orderUuid,
        int $amount,
        string $status,
        array $lines
    ): void {
        $this->connection->table('commerce_refunds')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT,
            'order_uuid' => $orderUuid,
            'idempotency_key' => 'idem-' . $uuid,
            'request_fingerprint' => 'fp-' . $uuid,
            'amount' => $amount,
            'currency' => 'USD',
            'method' => 'manual',
            'status' => $status,
            'restocked' => false,
        ]);

        foreach ($lines as $line) {
            $this->connection->table('commerce_refund_lines')->insert([
                'refund_uuid' => $uuid,
                'order_line_uuid' => $line['order_line_uuid'],
                'quantity' => 1,
                'amount' => $line['amount'],
            ]);
        }
    }

    // -----------------------------------------------------------------
    // 1. Full chargeback auto-expands proportionally (non-trivial largest-
    //    remainder allocation), persists lines, reverses debit (uncapped)
    //    + commission_reversal (merchandise-capped) exactly.
    // -----------------------------------------------------------------

    public function testFullChargebackAutoExpandsAndReversesProportionally(): void
    {
        $order = $this->seedOrder(['uuid' => 'orderCBFULL1', 'grand_total' => 1500]);
        $this->seedSellerOrder([
            'uuid' => 'selordCB0001',
            'order_uuid' => 'orderCBFULL1',
            'seller_uuid' => 'sellerCBFL01',
            'currency' => 'USD',
            'subtotal' => 1200,
            'attributed_total' => 1500,
        ]);
        // weight1 = 700 - 0 + 0 = 700; weight2 = 500 - 0 + 1 = 501 -- deliberately
        // non-evenly-divisible (sum 1201) to exercise a REAL largest-remainder
        // bonus-unit allocation, not just an exact proportional split.
        $this->seedOrderLine([
            'uuid' => 'lineCBFL0001',
            'order_uuid' => 'orderCBFULL1',
            'seller_uuid' => 'sellerCBFL01',
            'line_total' => 700,
            'commission_basis' => 700,
            'commission_amount' => 70,
        ]);
        $this->seedOrderLine([
            'uuid' => 'lineCBFL0002',
            'order_uuid' => 'orderCBFULL1',
            'seller_uuid' => 'sellerCBFL01',
            'line_total' => 500,
            'tax_amount' => 1,
            'commission_basis' => 500,
            'commission_amount' => 50,
        ]);
        $this->seedSaleLedger('orderCBFULL1', 'sellerCBFL01', 'USD', 1500, 120);

        $result = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_full_expand_1',
            'amount' => 1500,
            'payable' => ['id' => 'orderCBFULL1', 'amount' => 1500],
        ]));

        self::assertSame('posted', $result['status']);
        self::assertNotNull($result['posted_at']);
        $chargebackUuid = (string) $result['uuid'];

        $lines = $this->chargebackLinesFor($chargebackUuid);
        self::assertCount(2, $lines);
        self::assertSame('lineCBFL0001', $lines[0]['order_line_uuid']);
        self::assertSame(874, (int) $lines[0]['amount'], 'floor(1500*700/1201) = 874 (326 remainder, no bonus)');
        self::assertSame('sellerCBFL01', $lines[0]['seller_uuid']);
        self::assertSame('lineCBFL0002', $lines[1]['order_line_uuid']);
        self::assertSame(626, (int) $lines[1]['amount'], 'floor(1500*501/1201) = 625, +1 bonus unit (875 remainder wins)');
        self::assertSame(1500, (int) $lines[0]['amount'] + (int) $lines[1]['amount']);

        $ledger = $this->ledgerRowsForChargeback($chargebackUuid);
        $debit = $this->rowForSellerAndType($ledger, 'sellerCBFL01', 'chargeback_debit');
        self::assertSame(-1500, (int) $debit['amount'], 'chargeback_debit reverses the FULL attributed amount, uncapped');
        self::assertSame('seller:sellerCBFL01', $debit['account_key']);
        self::assertSame('seller', $debit['account_kind']);
        self::assertSame("{$chargebackUuid}:sellerCBFL01:chargeback_debit", $debit['idempotency_key']);

        $reversal = $this->rowForSellerAndType($ledger, 'sellerCBFL01', 'commission_reversal');
        self::assertSame(120, (int) $reversal['amount'], '70 (line1, fully capped at its own basis) + 50 (line2)');

        $marketplaceEntries = array_values(array_filter(
            $ledger,
            static fn (array $row): bool => $row['account_kind'] === 'marketplace'
        ));
        self::assertSame([], $marketplaceEntries, 'attributed_total == chargeback amount: no marketplace remainder');

        $balance = $this->ledgerRepo->balanceComponents(
            $this->context,
            self::TENANT,
            LedgerRepository::accountKeyForSeller('sellerCBFL01'),
            'USD'
        );
        self::assertSame(0, $balance['available'], '1500 sale - 120 commission - 1500 debit + 120 reversal = 0');
        self::assertSame(0, $balance['debt']);
    }

    // -----------------------------------------------------------------
    // 2. All-zero-weight seller stays exact: equal-unit fallback, tie-broken
    //    by ascending line UUID.
    // -----------------------------------------------------------------

    public function testAllZeroWeightLinesFallBackToEqualUnitDistribution(): void
    {
        $this->seedOrder(['uuid' => 'orderCBZERO1', 'grand_total' => 100]);
        $this->seedSellerOrder([
            'uuid' => 'selordCBZ001',
            'order_uuid' => 'orderCBZERO1',
            'seller_uuid' => 'sellerCBZ001',
            'currency' => 'USD',
            'subtotal' => 300,
            'attributed_total' => 100,
        ]);
        foreach (['lineCBZ0001', 'lineCBZ0002', 'lineCBZ0003'] as $lineUuid) {
            $this->seedOrderLine([
                'uuid' => $lineUuid,
                'order_uuid' => 'orderCBZERO1',
                'seller_uuid' => 'sellerCBZ001',
                'line_total' => 100,
                'discount_amount' => 100,
                'commission_basis' => 0,
                'commission_amount' => 0,
            ]);
        }
        $this->seedSaleLedger('orderCBZERO1', 'sellerCBZ001', 'USD', 100, 0);

        $result = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_zero_weight_1',
            'amount' => 100,
            'payable' => ['id' => 'orderCBZERO1', 'amount' => 100],
        ]));

        self::assertSame('posted', $result['status']);
        $chargebackUuid = (string) $result['uuid'];

        $lines = $this->chargebackLinesFor($chargebackUuid);
        self::assertCount(3, $lines);
        self::assertSame(
            [34, 33, 33],
            array_map(static fn (array $l): int => (int) $l['amount'], $lines),
            'equal-unit fallback: the ascending-first line (lineCBZ0001) wins the single leftover unit'
        );
        self::assertSame(100, array_sum(array_map(static fn (array $l): int => (int) $l['amount'], $lines)));

        $ledger = $this->ledgerRowsForChargeback($chargebackUuid);
        self::assertCount(1, $ledger, 'zero commission_basis => zero commission_reversal => debit only');
        self::assertSame('chargeback_debit', $ledger[0]['entry_type']);
        self::assertSame(-100, (int) $ledger[0]['amount']);
    }

    // -----------------------------------------------------------------
    // 3. Partial with persisted (operator-supplied) lines posts, sum-checked.
    // -----------------------------------------------------------------

    public function testPartialChargebackWithLinesPosts(): void
    {
        $this->seedOrder(['uuid' => 'orderCBPART1', 'grand_total' => 1000]);
        $this->seedSellerOrder([
            'uuid' => 'selordCBP001',
            'order_uuid' => 'orderCBPART1',
            'seller_uuid' => 'sellerCBPT01',
            'currency' => 'USD',
            'subtotal' => 1000,
            'attributed_total' => 1000,
        ]);
        $this->seedOrderLine([
            'uuid' => 'lineCBPT0001',
            'order_uuid' => 'orderCBPART1',
            'seller_uuid' => 'sellerCBPT01',
            'line_total' => 1000,
            'commission_basis' => 1000,
            'commission_amount' => 100,
        ]);
        $this->seedSaleLedger('orderCBPART1', 'sellerCBPT01', 'USD', 1000, 100);

        $ingested = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_partial_lines_1',
            'amount' => 400,
            'payable' => ['id' => 'orderCBPART1', 'amount' => 1000],
        ]));
        self::assertSame('awaiting_attribution', $ingested['status']);
        $chargebackUuid = (string) $ingested['uuid'];

        $posted = $this->service->attributeAndPost($this->context, self::TENANT, $chargebackUuid, [
            ['order_line_uuid' => 'lineCBPT0001', 'amount' => 400],
        ]);

        self::assertSame('posted', $posted['status']);
        self::assertNotNull($posted['posted_at']);

        $lines = $this->chargebackLinesFor($chargebackUuid);
        self::assertCount(1, $lines);
        self::assertSame(400, (int) $lines[0]['amount']);

        $ledger = $this->ledgerRowsForChargeback($chargebackUuid);
        $debit = $this->rowForSellerAndType($ledger, 'sellerCBPT01', 'chargeback_debit');
        self::assertSame(-400, (int) $debit['amount']);
        $reversal = $this->rowForSellerAndType($ledger, 'sellerCBPT01', 'commission_reversal');
        self::assertSame(40, (int) $reversal['amount'], 'target(100,1000,400) = floor(100*400+500,1000) = 40');
    }

    // -----------------------------------------------------------------
    // 4. Partial without lines stays awaiting -- no posting, no lines.
    // -----------------------------------------------------------------

    public function testPartialChargebackWithoutLinesStaysAwaitingAndPostsNothing(): void
    {
        $this->seedOrder(['uuid' => 'orderCBPART2', 'grand_total' => 1000]);

        $result = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_partial_nolines_1',
            'amount' => 300,
            'payable' => ['id' => 'orderCBPART2', 'amount' => 1000],
        ]));

        self::assertSame('awaiting_attribution', $result['status']);
        $chargebackUuid = (string) $result['uuid'];

        self::assertCount(0, $this->chargebackLinesFor($chargebackUuid));
        self::assertCount(0, $this->ledgerRowsForChargeback($chargebackUuid));
    }

    // -----------------------------------------------------------------
    // 4b. Conservation guard: every attribution line amount must be
    //     strictly positive. A signed mix like {-50, +150} sums to the
    //     chargeback amount (100), yet pre-guard it would post a 150
    //     chargeback_debit against one seller while the negative line is
    //     silently skipped by the `$debit > 0` posting gate -- 150 of
    //     ledger movement for 100 of chargeback cash. Must be rejected as
    //     caller input: nothing posts, no lines persist, the chargeback
    //     stays awaiting_attribution.
    // -----------------------------------------------------------------

    public function testPartialChargebackRejectsNonPositiveLineAmounts(): void
    {
        $this->seedOrder(['uuid' => 'orderCBNEG01', 'grand_total' => 1000]);
        $this->seedSellerOrder([
            'uuid' => 'selordCBN001',
            'order_uuid' => 'orderCBNEG01',
            'seller_uuid' => 'sellerCBNEGA',
            'currency' => 'USD',
            'subtotal' => 200,
            'attributed_total' => 200,
            'partition_number' => 1,
        ]);
        $this->seedSellerOrder([
            'uuid' => 'selordCBN002',
            'order_uuid' => 'orderCBNEG01',
            'seller_uuid' => 'sellerCBNEGB',
            'currency' => 'USD',
            'subtotal' => 800,
            'attributed_total' => 800,
            'partition_number' => 2,
        ]);
        $this->seedOrderLine([
            'uuid' => 'lineCBNEGA01',
            'order_uuid' => 'orderCBNEG01',
            'seller_uuid' => 'sellerCBNEGA',
            'line_total' => 200,
            'commission_basis' => 200,
            'commission_amount' => 20,
        ]);
        $this->seedOrderLine([
            'uuid' => 'lineCBNEGB01',
            'order_uuid' => 'orderCBNEG01',
            'seller_uuid' => 'sellerCBNEGB',
            'line_total' => 800,
            'commission_basis' => 800,
            'commission_amount' => 80,
        ]);
        $this->seedSaleLedger('orderCBNEG01', 'sellerCBNEGA', 'USD', 200, 20);
        $this->seedSaleLedger('orderCBNEG01', 'sellerCBNEGB', 'USD', 800, 80);

        $ingested = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_partial_negline_1',
            'amount' => 100,
            'payable' => ['id' => 'orderCBNEG01', 'amount' => 1000],
        ]));
        self::assertSame('awaiting_attribution', $ingested['status']);
        $chargebackUuid = (string) $ingested['uuid'];

        try {
            $this->service->attributeAndPost($this->context, self::TENANT, $chargebackUuid, [
                ['order_line_uuid' => 'lineCBNEGA01', 'amount' => -50],
                ['order_line_uuid' => 'lineCBNEGB01', 'amount' => 150],
            ]);
            self::fail('Expected ChargebackAttributionException for a negative attribution line amount.');
        } catch (ChargebackAttributionException $e) {
            self::assertStringContainsString('positive', $e->getMessage());
        }

        // A zero line is equally rejected (0 + 100 also sums to the amount).
        try {
            $this->service->attributeAndPost($this->context, self::TENANT, $chargebackUuid, [
                ['order_line_uuid' => 'lineCBNEGA01', 'amount' => 0],
                ['order_line_uuid' => 'lineCBNEGB01', 'amount' => 100],
            ]);
            self::fail('Expected ChargebackAttributionException for a zero attribution line amount.');
        } catch (ChargebackAttributionException $e) {
            self::assertStringContainsString('positive', $e->getMessage());
        }

        // Conservation regression: NOTHING posted or persisted by either
        // attempt, and the chargeback is still attributable with valid lines.
        $row = $this->chargebackRow($chargebackUuid);
        self::assertNotNull($row);
        self::assertSame('awaiting_attribution', $row['status']);
        self::assertCount(0, $this->chargebackLinesFor($chargebackUuid));
        self::assertCount(0, $this->ledgerRowsForChargeback($chargebackUuid));

        // A subsequent well-formed attribution still posts exactly the
        // chargeback amount -- total signed ledger debits === -amount.
        $posted = $this->service->attributeAndPost($this->context, self::TENANT, $chargebackUuid, [
            ['order_line_uuid' => 'lineCBNEGB01', 'amount' => 100],
        ]);
        self::assertSame('posted', $posted['status']);
        $ledger = $this->ledgerRowsForChargeback($chargebackUuid);
        $debitTotal = 0;
        foreach ($ledger as $entry) {
            if ((string) $entry['entry_type'] === 'chargeback_debit') {
                $debitTotal += (int) $entry['amount'];
            }
        }
        self::assertSame(-100, $debitTotal, 'posted chargeback debits must sum to exactly -amount');
    }

    // -----------------------------------------------------------------
    // 5. Over-attribution: a FULL chargeback arriving after a prior
    //    COMPLETED partial refund on the same line must NOT double-reverse
    //    already-reversed proceeds -- rejected into integrity_hold, nothing
    //    posts.
    // -----------------------------------------------------------------

    public function testFullChargebackAfterPriorPartialRefundIsRejectedAsOverAttributed(): void
    {
        $this->seedOrder(['uuid' => 'orderCBOVER1', 'grand_total' => 1000]);
        $this->seedSellerOrder([
            'uuid' => 'selordCBO001',
            'order_uuid' => 'orderCBOVER1',
            'seller_uuid' => 'sellerCBOV01',
            'currency' => 'USD',
            'subtotal' => 1000,
            'attributed_total' => 1000,
        ]);
        $this->seedOrderLine([
            'uuid' => 'lineCBOV0001',
            'order_uuid' => 'orderCBOVER1',
            'seller_uuid' => 'sellerCBOV01',
            'line_total' => 1000,
            'commission_basis' => 1000,
            'commission_amount' => 100,
        ]);
        $this->seedSaleLedger('orderCBOVER1', 'sellerCBOV01', 'USD', 1000, 100);

        // A prior COMPLETED refund already reversed 400 of this line's 1000 cash
        // weight -- leaving only 600 of derived "remaining" for a later chargeback.
        $this->seedRefund('refundCBOV01', 'orderCBOVER1', 400, 'completed', [
            ['order_line_uuid' => 'lineCBOV0001', 'amount' => 400],
        ]);

        $result = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_over_attr_1',
            'amount' => 1000,
            'payable' => ['id' => 'orderCBOVER1', 'amount' => 1000],
        ]));

        self::assertSame(
            'integrity_hold',
            $result['status'],
            'auto-expand would assign the FULL 1000 to the line, exceeding its 600 remaining -- rejected'
        );
        $chargebackUuid = (string) $result['uuid'];

        self::assertCount(0, $this->chargebackLinesFor($chargebackUuid), 'no lines persisted for a rejected attribution');
        self::assertCount(0, $this->ledgerRowsForChargeback($chargebackUuid), 'no ledger postings for a rejected attribution');
    }

    // -----------------------------------------------------------------
    // 6. Reserve consumed FIRST, then the full debit lands -- when the
    //    seller's proceeds already left via a payout, available goes
    //    NEGATIVE (debt appears via the balance component), never truncated.
    // -----------------------------------------------------------------

    public function testReserveConsumedFirstThenAvailableGoesNegativeWhenLiabilityExceedsReservePlusAvailable(): void
    {
        $this->seedOrder(['uuid' => 'orderCBDEBT1', 'grand_total' => 1000]);
        $this->seedSellerOrder([
            'uuid' => 'selordCBD001',
            'order_uuid' => 'orderCBDEBT1',
            'seller_uuid' => 'sellerCBDB01',
            'currency' => 'USD',
            'subtotal' => 1000,
            'attributed_total' => 1000,
        ]);
        $this->seedOrderLine([
            'uuid' => 'lineCBDB0001',
            'order_uuid' => 'orderCBDEBT1',
            'seller_uuid' => 'sellerCBDB01',
            'line_total' => 1000,
            'commission_basis' => 1000,
            'commission_amount' => 100,
        ]);
        $this->seedSaleLedger('orderCBDEBT1', 'sellerCBDB01', 'USD', 1000, 100);
        $this->seedHeldReserve('resvCBDEBT01', 'sellerCBDB01', 'USD', 150, 'selordCBD001');

        // The seller already withdrew their full available proceeds (1000 - 100
        // commission - 150 reserve = 750) via a completed payout, BEFORE the
        // chargeback ever arrives -- mirroring design spec §2.6's "an executing
        // payout is not implicitly cancelled" scenario. available is now exactly 0.
        $this->connection->table('commerce_marketplace_ledger')->insert([
            'uuid' => 'ledgerPAYOUT01',
            'tenant_uuid' => self::TENANT,
            'account_key' => LedgerRepository::accountKeyForSeller('sellerCBDB01'),
            'account_kind' => 'seller',
            'seller_uuid' => 'sellerCBDB01',
            'currency' => 'USD',
            'entry_type' => 'payout_debit',
            'amount' => -750,
            'order_uuid' => 'orderCBDEBT1',
            'payout_uuid' => 'payoutCBDEBT1',
            'idempotency_key' => 'payoutCBDEBT1:payout_debit',
        ]);
        $balanceBefore = $this->ledgerRepo->balanceComponents(
            $this->context,
            self::TENANT,
            LedgerRepository::accountKeyForSeller('sellerCBDB01'),
            'USD'
        );
        self::assertSame(0, $balanceBefore['available'], 'sanity: the payout drained available to exactly 0');
        self::assertSame(150, $balanceBefore['reserved']);

        $result = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_debt_1',
            'amount' => 1000,
            'payable' => ['id' => 'orderCBDEBT1', 'amount' => 1000],
        ]));
        self::assertSame('posted', $result['status']);
        $chargebackUuid = (string) $result['uuid'];

        // Reserve-first: the held 150 is fully consumed (netLiability 900 > 150),
        // and its reserve_release lands BEFORE the chargeback_debit -- proving the
        // reserve was drawn down before the full debit posted.
        $reserveRow = $this->connection->table('commerce_seller_reserves')
            ->where('uuid', '=', 'resvCBDEBT01')->first();
        self::assertSame('consumed', $reserveRow['status']);

        $ledger = $this->ledgerRowsForChargeback($chargebackUuid);
        self::assertSame(
            ['reserve_release', 'chargeback_debit', 'commission_reversal'],
            array_column($ledger, 'entry_type'),
            'reserve consumption must be posted BEFORE the chargeback_debit lands'
        );
        self::assertSame(150, (int) $ledger[0]['amount']);
        self::assertSame('resvCBDEBT01', $ledger[0]['reserve_uuid']);
        self::assertSame($chargebackUuid, $ledger[0]['chargeback_uuid']);
        self::assertSame(-1000, (int) $ledger[1]['amount'], 'the debit posts in FULL, never truncated to the shortfall');
        self::assertSame(100, (int) $ledger[2]['amount']);

        $balanceAfter = $this->ledgerRepo->balanceComponents(
            $this->context,
            self::TENANT,
            LedgerRepository::accountKeyForSeller('sellerCBDB01'),
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
    // 7. Unattributable remainder (auto-expand's seller allocations don't
    //    cover the whole chargeback amount) -> marketplace-funded, explicit,
    //    never silently assigned to the seller.
    // -----------------------------------------------------------------

    public function testUnattributableGapBetweenSellerAllocationsAndChargebackAmountGoesToMarketplace(): void
    {
        // grand_total (1000) exceeds the SUM of seller attributed_total (800) --
        // a structurally rare but explicitly-handled gap (design spec §2.5
        // "or exceeds total seller allocations").
        $this->seedOrder(['uuid' => 'orderCBGAP01', 'grand_total' => 1000]);
        $this->seedSellerOrder([
            'uuid' => 'selordCBG001',
            'order_uuid' => 'orderCBGAP01',
            'seller_uuid' => 'sellerCBGP01',
            'currency' => 'USD',
            'subtotal' => 800,
            'attributed_total' => 800,
        ]);
        $this->seedOrderLine([
            'uuid' => 'lineCBGP0001',
            'order_uuid' => 'orderCBGAP01',
            'seller_uuid' => 'sellerCBGP01',
            'line_total' => 800,
            'commission_basis' => 800,
            'commission_amount' => 80,
        ]);
        $this->seedSaleLedger('orderCBGAP01', 'sellerCBGP01', 'USD', 800, 80);

        $result = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_gap_1',
            'amount' => 1000,
            'payable' => ['id' => 'orderCBGAP01', 'amount' => 1000],
        ]));

        self::assertSame('posted', $result['status']);
        $chargebackUuid = (string) $result['uuid'];

        $ledger = $this->ledgerRowsForChargeback($chargebackUuid);
        $sellerDebit = $this->rowForSellerAndType($ledger, 'sellerCBGP01', 'chargeback_debit');
        self::assertSame(-800, (int) $sellerDebit['amount']);

        $marketplaceRow = $this->rowForAccountKeyAndType($ledger, LedgerRepository::MARKETPLACE_ACCOUNT_KEY, 'chargeback_debit');
        self::assertSame(-200, (int) $marketplaceRow['amount'], '1000 chargeback - 800 seller allocation = 200 gap');
        self::assertSame('marketplace', $marketplaceRow['account_kind']);
        self::assertNull($marketplaceRow['seller_uuid']);
        self::assertSame("{$chargebackUuid}:marketplace:chargeback_debit", $marketplaceRow['idempotency_key']);

        self::assertSame(
            1000,
            abs((int) $sellerDebit['amount']) + abs((int) $marketplaceRow['amount']),
            'seller debit + marketplace debit must equal the chargeback amount exactly'
        );
    }

    // -----------------------------------------------------------------
    // 8. Multi-seller: two sellers on one order, each reversed under its
    //    own lock, deterministic account_key order.
    // -----------------------------------------------------------------

    public function testMultiSellerFullChargebackReversesEachSellerUnderItsOwnLockDeterministically(): void
    {
        $this->seedOrder(['uuid' => 'orderCBMULT1', 'grand_total' => 1300]);
        $this->seedSellerOrder([
            'uuid' => 'selordCBM001',
            'order_uuid' => 'orderCBMULT1',
            'seller_uuid' => 'sellerCBMLTA',
            'currency' => 'USD',
            'subtotal' => 800,
            'attributed_total' => 800,
            'partition_number' => 1,
        ]);
        $this->seedSellerOrder([
            'uuid' => 'selordCBM002',
            'order_uuid' => 'orderCBMULT1',
            'seller_uuid' => 'sellerCBMLTB',
            'currency' => 'USD',
            'subtotal' => 500,
            'attributed_total' => 500,
            'partition_number' => 2,
        ]);
        $this->seedOrderLine([
            'uuid' => 'lineCBMLTA01',
            'order_uuid' => 'orderCBMULT1',
            'seller_uuid' => 'sellerCBMLTA',
            'line_total' => 800,
            'commission_basis' => 800,
            'commission_amount' => 80,
        ]);
        $this->seedOrderLine([
            'uuid' => 'lineCBMLTB01',
            'order_uuid' => 'orderCBMULT1',
            'seller_uuid' => 'sellerCBMLTB',
            'line_total' => 500,
            'commission_basis' => 500,
            'commission_amount' => 50,
        ]);
        $this->seedSaleLedger('orderCBMULT1', 'sellerCBMLTA', 'USD', 800, 80);
        $this->seedSaleLedger('orderCBMULT1', 'sellerCBMLTB', 'USD', 500, 50);

        $result = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_multiseller_1',
            'amount' => 1300,
            'payable' => ['id' => 'orderCBMULT1', 'amount' => 1300],
        ]));

        self::assertSame('posted', $result['status']);
        $chargebackUuid = (string) $result['uuid'];

        $ledger = $this->ledgerRowsForChargeback($chargebackUuid);
        // account_key order: 'marketplace' < 'seller:sellerCBMLTA' < 'seller:sellerCBMLTB'
        // -- the marketplace lock is claimed even though it never posts (design
        // spec §2.6 deadlock avoidance, mirroring postRefund's unconditional claim).
        $accountKeys = array_values(array_unique(array_column($ledger, 'account_key')));
        self::assertSame(['seller:sellerCBMLTA', 'seller:sellerCBMLTB'], $accountKeys);

        $debitA = $this->rowForSellerAndType($ledger, 'sellerCBMLTA', 'chargeback_debit');
        self::assertSame(-800, (int) $debitA['amount']);
        $reversalA = $this->rowForSellerAndType($ledger, 'sellerCBMLTA', 'commission_reversal');
        self::assertSame(80, (int) $reversalA['amount']);

        $debitB = $this->rowForSellerAndType($ledger, 'sellerCBMLTB', 'chargeback_debit');
        self::assertSame(-500, (int) $debitB['amount']);
        $reversalB = $this->rowForSellerAndType($ledger, 'sellerCBMLTB', 'commission_reversal');
        self::assertSame(50, (int) $reversalB['amount']);

        $lockA = $this->connection->table('commerce_ledger_account_locks')
            ->where('account_key', '=', 'seller:sellerCBMLTA')->first();
        $lockB = $this->connection->table('commerce_ledger_account_locks')
            ->where('account_key', '=', 'seller:sellerCBMLTB')->first();
        self::assertNotNull($lockA, "sellerA's own account lock must have been claimed");
        self::assertNotNull($lockB, "sellerB's own account lock must have been claimed");
    }

    // -----------------------------------------------------------------
    // 9. ATOMICITY: a genuine mid-posting failure (a pre-seeded conflicting
    //    ledger row under the SECOND seller's exact expected idempotency key)
    //    rolls back the WHOLE transaction -- the chargeback stays
    //    `awaiting_attribution`, no lines persist, and NOT EVEN the first
    //    seller's already-attempted postings survive.
    // -----------------------------------------------------------------

    public function testForcedLedgerConflictDuringAttributedPostingRollsBackEverything(): void
    {
        $this->seedOrder(['uuid' => 'orderCBATOM1', 'grand_total' => 1000]);
        $this->seedSellerOrder([
            'uuid' => 'selordCBAT01',
            'order_uuid' => 'orderCBATOM1',
            'seller_uuid' => 'sellerCBAAAA1', // sorts BEFORE sellerCBBBBB1
            'currency' => 'USD',
            'subtotal' => 300,
            'attributed_total' => 300,
            'partition_number' => 1,
        ]);
        $this->seedSellerOrder([
            'uuid' => 'selordCBAT02',
            'order_uuid' => 'orderCBATOM1',
            'seller_uuid' => 'sellerCBBBBB1',
            'currency' => 'USD',
            'subtotal' => 300,
            'attributed_total' => 300,
            'partition_number' => 2,
        ]);
        $this->seedOrderLine([
            'uuid' => 'lineCBATOMA1',
            'order_uuid' => 'orderCBATOM1',
            'seller_uuid' => 'sellerCBAAAA1', // sorts BEFORE sellerCBBBBB1
            'line_total' => 300,
            'commission_basis' => 300,
            'commission_amount' => 30,
        ]);
        $this->seedOrderLine([
            'uuid' => 'lineCBATOMB1',
            'order_uuid' => 'orderCBATOM1',
            'seller_uuid' => 'sellerCBBBBB1',
            'line_total' => 300,
            'commission_basis' => 300,
            'commission_amount' => 30,
        ]);
        $this->seedSaleLedger('orderCBATOM1', 'sellerCBAAAA1', 'USD', 300, 30);
        $this->seedSaleLedger('orderCBATOM1', 'sellerCBBBBB1', 'USD', 300, 30);

        $ingested = $this->service->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_atomicity_1',
            'amount' => 600,
            'payable' => ['id' => 'orderCBATOM1', 'amount' => 1000],
        ]));
        self::assertSame('awaiting_attribution', $ingested['status']);
        $chargebackUuid = (string) $ingested['uuid'];

        // Pre-seed a MISMATCHED ledger row under the EXACT idempotency key the
        // second seller's (sellerCBBBBB1) chargeback_debit will compute -- forces
        // LedgerRepository::post() to throw a LedgerException mid-posting, AFTER
        // the first seller (sellerCBAAAA1, sorted first) has already been posted
        // to inside this same open transaction.
        $this->connection->table('commerce_marketplace_ledger')->insert([
            'uuid' => 'ledgerCBCONFLICT',
            'tenant_uuid' => self::TENANT,
            'account_key' => LedgerRepository::accountKeyForSeller('sellerCBBBBB1'),
            'account_kind' => 'seller',
            'seller_uuid' => 'sellerCBBBBB1',
            'currency' => 'USD',
            'entry_type' => 'chargeback_debit',
            'amount' => -999999,
            'order_uuid' => 'orderCBATOM1',
            'chargeback_uuid' => $chargebackUuid,
            'idempotency_key' => "{$chargebackUuid}:sellerCBBBBB1:chargeback_debit",
        ]);

        try {
            $this->service->attributeAndPost($this->context, self::TENANT, $chargebackUuid, [
                ['order_line_uuid' => 'lineCBATOMA1', 'amount' => 300],
                ['order_line_uuid' => 'lineCBATOMB1', 'amount' => 300],
            ]);
            self::fail('Expected the mismatched ledger replay to throw and roll back the whole transaction.');
        } catch (LedgerException) {
            $this->addToAssertionCount(1);
        }

        $row = $this->chargebackRow($chargebackUuid);
        self::assertSame(
            'awaiting_attribution',
            $row['status'],
            'the status transition to posted must roll back with the failed posting'
        );
        self::assertNull($row['posted_at']);

        self::assertCount(0, $this->chargebackLinesFor($chargebackUuid), 'no lines may survive a rolled-back attempt');

        $remaining = $this->ledgerRowsForChargeback($chargebackUuid);
        self::assertCount(
            1,
            $remaining,
            'only the pre-seeded conflicting row may remain -- sellerA\'s already-attempted legitimate '
                . 'postings must have rolled back too'
        );
        self::assertSame(-999999, (int) $remaining[0]['amount']);
    }
}
