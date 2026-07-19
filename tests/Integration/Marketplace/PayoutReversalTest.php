<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutAccountRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutAccountService;
use Glueful\Extensions\Commerce\Marketplace\PayoutRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutService;
use Glueful\Extensions\Commerce\Marketplace\SellerBalanceService;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Payments\DestinationStatus;
use Glueful\Extensions\Contracts\Payments\PayoutCollector;
use Glueful\Extensions\Contracts\Payments\PayoutDestination;
use Glueful\Extensions\Contracts\Payments\PayoutRequest;
use Glueful\Extensions\Contracts\Payments\PayoutResult;
use Glueful\Extensions\Contracts\Payments\PayoutStatusResult;

/**
 * `PayoutService::reconcile()`'s paid-row reversal cadence (design spec §2.6/§2.8, MV4
 * Task 9): a cumulative `reversedAmount` greater than the persisted `reversed_total` posts
 * ONLY the unseen `payout_reversal` delta (positive -- it offsets `payout_debit`), a full
 * reversal reaches `status=reversed`, a replay of an already-applied cumulative value posts
 * nothing, and a provider regression (status or amount) is an integrity finding that never
 * touches the ledger.
 */
final class PayoutReversalTest extends CommerceTestCase
{
    private const TENANT = '';
    private const SELLER = 'sellerREVRS01';
    private const ACTOR = 'operatorREVRS1';
    private const PROVIDER = 'default';
    private const AMOUNT = 1000;

    private LedgerRepository $ledger;
    private PayoutRepository $payouts;
    private SellerBalanceService $balances;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = new LedgerRepository();
        $this->payouts = new PayoutRepository();
        $this->balances = new SellerBalanceService($this->ledger);

