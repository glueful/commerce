<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutAccountRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutAccountService;
use Glueful\Extensions\Commerce\Marketplace\PayoutException;
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
use Psr\Container\ContainerInterface;

/**
 * Real-PostgreSQL race lanes for Marketplace MV4's provider-payout saga (design
 * spec §2.3-§2.8; Task 11, GATES). Every case here requires TRUE two-connection
 * row-lock interleaving that SQLite -- a single-process, single-connection engine
 * in this test harness -- cannot exercise at all. This file mirrors
 * `SettlementPgsqlTest` primitive-for-primitive: `skipUnlessPgsql()`, `pgConfig()`,
 * `migratedConnection()`, `pgsqlContext()`, `launchRaceChild()`/`collectRaceChild()`,
 * and the fixture-width discipline (every `uuid`/`tenant_uuid`/`seller_uuid` literal
 * here is 11 characters -- `varchar(12)`, strictly enforced by PostgreSQL but
 * silently ignored by SQLite; `account_key` derives to `seller:{11 chars}` = 18,
 * well under its own 32-char column).
 *
 * Every lane follows the SAME established pattern: connection A (this test) either
 * manually replicates the pre-I/O critical section of `PayoutService::reserve()`
 * directly via the repositories (lanes 1 and 3 -- chosen specifically so A itself
 * never calls a `PayoutCollector` method while its own explicit outer transaction
 * is still open, keeping the "provider I/O strictly outside transactions" invariant
 * uncontaminated by the test harness), or calls a REAL `PayoutService` entry point
 * directly on connection A after an explicit `getTransactionManager()->begin()`,
 * holding it open (uncommitted) rather than manually reimplementing its internals
 * (lanes 2, 4, and 5 -- `reconcile()`/`retry()`, whose only DB-mutating work is
 * already wrapped in `db($context)->transaction()`, which NESTS as a savepoint
 * rather than committing when called from inside an already-open outer transaction;
 * their own `PayoutCollector` calls are plain in-process PHP method calls with no
 * real network I/O, so holding them inside a still-open outer transaction never
 * risks anything the unit-level `PayoutSagaTest::testPaidResultPostsReserveReleaseAndPayoutDebitWithExactBalances()`
 * doesn't already separately prove `withinTransaction` false for). Connection B is
 * always launched as a genuinely separate subprocess
 * (`fixtures/payout_saga_race_child.php`, a single multiplexed script mirroring
 * `fixtures/settlement_race_child.php`'s shape) running the REAL
 * `PayoutService` entry point end to end, which blocks on A's held claim until A
 * commits or rolls back.
 */
final class PayoutSagaPgsqlTest extends CommerceTestCase
{
    private const PROVIDER = 'default';

    // =====================================================================
    // 1. Payout-vs-second-payout under the hold (no overdraw, design spec
    //    §2.3/§2.4): connection A holds the seller account lock mid-reserve
    //    (a hold posted, uncommitted); connection B (subprocess) attempts a
    //    SECOND payout that would overdraw once A's hold commits. A refund is
    //    deliberately NOT used for the overdraw side of this lane -- refunds
    //    are never gated on available balance at all (proven already by
    //    `SettlementPgsqlTest::testPayoutSucceedsAgainstThePreRefundBalanceWhenItCommitsFirstOnRealPostgres()`),
    //    so only a second payout attempt can actually exercise "refused
    //    because it would overdraw."
    // =====================================================================

    public function testPayoutReservationHoldPreventsAConcurrentSecondPayoutFromOverdrawingOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'pg4hldtn001';
        $seller = 'pg4hldsl001';
        $this->cleanupTenant($connectionA, $tenant);

        $this->seedReadyAccount($connectionA, $tenant, $seller, 'pg4hldac001', 'acct-pg4-hld');
        $this->seedAvailable($contextA, $tenant, $seller, 5000);

        $accountKey = LedgerRepository::accountKeyForSeller($seller);
        $payoutUuidA = 'pg4hldpa001';

