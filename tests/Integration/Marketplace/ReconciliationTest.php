<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

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
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerPostingService;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceRefundGuard;
use Glueful\Extensions\Commerce\Marketplace\PayoutRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutService;
use Glueful\Extensions\Commerce\Marketplace\ReconciliationService;
use Glueful\Extensions\Commerce\Marketplace\SellerBalanceService;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderPaymentConfirmation;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
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
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;

/**
 * Read-only reconciliation (design spec §2.11, MV3 Task 10):
 * {@see ReconciliationService::scan()} scans DIRECTLY from confirmed
 * seller-order partitions / completed refunds / payouts -- never by parent
 * order status -- and never posts. Covers each of the missing/duplicate/
 * mismatched finding kinds across all three scan sources, the "no false
 * positive on a still-`paid` parent with a completed partial refund"
 * invariant that is this task's whole reason to exist, the zero-commission
 * skip, and the read-only guarantee itself.
 */
final class ReconciliationTest extends CommerceTestCase
{
    private const TENANT = '';

    private ReconciliationService $reconciliation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reconciliation = new ReconciliationService();
    }

    // -----------------------------------------------------------------
    // 1. Clean ledger -> empty report. Service posts nothing.
    // -----------------------------------------------------------------

    public function testCleanLedgerProducesAnEmptyReport(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A', 'active', [
            'commission_kind' => 'percentage', 'commission_bps' => 1000,
        ]);
        $product = $this->seedProduct('recon-clean-x', 1000, 'sellerAAAA01');
        $order = $this->placeOneSellerOrder($product);
        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);

        $report = $this->reconciliation->scan($this->context, self::TENANT);

        self::assertSame(['missing' => [], 'duplicate' => [], 'mismatched' => []], $report);
    }

    public function testScanPostsNothingAndLeavesTheLedgerRowCountUnchanged(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A', 'active', [
            'commission_kind' => 'percentage', 'commission_bps' => 1000,
        ]);
        $product = $this->seedProduct('recon-noop-x', 1000, 'sellerAAAA01');
        $order = $this->placeOneSellerOrder($product);
        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);

        $before = $this->connection->table('commerce_marketplace_ledger')->count();
        $lockRevisionsBefore = $this->connection->table('commerce_ledger_account_locks')
            ->where('account_key', '=', 'seller:sellerAAAA01')
            ->first();

        $this->reconciliation->scan($this->context, self::TENANT);

        self::assertSame(
            $before,
            $this->connection->table('commerce_marketplace_ledger')->count(),
            'scan() must never insert/update/delete ledger rows'
        );
        self::assertSame(
            (int) ($lockRevisionsBefore['revision'] ?? -1),
            (int) ($this->connection->table('commerce_ledger_account_locks')
                ->where('account_key', '=', 'seller:sellerAAAA01')->first()['revision'] ?? -2),
            'scan() must never claim an account lock either -- it is a pure read'
        );
    }

    // -----------------------------------------------------------------
    // 2. Missing / duplicate / mismatched sale_credit (source a).
    // -----------------------------------------------------------------

    public function testMissingSalePostingIsDetected(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A', 'active', [
            'commission_kind' => 'percentage', 'commission_bps' => 1000,
        ]);
        $product = $this->seedProduct('recon-missing-x', 1000, 'sellerAAAA01');
        $order = $this->placeOneSellerOrder($product);
        $orderUuid = (string) $order['uuid'];
        $this->paymentService()->markPaid($this->context, self::TENANT, $orderUuid);

        $this->connection->table('commerce_marketplace_ledger')
            ->where('order_uuid', '=', $orderUuid)
            ->where('entry_type', '=', 'sale_credit')
            ->delete();

        $report = $this->reconciliation->scan($this->context, self::TENANT);

        self::assertSame([], $report['duplicate']);
        self::assertSame([], $report['mismatched']);
        self::assertCount(1, $report['missing']);
        $finding = $report['missing'][0];
        self::assertSame('seller_order', $finding['source']);
        self::assertSame($orderUuid, $finding['order_uuid']);
        self::assertSame('sellerAAAA01', $finding['seller_uuid']);
        self::assertSame('sale_credit', $finding['entry_type']);
    }

    public function testDuplicateSaleCreditIsDetected(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A', 'active', [
            'commission_kind' => 'percentage', 'commission_bps' => 1000,
        ]);
        $product = $this->seedProduct('recon-dup-x', 1000, 'sellerAAAA01');
        $order = $this->placeOneSellerOrder($product);
        $orderUuid = (string) $order['uuid'];
        $this->paymentService()->markPaid($this->context, self::TENANT, $orderUuid);

        $sellerOrder = $this->sellerOrdersFor($orderUuid)[0];

        // A second sale_credit row for the SAME (order, seller) posting slot,
        // under a DIFFERENT idempotency_key so it does not collide with the
        // real row's unique (tenant_uuid, idempotency_key) constraint -- this
        // is exactly the shape a regression in the idempotency-key computation
        // (never a legitimate replay) would produce, which is why the
        // reconciliation backstop groups by (seller_order_uuid, entry_type)
        // rather than by idempotency_key.
        $this->connection->table('commerce_marketplace_ledger')->insert([
            'uuid' => 'ledgerDUPSALE',
            'tenant_uuid' => self::TENANT,
            'account_key' => 'seller:sellerAAAA01',
            'account_kind' => 'seller',
            'seller_uuid' => 'sellerAAAA01',
            'currency' => 'USD',
            'entry_type' => 'sale_credit',
            'amount' => (int) $sellerOrder['attributed_total'],
            'order_uuid' => $orderUuid,
            'seller_order_uuid' => (string) $sellerOrder['uuid'],
            'idempotency_key' => $orderUuid . ':sellerAAAA01:sale_credit:dup',
        ]);

        $report = $this->reconciliation->scan($this->context, self::TENANT);

        self::assertSame([], $report['missing']);
        self::assertSame([], $report['mismatched']);
        self::assertCount(1, $report['duplicate']);
        $finding = $report['duplicate'][0];
        self::assertSame('seller_order', $finding['source']);
        self::assertSame($orderUuid, $finding['order_uuid']);
        self::assertSame('sale_credit', $finding['entry_type']);
        self::assertSame(2, $finding['count']);
    }

    public function testMismatchedSaleCreditAmountIsDetected(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A', 'active', [
            'commission_kind' => 'percentage', 'commission_bps' => 1000,
        ]);
        $product = $this->seedProduct('recon-mismatch-x', 1000, 'sellerAAAA01');
        $order = $this->placeOneSellerOrder($product);
        $orderUuid = (string) $order['uuid'];
        $this->paymentService()->markPaid($this->context, self::TENANT, $orderUuid);

        $this->connection->table('commerce_marketplace_ledger')
            ->where('order_uuid', '=', $orderUuid)
            ->where('entry_type', '=', 'sale_credit')
            ->update(['amount' => 999999]);

        $report = $this->reconciliation->scan($this->context, self::TENANT);

        self::assertSame([], $report['missing']);
        self::assertSame([], $report['duplicate']);
        self::assertCount(1, $report['mismatched']);
        $finding = $report['mismatched'][0];
        self::assertSame('seller_order', $finding['source']);
        self::assertSame($orderUuid, $finding['order_uuid']);
        self::assertSame('sale_credit', $finding['entry_type']);
        self::assertSame(999999, $finding['found_amount']);
        self::assertNotSame(999999, $finding['expected_amount']);
    }

    // -----------------------------------------------------------------
    // 3. Zero-commission seller order: no missing commission_debit.
    // -----------------------------------------------------------------

    public function testZeroCommissionSellerOrderIsNotFlaggedForMissingCommissionDebit(): void
    {
        $this->activateMarketplace();
        // No commission override anywhere (seller/product/workspace all null)
        // -- resolves to the config tail default {percentage, bps: 0, fixed:
        // null}, so commission_amount is exactly 0 for this seller order and
        // LedgerPostingService::postSale() never posts a commission_debit row.
        $this->seedSeller('sellerZERO001', 'Seller Zero');
        $product = $this->seedProduct('recon-zero-x', 1000, 'sellerZERO001');
        $order = $this->placeOneSellerOrder($product);
        $orderUuid = (string) $order['uuid'];
        $this->paymentService()->markPaid($this->context, self::TENANT, $orderUuid);

        $sellerOrder = $this->sellerOrdersFor($orderUuid)[0];
        self::assertSame(0, (int) $sellerOrder['commission_amount'], 'sanity: this seller order truly has zero commission');
        self::assertCount(
            1,
            $this->ledgerRowsForOrder($orderUuid),
            'sanity: only sale_credit posted, no commission_debit row exists at all'
        );

        $report = $this->reconciliation->scan($this->context, self::TENANT);

        self::assertSame(['missing' => [], 'duplicate' => [], 'mismatched' => []], $report);
    }

    // -----------------------------------------------------------------
    // 4. Refunds scanned even though the parent order is still `paid`
    //    (a partial refund never transitions the parent to `refunded`).
    //    No false positives on a correctly-posted partial-refunded order.
    // -----------------------------------------------------------------

    public function testPartialRefundOnAStillPaidOrderProducesNoFalsePositives(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A', 'active', [
            'commission_kind' => 'percentage', 'commission_bps' => 1000,
        ]);
        // Two units so a 1-unit partial refund leaves the order genuinely
        // still open/paid (never fully refunded).
        $product = $this->seedProduct('recon-partial-x', 1000, 'sellerAAAA01', stock: 10);
        $order = $this->placeSellerOrderWithQuantity($product, 2);
        $orderUuid = (string) $order['uuid'];
        $this->paymentService()->markPaid($this->context, self::TENANT, $orderUuid);
        $line = $this->orderLinesFor($orderUuid)[0];

        $refund = $this->refundService()->issue(
            $this->context,
            $orderUuid,
            new RefundInput(1000, 'partial refund', [
                ['order_line_uuid' => (string) $line['uuid'], 'quantity' => 1, 'amount' => 1000],
            ], false),
            'idem-recon-partial-1'
        );
        self::assertSame('completed', $refund['status']);
        self::assertSame(
            'paid',
            $this->orderRow($orderUuid)['status'],
            'sanity: a partial refund leaves the parent order status at paid, never refunded -- '
                . 'this is exactly why reconciliation must never scan by parent order status'
        );

        $report = $this->reconciliation->scan($this->context, self::TENANT);

        self::assertSame(
            ['missing' => [], 'duplicate' => [], 'mismatched' => []],
            $report,
            'both the sale_credit/commission_debit set (source a, confirmed_at-keyed) and the '
                . 'refund_debit/commission_reversal set (source b, completed-refund-keyed) are '
                . 'correctly posted -- no false positive despite the order staying "paid"'
        );
    }

    public function testMissingRefundDebitIsDetected(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A', 'active', [
            'commission_kind' => 'percentage', 'commission_bps' => 1000,
        ]);
        $product = $this->seedProduct('recon-refund-missing-x', 1000, 'sellerAAAA01');
        $order = $this->placeOneSellerOrder($product);
        $orderUuid = (string) $order['uuid'];
        $this->paymentService()->markPaid($this->context, self::TENANT, $orderUuid);
        $line = $this->orderLinesFor($orderUuid)[0];

        $refund = $this->refundService()->issue(
            $this->context,
            $orderUuid,
            new RefundInput(1000, 'full line refund', [
                ['order_line_uuid' => (string) $line['uuid'], 'quantity' => 1, 'amount' => 1000],
            ], false),
            'idem-recon-refund-missing-1'
        );
        $refundUuid = (string) $refund['uuid'];

        $this->connection->table('commerce_marketplace_ledger')
            ->where('refund_uuid', '=', $refundUuid)
            ->where('entry_type', '=', 'refund_debit')
            ->delete();

        $report = $this->reconciliation->scan($this->context, self::TENANT);

        self::assertSame([], $report['duplicate']);
        self::assertSame([], $report['mismatched']);
        self::assertCount(1, $report['missing']);
        $finding = $report['missing'][0];
        self::assertSame('refund', $finding['source']);
        self::assertSame($refundUuid, $finding['refund_uuid']);
        self::assertSame('refund_debit', $finding['entry_type']);
    }

    // -----------------------------------------------------------------
    // 4b. Refund-scan completeness (fix wave): duplicate refund_debit,
    //     duplicate commission_reversal, a corrupted refund_debit amount,
    //     and a fully-dropped commission_reversal, plus the false-positive
    //     guard on a zero-commission line. Each of these exercises a branch
    //     of scanRefunds() that testMissingRefundDebitIsDetected() and
    //     testPartialRefundOnAStillPaidOrderProducesNoFalsePositives() above
    //     never touch.
    // -----------------------------------------------------------------

    /** @return array{orderUuid:string,refundUuid:string} places one seller order, pays it, and issues one full-line refund (10% commission) */
    private function placeOrderAndFullyRefundOneLine(string $productSlug, string $idempotencyKey): array
    {
        $this->seedSeller('sellerAAAA01', 'Seller A', 'active', [
            'commission_kind' => 'percentage', 'commission_bps' => 1000,
        ]);
        $product = $this->seedProduct($productSlug, 1000, 'sellerAAAA01');
        $order = $this->placeOneSellerOrder($product);
        $orderUuid = (string) $order['uuid'];
        $this->paymentService()->markPaid($this->context, self::TENANT, $orderUuid);
        $line = $this->orderLinesFor($orderUuid)[0];

        $refund = $this->refundService()->issue(
            $this->context,
            $orderUuid,
            new RefundInput(1000, 'full line refund', [
                ['order_line_uuid' => (string) $line['uuid'], 'quantity' => 1, 'amount' => 1000],
            ], false),
            $idempotencyKey
        );

        return ['orderUuid' => $orderUuid, 'refundUuid' => (string) $refund['uuid']];
    }

    public function testDuplicateRefundDebitIsDetected(): void
    {
        $this->activateMarketplace();
        ['orderUuid' => $orderUuid, 'refundUuid' => $refundUuid] = $this->placeOrderAndFullyRefundOneLine(
            'recon-refund-dup-debit-x',
            'idem-recon-refund-dup-debit-1'
        );

        // Split the seller's single -1000 refund_debit row into TWO rows for
        // the SAME account (-600 and -400) so the sum invariant (Σ abs = 1000)
        // still holds -- isolating the duplicate-detection branch from the
        // separate sum-mismatch branch below.
        $this->connection->table('commerce_marketplace_ledger')
            ->where('refund_uuid', '=', $refundUuid)
            ->where('entry_type', '=', 'refund_debit')
            ->update(['amount' => -600]);
        $this->connection->table('commerce_marketplace_ledger')->insert([
            'uuid' => 'ledgerDUPRFD01',
            'tenant_uuid' => self::TENANT,
            'account_key' => 'seller:sellerAAAA01',
            'account_kind' => 'seller',
            'seller_uuid' => 'sellerAAAA01',
            'currency' => 'USD',
            'entry_type' => 'refund_debit',
            'amount' => -400,
            'order_uuid' => $orderUuid,
            'refund_uuid' => $refundUuid,
            'idempotency_key' => $refundUuid . ':sellerAAAA01:refund_debit:dup',
        ]);

        $report = $this->reconciliation->scan($this->context, self::TENANT);

        self::assertSame([], $report['missing']);
        self::assertSame([], $report['mismatched']);
        self::assertCount(1, $report['duplicate']);
        $finding = $report['duplicate'][0];
        self::assertSame('refund', $finding['source']);
        self::assertSame($refundUuid, $finding['refund_uuid']);
        self::assertSame('refund_debit', $finding['entry_type']);
        self::assertSame(2, $finding['count']);
    }

    public function testDuplicateCommissionReversalIsDetected(): void
    {
        $this->activateMarketplace();
        ['refundUuid' => $refundUuid] = $this->placeOrderAndFullyRefundOneLine(
            'recon-refund-dup-reversal-x',
            'idem-recon-refund-dup-reversal-1'
        );

        $reversal = $this->connection->table('commerce_marketplace_ledger')
            ->where('refund_uuid', '=', $refundUuid)
            ->where('entry_type', '=', 'commission_reversal')
            ->first();
        self::assertNotNull($reversal, 'sanity: the full-line refund posts a commission_reversal row');

        $this->connection->table('commerce_marketplace_ledger')->insert([
            'uuid' => 'ledgerDUPREV01',
            'tenant_uuid' => self::TENANT,
            'account_key' => 'seller:sellerAAAA01',
            'account_kind' => 'seller',
            'seller_uuid' => 'sellerAAAA01',
            'currency' => 'USD',
            'entry_type' => 'commission_reversal',
            'amount' => (int) $reversal['amount'],
            'order_uuid' => (string) $reversal['order_uuid'],
            'refund_uuid' => $refundUuid,
            'idempotency_key' => $refundUuid . ':sellerAAAA01:commission_reversal:dup',
        ]);

        $report = $this->reconciliation->scan($this->context, self::TENANT);

        self::assertSame([], $report['missing']);
        self::assertSame([], $report['mismatched']);
        self::assertCount(1, $report['duplicate']);
        $finding = $report['duplicate'][0];
        self::assertSame('refund', $finding['source']);
        self::assertSame($refundUuid, $finding['refund_uuid']);
        self::assertSame('commission_reversal', $finding['entry_type']);
        self::assertSame(2, $finding['count']);
    }

    public function testCorruptedRefundDebitAmountIsFlagged(): void
    {
        $this->activateMarketplace();
        ['refundUuid' => $refundUuid] = $this->placeOrderAndFullyRefundOneLine(
            'recon-refund-corrupt-x',
            'idem-recon-refund-corrupt-1'
        );

        // Breaks the Σ abs(refund_debit) = refund.amount invariant without
        // deleting the row or duplicating it -- isolates the mismatch branch.
        $this->connection->table('commerce_marketplace_ledger')
            ->where('refund_uuid', '=', $refundUuid)
            ->where('entry_type', '=', 'refund_debit')
            ->update(['amount' => -900]);

        $report = $this->reconciliation->scan($this->context, self::TENANT);

        self::assertSame([], $report['duplicate']);
        $flagged = array_merge($report['mismatched'], $report['missing']);
        self::assertNotSame([], $flagged, 'a corrupted refund_debit amount that breaks the sum must be flagged');
        self::assertCount(1, $flagged);
        $finding = $flagged[0];
        self::assertSame('refund', $finding['source']);
        self::assertSame($refundUuid, $finding['refund_uuid']);
        self::assertSame('refund_debit', $finding['entry_type']);
    }

    public function testMissingCommissionReversalIsDetectedWhenDropped(): void
    {
        $this->activateMarketplace();
        ['refundUuid' => $refundUuid] = $this->placeOrderAndFullyRefundOneLine(
            'recon-refund-drop-reversal-x',
            'idem-recon-refund-drop-reversal-1'
        );

        $this->connection->table('commerce_marketplace_ledger')
            ->where('refund_uuid', '=', $refundUuid)
            ->where('entry_type', '=', 'commission_reversal')
            ->delete();

        $report = $this->reconciliation->scan($this->context, self::TENANT);

        self::assertSame([], $report['duplicate']);
        self::assertSame([], $report['mismatched']);
        self::assertCount(1, $report['missing']);
        $finding = $report['missing'][0];
        self::assertSame('refund', $finding['source']);
        self::assertSame($refundUuid, $finding['refund_uuid']);
        self::assertSame('sellerAAAA01', $finding['seller_uuid']);
        self::assertSame('commission_reversal', $finding['entry_type']);
    }

    public function testZeroCommissionRefundLineProducesNoFalsePositiveForMissingCommissionReversal(): void
    {
        $this->activateMarketplace();
        // No commission override anywhere -- resolves to the config tail
        // default (percentage, bps: 0), so commission_amount is exactly 0 on
        // this order line even though commission_basis (independent of the
        // rate) is still positive, and LedgerPostingService::postRefund()
        // never posts a commission_reversal row for it (target() is always 0
        // when commissionAmount is 0).
        $this->seedSeller('sellerZERO001', 'Seller Zero');
        $product = $this->seedProduct('recon-refund-zero-x', 1000, 'sellerZERO001');
        $order = $this->placeOneSellerOrder($product);
        $orderUuid = (string) $order['uuid'];
        $this->paymentService()->markPaid($this->context, self::TENANT, $orderUuid);
        $line = $this->orderLinesFor($orderUuid)[0];
        self::assertSame(0, (int) $line['commission_amount'], 'sanity: zero-commission line');

        $refund = $this->refundService()->issue(
            $this->context,
            $orderUuid,
            new RefundInput(1000, 'full line refund', [
                ['order_line_uuid' => (string) $line['uuid'], 'quantity' => 1, 'amount' => 1000],
            ], false),
            'idem-recon-refund-zero-1'
        );
        self::assertSame('completed', $refund['status']);
        self::assertCount(
            1,
            $this->connection->table('commerce_marketplace_ledger')
                ->where('refund_uuid', '=', (string) $refund['uuid'])
                ->get(),
            'sanity: only refund_debit posted, no commission_reversal row exists at all'
        );

        $report = $this->reconciliation->scan($this->context, self::TENANT);

        self::assertSame(['missing' => [], 'duplicate' => [], 'mismatched' => []], $report);
    }

    // -----------------------------------------------------------------
    // 5. Payout without its debit is detected; a duplicate payout_debit too.
    // -----------------------------------------------------------------

    public function testPayoutWithoutItsDebitIsDetected(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $this->seedLedgerSale('sellerAAAA01', 5000);

        $payout = $this->payoutService()->record(
            $this->context,
            self::TENANT,
            'sellerAAAA01',
            'USD',
            3000,
            'idem-recon-payout-1',
            'ext-ref-recon-1',
            null,
            'operatorRECON01'
        );
        $payoutUuid = (string) $payout['uuid'];

        $this->connection->table('commerce_marketplace_ledger')
            ->where('payout_uuid', '=', $payoutUuid)
            ->where('entry_type', '=', 'payout_debit')
            ->delete();

        $report = $this->reconciliation->scan($this->context, self::TENANT);

        self::assertSame([], $report['duplicate']);
        self::assertSame([], $report['mismatched']);
        self::assertCount(1, $report['missing']);
        $finding = $report['missing'][0];
        self::assertSame('payout', $finding['source']);
        self::assertSame($payoutUuid, $finding['payout_uuid']);
        self::assertSame('sellerAAAA01', $finding['seller_uuid']);
        self::assertSame('payout_debit', $finding['entry_type']);
        self::assertSame(-3000, $finding['expected_amount']);
    }

    public function testDuplicatePayoutDebitIsDetected(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $this->seedLedgerSale('sellerAAAA01', 5000);

        $payout = $this->payoutService()->record(
            $this->context,
            self::TENANT,
            'sellerAAAA01',
            'USD',
            3000,
            'idem-recon-payout-2',
            'ext-ref-recon-2',
            null,
            'operatorRECON01'
        );
        $payoutUuid = (string) $payout['uuid'];

        $this->connection->table('commerce_marketplace_ledger')->insert([
            'uuid' => 'ledgerDUPPAYOU',
            'tenant_uuid' => self::TENANT,
            'account_key' => 'seller:sellerAAAA01',
            'account_kind' => 'seller',
            'seller_uuid' => 'sellerAAAA01',
            'currency' => 'USD',
            'entry_type' => 'payout_debit',
            'amount' => -3000,
            'payout_uuid' => $payoutUuid,
            'idempotency_key' => $payoutUuid . ':sellerAAAA01:payout_debit:dup',
        ]);

        $report = $this->reconciliation->scan($this->context, self::TENANT);

        self::assertSame([], $report['missing']);
        self::assertSame([], $report['mismatched']);
        self::assertCount(1, $report['duplicate']);
        $finding = $report['duplicate'][0];
        self::assertSame('payout', $finding['source']);
        self::assertSame($payoutUuid, $finding['payout_uuid']);
        self::assertSame('payout_debit', $finding['entry_type']);
        self::assertSame(2, $finding['count']);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function ledgerPostingService(): LedgerPostingService
    {
        return new LedgerPostingService(new LedgerRepository(), new LedgerAccountLock());
    }

    private function paymentService(): OrderPaymentService
    {
        return new OrderPaymentService(
            new OrderRepository(),
            new SellerOrderPaymentConfirmation(),
            null,
            new SellerOrderRepository(),
            $this->ledgerPostingService()
        );
    }

    private function refundService(): RefundService
    {
        return new RefundService(
            new OrderRepository(),
            new RefundRepository(),
            new StockRepository(),
            $this->tenantResolver(),
            null,
            new MarketplaceRefundGuard(new RefundRepository()),
            $this->ledgerPostingService()
        );
    }

    private function payoutService(): PayoutService
    {
        $ledger = new LedgerRepository();

        return new PayoutService(
            new PayoutRepository(),
            $ledger,
            new LedgerAccountLock(),
            new SellerBalanceService($ledger),
            new SellerRepository()
        );
    }

    /** Directly posts a sale_credit so a payout test has an available balance without a full checkout. */
    private function seedLedgerSale(string $sellerUuid, int $amount): void
    {
        (new LedgerRepository())->post($this->context, self::TENANT, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller($sellerUuid),
            'seller_uuid' => $sellerUuid,
            'currency' => 'USD',
            'entry_type' => 'sale_credit',
            'amount' => $amount,
            'order_uuid' => 'orderSEEDSALE1',
            'idempotency_key' => 'orderSEEDSALE1:' . $sellerUuid . ':sale_credit',
        ]);
    }

    /** @return array<string,mixed> the placed parent order */
    private function placeOneSellerOrder(array $product): array
    {
        return $this->placeSellerOrderWithQuantity($product, 1);
    }

    /** @return array<string,mixed> the placed parent order */
    private function placeSellerOrderWithQuantity(array $product, int $quantity): array
    {
        ['cart' => $cart, 'token' => $token] = $this->cart()->create($this->context);
        $this->cart()->addLine($this->context, $cart, (string) $product['variants'][0]['uuid'], $quantity);

        $placed = $this->checkout()
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        return $placed['order'];
    }

    private function checkout(): CheckoutService
    {
        return new CheckoutService(
            $this->cart(),
            new DiscountRepository(),
            new DiscountService(new DiscountRepository(), $this->tenantResolver()),
            new StockRepository(),
            new PricingEngine(),
            $this->fakeShipping(),
            $this->zeroTax(),
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
            'uuid' => 'mktsettings1',
            'tenant_uuid' => self::TENANT,
            'status' => 'active',
        ]);
    }

    /** @param array{commission_kind?:string,commission_bps?:int,commission_fixed?:int} $commission */
    private function seedSeller(string $uuid, string $name, string $status = 'active', array $commission = []): void
    {
        $this->connection->table('commerce_sellers')->insert(array_merge([
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT,
            'slug' => strtolower($uuid),
            'name' => $name,
            'status' => $status,
        ], $commission));
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

    private function zeroTax(): TaxCalculator
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
    private function orderLinesFor(string $orderUuid): array
    {
        return $this->connection->table('commerce_order_lines')
            ->where('order_uuid', '=', $orderUuid)
            ->orderBy('id', 'ASC')
            ->get();
    }

    /** @return list<array<string,mixed>> */
    private function sellerOrdersFor(string $orderUuid): array
    {
        return $this->connection->table('commerce_seller_orders')
            ->where('order_uuid', '=', $orderUuid)
            ->orderBy('partition_number', 'ASC')
            ->get();
    }

    /** @return list<array<string,mixed>> */
    private function ledgerRowsForOrder(string $orderUuid): array
    {
        return $this->connection->table('commerce_marketplace_ledger')
            ->where('order_uuid', '=', $orderUuid)
            ->orderBy('id', 'ASC')
            ->get();
    }
}