        $this->connection->table('commerce_seller_payout_accounts')->insert([
            'uuid' => 'acctREVRS0001',
            'tenant_uuid' => self::TENANT,
            'seller_uuid' => self::SELLER,
            'provider' => self::PROVIDER,
            'account_ref' => 'acct-ref-reversal',
            'readiness_state' => 'ready',
            'last_synced_at' => null,
            'failure_code' => null,
        ]);
    }

    private function makeService(?PayoutCollector $collector): PayoutService
    {
        return new PayoutService(
            $this->payouts,
            $this->ledger,
            new LedgerAccountLock(),
            $this->balances,
            new SellerRepository(),
            null,
            $collector,
            new PayoutAccountService(new PayoutAccountRepository())
        );
    }

    /** Reserves, executes, and finalizes ONE paid payout of {@see self::AMOUNT} to reconcile against. */
    private function seedPaidPayout(): array
    {
        $this->ledger->post($this->context, self::TENANT, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller(self::SELLER),
            'seller_uuid' => self::SELLER,
            'currency' => 'USD',
            'entry_type' => 'sale_credit',
            'amount' => self::AMOUNT,
            'order_uuid' => 'orderREVRSSD1',
            'idempotency_key' => 'orderREVRSSD1:' . self::SELLER . ':sale_credit',
        ]);

        $collector = new ReversalFakeCollector(transferQueue: [new PayoutResult(PayoutResult::PAID, 'prov-reversal-1')]);
        $service = $this->makeService($collector);

        return $service->execute($this->context, self::TENANT, self::SELLER, 'USD', self::AMOUNT, self::ACTOR);
    }

    private function reversalLedgerRows(string $payoutUuid): array
    {
        return $this->connection->table('commerce_marketplace_ledger')
            ->where('payout_uuid', '=', $payoutUuid)
            ->where('entry_type', '=', 'payout_reversal')
            ->orderBy('id', 'ASC')
            ->get();
    }

    private function row(string $payoutUuid): array
    {
        return $this->connection->table('commerce_payouts')->where('uuid', '=', $payoutUuid)->first();
    }

    // -----------------------------------------------------------------
    // Partial reversal, then full reversal on the SAME payout.
    // -----------------------------------------------------------------

    public function testPartialReversalPostsOnlyTheDeltaAndKeepsStatusPaid(): void
    {
        $payout = $this->seedPaidPayout();
        $payoutUuid = (string) $payout['uuid'];

        $collector = new ReversalFakeCollector(statusQueue: [new PayoutStatusResult(PayoutStatusResult::PAID, 200)]);
        $service = $this->makeService($collector);

        $reconciled = $service->reconcile($this->context, self::TENANT, $this->row($payoutUuid));

        self::assertSame('paid', $reconciled['status']);
        self::assertSame(200, (int) $reconciled['reversed_total']);

        $reversals = $this->reversalLedgerRows($payoutUuid);
        self::assertCount(1, $reversals);
        self::assertSame(200, (int) $reversals[0]['amount'], 'payout_reversal must be POSITIVE.');
        self::assertSame("{$payoutUuid}:payout_reversal:200", $reversals[0]['idempotency_key']);

        $balance = $this->balances->balance($this->context, self::TENANT, self::SELLER, 'USD');
        self::assertSame(200, $balance['available'], 'available must be restored by the delta.');
        self::assertSame(self::AMOUNT - 200, $balance['paid_out'], 'paid_out must be reduced by the delta.');
    }

    public function testFullReversalAfterPartialPostsOnlyTheRemainingDeltaAndReachesReversed(): void
    {
        $payout = $this->seedPaidPayout();
        $payoutUuid = (string) $payout['uuid'];

        // First: a partial reversal of 200.
        $this->makeService(new ReversalFakeCollector(statusQueue: [new PayoutStatusResult(PayoutStatusResult::PAID, 200)]))
            ->reconcile($this->context, self::TENANT, $this->row($payoutUuid));

        // Then: the cumulative reversedAmount reaches the full payout amount.
        $collector = new ReversalFakeCollector(
            statusQueue: [new PayoutStatusResult(PayoutStatusResult::REVERSED, self::AMOUNT)]
        );
        $reconciled = $this->makeService($collector)->reconcile($this->context, self::TENANT, $this->row($payoutUuid));

        self::assertSame('reversed', $reconciled['status']);
        self::assertSame(self::AMOUNT, (int) $reconciled['reversed_total']);

        $reversals = $this->reversalLedgerRows($payoutUuid);
        self::assertCount(2, $reversals, 'the second reconcile must post ONLY the unseen delta -- a NEW row, not a rewrite.');
        self::assertSame(200, (int) $reversals[0]['amount']);
        self::assertSame(self::AMOUNT - 200, (int) $reversals[1]['amount'], 'the second post is the delta (800), not the full 1000.');
        self::assertSame("{$payoutUuid}:payout_reversal:" . self::AMOUNT, $reversals[1]['idempotency_key']);

        $balance = $this->balances->balance($this->context, self::TENANT, self::SELLER, 'USD');
        self::assertSame(self::AMOUNT, $balance['available'], 'a fully reversed payout restores available in full.');
        self::assertSame(0, $balance['paid_out'], 'paid_out must be back to 0.');
    }

    // -----------------------------------------------------------------
    // Replay: the SAME cumulative reversedAmount posts nothing (idempotent).
    // -----------------------------------------------------------------

    public function testReplayOfTheSameCumulativeReversedAmountPostsNothing(): void
    {
        $payout = $this->seedPaidPayout();
        $payoutUuid = (string) $payout['uuid'];

        $this->makeService(new ReversalFakeCollector(statusQueue: [new PayoutStatusResult(PayoutStatusResult::PAID, 300)]))
            ->reconcile($this->context, self::TENANT, $this->row($payoutUuid));
        self::assertCount(1, $this->reversalLedgerRows($payoutUuid));

        // A replayed observation of the SAME cumulative value (e.g. a re-run sweep before the
        // provider reports anything new).
        $reconciled = $this->makeService(new ReversalFakeCollector(statusQueue: [new PayoutStatusResult(PayoutStatusResult::PAID, 300)]))
            ->reconcile($this->context, self::TENANT, $this->row($payoutUuid));

        self::assertSame('paid', $reconciled['status']);
        self::assertSame(300, (int) $reconciled['reversed_total']);
        self::assertCount(1, $this->reversalLedgerRows($payoutUuid), 'a replay must never post a second entry.');

        $balance = $this->balances->balance($this->context, self::TENANT, self::SELLER, 'USD');
        self::assertSame(300, $balance['available']);
        self::assertSame(self::AMOUNT - 300, $balance['paid_out']);
    }

    // -----------------------------------------------------------------
    // Paid regression: never a ledger change, never a release.
    // -----------------------------------------------------------------

    public function testProviderStatusRegressionAfterPaidIsAnIntegrityFindingWithNoLedgerChange(): void
    {
        $payout = $this->seedPaidPayout();
        $payoutUuid = (string) $payout['uuid'];

        $collector = new ReversalFakeCollector(
            statusQueue: [new PayoutStatusResult(PayoutStatusResult::RETRYABLE_FAILURE, 0, null, 'declined', 'declined')]
        );
        $reconciled = $this->makeService($collector)->reconcile($this->context, self::TENANT, $this->row($payoutUuid));

        self::assertSame('paid', $reconciled['status'], 'a provider regression must never change status.');
        self::assertSame(0, (int) $reconciled['reversed_total']);
        self::assertSame([], $this->reversalLedgerRows($payoutUuid));

        $balance = $this->balances->balance($this->context, self::TENANT, self::SELLER, 'USD');
        self::assertSame(0, $balance['available']);
        self::assertSame(self::AMOUNT, $balance['paid_out'], 'no release, no compensating post.');
    }

    public function testReversedAmountRegressingBelowRecordedTotalIsAnIntegrityFinding(): void
    {
        $payout = $this->seedPaidPayout();
        $payoutUuid = (string) $payout['uuid'];

        $this->makeService(new ReversalFakeCollector(statusQueue: [new PayoutStatusResult(PayoutStatusResult::PAID, 500)]))
            ->reconcile($this->context, self::TENANT, $this->row($payoutUuid));
        self::assertSame(500, (int) $this->row($payoutUuid)['reversed_total']);

        // A regression: the provider now reports LESS reversed than Commerce already recorded.
        $collector = new ReversalFakeCollector(statusQueue: [new PayoutStatusResult(PayoutStatusResult::PAID, 300)]);
        $reconciled = $this->makeService($collector)->reconcile($this->context, self::TENANT, $this->row($payoutUuid));

        self::assertSame('paid', $reconciled['status']);
        self::assertSame(500, (int) $reconciled['reversed_total'], 'the recorded total must never regress.');
        self::assertCount(1, $this->reversalLedgerRows($payoutUuid), 'no compensating/negative post.');

        $balance = $this->balances->balance($this->context, self::TENANT, self::SELLER, 'USD');
        self::assertSame(500, $balance['available']);
        self::assertSame(self::AMOUNT - 500, $balance['paid_out']);
    }

    public function testReversedAmountExceedingThePayoutAmountIsAnIntegrityFinding(): void
    {
        $payout = $this->seedPaidPayout();
        $payoutUuid = (string) $payout['uuid'];

        $collector = new ReversalFakeCollector(
            statusQueue: [new PayoutStatusResult(PayoutStatusResult::PAID, self::AMOUNT + 500)]
        );
        $reconciled = $this->makeService($collector)->reconcile($this->context, self::TENANT, $this->row($payoutUuid));

        self::assertSame('paid', $reconciled['status']);
        self::assertSame(0, (int) $reconciled['reversed_total']);
        self::assertSame([], $this->reversalLedgerRows($payoutUuid));

        $balance = $this->balances->balance($this->context, self::TENANT, self::SELLER, 'USD');
        self::assertSame(0, $balance['available']);
        self::assertSame(self::AMOUNT, $balance['paid_out']);
    }
}

