<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutAccountRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutAccountService;
use Glueful\Extensions\Commerce\Marketplace\PayoutException;
use Glueful\Extensions\Commerce\Marketplace\PayoutOutcomeUnknownException;
use Glueful\Extensions\Commerce\Marketplace\PayoutRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutService;
use Glueful\Extensions\Commerce\Marketplace\SellerBalanceService;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Payments\DestinationStatus;
use Glueful\Extensions\Contracts\Payments\PayoutCollector;
use Glueful\Extensions\Contracts\Payments\PayoutDestination;
use Glueful\Extensions\Contracts\Payments\PayoutRequest;
use Glueful\Extensions\Contracts\Payments\PayoutResult;
use Glueful\Extensions\Contracts\Payments\PayoutStatusResult;

/**
 * `PayoutService::execute()` (design spec §2.3, MV4 Task 7): the provider-payout
 * saga -- reserve (claim the ledger lock, re-read available, insert `pending`,
 * post `reserve_hold`) -> execute (`PayoutCollector::transfer()`, strictly
 * outside any transaction) -> finalize (`PayoutRepository::claimPending()` CAS,
 * then apply the `PayoutResult`). Mirrors
 * {@see \Glueful\Extensions\Commerce\Tests\Integration\Refunds\GatewayRefundTest}'s
 * structure for the refund gateway saga.
 */
final class PayoutSagaTest extends CommerceTestCase
{
    private const TENANT = '';
    private const SELLER = 'sellerSAGA001';
    private const ACTOR = 'operatorSAGA1';
    // Task 8: the readiness gate resolves this SAME literal whenever
    // `commerce.marketplace.payouts.default_provider` is unconfigured (see
    // PayoutService::defaultProvider()) -- every execute() test below needs a
    // `ready` commerce_seller_payout_accounts row under this exact provider id.
    private const PROVIDER = 'default';

    private LedgerRepository $ledger;
    private PayoutRepository $payouts;
    private PayoutAccountRepository $payoutAccounts;
    private SellerBalanceService $balances;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = new LedgerRepository();
        $this->payouts = new PayoutRepository();
        $this->payoutAccounts = new PayoutAccountRepository();
        $this->balances = new SellerBalanceService($this->ledger);

        // Task 8 (design spec §2.7): execute()'s reserve now refuses (422) unless a `ready`
        // payout destination exists BEFORE any lock claim/row insert/hold post. Every saga test
        // below exercises the reserve->execute->finalize saga ITSELF, not the readiness gate --
        // so a ready destination is seeded up front here, once, exactly like `seedAvailable()`
        // seeds ledger balance for every test that needs one.
        $this->connection->table('commerce_seller_payout_accounts')->insert([
            'uuid' => 'acctSAGA0001',
            'tenant_uuid' => self::TENANT,
            'seller_uuid' => self::SELLER,
            'provider' => self::PROVIDER,
            'account_ref' => 'acct-ref-saga',
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
            null,
            $collector,
            new PayoutAccountService($this->payoutAccounts)
        );
    }

