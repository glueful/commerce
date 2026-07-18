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
use Glueful\Extensions\Commerce\Marketplace\LedgerException;
use Glueful\Extensions\Commerce\Marketplace\LedgerPostingService;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceRefundGuard;
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
use Glueful\Extensions\Commerce\Orders\Refunds\RefundValidationException;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Commerce\Tests\Support\QueryLoggingPdoStatement;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\RefundCollector;
use Glueful\Extensions\Contracts\Payments\RefundRequest;
use Glueful\Extensions\Contracts\Payments\RefundResult;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;

/**
 * Refund-side settlement posting (design spec §2.8, MV3 plan Task 7):
 * {@see MarketplaceRefundGuard::validateAndNormalize()} tightens/auto-expands
 * a `marketplace_partitioned` order's refund line attribution at VALIDATION
 * time; {@see LedgerPostingService::postRefund()} computes `delta_R` and the
 * per-line cumulative commission reversal at COMPLETION time, inside
 * `RefundService::applyCompletion()`'s own transaction, immediately after the
 * `refunded_total` CAS.
 */
final class RefundPostingTest extends CommerceTestCase
{
    private const TENANT = '';

    // -----------------------------------------------------------------
    // 1. Validation tightening: line-less/under-attributed partial rejected;
    //    full-remaining auto-expands.
    // -----------------------------------------------------------------

