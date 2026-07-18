<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Console\PayoutsReconcileSweepCommand;
use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutAccountRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutAccountService;
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
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `PayoutService::retry()`/`reconcile()` (design spec §2.6, MV4 Task 9): the bounded-retry
 * CAS ({@see PayoutRepository::claimRetryableForAttempt()}), max-attempt terminalization,
 * and reconcile-before-retry for an ambiguous (UNKNOWN) attempt -- plus the reconcile
 * sweep's own due-selection filter ({@see PayoutRepository::dueForReconcile()}).
 */
final class PayoutRetryReconcileTest extends CommerceTestCase
{
    private const TENANT = '';
    private const SELLER = 'sellerRETRY01';
    private const ACTOR = 'operatorRETRY1';
    private const PROVIDER = 'default';

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
            'uuid' => 'acctRETRY0001',
            'tenant_uuid' => self::TENANT,
            'seller_uuid' => self::SELLER,
            'provider' => self::PROVIDER,
            'account_ref' => 'acct-ref-retry',
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
            new PayoutAccountService(new PayoutAccountRepository())
        );
    }

    private function seedAvailable(int $amount, string $currency = 'USD'): void
    {
        $this->ledger->post($this->context, self::TENANT, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller(self::SELLER),
            'seller_uuid' => self::SELLER,
            'currency' => $currency,
            'entry_type' => 'sale_credit',
            'amount' => $amount,
            'order_uuid' => 'orderRETRYSD1',
            'idempotency_key' => 'orderRETRYSD1:' . self::SELLER . ':sale_credit',
        ]);
    }

    /** Forces a timing column into the past so a CAS/due-select predicate treats it as due. */
    private function forcePastDue(string $payoutUuid, string $column): void
    {
        $this->connection->table('commerce_payouts')
            ->where('uuid', '=', $payoutUuid)
            ->update([$column => gmdate('Y-m-d H:i:s', time() - 3600)]);
    }

    private function row(string $payoutUuid): array
    {
        return $this->connection->table('commerce_payouts')->where('uuid', '=', $payoutUuid)->first();
    }

    // -----------------------------------------------------------------
    // 1. Retry CAS increments attempt_count exactly once immediately before I/O;
    //    the new attempt uses key attempt:{n+1}.
    // -----------------------------------------------------------------

    public function testRetryCasIncrementsAttemptCountExactlyOnceBeforeIoAndUsesNextAttemptKey(): void
    {
        $this->seedAvailable(1000);
        $seenAttemptCountAtCallTime = [];
        $collector = new RetryReconcileFakeCollector(
            transferQueue: [
                new PayoutResult(PayoutResult::RETRYABLE_FAILURE, null, 'insufficient_funds', 'try later'),
                new PayoutResult(PayoutResult::PAID, 'prov-retry-2'),
            ],
            onTransfer: function () use (&$seenAttemptCountAtCallTime): void {
                $row = $this->connection->table('commerce_payouts')
                    ->where('seller_uuid', '=', self::SELLER)
                    ->first();
                $seenAttemptCountAtCallTime[] = (int) $row['attempt_count'];
            }
        );
        $service = $this->makeService($collector);

        $payout = $service->execute($this->context, self::TENANT, self::SELLER, 'USD', 300, self::ACTOR);
        self::assertSame('failed', $payout['status']);
        self::assertTrue((bool) $payout['retryable']);
        self::assertSame(1, (int) $payout['attempt_count']);

        $this->forcePastDue((string) $payout['uuid'], 'next_attempt_at');

        $result = $service->retry($this->context, self::TENANT, (string) $payout['uuid']);
        self::assertNotNull($result);
        self::assertSame('paid', $result['status']);
        self::assertSame(2, (int) $result['attempt_count'], 'the CAS must increment attempt_count exactly once.');

        // The FIRST transfer() call (from execute()) saw attempt_count still at 1; the SECOND
        // (from retry()) saw it ALREADY incremented to 2 -- proving the CAS bump happens BEFORE
        // any provider I/O, not after.
        self::assertSame([1, 2], $seenAttemptCountAtCallTime);

        self::assertCount(2, $collector->transferCalls);
        self::assertSame($payout['uuid'] . ':attempt:1', $collector->transferCalls[0]['idempotencyKey']);
        self::assertSame($payout['uuid'] . ':attempt:2', $collector->transferCalls[1]['idempotencyKey']);
    }

    // -----------------------------------------------------------------
    // 2. Crash-after-claim (claim succeeds, execute never finalizes) is recovered by the
    //    reconcile sweep via the watchdog, NOT a blind second retry.
    // -----------------------------------------------------------------

    public function testCrashAfterRetryClaimIsRecoveredByReconcileNotABlindSecondRetry(): void
    {
        $this->seedAvailable(1000);
        $collector = new RetryReconcileFakeCollector(
            transferQueue: [
                new PayoutResult(PayoutResult::RETRYABLE_FAILURE, null, 'card_declined', 'declined'),
                new \RuntimeException('connection reset mid-attempt'),
            ],
            statusQueue: [new PayoutStatusResult(PayoutStatusResult::PAID, 0, 'prov-recovered')]
        );
        $service = $this->makeService($collector);

        $payout = $service->execute($this->context, self::TENANT, self::SELLER, 'USD', 400, self::ACTOR);
        self::assertSame('failed', $payout['status']);
        $this->forcePastDue((string) $payout['uuid'], 'next_attempt_at');

        $threw = null;
        try {
            $service->retry($this->context, self::TENANT, (string) $payout['uuid']);
        } catch (PayoutOutcomeUnknownException $e) {
            $threw = $e;
        }
        self::assertInstanceOf(PayoutOutcomeUnknownException::class, $threw);

        $row = $this->row((string) $payout['uuid']);
        self::assertSame('pending', $row['status'], 'the claim-before-I/O CAS already moved the row out of failed.');
        self::assertSame(2, (int) $row['attempt_count']);
        self::assertNotNull($row['next_reconcile_at'], 'the watchdog must be armed for the reconcile sweep.');

        // Not a blind second retry: the row is no longer status=failed, so the retry sweep's
        // own due-selection can never re-claim it.
        $maxAttempts = (int) config($this->context, 'commerce.marketplace.payouts.max_attempts', 5);
        self::assertSame([], $this->payouts->dueForRetry($this->context, self::TENANT, $maxAttempts));
        self::assertNull(
            $service->retry($this->context, self::TENANT, (string) $payout['uuid']),
            'a payout that is not status=failed can never be re-claimed by retry().'
        );

        // Recovered by the reconcile sweep instead.
        $this->forcePastDue((string) $payout['uuid'], 'next_reconcile_at');
        $due = $this->payouts->dueForReconcile($this->context, self::TENANT);
        self::assertCount(1, $due);
        self::assertSame((string) $payout['uuid'], (string) $due[0]['uuid']);

        $reconciled = $service->reconcile($this->context, self::TENANT, $due[0]);
        self::assertSame('paid', $reconciled['status']);
        self::assertSame($payout['uuid'] . ':attempt:2', $collector->statusCalls[0]['idempotencyKey']);
    }

    // -----------------------------------------------------------------
    // 3. Max-attempt exhaustion (final retryable) terminalizes + releases hold; a later
    //    attempt is NOT created.
    // -----------------------------------------------------------------

    public function testMaxAttemptExhaustionTerminalizesReleasesHoldAndPreventsFurtherRetry(): void
    {
        $this->context->overrideConfig('commerce.marketplace.payouts.max_attempts', 2);
        $this->seedAvailable(1000);
        $collector = new RetryReconcileFakeCollector(transferQueue: [
            new PayoutResult(PayoutResult::RETRYABLE_FAILURE, null, 'card_declined', 'declined'),
            new PayoutResult(PayoutResult::RETRYABLE_FAILURE, null, 'card_declined', 'declined again'),
        ]);
        $service = $this->makeService($collector);

        $payout = $service->execute($this->context, self::TENANT, self::SELLER, 'USD', 500, self::ACTOR);
        self::assertTrue((bool) $payout['retryable']);
        $this->forcePastDue((string) $payout['uuid'], 'next_attempt_at');

        $result = $service->retry($this->context, self::TENANT, (string) $payout['uuid']);
        self::assertNotNull($result);
        self::assertSame('failed', $result['status']);
        self::assertFalse((bool) $result['retryable'], 'the final allowed attempt must terminalize.');
        self::assertSame(2, (int) $result['attempt_count']);
        self::assertNull($result['next_attempt_at']);

        $balance = $this->balances->balance($this->context, self::TENANT, self::SELLER, 'USD');
        self::assertSame(1000, $balance['available'], 'the hold must be fully released on terminalization.');
        self::assertSame(0, $balance['pending']);

        // A later attempt is NOT created: the row is no longer retryable, so both the sweep's
        // due-selection and a direct retry() call must treat it as exhausted.
        self::assertSame([], $this->payouts->dueForRetry($this->context, self::TENANT, 2));
        self::assertNull($service->retry($this->context, self::TENANT, (string) $payout['uuid']));
        self::assertCount(2, $collector->transferCalls, 'no third transfer() call must ever be made.');
    }

    // -----------------------------------------------------------------
    // 4. Reconcile-before-retry for an UNKNOWN attempt: reconcile resolves attempt n to a
    //    definite state; no n+1 is minted while UNKNOWN/PENDING.
    // -----------------------------------------------------------------

    public function testReconcileResolvesAnUnknownAttemptWithoutMintingANewAttempt(): void
    {
        $this->seedAvailable(1000);
        $collector = new RetryReconcileFakeCollector(
            transferQueue: [new \RuntimeException('gateway timeout')],
            statusQueue: [new PayoutStatusResult(PayoutStatusResult::PAID, 0, 'prov-unknown-resolved')]
        );
        $service = $this->makeService($collector);

        $threw = null;
        try {
            $service->execute($this->context, self::TENANT, self::SELLER, 'USD', 600, self::ACTOR);
        } catch (PayoutOutcomeUnknownException $e) {
            $threw = $e;
        }
        self::assertInstanceOf(PayoutOutcomeUnknownException::class, $threw);

        $row = $this->connection->table('commerce_payouts')->where('seller_uuid', '=', self::SELLER)->first();
        self::assertSame('pending', $row['status']);
        self::assertSame(1, (int) $row['attempt_count']);

        // Not eligible for the retry sweep at all -- status is not 'failed'.
        self::assertSame([], $this->payouts->dueForRetry($this->context, self::TENANT, 5));

        $reconciled = $service->reconcile($this->context, self::TENANT, $row);
        self::assertSame('paid', $reconciled['status']);
        self::assertSame('prov-unknown-resolved', $reconciled['provider_ref']);
        self::assertSame(1, (int) $reconciled['attempt_count'], 'resolving a pending attempt never mints n+1.');
        self::assertSame($row['uuid'] . ':attempt:1', $collector->statusCalls[0]['idempotencyKey']);

        // Now paid, not failed -- still no way to mint a further attempt via retry().
        self::assertNull($service->retry($this->context, self::TENANT, (string) $row['uuid']));
    }

    // -----------------------------------------------------------------
    // 4b. A due `pending` payout whose status() call itself returns UNKNOWN (a legitimate,
    //     still-ambiguous provider answer, distinct from test 4's PAID resolution) must NOT
    //     throw out of the sweep: the row stays pending, the hold stays intact, attempt_count
    //     never advances, and next_reconcile_at is RE-ARMED to the future so a second
    //     immediate sweep does not hot-loop on it.
    // -----------------------------------------------------------------

    public function testPendingReconcileOfAnUnknownProviderStatusStaysPendingKeepsHoldAndRearmsWatchdog(): void
    {
        $this->seedAvailable(1000);
        $collector = new RetryReconcileFakeCollector(
            transferQueue: [new \RuntimeException('gateway timeout')],
            statusQueue: [new PayoutStatusResult(PayoutStatusResult::UNKNOWN)]
        );
        $service = $this->makeService($collector);

        $threw = null;
        try {
            $service->execute($this->context, self::TENANT, self::SELLER, 'USD', 700, self::ACTOR);
        } catch (PayoutOutcomeUnknownException $e) {
            $threw = $e;
        }
        self::assertInstanceOf(PayoutOutcomeUnknownException::class, $threw);

        $seeded = $this->connection->table('commerce_payouts')->where('seller_uuid', '=', self::SELLER)->first();
        $payoutUuid = (string) $seeded['uuid'];
        self::assertSame('pending', $seeded['status']);
        self::assertSame(1, (int) $seeded['attempt_count']);
        $this->forcePastDue($payoutUuid, 'next_reconcile_at');

        // Run the ACTUAL reconcile sweep command -- reproduces the hot-loop scenario exactly:
        // a past-due pending row selected by dueForReconcile() and fed through reconcile().
        $this->bind(PayoutRepository::class, $this->payouts);
        $this->bind(PayoutService::class, $service);
        $command = new PayoutsReconcileSweepCommand($this->context->getContainer(), $this->context);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode, 'an UNKNOWN provider status must not fail the reconcile sweep.');

        $after = $this->row($payoutUuid);
        self::assertSame('pending', $after['status'], 'an UNKNOWN status must leave the row pending.');
        self::assertSame(1, (int) $after['attempt_count'], 'an ambiguous outcome must never advance the attempt.');
        self::assertNotNull($after['next_reconcile_at'], 'the watchdog must be re-armed, not left null.');
        self::assertGreaterThan(
            time(),
            strtotime((string) $after['next_reconcile_at']),
            'the watchdog must be re-armed to a FUTURE time, not left past-due.'
        );

        $balance = $this->balances->balance($this->context, self::TENANT, self::SELLER, 'USD');
        self::assertSame(300, $balance['available'], 'the 700 hold must remain fully intact.');
        self::assertSame(700, $balance['pending']);

        // A second immediate sweep must NOT re-select the row -- proves the watchdog re-arm
        // actually prevents the hot loop, not just that this one call didn't throw.
        self::assertSame(
            [],
            $this->payouts->dueForReconcile($this->context, self::TENANT),
            'the re-armed watchdog must prevent immediate re-selection by a second sweep.'
        );
    }

    // -----------------------------------------------------------------
    // 5. Reconcile sweep due-selection: skips pending/paid rows whose next_reconcile_at is in
    //    the future; a NULL next_reconcile_at pending row IS treated as immediately due.
    // -----------------------------------------------------------------

    public function testDueForReconcileSkipsFutureRowsAndTreatsNullPendingAsImmediatelyDue(): void
    {
        $future = gmdate('Y-m-d H:i:s', time() + 3600);
        $past = gmdate('Y-m-d H:i:s', time() - 3600);

        $this->seedPayoutRow('payoutPENDFUT', 'pending', $future);
        $this->seedPayoutRow('payoutPENDNUL', 'pending', null);
        $this->seedPayoutRow('payoutPAIDFUT', 'paid', $future);
        $this->seedPayoutRow('payoutPAIDPST', 'paid', $past);
        // A failed+retryable row's own watchdog must never surface here -- it belongs to the
        // retry sweep exclusively.
        $this->seedPayoutRow('payoutFAILPST', 'failed', $past, retryable: true);
        $this->seedPayoutRow('payoutREVRPST', 'reversed', $past);

        $due = $this->payouts->dueForReconcile($this->context, self::TENANT);
        $uuids = array_map(static fn (array $row): string => (string) $row['uuid'], $due);
        sort($uuids);

        self::assertSame(['payoutPAIDPST', 'payoutPENDNUL'], $uuids);
    }

    private function seedPayoutRow(string $uuid, string $status, ?string $nextReconcileAt, bool $retryable = false): void
    {
        $this->connection->table('commerce_payouts')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT,
            'seller_uuid' => self::SELLER,
            'currency' => 'USD',
            'amount' => 100,
            'idempotency_key' => $uuid,
            'status' => $status,
            'method' => 'provider',
            'provider' => self::PROVIDER,
            'destination_ref' => 'acct-ref-retry',
            'retryable' => $retryable,
            'attempt_count' => 1,
            'next_reconcile_at' => $nextReconcileAt,
        ]);
    }
}