    private function seedAvailable(string $seller, int $amount, string $currency = 'USD'): void
    {
        $this->ledger->post($this->context, self::TENANT, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller($seller),
            'seller_uuid' => $seller,
            'currency' => $currency,
            'entry_type' => 'sale_credit',
            'amount' => $amount,
            'order_uuid' => 'orderSAGASD01',
            'idempotency_key' => 'orderSAGASD01:' . $seller . ':sale_credit',
        ]);
    }

    private function countLedger(string $payoutUuid, string $entryType): int
    {
        return $this->connection->table('commerce_marketplace_ledger')
            ->where('payout_uuid', '=', $payoutUuid)
            ->where('entry_type', '=', $entryType)
            ->count();
    }

    // -----------------------------------------------------------------
    // PAID
    // -----------------------------------------------------------------

    public function testPaidResultPostsReserveReleaseAndPayoutDebitWithExactBalances(): void
    {
        $this->seedAvailable(self::SELLER, 5000);
        $collector = new FakePayoutCollector([new PayoutResult(PayoutResult::PAID, 'prov-1')]);
        $service = $this->makeService($collector);

        $payout = $service->execute($this->context, self::TENANT, self::SELLER, 'USD', 2000, self::ACTOR);

        self::assertSame('paid', $payout['status']);
        self::assertSame('provider', $payout['method']);
        self::assertSame('prov-1', $payout['provider_ref']);
        self::assertNotNull($payout['completed_at']);

        $balance = $this->balances->balance($this->context, self::TENANT, self::SELLER, 'USD');
        self::assertSame(3000, $balance['available'], 'available must be unchanged from hold -> paid (5000 - 2000).');
        self::assertSame(0, $balance['pending']);
        self::assertSame(2000, $balance['paid_out']);

        self::assertSame(1, $this->countLedger($payout['uuid'], 'reserve_hold'));
        self::assertSame(1, $this->countLedger($payout['uuid'], 'reserve_release'));
        self::assertSame(1, $this->countLedger($payout['uuid'], 'payout_debit'));

        self::assertCount(1, $collector->calls);
        self::assertFalse(
            $collector->calls[0]['withinTransaction'],
            'The collector must never be invoked while a database transaction is open.'
        );
        self::assertSame($payout['uuid'] . ':attempt:1', $collector->calls[0]['idempotencyKey']);
    }

    // -----------------------------------------------------------------
    // PENDING
    // -----------------------------------------------------------------

    public function testPendingResultKeepsHoldAndSetsNextReconcileAt(): void
    {
        $this->seedAvailable(self::SELLER, 1000);
        $collector = new FakePayoutCollector([new PayoutResult(PayoutResult::PENDING)]);
        $service = $this->makeService($collector);

        $payout = $service->execute($this->context, self::TENANT, self::SELLER, 'USD', 400, self::ACTOR);

        self::assertSame('pending', $payout['status']);

        $balance = $this->balances->balance($this->context, self::TENANT, self::SELLER, 'USD');
        self::assertSame(600, $balance['available']);
        self::assertSame(400, $balance['pending']);

        $row = $this->payouts->findByUuid($this->context, self::TENANT, (string) $payout['uuid']);
        self::assertNotNull($row);
        self::assertNotNull($row['next_reconcile_at']);
    }

    // -----------------------------------------------------------------
    // RETRYABLE_FAILURE
    // -----------------------------------------------------------------

    public function testRetryableFailureKeepsHoldAndSchedulesNextAttemptWithoutIncrementingAttemptCount(): void
    {
        $this->seedAvailable(self::SELLER, 1000);
        $collector = new FakePayoutCollector([
            new PayoutResult(PayoutResult::RETRYABLE_FAILURE, null, 'insufficient_funds', 'try later'),
        ]);
        $service = $this->makeService($collector);

        $payout = $service->execute($this->context, self::TENANT, self::SELLER, 'USD', 300, self::ACTOR);

        self::assertSame('failed', $payout['status']);
        self::assertTrue((bool) $payout['retryable']);
        self::assertSame(1, (int) $payout['attempt_count'], 'attempt_count only advances on re-claim, never at finalize.');

        $row = $this->payouts->findByUuid($this->context, self::TENANT, (string) $payout['uuid']);
        self::assertNotNull($row);
        self::assertNotNull($row['next_attempt_at']);

        $balance = $this->balances->balance($this->context, self::TENANT, self::SELLER, 'USD');
        self::assertSame(700, $balance['available'], 'the hold stays through a retryable failure.');
        self::assertSame(300, $balance['pending']);
    }

    public function testRetryableFailureOnFinalAllowedAttemptTerminalizesAndReleasesHold(): void
    {
        $this->context->overrideConfig('commerce.marketplace.payouts.max_attempts', 1);
        $this->seedAvailable(self::SELLER, 1000);
        $collector = new FakePayoutCollector([
            new PayoutResult(PayoutResult::RETRYABLE_FAILURE, null, 'card_declined', 'declined'),
        ]);
        $service = $this->makeService($collector);

        $payout = $service->execute($this->context, self::TENANT, self::SELLER, 'USD', 400, self::ACTOR);

        self::assertSame('failed', $payout['status']);
        self::assertFalse((bool) $payout['retryable'], 'the final allowed attempt must terminalize, not stay retryable.');
        self::assertNull($payout['next_attempt_at']);

        $balance = $this->balances->balance($this->context, self::TENANT, self::SELLER, 'USD');
        self::assertSame(1000, $balance['available'], 'the hold must be released on terminalization.');
        self::assertSame(0, $balance['pending']);
    }

    // -----------------------------------------------------------------
    // TERMINAL_FAILURE
    // -----------------------------------------------------------------

    public function testTerminalFailureReleasesHoldAndMarksFailed(): void
    {
        $this->seedAvailable(self::SELLER, 1000);
        $collector = new FakePayoutCollector([
            new PayoutResult(PayoutResult::TERMINAL_FAILURE, null, 'account_closed', 'destination closed'),
        ]);
        $service = $this->makeService($collector);

        $payout = $service->execute($this->context, self::TENANT, self::SELLER, 'USD', 500, self::ACTOR);

        self::assertSame('failed', $payout['status']);
        self::assertFalse((bool) $payout['retryable']);

        $balance = $this->balances->balance($this->context, self::TENANT, self::SELLER, 'USD');
        self::assertSame(1000, $balance['available'], 'available must be fully restored.');
        self::assertSame(0, $balance['pending']);

        self::assertSame(1, $this->countLedger($payout['uuid'], 'reserve_release'));
        self::assertSame(0, $this->countLedger($payout['uuid'], 'payout_debit'));
    }

    // -----------------------------------------------------------------
    // UNKNOWN (returned result and transport throw)
    // -----------------------------------------------------------------

    public function testUnknownResultKeepsHoldStaysPendingNeverAdvancesAttemptAndRaises(): void
    {
        $this->seedAvailable(self::SELLER, 1000);
        $collector = new FakePayoutCollector([new PayoutResult(PayoutResult::UNKNOWN, null, null, 'ambiguous')]);
        $service = $this->makeService($collector);

        $threw = null;
        try {
            $service->execute($this->context, self::TENANT, self::SELLER, 'USD', 400, self::ACTOR);
        } catch (PayoutOutcomeUnknownException $e) {
            $threw = $e;
        }
        self::assertInstanceOf(PayoutOutcomeUnknownException::class, $threw);

        $row = $this->connection->table('commerce_payouts')->where('seller_uuid', '=', self::SELLER)->first();
        self::assertNotNull($row);
        self::assertSame('pending', $row['status']);
        self::assertSame(1, (int) $row['attempt_count'], 'UNKNOWN must never advance the attempt.');
        self::assertNotNull($row['next_reconcile_at']);

        $balance = $this->balances->balance($this->context, self::TENANT, self::SELLER, 'USD');
        self::assertSame(600, $balance['available'], 'the hold must never be released under UNKNOWN.');
        self::assertSame(400, $balance['pending']);
    }

    public function testTransportThrowIsTreatedAsUnknownKeepsHoldAndRaises(): void
    {
        $this->seedAvailable(self::SELLER, 1000);
        $collector = new FakePayoutCollector([new \RuntimeException('connection reset')]);
        $service = $this->makeService($collector);

        $threw = null;
        try {
            $service->execute($this->context, self::TENANT, self::SELLER, 'USD', 250, self::ACTOR);
        } catch (PayoutOutcomeUnknownException $e) {
            $threw = $e;
        }
        self::assertInstanceOf(PayoutOutcomeUnknownException::class, $threw);

        $row = $this->connection->table('commerce_payouts')->where('seller_uuid', '=', self::SELLER)->first();
        self::assertNotNull($row);
        self::assertSame('pending', $row['status']);
        self::assertSame(1, (int) $row['attempt_count']);

        $balance = $this->balances->balance($this->context, self::TENANT, self::SELLER, 'USD');
        self::assertSame(750, $balance['available']);
        self::assertSame(250, $balance['pending']);
    }

    // -----------------------------------------------------------------
    // Single-finalizer CAS
    // -----------------------------------------------------------------

    public function testSingleFinalizerCasPreventsDoublePosting(): void
    {
        $this->seedAvailable(self::SELLER, 1000);
        $collector = new FakePayoutCollector([new PayoutResult(PayoutResult::PAID, 'prov-cas')]);
        $service = $this->makeService($collector);

        $payout = $service->execute($this->context, self::TENANT, self::SELLER, 'USD', 300, self::ACTOR);
        self::assertSame('paid', $payout['status']);

        // A second finalize attempt on the same already-terminal payout must be a no-op:
        // claimPending()'s CAS only ever matches status='pending', which PayoutService::finalize()
        // relies on as its single-finalizer-wins primitive before posting anything.
        $claimedAgain = $this->payouts->claimPending(
            $this->context,
            self::TENANT,
            (string) $payout['uuid'],
            'paid',
            ['provider_ref' => 'prov-cas']
        );
        self::assertFalse($claimedAgain, 'A second finalize attempt on an already-terminal payout must not re-claim it.');

        self::assertSame(1, $this->countLedger((string) $payout['uuid'], 'reserve_release'));
        self::assertSame(1, $this->countLedger((string) $payout['uuid'], 'payout_debit'));
    }

    // -----------------------------------------------------------------
    // Process-death recovery via the reserve-time watchdog
    // -----------------------------------------------------------------

    public function testProcessDeathAfterReserveIsRecoverableViaTheInitialReconcileWatchdog(): void
    {
        $this->seedAvailable(self::SELLER, 1000);

        $capturedRow = null;
        $collector = new FakePayoutCollector(
            [new PayoutResult(PayoutResult::PAID, 'prov-death')],
            function () use (&$capturedRow): void {
                // Snapshots the row exactly as it stood the moment transfer() was invoked --
                // i.e. strictly after reserve()'s transaction committed and strictly before
                // finalize() has had any chance to run. This simulates "the process died right
                // after the provider call started" without needing a second public entry point.
                $capturedRow = $this->connection->table('commerce_payouts')
                    ->where('seller_uuid', '=', self::SELLER)
                    ->first();
            }
        );
        $service = $this->makeService($collector);

        $service->execute($this->context, self::TENANT, self::SELLER, 'USD', 350, self::ACTOR);

        self::assertNotNull($capturedRow, 'transfer() must run only after the reserve transaction has committed.');
        self::assertSame('pending', $capturedRow['status']);
        self::assertSame('provider', $capturedRow['method']);
        self::assertSame(1, (int) $capturedRow['attempt_count']);
        self::assertNotNull($capturedRow['last_attempt_at']);
        self::assertNotNull(
            $capturedRow['next_reconcile_at'],
            'the RESERVE step must stamp a watchdog before any provider I/O -- this is what lets a '
                . 'reconcile sweep recover a payout whose process died between execute() and finalize().'
        );
    }

    // -----------------------------------------------------------------
    // Reserve refusals
    // -----------------------------------------------------------------

    public function testReserveRefusesWhenAvailableIsBelowAmountAndPostsNothing(): void
    {
        $this->seedAvailable(self::SELLER, 100);
        $collector = new FakePayoutCollector([]);
        $service = $this->makeService($collector);

        $threw = null;
        try {
            $service->execute($this->context, self::TENANT, self::SELLER, 'USD', 200, self::ACTOR);
        } catch (PayoutException $e) {
            $threw = $e;
        }
        self::assertInstanceOf(PayoutException::class, $threw);

        self::assertSame(0, $this->connection->table('commerce_payouts')->count());
        self::assertSame(
            0,
            $this->connection->table('commerce_marketplace_ledger')
                ->where('entry_type', '=', 'reserve_hold')->count()
        );
        self::assertCount(0, $collector->calls, 'the collector must never be called when the reserve refuses.');
    }

    public function testProviderUnboundRefusesBeforeReservingWithPayoutException(): void
    {
        $this->seedAvailable(self::SELLER, 1000);
        $service = $this->makeService(null);

        $threw = null;
        try {
            $service->execute($this->context, self::TENANT, self::SELLER, 'USD', 200, self::ACTOR);
        } catch (PayoutException $e) {
            $threw = $e;
        }
        self::assertInstanceOf(PayoutException::class, $threw);

        self::assertSame(0, $this->connection->table('commerce_payouts')->count());
        self::assertSame(
            0,
            $this->connection->table('commerce_marketplace_ledger')
                ->where('entry_type', '=', 'reserve_hold')->count()
        );
    }

    // -----------------------------------------------------------------
    // Manual record() regression guard (folded schema columns)
    // -----------------------------------------------------------------

    public function testManualRecordStillWritesMethodManualStatusPaidAndCompletedAt(): void
    {
        $this->seedAvailable(self::SELLER, 1000);
        $service = $this->makeService(null);

        $payout = $service->record(
            $this->context,
            self::TENANT,
            self::SELLER,
            'USD',
            500,
            'idem-manual-regression',
            'ext-manual-1',
            null,
            self::ACTOR
        );

        self::assertSame('manual', $payout['method']);
        self::assertSame('paid', $payout['status']);
        self::assertNotNull($payout['completed_at']);

        $persisted = $this->payouts->findByUuid($this->context, self::TENANT, (string) $payout['uuid']);
        self::assertNotNull($persisted);
        self::assertSame('manual', $persisted['method']);
        self::assertSame('paid', $persisted['status']);
        self::assertNotNull($persisted['completed_at']);
    }
}

