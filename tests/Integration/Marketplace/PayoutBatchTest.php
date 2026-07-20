<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Console\PayoutsRunBatchCommand;
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
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `PayoutService::executeBatch()` (design spec §2.6, MV4 Task 9): the batch amount is
 * derived from the seller/currency account's `available` balance AS OF the moment the
 * account lock is claimed -- never from the unlocked candidate hint
 * ({@see LedgerRepository::positiveAvailableCandidates()}) -- capped by the optional
 * per-currency `maximums` config and refused below `minimums`. This is what lets
 * concurrent batch workers serialize on the SAME account lock instead of needing a
 * separate candidate lease. `PayoutsRunBatchCommand` processes every candidate
 * independently -- one seller's failure never aborts the rest.
 */
final class PayoutBatchTest extends CommerceTestCase
{
    private const TENANT = '';
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
            'order_uuid' => 'orderBATCHSD1' . substr($seller, -2),
            'idempotency_key' => 'orderBATCHSD1' . substr($seller, -2) . ':' . $seller . ':sale_credit',
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

    // -----------------------------------------------------------------
    // Amount derivation: LOCKED available, not the unlocked candidate hint.
    // -----------------------------------------------------------------

    public function testBatchDerivesAmountFromTheLockedAvailableNotTheUnlockedHint(): void
    {
        $seller = 'sellerBATCHHT1';
        $this->seedReadyAccount($seller, 'acct-hint');
        $this->seedAvailable($seller, 1000);

        // The unlocked candidate hint, captured BEFORE a concurrent posting lands.
        $candidates = $this->ledger->positiveAvailableCandidates($this->context, self::TENANT);
        $hint = array_values(array_filter($candidates, static fn (array $c): bool => $c['seller_uuid'] === $seller))[0];
        self::assertSame(1000, $hint['available']);

        // A concurrent posting drains the TRUE balance between the hint read and the batch
        // actually claiming the lock -- a manual payout of 300 (an operator action, or any
        // other posting) lands first.
        $this->makeService(null)->record(
            $this->context,
            self::TENANT,
            $seller,
            'USD',
            300,
            'idem-batch-hint-drain',
            'ext-batch-hint-drain',
            null,
            'operatorBATCH1'
        );

        $collector = new BatchFakeCollector(['acct-hint' => new PayoutResult(PayoutResult::PAID, 'prov-hint')]);
        $payout = $this->makeService($collector)->executeBatch($this->context, self::TENANT, $seller, 'USD', null);

        self::assertNotNull($payout);
        self::assertSame(700, (int) $payout['amount'], 'must derive from the LOCKED available (700), not the stale hint (1000).');
    }

    // -----------------------------------------------------------------
    // Per-currency maximum cap.
    // -----------------------------------------------------------------

    public function testBatchHonorsThePerCurrencyMaximumCap(): void
    {
        $this->context->overrideConfig('commerce.marketplace.payouts.maximums.USD', 400);
        $seller = 'sellerBATCHMX1';
        $this->seedReadyAccount($seller, 'acct-max');
        $this->seedAvailable($seller, 1000);

        $collector = new BatchFakeCollector(['acct-max' => new PayoutResult(PayoutResult::PAID, 'prov-max')]);
        $payout = $this->makeService($collector)->executeBatch($this->context, self::TENANT, $seller, 'USD', null);

        self::assertNotNull($payout);
        self::assertSame(400, (int) $payout['amount'], 'must be capped at the configured maximum, not the full 1000 available.');
    }

    // -----------------------------------------------------------------
    // Below the per-currency minimum: skipped entirely.
    // -----------------------------------------------------------------

    public function testBatchSkipsACandidateBelowThePerCurrencyMinimum(): void
    {
        $this->context->overrideConfig('commerce.marketplace.payouts.minimums.USD', 100);
        $seller = 'sellerBATCHMN1';
        $this->seedReadyAccount($seller, 'acct-min');
        $this->seedAvailable($seller, 50);

        $collector = new BatchFakeCollector([]);
        $payout = $this->makeService($collector)->executeBatch($this->context, self::TENANT, $seller, 'USD', null);

        self::assertNull($payout, 'below the configured minimum must be skipped, not attempted.');
        self::assertSame(0, $this->connection->table('commerce_payouts')->where('seller_uuid', '=', $seller)->count());
        self::assertSame(
            0,
            $this->connection->table('commerce_marketplace_ledger')
                ->where('seller_uuid', '=', $seller)->where('entry_type', '=', 'reserve_hold')->count(),
            'no hold must ever be posted for a skipped candidate.'
        );
        self::assertCount(0, $collector->transferCalls, 'the collector must never be called for a skipped candidate.');
    }

    // -----------------------------------------------------------------
    // Serialization: concurrent-equivalent sequential workers can't duplicate the amount.
    // -----------------------------------------------------------------