/**
 * Scripted fake payout collector for the reversal suite -- distinct name from every other
 * fake collector in this namespace. Only `status()` is exercised by the reconcile calls
 * under test; `transfer()` is scripted separately (a single PAID result) purely to get a
 * payout into `status=paid` via the ordinary saga before reconciling it.
 */
final class ReversalFakeCollector implements PayoutCollector
{
    /**
     * @param list<PayoutResult|\Throwable> $transferQueue
     * @param list<PayoutStatusResult|\Throwable> $statusQueue
     */
    public function __construct(
        private array $transferQueue = [],
        private array $statusQueue = []
    ) {
    }

    public function transfer(
        ApplicationContext $context,
        PayoutDestination $destination,
        PayoutRequest $request
    ): PayoutResult {
        if ($this->transferQueue === []) {
            throw new \RuntimeException('ReversalFakeCollector transfer queue exhausted.');
        }

        $next = array_shift($this->transferQueue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function status(
        ApplicationContext $context,
        PayoutDestination $destination,
        string $idempotencyKey
    ): PayoutStatusResult {
        if ($this->statusQueue === []) {
            throw new \RuntimeException('ReversalFakeCollector status queue exhausted.');
        }

        $next = array_shift($this->statusQueue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function inspectDestination(
        ApplicationContext $context,
        PayoutDestination $destination
    ): DestinationStatus {
        throw new \LogicException('ReversalFakeCollector::inspectDestination() is not exercised by this suite.');
    }
}
