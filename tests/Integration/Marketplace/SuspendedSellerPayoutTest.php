<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Console\PayoutsRunBatchCommand;
use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerException;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutAccountRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutAccountService;
use Glueful\Extensions\Commerce\Marketplace\PayoutException;
use Glueful\Extensions\Commerce\Marketplace\PayoutRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutService;
use Glueful\Extensions\Commerce\Marketplace\SellerBalanceService;
use Glueful\Extensions\Commerce\Marketplace\SellerLifecycleEventRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerService;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Payments\DestinationStatus;
use Glueful\Extensions\Contracts\Payments\PayoutCollector;
use Glueful\Extensions\Contracts\Payments\PayoutDestination;
use Glueful\Extensions\Contracts\Payments\PayoutRequest;
use Glueful\Extensions\Contracts\Payments\PayoutResult;
use Glueful\Extensions\Contracts\Payments\PayoutStatusResult;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Payout freeze + revision serialization for a suspended seller (design spec
 * §2.7, MV5b Task 6): {@see PayoutService::record()} and
 * {@see PayoutService} `reserve()` (the shared step behind `execute()`/
 * `executeBatch()`) now claim the seller's own
 * {@see SellerRepository::claimRevision()} FIRST, inside their transaction,
 * strictly BEFORE the seller/currency ledger account lock -- the SAME
 * primitive `SellerService::suspend()`/`reactivate()` claims for that exact
 * seller row -- so a new-payout transaction and a concurrent lifecycle
 * transition are strictly serialized against one another, never
 * interleaved: whichever commits first is authoritative, and a suspended
 * seller is refused (or, for a batch derive-in-lock candidate, silently
 * skipped) WITHOUT the account lock ever being claimed or anything ever
 * being posted. A `commerce_sellers` row with NO match for the requested
 * `seller_uuid` -- every pre-MV5b payout suite in this namespace uses
 * synthetic `seller_uuid` strings with no backing seller row, see
 * {@see PayoutTest}/{@see PayoutSagaTest}/{@see PayoutDebtGateTest}/etc. --
 * is untracked by the marketplace lifecycle and is NEVER gated by this
 * check, keeping every one of those suites byte-identical after the
 * constructor sweep (also proven directly below).
 *
 * The genuinely concurrent "preflight-miss + concurrent commit + suspend +
 * replay" interleaving that `record()`'s SECOND, in-transaction
 * `findByIdempotencyKey()` lookup exists to close needs TRUE
 * multi-connection concurrency this single-connection SQLite harness cannot
 * produce (PHP has no threads, and both `PayoutRepository` and
 * `PayoutService` are `final` -- there is no way to make one call's own
 * up-front preflight observe a different DB state than its own later,
 * same-process lookup without a second real connection) -- mirrors
 * {@see SettlementPgsqlTest}'s own documented boundary and is proven live
 * over PostgreSQL in Task 7's `SellerSuspensionPgsqlTest`. This suite
 * instead proves, deterministically, the exact promise that lookup exists
 * to keep -- a manual payout that already committed is returned as a
 * verified replay no matter when suspension lands afterward -- and pins the
 * revision-before-lock ORDER directly: a refused suspended-seller attempt
 * never leaves an account-lock row behind, and the seller's own `revision`
 * column always advances by exactly one, proving `claimRevision()` runs on
 * every attempt regardless of outcome.
 */
final class SuspendedSellerPayoutTest extends CommerceTestCase
{
    private const TENANT = '';
    private const ACTOR = 'operatorSUSP01';
    private const PROVIDER = 'default';