    public function testSequentialBatchWorkersOnTheSameAccountSerializeAndCannotDuplicateTheAmount(): void
    {
        $this->context->overrideConfig('commerce.marketplace.payouts.maximums.USD', 600);
        $seller = 'sellerBATCHCC1';
        $this->seedReadyAccount($seller, 'acct-cc');
        $this->seedAvailable($seller, 1000);

        // Worker A: claims the lock first, derives min(1000, 600) = 600.
        $collectorA = new BatchFakeCollector(['acct-cc' => new PayoutResult(PayoutResult::PAID, 'prov-cc-a')]);
        $payoutA = $this->makeService($collectorA)->executeBatch($this->context, self::TENANT, $seller, 'USD', null);
        self::assertNotNull($payoutA);
        self::assertSame(600, (int) $payoutA['amount']);

        // Worker B: the SAME lock now serializes it after A committed -- it re-reads a
        // now-drained available (1000 - 600 = 400) and derives LESS than the cap (400, not 600).
        $collectorB = new BatchFakeCollector(['acct-cc' => new PayoutResult(PayoutResult::PAID, 'prov-cc-b')]);
        $payoutB = $this->makeService($collectorB)->executeBatch($this->context, self::TENANT, $seller, 'USD', null);
        self::assertNotNull($payoutB);
        self::assertSame(400, (int) $payoutB['amount'], 'the second worker must derive LESS from the drained balance.');
        self::assertNotSame($payoutA['uuid'], $payoutB['uuid']);

        // Worker C: the account is now fully drained -- must be skipped entirely, never a
        // third payout duplicating any amount.
        $collectorC = new BatchFakeCollector(['acct-cc' => new PayoutResult(PayoutResult::PAID, 'prov-cc-c')]);
        $payoutC = $this->makeService($collectorC)->executeBatch($this->context, self::TENANT, $seller, 'USD', null);
        self::assertNull($payoutC, 'a fully-drained account must be skipped, never a duplicate payout.');

        self::assertSame(
            2,
            $this->connection->table('commerce_payouts')->where('seller_uuid', '=', $seller)->count(),
            'exactly two payouts total -- the amount was never duplicated across workers.'
        );

        $balance = $this->balances->balance($this->context, self::TENANT, $seller, 'USD');
        self::assertSame(0, $balance['available']);
        self::assertSame(1000, $balance['paid_out']);
    }

    // -----------------------------------------------------------------
    // Candidate independence: one failing/ambiguous seller never aborts the batch.
    // -----------------------------------------------------------------

    public function testOneFailingSellerDoesNotAbortTheBatch(): void
    {
        // Seller A: positive balance, but NO ready payout destination -- executeBatch() must
        // refuse (PayoutException) BEFORE any hold, caught by the command as a failure.
        $sellerA = 'sellerBATCHFA1';
        $this->seedAvailable($sellerA, 500);

        // Seller B: ready, but the provider throws mid-transfer -- an ambiguous (UNKNOWN)
        // outcome, caught by the command separately from a hard failure.
        $sellerB = 'sellerBATCHFB1';
        $this->seedReadyAccount($sellerB, 'acct-fail-b');
        $this->seedAvailable($sellerB, 500);

        // Seller C: ready and succeeds normally.
        $sellerC = 'sellerBATCHFC1';
        $this->seedReadyAccount($sellerC, 'acct-ok-c');
        $this->seedAvailable($sellerC, 500);

        $collector = new BatchFakeCollector([
            'acct-fail-b' => new \RuntimeException('gateway unreachable'),
            'acct-ok-c' => new PayoutResult(PayoutResult::PAID, 'prov-ok-c'),
        ]);
        $service = $this->makeService($collector);

        $this->bind(LedgerRepository::class, $this->ledger);
        $this->bind(PayoutService::class, $service);

        $command = new PayoutsRunBatchCommand($this->context->getContainer(), $this->context);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $payoutC = $this->connection->table('commerce_payouts')->where('seller_uuid', '=', $sellerC)->first();
        self::assertNotNull($payoutC, 'seller C must still be processed despite seller A/B failing.');
        self::assertSame('paid', $payoutC['status']);

        self::assertSame(
            0,
            $this->connection->table('commerce_payouts')->where('seller_uuid', '=', $sellerA)->count(),
            'seller A must never get a payout row -- refused before any reserve.'
        );

        $payoutB = $this->connection->table('commerce_payouts')->where('seller_uuid', '=', $sellerB)->first();
        self::assertNotNull($payoutB, 'seller B DID reserve (a hold exists) -- the ambiguous outcome leaves it pending.');
        self::assertSame('pending', $payoutB['status']);
    }
}

/**
 * Scripted fake payout collector for the batch suite -- distinct name from every other
 * fake collector in this namespace. Routes `transfer()` by the destination's opaque
 * `accountRef` (each seller under test gets its own distinct ref) so ONE collector instance
 * can script DIFFERENT outcomes for DIFFERENT candidates within the same batch run.
 */
final class BatchFakeCollector implements PayoutCollector
{
    /** @var list<string> */
    public array $transferCalls = [];

    /** @param array<string, PayoutResult|\Throwable> $behaviorsByAccountRef */
    public function __construct(private array $behaviorsByAccountRef)
    {
    }

    public function transfer(
        ApplicationContext $context,
        PayoutDestination $destination,
        PayoutRequest $request
    ): PayoutResult {
        $this->transferCalls[] = $destination->accountRef;

        $behavior = $this->behaviorsByAccountRef[$destination->accountRef]
            ?? throw new \RuntimeException("BatchFakeCollector: no behavior scripted for '{$destination->accountRef}'.");

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
        throw new \LogicException('BatchFakeCollector::status() is not exercised by this suite.');
    }

    public function inspectDestination(
        ApplicationContext $context,
        PayoutDestination $destination
    ): DestinationStatus {
        throw new \LogicException('BatchFakeCollector::inspectDestination() is not exercised by this suite.');
    }
}
