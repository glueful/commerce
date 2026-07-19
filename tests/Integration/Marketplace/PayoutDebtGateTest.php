<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Console\PayoutsRunBatchCommand;
use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutAccountRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutAccountService;
use Glueful\Extensions\Commerce\Marketplace\PayoutException;
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
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The MV5a design spec §2.7 payout debt gate (Task 13): provider AND manual payout
 * creation now ALSO refuses (`422` {@see PayoutException}) whenever the seller's LOCKED
 * `debt` balance component is positive -- ADDITIVE to, never a replacement for, the
 * pre-existing MV4 `available >= amount` capacity guard {@see PayoutSagaTest}/
 * {@see PayoutTest} already cover. `payouts:run-batch` skips (never aborts the batch for)
 * an indebted candidate, mirroring its existing independent-per-candidate discipline for a
 * non-positive locked `available`. A solvent seller (`debt == 0`) is completely unaffected.
 */
final class PayoutDebtGateTest extends CommerceTestCase
{
    private const TENANT = '';
    private const ACTOR = 'operatorDEBT01';
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
    }

    private function makeService(?PayoutCollector $collector = null): PayoutService
    {
        return new PayoutService(
            $this->payouts,
            $this->ledger,
            new LedgerAccountLock(),
            $this->balances,
            new SellerRepository(),
            null,
            $collector,
            new PayoutAccountService($this->payoutAccounts)
        );
    }

    private function seedReadyAccount(string $seller, string $accountRef): void
    {
        $this->connection->table('commerce_seller_payout_accounts')->insert([
            'uuid' => 'acct' . substr($seller, -8) . '01',
            'tenant_uuid' => self::TENANT,
            'seller_uuid' => $seller,
            'provider' => self::PROVIDER,
            'account_ref' => $accountRef,
            'readiness_state' => 'ready',
            'last_synced_at' => null,
            'failure_code' => null,
        ]);
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
            'order_uuid' => 'orderDEBTSD01' . substr($seller, -2),
            'idempotency_key' => 'orderDEBTSD01' . substr($seller, -2) . ':' . $seller . ':sale_credit',
        ]);
    }

    /**
     * Simulates the aftermath of a chargeback/refund whose net liability exceeded the
     * seller's proceeds (design spec §2.5/§2.6) -- posts a `chargeback_debit` that drives
     * `available` (hence `debt = max(0, -available)`) negative. A raw ledger post, exactly
     * like every other fixture helper in this suite (`seedAvailable()` above,
     * `ChargebackReversalTest`'s `seedSaleLedger()`) -- this test targets the PAYOUT gate,
     * not chargeback attribution itself.
     */
    private function seedChargebackDebit(string $seller, int $amount, string $currency = 'USD'): void
    {
        $this->ledger->post($this->context, self::TENANT, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller($seller),
            'seller_uuid' => $seller,
            'currency' => $currency,
            'entry_type' => 'chargeback_debit',
            'amount' => -$amount,
            'idempotency_key' => 'cbDEBT' . substr($seller, -2) . ':' . $seller . ':chargeback_debit',
        ]);
    }

    private function countPayouts(string $seller): int
    {
        return $this->connection->table('commerce_payouts')->where('seller_uuid', '=', $seller)->count();
    }

    private function countLedgerEntryType(string $seller, string $entryType): int
    {
        return $this->connection->table('commerce_marketplace_ledger')
            ->where('seller_uuid', '=', $seller)
            ->where('entry_type', '=', $entryType)
            ->count();
    }

    // -----------------------------------------------------------------
    // 1. Manual payout (record()) refused 422 while in debt -- before any row/entry.
    // -----------------------------------------------------------------

    public function testManualPayoutRefusedWhenSellerCarriesDebt(): void
    {
        $seller = 'sellerDEBTMN01';
        $this->seedAvailable($seller, 1000);
        $this->seedChargebackDebit($seller, 1500);

        $balance = $this->balances->balance($this->context, self::TENANT, $seller, 'USD');
        self::assertSame(-500, $balance['available'], 'sanity: the chargeback drove available negative');
        self::assertSame(500, $balance['debt'], 'sanity: debt = max(0, -available)');

        $threw = null;
        try {
            $this->makeService()->record(
                $this->context,
                self::TENANT,
                $seller,
                'USD',
                100,
                'idem-debt-manual-1',
                'ext-debt-manual-1',
                null,
                self::ACTOR
            );
            self::fail('Expected a PayoutException for a manual payout while the seller is in debt.');
        } catch (PayoutException $e) {
            $threw = $e;
        }
        self::assertInstanceOf(PayoutException::class, $threw);
        // Asserts on the message content (not just the exception class), so this test
        // genuinely distinguishes the NEW explicit debt gate from the pre-existing MV4
        // `amount > available` capacity check, which -- given `debt = max(0, -available)`
        // and a validated-positive amount -- ALSO always throws for this exact fixture
        // (debt > 0 mathematically implies available < 0 implies amount > available). The
        // debt gate is additive/defensive per design spec §2.7, not behavior-changing for
        // this scenario; this message assertion is what makes the RED/GREEN distinction
        // real rather than vacuous.
        self::assertStringContainsString('outstanding debt', $threw->getMessage());

        self::assertSame(0, $this->countPayouts($seller), 'no payout row while in debt');
        self::assertSame(0, $this->countLedgerEntryType($seller, 'payout_debit'), 'no payout_debit posted');
    }

    // -----------------------------------------------------------------
    // 2. Provider payout (execute()) refused 422 while in debt -- before any lock claim,
    //    row insert, or reserve_hold post; the collector is never called.
    // -----------------------------------------------------------------

    public function testProviderPayoutRefusedWhenSellerCarriesDebt(): void
    {
        $seller = 'sellerDEBTPV01';
        $this->seedReadyAccount($seller, 'acct-debt-pv');
        $this->seedAvailable($seller, 1000);
        $this->seedChargebackDebit($seller, 1500);

        $collector = new DebtGateFakeCollector([]);
        $service = $this->makeService($collector);

        $threw = null;
        try {
            $service->execute($this->context, self::TENANT, $seller, 'USD', 100, self::ACTOR);
            self::fail('Expected a PayoutException for a provider payout while the seller is in debt.');
        } catch (PayoutException $e) {
            $threw = $e;
        }
        self::assertInstanceOf(PayoutException::class, $threw);
        self::assertStringContainsString(
            'outstanding debt',
            $threw->getMessage(),
            'proves the explicit debt gate fired, not just the pre-existing capacity check'
        );

        self::assertSame(0, $this->countPayouts($seller), 'no payout row while in debt');
        self::assertSame(0, $this->countLedgerEntryType($seller, 'reserve_hold'), 'no reserve_hold posted');
        self::assertCount(0, $collector->calls, 'the collector must never be called when the debt gate refuses.');
    }

    // -----------------------------------------------------------------
    // 3. Batch: skips the indebted seller (no row, no hold) while STILL processing a
    //    solvent seller in the same run -- independent-per-candidate.
    // -----------------------------------------------------------------

    public function testBatchSkipsIndebtedSellerAndStillProcessesSolventSellerInTheSameRun(): void
    {
        $sellerIndebted = 'sellerDEBTBA01';
        $this->seedReadyAccount($sellerIndebted, 'acct-debt-batch');
        $this->seedAvailable($sellerIndebted, 1000);
        $this->seedChargebackDebit($sellerIndebted, 1500);

        $sellerSolvent = 'sellerDEBTBB01';
        $this->seedReadyAccount($sellerSolvent, 'acct-solvent-batch');
        $this->seedAvailable($sellerSolvent, 500);

        $collector = new DebtGateFakeCollector([
            'acct-solvent-batch' => new PayoutResult(PayoutResult::PAID, 'prov-debt-batch-ok'),
        ]);
        $service = $this->makeService($collector);

        $this->bind(LedgerRepository::class, $this->ledger);
        $this->bind(PayoutService::class, $service);

        $command = new PayoutsRunBatchCommand($this->context->getContainer(), $this->context);
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(
            0,
            $this->countPayouts($sellerIndebted),
            'the indebted seller must never get a payout row'
        );
        self::assertSame(
            0,
            $this->countLedgerEntryType($sellerIndebted, 'reserve_hold'),
            'the indebted seller must never get a reserve_hold'
        );
        self::assertCount(
            0,
            array_filter($collector->calls, static fn (string $ref): bool => $ref === 'acct-debt-batch'),
            'the collector must never be called for the indebted seller.'
        );

        $solventPayout = $this->connection->table('commerce_payouts')
            ->where('seller_uuid', '=', $sellerSolvent)->first();
        self::assertNotNull($solventPayout, 'the solvent seller must still be processed in the same run.');
        self::assertSame('paid', $solventPayout['status']);
        self::assertSame(500, (int) $solventPayout['amount']);
    }

    // -----------------------------------------------------------------
    // 4. Solvent seller (debt == 0, available >= amount) unaffected: manual payout
    //    proceeds exactly as MV4.
    // -----------------------------------------------------------------

    public function testSolventSellerManualPayoutProceedsUnaffected(): void
    {
        $seller = 'sellerDEBTOK01';
        $this->seedAvailable($seller, 5000);

        $payout = $this->makeService()->record(
            $this->context,
            self::TENANT,
            $seller,
            'USD',
            3000,
            'idem-debt-ok-manual-1',
            'ext-debt-ok-manual-1',
            null,
            self::ACTOR
        );

        self::assertSame(3000, (int) $payout['amount']);
        self::assertSame(1, $this->countPayouts($seller));
        self::assertSame(2000, $this->balances->available($this->context, self::TENANT, $seller, 'USD'));
    }

    // -----------------------------------------------------------------
    // 5. Solvent seller (debt == 0, available >= amount) unaffected: provider payout
    //    proceeds exactly as MV4.
    // -----------------------------------------------------------------

    public function testSolventSellerProviderPayoutProceedsUnaffected(): void
    {
        $seller = 'sellerDEBTOK02';
        $this->seedReadyAccount($seller, 'acct-debt-ok');
        $this->seedAvailable($seller, 5000);

        $collector = new DebtGateFakeCollector([
            'acct-debt-ok' => new PayoutResult(PayoutResult::PAID, 'prov-debt-ok'),
        ]);
        $payout = $this->makeService($collector)->execute(
            $this->context,
            self::TENANT,
            $seller,
            'USD',
            2000,
            self::ACTOR
        );

        self::assertSame('paid', $payout['status']);
        self::assertSame(2000, (int) $payout['amount']);
        self::assertSame(3000, $this->balances->available($this->context, self::TENANT, $seller, 'USD'));
    }

    // -----------------------------------------------------------------
    // 6. The debt check reads the LOCKED balance, not the unlocked batch candidate hint:
    //    debt developing AFTER the hint would have looked positive is still caught because
    //    executeBatch() re-derives everything fresh under the account lock.
    // -----------------------------------------------------------------

    public function testExecuteBatchDebtCheckUsesTheLockedBalanceNotAStaleUnlockedHint(): void
    {
        $seller = 'sellerDEBTHT01';
        $this->seedReadyAccount($seller, 'acct-debt-hint');
        $this->seedAvailable($seller, 1000);

        // The unlocked candidate hint, captured BEFORE the chargeback lands -- still
        // positive at this point, exactly as PayoutBatchTest's own hint test captures it.
        $candidates = $this->ledger->positiveAvailableCandidates($this->context, self::TENANT);
        $hint = array_values(array_filter($candidates, static fn (array $c): bool => $c['seller_uuid'] === $seller));
        self::assertCount(1, $hint, 'sanity: the seller is a positive-available candidate BEFORE the chargeback');
        self::assertSame(1000, $hint[0]['available']);

        // A concurrent chargeback lands, driving the seller into debt, between the hint
        // read above and executeBatch()'s own locked re-read below.
        $this->seedChargebackDebit($seller, 1500);

        $collector = new DebtGateFakeCollector([]);
        $payout = $this->makeService($collector)->executeBatch($this->context, self::TENANT, $seller, 'USD', null);

        self::assertNull($payout, 'the LOCKED re-read must catch the debt the stale hint never saw.');
        self::assertSame(0, $this->countPayouts($seller));
        self::assertSame(0, $this->countLedgerEntryType($seller, 'reserve_hold'));
        self::assertCount(0, $collector->calls, 'the collector must never be called for a locked-debt skip.');
    }
}