    public function testPartialRefundWithoutLinesOnPartitionedOrderIsRejected(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A', 'active', [
            'commission_kind' => 'percentage', 'commission_bps' => 1000,
        ]);
        $product = $this->seedProduct('reject-partial-x', 1000, 'sellerAAAA01');
        $order = $this->placeOneSellerOrder($product);
        $orderUuid = (string) $order['uuid'];
        $this->paymentService()->markPaid($this->context, self::TENANT, $orderUuid);

        $this->expectException(RefundValidationException::class);
        $this->refundService()->issue(
            $this->context,
            $orderUuid,
            new RefundInput(500, 'partial no lines', [], false),
            'idem-reject-partial-1'
        );
    }

    public function testFullRemainingRefundWithoutLinesAutoExpandsAcrossSellerLines(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A', 'active', [
            'commission_kind' => 'percentage', 'commission_bps' => 1000,
        ]);
        $product = $this->seedProduct('auto-expand-x', 1000, 'sellerAAAA01');
        $order = $this->placeOneSellerOrder($product);
        $orderUuid = (string) $order['uuid'];
        self::assertSame(1500, (int) $order['grand_total'], 'sanity: 1000 product + 500 flat shipping');
        $this->paymentService()->markPaid($this->context, self::TENANT, $orderUuid);

        $line = $this->orderLinesFor($orderUuid)[0];

        $refund = $this->refundService()->issue(
            $this->context,
            $orderUuid,
            new RefundInput(null, 'full remaining', [], false),
            'idem-auto-expand-1'
        );

        self::assertSame('completed', $refund['status']);
        self::assertSame('refunded', $this->orderRow($orderUuid)['status']);

        $persistedLines = $this->refundLinesFor((string) $refund['uuid']);
        self::assertCount(1, $persistedLines, 'guard must auto-expand across the single refundable seller line');
        self::assertSame((string) $line['uuid'], $persistedLines[0]['order_line_uuid']);
        self::assertSame(
            1000,
            (int) $persistedLines[0]['amount'],
            "auto-expand attributes the line's own remaining commission basis, not the whole refund amount"
        );

        $ledgerRows = $this->ledgerRowsForRefund((string) $refund['uuid']);
        $sellerDebit = $this->ledgerRowForSellerAndType($ledgerRows, 'sellerAAAA01', 'refund_debit');
        self::assertSame(-1000, (int) $sellerDebit['amount']);
        $reversal = $this->ledgerRowForSellerAndType($ledgerRows, 'sellerAAAA01', 'commission_reversal');
        self::assertSame(100, (int) $reversal['amount']);
        $marketplaceRow = $this->ledgerRowByAccountKeyAndType($ledgerRows, 'marketplace', 'refund_debit');
        self::assertSame(-500, (int) $marketplaceRow['amount'], 'the shipping portion the guard never attributed');
        self::assertNull($marketplaceRow['seller_uuid']);
        self::assertSame('marketplace', $marketplaceRow['account_kind']);

        self::assertSame(
            (int) $refund['amount'],
            abs((int) $sellerDebit['amount']) + abs((int) $marketplaceRow['amount']),
            'sum(abs(seller refund_debit)) + abs(marketplace refund_debit) must equal the refund amount exactly'
        );
    }

    // -----------------------------------------------------------------
    // 2. delta_R caps at the line's remaining basis; both marketplace-funded
    //    remainder mechanisms (over-cap AND amount never attributed to any
    //    line) in one refund.
    // -----------------------------------------------------------------

    public function testOverAttributedLineCapsDeltaRAndUnattributedRemainderBothGoToMarketplace(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A', 'active', [
            'commission_kind' => 'percentage', 'commission_bps' => 2500,
        ]);
        $product = $this->seedProduct('over-attr-x', 1000, 'sellerAAAA01');
        $order = $this->placeOneSellerOrder($product);
        $orderUuid = (string) $order['uuid'];
        self::assertSame(1500, (int) $order['grand_total']);
        $this->paymentService()->markPaid($this->context, self::TENANT, $orderUuid);
        $line = $this->orderLinesFor($orderUuid)[0];

        // Deliberately over-attribute 1200 to a line whose commission_basis is only 1000,
        // AND leave 300 of the 1500 refund amount unattributed to any line at all -- both
        // mechanisms of the design spec §2.8 marketplace-funded remainder, in one refund.
        $refund = $this->refundService()->issue(
            $this->context,
            $orderUuid,
            new RefundInput(1500, 'over attributed', [
                ['order_line_uuid' => (string) $line['uuid'], 'quantity' => 1, 'amount' => 1200],
            ], false),
            'idem-over-attr-1'
        );

        self::assertSame('completed', $refund['status']);

        $ledgerRows = $this->ledgerRowsForRefund((string) $refund['uuid']);
        $sellerDebit = $this->ledgerRowForSellerAndType($ledgerRows, 'sellerAAAA01', 'refund_debit');
        self::assertLessThan(0, (int) $sellerDebit['amount'], 'refund_debit must be negative');
        self::assertSame(
            -1000,
            (int) $sellerDebit['amount'],
            "delta_R must cap at the line's remaining basis (1000), never the raw over-attributed 1200"
        );
        self::assertSame('seller:sellerAAAA01', $sellerDebit['account_key']);
        self::assertSame('seller', $sellerDebit['account_kind']);

        $reversal = $this->ledgerRowForSellerAndType($ledgerRows, 'sellerAAAA01', 'commission_reversal');
        self::assertGreaterThan(0, (int) $reversal['amount'], 'commission_reversal must be positive');
        self::assertSame(
            250,
            (int) $reversal['amount'],
            'the line is fully refunded (capped at basis) so it reverses the full original commission'
        );

        $marketplaceRow = $this->ledgerRowByAccountKeyAndType($ledgerRows, 'marketplace', 'refund_debit');
        self::assertSame(-500, (int) $marketplaceRow['amount'], '200 over-cap excess + 300 not attributed to any line');
        self::assertSame('marketplace', $marketplaceRow['account_kind']);
        self::assertNull($marketplaceRow['seller_uuid']);

        self::assertSame(
            (int) $refund['amount'],
            abs((int) $sellerDebit['amount']) + abs((int) $marketplaceRow['amount']),
            'sum(abs(seller refund_debit)) + abs(marketplace refund_debit) must equal the refund amount exactly'
        );
    }

    // -----------------------------------------------------------------
    // 3. Per-line cumulative reversal across differing product rates under
    //    ONE seller: multiple partials on a line sum to exactly its own
    //    commission snapshot; a full line refund reverses exactly C;
    //    different lines' rates never contaminate each other.
    // -----------------------------------------------------------------

    public function testCumulativeReversalAcrossDifferingRatesUnderOneSellerNeverContaminates(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A', 'active', [
            'commission_kind' => 'percentage', 'commission_bps' => 1000,
        ]);
        // Line A: inherits the seller's 10% -> B=1000, C=100.
        $productA = $this->seedProduct('cumulative-a', 1000, 'sellerAAAA01');
        // Line B: product-level override, fixed $50 -> B=2000, C=50.
        $productB = $this->seedProduct('cumulative-b', 2000, 'sellerAAAA01', [
            'commission_kind' => 'fixed', 'commission_fixed' => 50,
        ]);

        $token = $this->cartWithLines([[$productA, 1], [$productB, 1]]);
        $placed = $this->checkout()->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');
        $order = $placed['order'];
        $orderUuid = (string) $order['uuid'];
        self::assertSame(3500, (int) $order['grand_total'], 'sanity: 1000 + 2000 + 500 flat shipping');
        $this->paymentService()->markPaid($this->context, self::TENANT, $orderUuid);

        $lines = $this->orderLinesFor($orderUuid);
        $lineA = $this->lineBySku($lines, 'CUMULATIVEA');
        $lineB = $this->lineBySku($lines, 'CUMULATIVEB');

        $refunds = $this->refundService();

        // Two partials against line A, summing to exactly its full basis.
        $r1 = $refunds->issue($this->context, $orderUuid, new RefundInput(500, 'r1', [
            ['order_line_uuid' => (string) $lineA['uuid'], 'quantity' => 1, 'amount' => 500],
        ], false), 'idem-cumulative-1');
        $r2 = $refunds->issue($this->context, $orderUuid, new RefundInput(500, 'r2', [
            ['order_line_uuid' => (string) $lineA['uuid'], 'quantity' => 1, 'amount' => 500],
        ], false), 'idem-cumulative-2');
        // One full refund against line B, in a single shot.
        $r3 = $refunds->issue($this->context, $orderUuid, new RefundInput(2000, 'r3', [
            ['order_line_uuid' => (string) $lineB['uuid'], 'quantity' => 1, 'amount' => 2000],
        ], false), 'idem-cumulative-3');

        $reversal1 = $this->ledgerRowForSellerAndType(
            $this->ledgerRowsForRefund((string) $r1['uuid']),
            'sellerAAAA01',
            'commission_reversal'
        );
        $reversal2 = $this->ledgerRowForSellerAndType(
            $this->ledgerRowsForRefund((string) $r2['uuid']),
            'sellerAAAA01',
            'commission_reversal'
        );
        $reversal3 = $this->ledgerRowForSellerAndType(
            $this->ledgerRowsForRefund((string) $r3['uuid']),
            'sellerAAAA01',
            'commission_reversal'
        );

        self::assertSame(50, (int) $reversal1['amount'], "the first half of line A's basis reverses half its 10% commission");
        self::assertSame(50, (int) $reversal2['amount'], "the second half completes line A's reversal");
        self::assertSame(50, (int) $reversal3['amount'], "a single full refund of line B reverses exactly its fixed $50 commission");
        self::assertSame(
            100,
            (int) $reversal1['amount'] + (int) $reversal2['amount'],
            "line A's two partial reversals sum to EXACTLY its own snapshot commission (100), never leaking into line B's"
        );

        // Neither refund posted a marketplace-funded remainder: each refund's lines summed
        // to its own amount exactly (no shipping was ever attributed).
        foreach ([$r1, $r2, $r3] as $r) {
            $marketplaceEntries = array_values(array_filter(
                $this->ledgerRowsForRefund((string) $r['uuid']),
                static fn (array $row): bool => $row['account_kind'] === 'marketplace'
            ));
            self::assertSame([], $marketplaceEntries, 'no shipping/tax was attributed in this test, so no marketplace posting is expected');
        }

        // Signs and account keys, directly, across all three refunds.
        foreach ([$r1['uuid'], $r2['uuid'], $r3['uuid']] as $refundUuid) {
            foreach ($this->ledgerRowsForRefund((string) $refundUuid) as $row) {
                self::assertSame('seller:sellerAAAA01', $row['account_key']);
                if ($row['entry_type'] === 'refund_debit') {
                    self::assertLessThan(0, (int) $row['amount']);
                } elseif ($row['entry_type'] === 'commission_reversal') {
                    self::assertGreaterThan(0, (int) $row['amount']);
                }
            }
        }

        self::assertSame(
            100 + 50,
            (int) $reversal1['amount'] + (int) $reversal2['amount'] + (int) $reversal3['amount'],
            'cumulative total across all three refunds equals the exact sum of both lines\' original commission'
        );
    }

    // -----------------------------------------------------------------
    // 4. LedgerPostingService::postRefund() idempotency, called directly
    //    (hand-built inputs -- no checkout/RefundService wiring needed).
    // -----------------------------------------------------------------

    public function testPostRefundCalledTwiceWithIdenticalInputsPostsNoDuplicateRows(): void
    {
        $orderUuid = 'orderREFHAND';
        $refundUuid = 'refundHAND01';
        $sellerUuid = 'sellerHAND001';
        $lineUuid = 'lineHAND00001';

        $this->connection->table('commerce_order_lines')->insert([
            'uuid' => $lineUuid,
            'order_uuid' => $orderUuid,
            'variant_uuid' => 'variantHAND01',
            'product_name' => 'Hand Product',
            'sku' => 'HANDSKU1',
            'option_values' => '[]',
            'unit_price' => 1000,
            'quantity' => 1,
            'line_total' => 1000,
            'seller_uuid' => $sellerUuid,
            'commission_basis' => 1000,
            'commission_amount' => 100,
        ]);

        $order = ['uuid' => $orderUuid];
        $refund = ['uuid' => $refundUuid, 'amount' => 1000, 'currency' => 'USD'];
        $refundLines = [['order_line_uuid' => $lineUuid, 'quantity' => 1, 'amount' => 1000]];

        $service = $this->ledgerPostingService();
        $service->postRefund($this->context, self::TENANT, $order, $refund, $refundLines);
        $service->postRefund($this->context, self::TENANT, $order, $refund, $refundLines);

        $rows = $this->ledgerRowsForRefund($refundUuid);
        self::assertCount(2, $rows, 'a byte-identical replay must never create duplicate rows (refund_debit + commission_reversal)');
    }

    // -----------------------------------------------------------------
    // 5. Non-partitioned refund: byte-identical, zero ledger/lock queries.
    // -----------------------------------------------------------------

    public function testNonPartitionedRefundExecutesZeroLedgerAndLockQueriesAndPostsNothingExtra(): void
    {
        $order = $this->placeNonPartitionedPaidOrder();
        $orderUuid = (string) $order['uuid'];
        self::assertFalse((bool) $order['marketplace_partitioned']);

        $pdo = $this->connection->getPDO();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [QueryLoggingPdoStatement::class]);
        QueryLoggingPdoStatement::$queries = [];

        // Fully wired collaborators (guard + ledger posting) -- proving the gate is the
        // order's OWN marketplace_partitioned flag, never a missing collaborator.
        $refund = $this->refundService()->issue(
            $this->context,
            $orderUuid,
            new RefundInput(null, 'non-partitioned full', [], false),
            'idem-nonpart-1'
        );

        self::assertSame('completed', $refund['status']);
        self::assertNotEmpty(QueryLoggingPdoStatement::$queries, 'sanity: issue() must run some queries');
        foreach (QueryLoggingPdoStatement::$queries as $sql) {
            self::assertStringNotContainsString('commerce_marketplace_ledger', $sql);
            self::assertStringNotContainsString('commerce_ledger_account_locks', $sql);
        }

        self::assertSame(0, $this->connection->table('commerce_marketplace_ledger')->count());
        self::assertSame(0, $this->connection->table('commerce_ledger_account_locks')->count());
    }

    // -----------------------------------------------------------------
    // 6. Forced ledger failure rolls back the refunded_total CAS (and the
    //    pending->completed claim, and every already-claimed lock/posting).
    // -----------------------------------------------------------------

    public function testForcedLedgerFailureDuringRefundPostingRollsBackTheRefundedTotalCas(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A', 'active', [
            'commission_kind' => 'percentage', 'commission_bps' => 1000,
        ]);
        $product = $this->seedProduct('forced-fail-refund-x', 1000, 'sellerAAAA01');
        $order = $this->placeOneSellerOrder($product);
        $orderUuid = (string) $order['uuid'];
        $this->paymentService()->markPaid($this->context, self::TENANT, $orderUuid);

        $lockAfterPayment = $this->accountLockRow('seller:sellerAAAA01');
        self::assertNotNull($lockAfterPayment);
        $revisionAfterPayment = (int) $lockAfterPayment['revision'];

        $line = $this->orderLinesFor($orderUuid)[0];
        $collector = new FakeRefundCollector([new RefundResult(RefundResult::PENDING)]);
        $service = $this->refundService($collector);

        $pending = $service->issue(
            $this->context,
            $orderUuid,
            new RefundInput(1000, 'forced fail', [
                ['order_line_uuid' => (string) $line['uuid'], 'quantity' => 1, 'amount' => 1000],
            ], false),
            'idem-forced-fail-1'
        );
        self::assertSame('pending', $pending['status']);
        $refundUuid = (string) $pending['uuid'];

        // Pre-seed a MISMATCHED ledger row under the EXACT idempotency key postRefund()
        // will compute for this refund's seller refund_debit -- forces LedgerRepository's
        // verify() to throw a LedgerException mid-posting, inside applyCompletion()'s own
        // transaction. The refund_uuid is only known now because the gateway saga commits
        // the `pending` row (and returns its uuid) BEFORE this posting ever runs.
        $this->connection->table('commerce_marketplace_ledger')->insert([
            'uuid' => 'ledgerCONFLICT2',
            'tenant_uuid' => self::TENANT,
            'account_key' => LedgerRepository::accountKeyForSeller('sellerAAAA01'),
            'account_kind' => 'seller',
            'seller_uuid' => 'sellerAAAA01',
            'currency' => 'USD',
            'entry_type' => 'refund_debit',
            'amount' => -999999,
            'order_uuid' => $orderUuid,
            'refund_uuid' => $refundUuid,
            'idempotency_key' => $refundUuid . ':sellerAAAA01:refund_debit',
        ]);

        try {
            $service->settle($this->context, $refundUuid, new RefundResult(RefundResult::COMPLETED, 'prov-forced-fail'));
            self::fail('Expected the mismatched ledger replay to throw and roll back the whole transaction.');
        } catch (LedgerException) {
            $this->addToAssertionCount(1);
        }

        $refundRow = $this->connection->table('commerce_refunds')->where('uuid', '=', $refundUuid)->first();
        self::assertNotNull($refundRow);
        self::assertSame('pending', $refundRow['status'], 'the pending->completed claim must roll back with the failed posting');

        $orderAfter = $this->orderRow($orderUuid);
        self::assertSame(0, (int) $orderAfter['refunded_total'], 'the refunded_total CAS must roll back');
        self::assertSame('paid', $orderAfter['status']);

        self::assertSame(
            1,
            $this->connection->table('commerce_marketplace_ledger')->where('refund_uuid', '=', $refundUuid)->count(),
            'only the one pre-seeded conflicting row may remain -- no legitimate postings persisted'
        );

        $lockAfterFailure = $this->accountLockRow('seller:sellerAAAA01');
        self::assertNotNull($lockAfterFailure);
        self::assertSame(
            $revisionAfterPayment,
            (int) $lockAfterFailure['revision'],
            "the refund's own account-lock claim must roll back too -- revision stays at its post-payment value"
        );
        self::assertNull(
            $this->accountLockRow('marketplace'),
            'the marketplace account-lock row (never claimed before this failed attempt) must not persist either'
        );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function ledgerPostingService(): LedgerPostingService
    {
        return new LedgerPostingService(new LedgerRepository(), new LedgerAccountLock());
    }

    private function refundService(?RefundCollector $collector = null): RefundService
    {
        return new RefundService(
            new OrderRepository(),
            new RefundRepository(),
            new StockRepository(),
            $this->tenantResolver(),
            $collector,
            new MarketplaceRefundGuard(new RefundRepository()),
            $this->ledgerPostingService()
        );
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

    /** @return array<string,mixed> the placed parent order */
    private function placeOneSellerOrder(array $product): array
    {
        ['cart' => $cart, 'token' => $token] = $this->cart()->create($this->context);
        $this->cart()->addLine($this->context, $cart, (string) $product['variants'][0]['uuid'], 1);

        $placed = $this->checkout()
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        return $placed['order'];
    }

    /** @return array<string,mixed> the placed, paid, non-partitioned parent order */
    private function placeNonPartitionedPaidOrder(): array
    {
        $catalog = new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            $this->tenantResolver(),
            new StockRepository()
        );
        $product = $catalog->createProduct($this->context, [
            'slug' => 'non-partitioned-refund-x',
            'name' => 'non-partitioned-refund-x',
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => 'NONPARTREFUNDX',
                'option_values' => [],
                'price' => 1000,
                'currency' => 'USD',
            ]],
        ]);
        (new StockRepository())->increment($this->context, self::TENANT, (string) $product['variants'][0]['uuid'], 10);

        ['cart' => $cart, 'token' => $token] = $this->cart()->create($this->context);
        $this->cart()->addLine($this->context, $cart, (string) $product['variants'][0]['uuid'], 1);

        $placed = $this->checkoutPlain()->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');
        $orderUuid = (string) $placed['order']['uuid'];

        (new OrderPaymentService(new OrderRepository()))->markPaid($this->context, self::TENANT, $orderUuid);
        $order = (new OrderRepository())->findByUuid($this->context, self::TENANT, $orderUuid);
        self::assertNotNull($order);

        return $order;
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

    private function checkoutPlain(): CheckoutService
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
            $this->tenantResolver()
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

    /**
     * @param array{commission_kind?:string,commission_bps?:int,commission_fixed?:int} $commission
     * @return array<string,mixed>
     */
    private function seedProduct(
        string $slug,
        int $price,
        ?string $sellerUuid,
        array $commission = [],
        int $stock = 100
    ): array {
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

        $updates = $commission;
        if ($sellerUuid !== null) {
            $updates['seller_uuid'] = $sellerUuid;
        }
        if ($updates !== []) {
            $this->connection->table('commerce_products')
                ->where('uuid', '=', $product['uuid'])
                ->update($updates);
        }

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

    /**
     * @param list<array{0:array<string,mixed>,1:int}> $productsAndQuantities
     * @return string cart token
     */
    private function cartWithLines(array $productsAndQuantities): string
    {
        $cartService = $this->cart();
        ['cart' => $cart, 'token' => $token] = $cartService->create($this->context);
        foreach ($productsAndQuantities as [$product, $quantity]) {
            $cart = $cartService->addLine(
                $this->context,
                $cart,
                (string) $product['variants'][0]['uuid'],
                $quantity
            );
        }

        return $token;
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

    /** @param list<array<string,mixed>> $lines @return array<string,mixed> */
    private function lineBySku(array $lines, string $sku): array
    {
        foreach ($lines as $line) {
            if ((string) $line['sku'] === $sku) {
                return $line;
            }
        }

        self::fail("No order line with sku '{$sku}'.");
    }

    /** @return list<array<string,mixed>> */
    private function refundLinesFor(string $refundUuid): array
    {
        return $this->connection->table('commerce_refund_lines')
            ->where('refund_uuid', '=', $refundUuid)
            ->orderBy('id', 'ASC')
            ->get();
    }

    /** @return list<array<string,mixed>> */
    private function ledgerRowsForRefund(string $refundUuid): array
    {
        return $this->connection->table('commerce_marketplace_ledger')
            ->where('refund_uuid', '=', $refundUuid)
            ->orderBy('id', 'ASC')
            ->get();
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    private function ledgerRowForSellerAndType(array $rows, string $sellerUuid, string $entryType): array
    {
        foreach ($rows as $row) {
            if ((string) $row['seller_uuid'] === $sellerUuid && (string) $row['entry_type'] === $entryType) {
                return $row;
            }
        }

        self::fail("No ledger row for seller '{$sellerUuid}' entry_type '{$entryType}'.");
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    private function ledgerRowByAccountKeyAndType(array $rows, string $accountKey, string $entryType): array
    {
        foreach ($rows as $row) {
            if ((string) $row['account_key'] === $accountKey && (string) $row['entry_type'] === $entryType) {
                return $row;
            }
        }

        self::fail("No ledger row for account_key '{$accountKey}' entry_type '{$entryType}'.");
    }

    /** @return array<string,mixed>|null */
    private function accountLockRow(string $accountKey): ?array
    {
        return $this->connection->table('commerce_ledger_account_locks')
            ->where('tenant_uuid', '=', self::TENANT)
            ->where('account_key', '=', $accountKey)
            ->first();
    }
}

/**
 * Scripted fake gateway (mirrors
 * {@see \Glueful\Extensions\Commerce\Tests\Integration\Refunds\GatewayRefundTest}'s
 * own `FakeRefundCollector`, duplicated here since that one lives in a
 * different test namespace): a queue of `RefundResult` outcomes consumed in
 * order.
 */
final class FakeRefundCollector implements RefundCollector
{
    /** @param list<RefundResult> $queue */
    public function __construct(private array $queue)
    {
    }

    public function refund(ApplicationContext $context, PayableReference $payable, RefundRequest $request): RefundResult
    {
        if ($this->queue === []) {
            throw new \RuntimeException('FakeRefundCollector queue exhausted.');
        }

        return array_shift($this->queue);
    }
}