/**
 * Scripted fake payout collector. Constructed with a queue of PayoutResult|Throwable
 * outcomes consumed in order by transfer(); records every call's idempotency key,
 * destination, and whether a database transaction was open at call time. An optional
 * $beforeReturn hook runs after recording the call but before consuming the queue --
 * used to snapshot database state at the exact moment transfer() executes (simulating
 * a process death between the reserve commit and finalize).
 */
final class FakePayoutCollector implements PayoutCollector
{
    /** @var list<array{idempotencyKey: string, provider: string, accountRef: string, withinTransaction: bool}> */
    public array $calls = [];

    /** @var (callable(ApplicationContext, PayoutDestination, PayoutRequest): void)|null */
    private $beforeReturn;

    /**
     * @param list<PayoutResult|\Throwable> $queue
     * @param (callable(ApplicationContext, PayoutDestination, PayoutRequest): void)|null $beforeReturn
     */
    public function __construct(private array $queue, ?callable $beforeReturn = null)
    {
        $this->beforeReturn = $beforeReturn;
    }

    public function transfer(
        ApplicationContext $context,
        PayoutDestination $destination,
        PayoutRequest $request
    ): PayoutResult {
        $this->calls[] = [
            'idempotencyKey' => $request->idempotencyKey,
            'provider' => $destination->provider,
            'accountRef' => $destination->accountRef,
            'withinTransaction' => db($context)->withinTransaction(),
        ];

        if ($this->beforeReturn !== null) {
            ($this->beforeReturn)($context, $destination, $request);
        }

        if ($this->queue === []) {
            throw new \RuntimeException('FakePayoutCollector queue exhausted.');
        }

        $next = array_shift($this->queue);
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
        throw new \LogicException('FakePayoutCollector::status() is not exercised by the Task 7 saga.');
    }

    public function inspectDestination(
        ApplicationContext $context,
        PayoutDestination $destination
    ): DestinationStatus {
        throw new \LogicException('FakePayoutCollector::inspectDestination() is not exercised by Task 7.');
    }
}