/**
 * Scripted fake payout collector for the retry/reconcile suite -- distinct name from
 * {@see FakePayoutCollector} (PayoutSagaTest) and {@see ReadinessFakeCollector}
 * (PayoutAccountReadinessTest), same namespace. Scripts BOTH `transfer()` (a queue of
 * PayoutResult|Throwable) and `status()` (a queue of PayoutStatusResult|Throwable) --
 * every prior fake in this suite only ever needed one or the other.
 */
final class RetryReconcileFakeCollector implements PayoutCollector
{
    /** @var list<array{idempotencyKey: string}> */
    public array $transferCalls = [];

    /** @var list<array{idempotencyKey: string}> */
    public array $statusCalls = [];

    /** @var (callable(ApplicationContext, PayoutDestination, PayoutRequest): void)|null */
    private $onTransfer;

    /**
     * @param list<PayoutResult|\Throwable> $transferQueue
     * @param list<PayoutStatusResult|\Throwable> $statusQueue
     * @param (callable(ApplicationContext, PayoutDestination, PayoutRequest): void)|null $onTransfer
     */
    public function __construct(
        private array $transferQueue = [],
        private array $statusQueue = [],
        ?callable $onTransfer = null
    ) {
        $this->onTransfer = $onTransfer;
    }

    public function transfer(
        ApplicationContext $context,
        PayoutDestination $destination,
        PayoutRequest $request
    ): PayoutResult {
        $this->transferCalls[] = ['idempotencyKey' => $request->idempotencyKey];

        if ($this->onTransfer !== null) {
            ($this->onTransfer)($context, $destination, $request);
        }

        if ($this->transferQueue === []) {
            throw new \RuntimeException('RetryReconcileFakeCollector transfer queue exhausted.');
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
        $this->statusCalls[] = ['idempotencyKey' => $idempotencyKey];

        if ($this->statusQueue === []) {
            throw new \RuntimeException('RetryReconcileFakeCollector status queue exhausted.');
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
        throw new \LogicException('RetryReconcileFakeCollector::inspectDestination() is not exercised by this suite.');
    }
}