        // Connection A: manually replicates PayoutService::reserve()'s pre-I/O critical
        // section (claim the account lock -> re-read available under it -> insert the
        // pending row -> post reserve_hold) directly via the repositories, held open.
        $connectionA->getTransactionManager()->begin();
        (new LedgerAccountLock())->claim($contextA, $tenant, $accountKey, 'USD');
        $availableUnderLock = $this->balances()->available($contextA, $tenant, $seller, 'USD');
        self::assertSame(5000, $availableUnderLock, 'sanity: A observes the full pre-hold balance under the lock.');
        $this->insertPendingPayoutWithHold($contextA, $tenant, $seller, $payoutUuidA, 'acct-pg4-hld', 3500);

        // Connection B (subprocess): a REAL PayoutService::execute() attempt for an amount
        // that would overdraw the post-hold balance (5000 - 3500 = 1500 remains) -- blocks
        // entirely on A's held account-lock claim.
        $handle = $this->launchRaceChild($pgConfig, 'payoutExecute', [
            'tenant' => $tenant,
            'sellerUuid' => $seller,
            'currency' => 'USD',
            'amount' => 2000,
            'actorUuid' => 'pg4operat01',
            'defaultProvider' => self::PROVIDER,
        ]);

        usleep(300_000);
        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertFalse(
            $result['ok'] ?? true,
            'a concurrent payout that would overdraw the post-hold balance must be refused: '
                . json_encode($result, JSON_THROW_ON_ERROR)
        );
        self::assertSame(PayoutException::class, $result['exceptionClass'] ?? null);

        // No overdraw: exactly ONE payout row exists (A's), and available reflects exactly
        // A's surviving hold -- never negative, never double-counted.
        self::assertSame(1, $connectionA->table('commerce_payouts')->where('tenant_uuid', '=', $tenant)->count());
        self::assertSame(
            1500,
            $this->balances()->available($contextA, $tenant, $seller, 'USD'),
            'no overdraw: available reflects exactly the surviving hold, untouched by the refused payout'
        );