/**
 * Scripted fake payout collector for this suite -- distinct name from every other fake
 * collector in this namespace (mirrors {@see BatchFakeCollector}'s own note). Routes
 * `transfer()` by the destination's opaque `accountRef`.
 */
final class DebtGateFakeCollector implements PayoutCollector
{
    /** @var list<string> */
    public array $calls = [];

    /** @param array<string, PayoutResult|\Throwable> $behaviorsByAccountRef */
    public function __construct(private array $behaviorsByAccountRef)
    {
    }

    public function transfer(
        ApplicationContext $context,
        PayoutDestination $destination,
        PayoutRequest $request
    ): PayoutResult {
        $this->calls[] = $destination->accountRef;

        $ref = $destination->accountRef;
        $behavior = $this->behaviorsByAccountRef[$ref]
            ?? throw new \RuntimeException("DebtGateFakeCollector: no behavior scripted for '{$ref}'.");

        if ($behavior instanceof \Throwable) {
            throw $behavior;
        }

        return $behavior;
    }

    public function status(
        ApplicationContext $context,
        PayoutDestination $destination,
        string $idempotencyKey
    ): PayoutStatusResult {
        throw new \LogicException('DebtGateFakeCollector::status() is not exercised by this suite.');
    }

    public function inspectDestination(
        ApplicationContext $context,
        PayoutDestination $destination
    ): DestinationStatus {
        throw new \LogicException('DebtGateFakeCollector::inspectDestination() is not exercised by this suite.');
    }
}