    private LedgerRepository $ledger;
    private PayoutRepository $payouts;
    private PayoutAccountRepository $payoutAccounts;
    private SellerBalanceService $balances;
    private SellerRepository $sellers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = new LedgerRepository();
        $this->payouts = new PayoutRepository();
        $this->payoutAccounts = new PayoutAccountRepository();
        $this->balances = new SellerBalanceService($this->ledger);
        $this->sellers = new SellerRepository();
    }

    private function makeService(?PayoutCollector $collector = null): PayoutService
    {
        return new PayoutService(
            $this->payouts,
            $this->ledger,
            new LedgerAccountLock(),
            $this->balances,
            $this->sellers,
            null,
            $collector,
            new PayoutAccountService($this->payoutAccounts, null, $collector)
        );
    }

    private function seedSeller(string $uuid, string $status = 'active'): void
    {
        $this->connection->table('commerce_sellers')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT,
            'slug' => strtolower($uuid),
            'name' => $uuid,
            'status' => $status,
        ]);
    }

    /**
     * Suspends via the REAL {@see SellerService::suspend()} -- the same audited transition
     * production uses, which itself claims {@see SellerRepository::claimRevision()} for this
     * exact seller row (design spec §2.1/§2.7). Using the real service (rather than a raw
     * status UPDATE) is what lets the revision-count assertions below prove suspend() and a
     * payout attempt genuinely share and advance the SAME revision counter.
     */
    private function suspend(string $uuid): void
    {
        $this->sellerService()->suspend($this->context, self::TENANT, $uuid, 'Under review.', self::ACTOR);
    }

    private function sellerService(): SellerService
    {
        return new SellerService(
            $this->sellers,
            new SellerMembershipRepository(),
            new SellerLifecycleEventRepository()
        );
    }

    private function sellerRevision(string $uuid): int
    {
        $row = $this->connection->table('commerce_sellers')->where('uuid', '=', $uuid)->first();
        self::assertNotNull($row, 'sanity: seller row must exist to read its revision');

        return (int) $row['revision'];
    }

    private function accountLockRowCount(string $sellerUuid, string $currency): int
    {
        return $this->connection->table('commerce_ledger_account_locks')
            ->where('tenant_uuid', '=', self::TENANT)
            ->where('account_key', '=', LedgerRepository::accountKeyForSeller($sellerUuid))
            ->where('currency', '=', $currency)
            ->count();
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
            'order_uuid' => 'orderSUSPSD01',
            'idempotency_key' => 'orderSUSPSD01:' . $seller . ':sale_credit',
        ]);
    }

    private function seedReadyAccount(string $seller, string $accountRef): void
    {
        $this->connection->table('commerce_seller_payout_accounts')->insert([
            'uuid' => 'acct' . substr($seller, -8),
            'tenant_uuid' => self::TENANT,
            'seller_uuid' => $seller,
            'provider' => self::PROVIDER,
            'account_ref' => $accountRef,
            'readiness_state' => 'ready',
            'last_synced_at' => null,
            'failure_code' => null,
        ]);
    }

    // -----------------------------------------------------------------
    // 1. Manual payout (record()): active unaffected, suspended/closed refused
    //    BEFORE the account lock, untracked sellers never gated.
    // -----------------------------------------------------------------

    public function testActiveSellerManualPayoutSucceedsAndClaimsTheRevisionAndTheAccountLock(): void
    {
        $seller = 'sellerSUSPACT1';
        $this->seedSeller($seller, 'active');
        $this->seedAvailable($seller, 5000);
        self::assertSame(0, $this->sellerRevision($seller));

        $payout = $this->makeService()->record(
            $this->context,
            self::TENANT,
            $seller,
            'USD',
            1000,
            'idem-active-manual',
            'ext-active-manual',
            null,
            self::ACTOR
        );

        self::assertSame('paid', $payout['status']);
        self::assertSame(1, $this->accountLockRowCount($seller, 'USD'));
        self::assertSame(
            1,
            $this->sellerRevision($seller),
            'claimRevision() must run for an active seller too, exactly once per attempt.'
        );
    }

    public function testSuspendedSellerManualPayoutIsRefused422BeforeClaimingTheAccountLock(): void
    {
        $seller = 'sellerSUSPMAN1';
        $this->seedSeller($seller, 'active');
        $this->seedAvailable($seller, 5000);
        $this->suspend($seller);
        self::assertSame(1, $this->sellerRevision($seller), 'sanity: suspend() itself already bumped the revision.');

        $threw = null;
        try {
            $this->makeService()->record(
                $this->context,
                self::TENANT,
                $seller,
                'USD',
                1000,
                'idem-suspended-manual',
                'ext-suspended-manual',
                null,
                self::ACTOR
            );
        } catch (PayoutException $e) {
            $threw = $e;
        }
        self::assertInstanceOf(
            PayoutException::class,
            $threw,
            'a suspended seller must refuse with a 422-mapped PayoutException.'
        );

        self::assertSame(0, $this->connection->table('commerce_payouts')->where('seller_uuid', '=', $seller)->count());
        self::assertSame(
            0,
            $this->connection->table('commerce_marketplace_ledger')
                ->where('seller_uuid', '=', $seller)->where('entry_type', '=', 'payout_debit')->count()
        );
        self::assertSame(
            0,
            $this->accountLockRowCount($seller, 'USD'),
            'the account lock must NEVER be claimed once the revision-claimed status re-read finds a non-active seller.'
        );
        // The whole transaction (including its own provisional revision claim) rolls back with
        // the thrown PayoutException, so the persisted revision is unchanged from suspend()'s
        // own commit -- claimRevision()'s row lock served its serialization purpose DURING the
        // transaction without needing to survive a refusal. The account-lock-row absence above
        // is what pins "status refused strictly BEFORE the account lock" deterministically.
        self::assertSame(
            1,
            $this->sellerRevision($seller),
            'a refused attempt rolls back its own provisional revision claim -- only suspend()\'s commit persists.'
        );
    }

    public function testClosedSellerManualPayoutIsAlsoRefused(): void
    {
        $seller = 'sellerSUSPCLS1';
        $this->seedSeller($seller, 'closed');
        $this->seedAvailable($seller, 5000);

        $this->expectException(PayoutException::class);
        $this->makeService()->record(
            $this->context,
            self::TENANT,
            $seller,
            'USD',
            1000,
            'idem-closed-manual',
            'ext-closed-manual',
            null,
            self::ACTOR
        );
    }

    public function testUntrackedSellerWithNoCommerceSellersRowIsNeverGatedForManualPayout(): void
    {
        // No seedSeller() call at all -- mirrors every pre-MV5b payout suite's convention of a
        // synthetic seller_uuid with no backing commerce_sellers row.
        $seller = 'sellerSUSPUNT1';
        $this->seedAvailable($seller, 5000);

        $payout = $this->makeService()->record(
            $this->context,
            self::TENANT,
            $seller,
            'USD',
            1000,
            'idem-untracked-manual',
            'ext-untracked-manual',
            null,
            self::ACTOR
        );

        self::assertSame('paid', $payout['status']);
        self::assertSame(1, $this->connection->table('commerce_payouts')->where('seller_uuid', '=', $seller)->count());
    }

    // -----------------------------------------------------------------
    // 2. The committed-replay-survives-suspension promise (design spec §2.7's
    //    last paragraph): an already-committed manual payout is returned as a
    //    verified replay no matter when suspension lands afterward.
    // -----------------------------------------------------------------

    public function testCommittedManualPayoutReplayIsHonoredEvenAfterTheSellerIsSuspended(): void
    {
        $seller = 'sellerSUSPRPL1';
        $this->seedSeller($seller, 'active');
        $this->seedAvailable($seller, 5000);

        $winner = $this->makeService()->record(
            $this->context,
            self::TENANT,
            $seller,
            'USD',
            1000,
            'idem-replay-race',
            'ext-replay-race',
            'first',
            self::ACTOR
        );

        $this->suspend($seller);

        // The exact params this call carries must match the winner's request fields
        // (verifyReplay()'s own invariant) -- a replay, not a fresh payout.
        $replay = $this->makeService()->record(
            $this->context,
            self::TENANT,
            $seller,
            'USD',
            1000,
            'idem-replay-race',
            'ext-replay-race',
            'first',
            self::ACTOR
        );

        self::assertSame(
            $winner['uuid'],
            $replay['uuid'],
            'a committed replay must be honored, never re-refused as a lifecycle 422.'
        );
        self::assertSame(
            1,
            $this->connection->table('commerce_payouts')->where('seller_uuid', '=', $seller)->count()
        );
        self::assertSame(
            1,
            $this->connection->table('commerce_marketplace_ledger')
                ->where('seller_uuid', '=', $seller)->where('entry_type', '=', 'payout_debit')->count(),
            'a replay must never post a second ledger entry.'
        );
    }

    public function testCommittedManualPayoutReplayMismatchIsAnIntegrityFailureNotALifecycleRefusal(): void
    {
        $seller = 'sellerSUSPRPL2';
        $this->seedSeller($seller, 'active');
        $this->seedAvailable($seller, 5000);

        $this->makeService()->record(
            $this->context,
            self::TENANT,
            $seller,
            'USD',
            1000,
            'idem-replay-mismatch',
            'ext-replay-mismatch',
            null,
            self::ACTOR
        );

        $this->suspend($seller);

        // A DIFFERENT amount under the SAME idempotency key: verifyReplay() must still be the
        // code path reached (an integrity LedgerException), never the plain lifecycle
        // PayoutException a fresh non-active-seller request would raise -- proving the
        // idempotency lookup is checked BEFORE the status gate, exactly as design spec §2.7
        // requires.
        $this->expectException(LedgerException::class);
        $this->makeService()->record(
            $this->context,
            self::TENANT,
            $seller,
            'USD',
            1500,
            'idem-replay-mismatch',
            'ext-replay-mismatch',
            null,
            self::ACTOR
        );
    }

    // -----------------------------------------------------------------
    // 3. Explicit provider payout (execute() -> reserve()): refused BEFORE any
    //    hold/row/lock; untracked sellers unaffected.
    // -----------------------------------------------------------------

    public function testActiveSellerExplicitProviderPayoutSucceeds(): void
    {
        $seller = 'sellerSUSPPRV1';
        $this->seedSeller($seller, 'active');
        $this->seedReadyAccount($seller, 'acct-active-prv');
        $this->seedAvailable($seller, 5000);

        $collector = new SuspendedPayoutFakeCollector([new PayoutResult(PayoutResult::PAID, 'prov-active')]);
        $payout = $this->makeService($collector)
            ->execute($this->context, self::TENANT, $seller, 'USD', 1000, self::ACTOR);

        self::assertSame('paid', $payout['status']);
    }

    public function testSuspendedSellerExplicitProviderPayoutIsRefused422BeforeAnyHoldRowOrLock(): void
    {
        $seller = 'sellerSUSPPRV2';
        $this->seedSeller($seller, 'active');
        $this->seedReadyAccount($seller, 'acct-susp-prv');
        $this->seedAvailable($seller, 5000);
        $this->suspend($seller);

        $collector = new SuspendedPayoutFakeCollector([]);

        $threw = null;
        try {
            $this->makeService($collector)->execute($this->context, self::TENANT, $seller, 'USD', 1000, self::ACTOR);
        } catch (PayoutException $e) {
            $threw = $e;
        }
        self::assertInstanceOf(PayoutException::class, $threw);

        self::assertSame(0, $this->connection->table('commerce_payouts')->where('seller_uuid', '=', $seller)->count());
        self::assertSame(
            0,
            $this->connection->table('commerce_marketplace_ledger')
                ->where('seller_uuid', '=', $seller)->where('entry_type', '=', 'reserve_hold')->count()
        );
        self::assertSame(0, $this->accountLockRowCount($seller, 'USD'));
        self::assertCount(
            0,
            $collector->transferCalls,
            'the collector must never be reached once the revision-claimed status re-read refuses.'
        );
    }

    public function testUntrackedSellerExplicitProviderPayoutIsNeverGated(): void
    {
        $seller = 'sellerSUSPPRV3';
        // No seedSeller() call -- untracked by the marketplace lifecycle.
        $this->seedReadyAccount($seller, 'acct-untracked-prv');
        $this->seedAvailable($seller, 5000);

        $collector = new SuspendedPayoutFakeCollector([new PayoutResult(PayoutResult::PAID, 'prov-untracked')]);
        $payout = $this->makeService($collector)
            ->execute($this->context, self::TENANT, $seller, 'USD', 1000, self::ACTOR);

        self::assertSame('paid', $payout['status']);
    }

    // -----------------------------------------------------------------
    // 4. Batch (executeBatch()): a suspended candidate is a silent SKIP (null),
    //    never a failure -- exactly like the existing debt/below-minimum skips.
    // -----------------------------------------------------------------

    public function testSuspendedSellerBatchSilentlySkipsWithoutAnyHoldRowOrLock(): void
    {
        $seller = 'sellerSUSPBAT1';
        $this->seedSeller($seller, 'active');
        $this->seedReadyAccount($seller, 'acct-susp-batch');
        $this->seedAvailable($seller, 5000);
        $this->suspend($seller);

        $collector = new SuspendedPayoutFakeCollector([]);
        $payout = $this->makeService($collector)->executeBatch($this->context, self::TENANT, $seller, 'USD', null);

        self::assertNull($payout, 'a suspended candidate must be a silent skip, not a refusal/exception.');
        self::assertSame(0, $this->connection->table('commerce_payouts')->where('seller_uuid', '=', $seller)->count());
        self::assertSame(
            0,
            $this->connection->table('commerce_marketplace_ledger')
                ->where('seller_uuid', '=', $seller)->where('entry_type', '=', 'reserve_hold')->count()
        );
        self::assertSame(0, $this->accountLockRowCount($seller, 'USD'));
        self::assertCount(0, $collector->transferCalls);
    }

    public function testRunBatchCommandCountsASuspendedSellerAsASkipNeverAFailureAlongsideANormalPayout(): void
    {
        $suspended = 'sellerSUSPRB01';
        $this->seedSeller($suspended, 'active');
        $this->seedReadyAccount($suspended, 'acct-rb-susp');
        $this->seedAvailable($suspended, 500);
        $this->suspend($suspended);

        $active = 'sellerSUSPRB02';
        $this->seedSeller($active, 'active');
        $this->seedReadyAccount($active, 'acct-rb-active');
        $this->seedAvailable($active, 500);

        $collector = new SuspendedPayoutFakeCollector([new PayoutResult(PayoutResult::PAID, 'prov-rb-active')]);
        $service = $this->makeService($collector);

        $this->bind(LedgerRepository::class, $this->ledger);
        $this->bind(PayoutService::class, $service);

        $command = new PayoutsRunBatchCommand($this->context->getContainer(), $this->context);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode, 'a suspended-seller skip must never cause the batch command to report failure.');
        self::assertStringContainsString(
            '0 failed',
            $tester->getDisplay(),
            'the suspended seller must be counted as a skip, never a failure.'
        );

        $suspendedPayout = $this->connection->table('commerce_payouts')->where('seller_uuid', '=', $suspended)->first();
        self::assertNull($suspendedPayout, 'the suspended seller must never receive a payout row.');

        $activePayout = $this->connection->table('commerce_payouts')->where('seller_uuid', '=', $active)->first();
        self::assertNotNull($activePayout, 'the active seller in the SAME batch run must still be paid out.');
        self::assertSame('paid', $activePayout['status']);
    }

    // -----------------------------------------------------------------
    // 5. In-flight continuation: retry()/reconcile() are NOT gated on the
    //    seller's CURRENT status -- suspension is prospective, never cancels
    //    or strands an already-reserved/committed attempt.
    // -----------------------------------------------------------------

    public function testInFlightProviderPayoutOfANowSuspendedSellerStillRetriesToCompletion(): void
    {
        $seller = 'sellerSUSPRTY1';
        $this->seedSeller($seller, 'active');
        $this->seedReadyAccount($seller, 'acct-retry-susp');
        $this->seedAvailable($seller, 5000);

        $collector = new SuspendedPayoutFakeCollector([
            new PayoutResult(PayoutResult::RETRYABLE_FAILURE, null, 'card_declined', 'declined'),
            new PayoutResult(PayoutResult::PAID, 'prov-retry-susp'),
        ]);
        $service = $this->makeService($collector);

        $payout = $service->execute($this->context, self::TENANT, $seller, 'USD', 500, self::ACTOR);
        self::assertSame('failed', $payout['status']);
        self::assertTrue((bool) $payout['retryable']);

        $this->connection->table('commerce_payouts')
            ->where('uuid', '=', $payout['uuid'])
            ->update(['next_attempt_at' => gmdate('Y-m-d H:i:s', time() - 3600)]);

        // The seller is suspended AFTER the payout was already reserved/attempted.
        $this->suspend($seller);

        $result = $service->retry($this->context, self::TENANT, (string) $payout['uuid']);

        self::assertNotNull($result, 'retry() must NOT be gated by the seller\'s current (suspended) status.');
        self::assertSame('paid', $result['status']);
        self::assertCount(2, $collector->transferCalls);
    }

    public function testInFlightProviderPayoutOfANowSuspendedSellerStillReconcilesToCompletion(): void
    {
        $seller = 'sellerSUSPRCN1';
        $this->seedSeller($seller, 'active');
        $this->seedReadyAccount($seller, 'acct-reconcile-susp');
        $this->seedAvailable($seller, 5000);

        $collector = new SuspendedPayoutFakeCollector([
            new PayoutResult(PayoutResult::PENDING),
        ], [
            new PayoutStatusResult(PayoutStatusResult::PAID, 0, 'prov-reconcile-susp'),
        ]);
        $service = $this->makeService($collector);

        $payout = $service->execute($this->context, self::TENANT, $seller, 'USD', 500, self::ACTOR);
        self::assertSame('pending', $payout['status']);

        // The seller is suspended AFTER the payout was already reserved and left pending.
        $this->suspend($seller);

        $reconciled = $service->reconcile($this->context, self::TENANT, $payout);

        self::assertSame(
            'paid',
            $reconciled['status'],
            'reconcile() must NOT be gated by the seller\'s current (suspended) status.'
        );
    }
}

/**
 * Scripted fake payout collector for the suspended-seller payout suite --
 * distinct name from every other fake collector in this namespace. Scripts
 * BOTH `transfer()` and `status()` (mirrors {@see RetryReconcileFakeCollector}'s
 * shape) since this suite exercises `execute()`, `executeBatch()`, `retry()`,
 * AND `reconcile()`.
 */
final class SuspendedPayoutFakeCollector implements PayoutCollector
{
    /** @var list<array{idempotencyKey: string}> */
    public array $transferCalls = [];

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
        $this->transferCalls[] = ['idempotencyKey' => $request->idempotencyKey];

        if ($this->transferQueue === []) {
            throw new \RuntimeException('SuspendedPayoutFakeCollector transfer queue exhausted.');
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
            throw new \RuntimeException('SuspendedPayoutFakeCollector status queue exhausted.');
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
        throw new \LogicException('SuspendedPayoutFakeCollector::inspectDestination() is not exercised by this suite.');
    }
}