        $this->cleanupTenant($connectionA, $tenant);
    }

    // =====================================================================
    // 2. Double-finalize idempotency (design spec §2.3, `claimPending()`): two
    //    concurrent `reconcile()` calls on the SAME still-`pending` payout,
    //    both observing the SAME PayoutStatusResult (PAID), post exactly ONE
    //    posting set -- the loser's own claimPending() CAS never matches, so
    //    it never posts. `reconcile()` (not the private `finalize()`) is the
    //    public entry point this lane drives -- it shares the EXACT same
    //    single-finalizer-wins `applyPendingTransition()`/`claimPending()`
    //    primitive `finalize()` itself uses (design spec §2.3/§2.6).
    // =====================================================================

    public function testConcurrentReconcileAttemptsOnTheSamePendingPayoutPostExactlyOnePostingSetOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'pg4fintn001';
        $seller = 'pg4finsl001';
        $this->cleanupTenant($connectionA, $tenant);

        $payoutUuid = 'pg4finpa001';
        $this->seedAvailable($contextA, $tenant, $seller, 4000);
        $this->insertPendingPayoutWithHold($contextA, $tenant, $seller, $payoutUuid, 'acct-pg4-fin', 1000);

        $collectorA = new PgsqlRacePayoutCollector(
            statusResult: new PayoutStatusResult(PayoutStatusResult::PAID, 0, 'prov-pg4-fin-a')
        );
        $serviceA = $this->payoutServiceFor($collectorA);

        // Connection A: the REAL reconcile(), held open -- its own db()->transaction() call
        // nests as a savepoint under this outer explicit transaction.
        $connectionA->getTransactionManager()->begin();
        $reconciledA = $serviceA->reconcile($contextA, $tenant, $this->payoutRow($connectionA, $payoutUuid));
        self::assertSame('paid', $reconciledA['status']);

        // Connection B (subprocess): a CONCURRENT reconcile() reporting the SAME PAID
        // result -- blocks on the SAME payout row (claimPending()'s own UPDATE) until A
        // commits, then observes status is no longer 'pending' and posts nothing.
        $handle = $this->launchRaceChild($pgConfig, 'reconcile', [
            'tenant' => $tenant,
            'payoutUuid' => $payoutUuid,
            'status' => ['status' => PayoutStatusResult::PAID, 'reversedAmount' => 0, 'providerRef' => 'prov-pg4-fin-b'],
        ]);

        usleep(300_000);
        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertTrue(
            $result['ok'] ?? false,
            'the second concurrent reconcile() must not error, only lose the CAS: '
                . json_encode($result, JSON_THROW_ON_ERROR)
        );
        self::assertSame(
            'paid',
            $result['status'],
            "B must observe A's already-applied paid state (its own claimPending() lost the CAS)."
        );
        self::assertSame(
            1,
            $result['statusCalls'],
            'reconcile() calls status() BEFORE the CAS on both sides -- only the CAS/posting is single-winner.'
        );

        // Exactly ONE posting set landed -- A's. B's provider_ref never overwrote it.
        self::assertSame(1, $this->countLedger($connectionA, $payoutUuid, 'reserve_release'));
        self::assertSame(1, $this->countLedger($connectionA, $payoutUuid, 'payout_debit'));
        $finalRow = $this->payoutRow($connectionA, $payoutUuid);
        self::assertSame('prov-pg4-fin-a', $finalRow['provider_ref'], "only A's finalize ever actually landed.");

        $balance = $this->balances()->balance($contextA, $tenant, $seller, 'USD');
        self::assertSame(3000, $balance['available'], 'hold(1000) -> paid: available unchanged from the pre-hold value.');
        self::assertSame(0, $balance['pending']);
        self::assertSame(1000, $balance['paid_out'], 'exactly ONE payout_debit landed -- never doubled.');

        $this->cleanupTenant($connectionA, $tenant);
    }

    // =====================================================================
    // 3. Concurrent batch workers (design spec §2.6): worker A holds the
    //    seller/currency account lock mid-reserve; worker B (subprocess,
    //    `executeBatch()`) blocks entirely on that lock, then re-reads the
    //    NOW-drained available and derives a SMALLER amount -- never the
    //    same amount A derived, never a duplicate.
    // =====================================================================

    public function testConcurrentBatchWorkersOnTheSameSellerCurrencyDeriveFromTheLockedBalanceAndNeverDuplicateOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'pg4battn001';
        $seller = 'pg4batsl001';
        $this->cleanupTenant($connectionA, $tenant);

        $this->seedReadyAccount($connectionA, $tenant, $seller, 'pg4batac001', 'acct-pg4-bat');
        $this->seedAvailable($contextA, $tenant, $seller, 1000);

        $accountKey = LedgerRepository::accountKeyForSeller($seller);
        $payoutUuidA = 'pg4batpa001';

        // Connection A: manually replicates the batch-derivation reserve step -- claims the
        // lock, re-reads available under it, and holds 600 of the 1000 available -- uncommitted.
        $connectionA->getTransactionManager()->begin();
        (new LedgerAccountLock())->claim($contextA, $tenant, $accountKey, 'USD');
        $availableUnderLock = $this->balances()->available($contextA, $tenant, $seller, 'USD');
        self::assertSame(1000, $availableUnderLock);
        $this->insertPendingPayoutWithHold($contextA, $tenant, $seller, $payoutUuidA, 'acct-pg4-bat', 600);

        // Connection B (subprocess): the REAL PayoutService::executeBatch() -- blocks
        // entirely on A's held account-lock claim; once unblocked, its own locked re-read
        // observes an available balance already drained by A's hold (1000 - 600 = 400) and
        // must derive exactly that smaller amount, never A's 600 and never the stale 1000.
        $handle = $this->launchRaceChild($pgConfig, 'executeBatch', [
            'tenant' => $tenant,
            'sellerUuid' => $seller,
            'currency' => 'USD',
            'actorUuid' => null,
            'defaultProvider' => self::PROVIDER,
            'transfer' => ['status' => PayoutResult::PAID, 'providerRef' => 'prov-pg4-bat-b'],
        ]);

        usleep(300_000);
        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertTrue($result['ok'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertNotNull($result['payout'], 'worker B must still find a positive candidate to process.');
        self::assertSame(
            400,
            $result['payout']['amount'],
            "worker B must derive the LOCKED remainder (400), never A's amount (600) nor the stale hint (1000)."
        );
        self::assertNotSame($payoutUuidA, $result['payout']['uuid']);
        self::assertSame(1, $result['transferCalls']);

        self::assertSame(
            2,
            $connectionA->table('commerce_payouts')->where('tenant_uuid', '=', $tenant)->count(),
            'exactly two payouts total -- the amount was never duplicated across workers.'
        );
        self::assertSame(0, $this->balances()->available($contextA, $tenant, $seller, 'USD'));

        $this->cleanupTenant($connectionA, $tenant);
    }

    // =====================================================================
    // 4. Concurrent retry sweeps + claimRetryableForAttempt (design spec
    //    §2.6): connection A runs the REAL retry() (claim -> transfer ->
    //    finalize) held open; connection B (subprocess) concurrently attempts
    //    the SAME retry() for the SAME payout -- its own claimRetryableForAttempt()
    //    CAS blocks on the row lock, then loses once A commits (status is no
    //    longer 'failed'), so it claims NOTHING and never reaches the collector
    //    at all -- no double-attempt, no double-transfer.
    // =====================================================================

    public function testConcurrentRetrySweepsOnTheSameFailedRetryablePayoutAdvanceTheAttemptExactlyOnceOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'pg4rtytn001';
        $seller = 'pg4rtysl001';
        $this->cleanupTenant($connectionA, $tenant);

        $payoutUuid = 'pg4rtypa001';
        $this->seedAvailable($contextA, $tenant, $seller, 1000);
        $this->insertFailedRetryablePayout($contextA, $tenant, $seller, $payoutUuid, 'acct-pg4-rty', 400);

        $collectorA = new PgsqlRacePayoutCollector(
            transferResult: new PayoutResult(PayoutResult::PAID, 'prov-pg4-rty-a')
        );
        $serviceA = $this->payoutServiceFor($collectorA);

        // Connection A: the REAL retry() (claim -> transfer -> finalize), held open by an
        // explicit outer transaction. The collector is a plain in-process PHP object (no real
        // network I/O), so holding it here is purely a test-harness technique to get genuine
        // PostgreSQL row-lock contention -- it never risks the production
        // "provider I/O strictly outside transactions" invariant, which is separately proven
        // at the unit level (`PayoutSagaTest`'s own `withinTransaction` assertion).
        $connectionA->getTransactionManager()->begin();
        $resultA = $serviceA->retry($contextA, $tenant, $payoutUuid, true);
        self::assertNotNull($resultA);
        self::assertSame('paid', $resultA['status']);
        self::assertSame(2, (int) $resultA['attempt_count']);

        // Connection B (subprocess): a CONCURRENT retry() for the SAME payout -- blocks on
        // A's held row lock until A commits, then its own claimRetryableForAttempt() CAS
        // matches zero rows (status is no longer 'failed') and short-circuits BEFORE ever
        // calling the collector.
        $handle = $this->launchRaceChild($pgConfig, 'retry', [
            'tenant' => $tenant,
            'payoutUuid' => $payoutUuid,
            'ignoreDueTime' => true,
            'transfer' => ['status' => PayoutResult::PAID, 'providerRef' => 'prov-pg4-rty-b'],
        ]);

        usleep(300_000);
        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertTrue($result['ok'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertNull($result['result'], 'the second concurrent retry sweep must claim NOTHING once A has already won.');
        self::assertSame(0, $result['transferCalls'], 'a lost retry claim must never reach the collector -- no double-transfer.');

        self::assertSame(1, $collectorA->transferCalls, 'exactly ONE transfer() call total across both connections.');
        $finalRow = $this->payoutRow($connectionA, $payoutUuid);
        self::assertSame('paid', $finalRow['status']);
        self::assertSame(2, (int) $finalRow['attempt_count'], 'the CAS must have advanced the attempt exactly once total.');
        self::assertSame('prov-pg4-rty-a', $finalRow['provider_ref'], "only A's attempt actually landed.");

        self::assertSame(1, $this->countLedger($connectionA, $payoutUuid, 'reserve_release'));
        self::assertSame(1, $this->countLedger($connectionA, $payoutUuid, 'payout_debit'));

        $this->cleanupTenant($connectionA, $tenant);
    }

    // =====================================================================
    // 5. Paid-state provider regression under concurrent legitimate reversal
    //    (design spec §2.6/§2.8): connection A applies a LEGITIMATE partial
    //    reversal to an already-paid payout, held open; connection B
    //    (subprocess) concurrently reconciles the SAME paid payout observing a
    //    provider REGRESSION (RETRYABLE_FAILURE) -- its own row-level write
    //    (the regression branch's unconditional `scheduleReconcile()` watchdog
    //    re-arm) blocks on A's still-uncommitted row lock, proving true
    //    interleaving even though the regression branch itself never claims the
    //    account lock (it short-circuits BEFORE any lock claim, since nothing
    //    about a regression should ever touch money). B must report the
    //    CURRENT (A's) state, never fabricate/override it, and post nothing.
    // =====================================================================

    public function testConcurrentReconcileRegressionOnAPaidPayoutNeverRepostsEvenInterleavedWithALegitimateReversalOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'pg4rgrtn001';
        $seller = 'pg4rgrsl001';
        $this->cleanupTenant($connectionA, $tenant);

        $payoutUuid = 'pg4rgrpa001';
        $this->seedPaidPayoutForReversal($contextA, $tenant, $seller, $payoutUuid, 'acct-pg4-rgr', 1000);

        $collectorA = new PgsqlRacePayoutCollector(
            statusResult: new PayoutStatusResult(PayoutStatusResult::PAID, 200, 'prov-pg4-rgr-a')
        );
        $serviceA = $this->payoutServiceFor($collectorA);

        // Connection A: the REAL reconcile() applying a LEGITIMATE partial reversal, held open.
        $connectionA->getTransactionManager()->begin();
        $reconciledA = $serviceA->reconcile($contextA, $tenant, $this->payoutRow($connectionA, $payoutUuid));
        self::assertSame('paid', $reconciledA['status']);
        self::assertSame(200, (int) $reconciledA['reversed_total']);

        // Connection B (subprocess): a CONCURRENT reconcile() reporting a provider
        // REGRESSION (RETRYABLE_FAILURE) for the SAME already-paid row.
        $handle = $this->launchRaceChild($pgConfig, 'reconcile', [
            'tenant' => $tenant,
            'payoutUuid' => $payoutUuid,
            'status' => [
                'status' => PayoutStatusResult::RETRYABLE_FAILURE,
                'reversedAmount' => 0,
                'failureCode' => 'card_declined',
                'failureReason' => 'poison-should-never-drive-a-ledger-post',
            ],
        ]);

        usleep(300_000);
        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertTrue($result['ok'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame(
            'paid',
            $result['status'],
            "a provider regression observed after a concurrent legitimate reversal must report the CURRENT "
                . "(A's) state, never fabricate/override it."
        );
        self::assertSame(200, (int) $result['reversed_total'], "B's regression report must never change reversed_total.");

        // Exactly ONE payout_reversal posting exists -- A's legitimate delta. The regression
        // never posted anything.
        self::assertSame(1, $this->countLedger($connectionA, $payoutUuid, 'payout_reversal'));
        self::assertStringNotContainsString(
            'poison-should-never-drive-a-ledger-post',
            (string) json_encode(
                $connectionA->table('commerce_marketplace_ledger')
                    ->where('payout_uuid', '=', $payoutUuid)
                    ->get(),
                JSON_THROW_ON_ERROR
            )
        );

        $balance = $this->balances()->balance($contextA, $tenant, $seller, 'USD');
        self::assertSame(200, $balance['available'], 'only the legitimate 200 delta was ever restored.');
        self::assertSame(800, $balance['paid_out']);

        $finalRow = $this->payoutRow($connectionA, $payoutUuid);
        self::assertSame('paid', $finalRow['status']);
        self::assertSame(200, (int) $finalRow['reversed_total']);

        $this->cleanupTenant($connectionA, $tenant);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function skipUnlessPgsql(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            self::markTestSkipped('Requires a PostgreSQL test lane for true two-connection row-lock interleaving.');
        }
    }

    private function payoutServiceFor(?PayoutCollector $collector): PayoutService
    {
        return new PayoutService(
            new PayoutRepository(),
            new LedgerRepository(),
            new LedgerAccountLock(),
            $this->balances(),
            null,
            $collector,
            new PayoutAccountService(new PayoutAccountRepository())
        );
    }

    private function balances(): SellerBalanceService
    {
        return new SellerBalanceService(new LedgerRepository());
    }

    private function seedAvailable(ApplicationContext $context, string $tenant, string $sellerUuid, int $amount): void
    {
        (new LedgerRepository())->post($context, $tenant, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller($sellerUuid),
            'seller_uuid' => $sellerUuid,
            'currency' => 'USD',
            'entry_type' => 'sale_credit',
            'amount' => $amount,
            'order_uuid' => 'seed' . substr(md5($sellerUuid), 0, 8),
            'idempotency_key' => 'seed:' . $sellerUuid . ':sale_credit',
        ]);
    }

    private function seedReadyAccount(
        Connection $connection,
        string $tenant,
        string $sellerUuid,
        string $accountUuid,
        string $accountRef
    ): void {
        $connection->table('commerce_seller_payout_accounts')->insert([
            'uuid' => $accountUuid,
            'tenant_uuid' => $tenant,
            'seller_uuid' => $sellerUuid,
            'provider' => self::PROVIDER,
            'account_ref' => $accountRef,
            'readiness_state' => 'ready',
            'last_synced_at' => null,
            'failure_code' => null,
        ]);
    }

    /** Inserts a `pending`/`method=provider` payout row plus its matching `reserve_hold` posting. */
    private function insertPendingPayoutWithHold(
        ApplicationContext $context,
        string $tenant,
        string $sellerUuid,
        string $payoutUuid,
        string $accountRef,
        int $amount
    ): void {
        (new PayoutRepository())->insert($context, [
            'uuid' => $payoutUuid,
            'tenant_uuid' => $tenant,
            'seller_uuid' => $sellerUuid,
            'currency' => 'USD',
            'amount' => $amount,
            'idempotency_key' => $payoutUuid,
            'status' => 'pending',
            'method' => 'provider',
            'provider' => self::PROVIDER,
            'destination_ref' => $accountRef,
            'retryable' => false,
            'attempt_count' => 1,
        ]);
        (new LedgerRepository())->post($context, $tenant, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller($sellerUuid),
            'seller_uuid' => $sellerUuid,
            'currency' => 'USD',
            'entry_type' => 'reserve_hold',
            'amount' => -$amount,
            'payout_uuid' => $payoutUuid,
            'idempotency_key' => "{$payoutUuid}:reserve_hold",
        ]);
    }

    /** Inserts a `failed`/`retryable`/due payout row whose hold (design spec §2.3) still stands. */
    private function insertFailedRetryablePayout(
        ApplicationContext $context,
        string $tenant,
        string $sellerUuid,
        string $payoutUuid,
        string $accountRef,
        int $amount
    ): void {
        (new PayoutRepository())->insert($context, [
            'uuid' => $payoutUuid,
            'tenant_uuid' => $tenant,
            'seller_uuid' => $sellerUuid,
            'currency' => 'USD',
            'amount' => $amount,
            'idempotency_key' => $payoutUuid,
            'status' => 'failed',
            'method' => 'provider',
            'provider' => self::PROVIDER,
            'destination_ref' => $accountRef,
            'failure_code' => 'card_declined',
            'failure_reason' => 'declined',
            'retryable' => true,
            'attempt_count' => 1,
            'next_attempt_at' => gmdate('Y-m-d H:i:s', time() - 3600),
        ]);
        (new LedgerRepository())->post($context, $tenant, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller($sellerUuid),
            'seller_uuid' => $sellerUuid,
            'currency' => 'USD',
            'entry_type' => 'reserve_hold',
            'amount' => -$amount,
            'payout_uuid' => $payoutUuid,
            'idempotency_key' => "{$payoutUuid}:reserve_hold",
        ]);
    }

    /** Inserts a fully-`paid` payout row (hold released, debit landed) ready to reconcile. */
    private function seedPaidPayoutForReversal(
        ApplicationContext $context,
        string $tenant,
        string $sellerUuid,
        string $payoutUuid,
        string $accountRef,
        int $amount
    ): void {
        $this->seedAvailable($context, $tenant, $sellerUuid, $amount);

        (new PayoutRepository())->insert($context, [
            'uuid' => $payoutUuid,
            'tenant_uuid' => $tenant,
            'seller_uuid' => $sellerUuid,
            'currency' => 'USD',
            'amount' => $amount,
            'idempotency_key' => $payoutUuid,
            'status' => 'paid',
            'method' => 'provider',
            'provider' => self::PROVIDER,
            'provider_ref' => 'prov-seed-' . $payoutUuid,
            'destination_ref' => $accountRef,
            'retryable' => false,
            'attempt_count' => 1,
            'reversed_total' => 0,
        ]);

        $accountKey = LedgerRepository::accountKeyForSeller($sellerUuid);
        $ledger = new LedgerRepository();
        foreach (
            [
                ['reserve_hold', -$amount, "{$payoutUuid}:reserve_hold"],
                ['reserve_release', $amount, "{$payoutUuid}:reserve_release"],
                ['payout_debit', -$amount, "{$payoutUuid}:payout_debit"],
            ] as [$entryType, $signedAmount, $idemKey]
        ) {
            $ledger->post($context, $tenant, [
                'account_kind' => 'seller',
                'account_key' => $accountKey,
                'seller_uuid' => $sellerUuid,
                'currency' => 'USD',
                'entry_type' => $entryType,
                'amount' => $signedAmount,
                'payout_uuid' => $payoutUuid,
                'idempotency_key' => $idemKey,
            ]);
        }
    }

    /** @return array<string,mixed> */
    private function payoutRow(Connection $connection, string $payoutUuid): array
    {
        $row = $connection->table('commerce_payouts')->where('uuid', '=', $payoutUuid)->first();
        self::assertNotNull($row);

        return $row;
    }

    private function countLedger(Connection $connection, string $payoutUuid, string $entryType): int
    {
        return $connection->table('commerce_marketplace_ledger')
            ->where('payout_uuid', '=', $payoutUuid)
            ->where('entry_type', '=', $entryType)
            ->count();
    }

    /**
     * @param array<string,mixed> $pgConfig
     * @param array<string,mixed> $args
     * @return array{0: resource, 1: array<int,resource>}
     */
    private function launchRaceChild(array $pgConfig, string $action, array $args): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/fixtures/payout_saga_race_child.php',
                json_encode($pgConfig, JSON_THROW_ON_ERROR),
                $action,
                json_encode($args, JSON_THROW_ON_ERROR),
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        return [$process, $pipes];
    }

    /**
     * @param array{0: resource, 1: array<int,resource>} $handle
     * @return array<string,mixed>
     */
    private function collectRaceChild(array $handle): array
    {
        [$process, $pipes] = $handle;
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $result = json_decode(trim((string) $stdout), true);
        self::assertIsArray($result, "subprocess produced no parseable result. stderr: {$stderr}");

        return $result;
    }

    private function cleanupTenant(Connection $connection, string $tenant): void
    {
        $connection->table('commerce_marketplace_ledger')->where('tenant_uuid', '=', $tenant)->delete();
        $connection->table('commerce_ledger_account_locks')->where('tenant_uuid', '=', $tenant)->delete();
        $connection->table('commerce_payouts')->where('tenant_uuid', '=', $tenant)->delete();
        $connection->table('commerce_seller_payout_accounts')->where('tenant_uuid', '=', $tenant)->delete();
    }

    /** @return array<string,mixed> */
    private function pgConfig(): array
    {
        return [
            'engine' => 'pgsql',
            'pgsql' => [
                'host' => getenv('DB_PGSQL_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('DB_PGSQL_PORT') ?: 5432),
                'db' => getenv('DB_PGSQL_DATABASE') ?: 'glueful_test',
                'user' => getenv('DB_PGSQL_USERNAME') ?: 'postgres',
                'pass' => getenv('DB_PGSQL_PASSWORD') ?: '',
                'schema' => getenv('DB_PGSQL_SCHEMA') ?: 'public',
            ],
            'pooling' => ['enabled' => false],
        ];
    }

    /** @param array<string,mixed> $pgConfig */
    private function migratedConnection(array $pgConfig): Connection
    {
        $connection = new Connection($pgConfig);
        $schema = $connection->getSchemaBuilder();
        foreach (static::MIGRATIONS as $migration) {
            (new $migration())->up($schema);
        }

        return $connection;
    }

    private function pgsqlContext(Connection $connection): ApplicationContext
    {
        $container = new class ($connection) implements ContainerInterface {
            public function __construct(private Connection $connection)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === 'database' || $id === Connection::class) {
                    return $this->connection;
                }

                throw new \RuntimeException("Unknown service: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === 'database' || $id === Connection::class;
            }
        };

        $context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
        $context->setContainer($container);
        $context->mergeConfigDefaults('commerce', require __DIR__ . '/../../../config/commerce.php');
        $context->mergeConfigDefaults('commerce', ['marketplace' => ['enabled' => true]]);

        return $context;
    }
}

/**
 * Scripted fake payout collector for connection A's own in-process calls (`reconcile()`/
 * `retry()`) -- distinct name from every other fake `PayoutCollector` in this namespace
 * (`FakePayoutCollector`, `RetryReconcileFakeCollector`, `ReadinessFakeCollector`,
 * `BatchFakeCollector`, `ReversalFakeCollector`, `SurfaceFakePayoutCollector`) so every
 * test file in this suite can load in the same PHPUnit process without a class-name
 * collision. Single-shot: each instance scripts at most one `transfer()`/`status()` call,
 * matching every lane's actual usage.
 */
final class PgsqlRacePayoutCollector implements PayoutCollector
{
    public int $transferCalls = 0;
    public int $statusCalls = 0;

    public function __construct(
        private readonly ?PayoutResult $transferResult = null,
        private readonly ?PayoutStatusResult $statusResult = null
    ) {
    }

    public function transfer(
        ApplicationContext $context,
        PayoutDestination $destination,
        PayoutRequest $request
    ): PayoutResult {
        $this->transferCalls++;

        return $this->transferResult ?? throw new \RuntimeException('PgsqlRacePayoutCollector: transfer() not scripted.');
    }

    public function status(
        ApplicationContext $context,
        PayoutDestination $destination,
        string $idempotencyKey
    ): PayoutStatusResult {
        $this->statusCalls++;

        return $this->statusResult ?? throw new \RuntimeException('PgsqlRacePayoutCollector: status() not scripted.');
    }

    public function inspectDestination(
        ApplicationContext $context,
        PayoutDestination $destination
    ): DestinationStatus {
        throw new \LogicException('PgsqlRacePayoutCollector::inspectDestination() is not exercised by this suite.');
    }
}
