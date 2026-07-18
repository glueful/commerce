<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
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
use Glueful\Http\Exceptions\Client\NotFoundException;

/**
 * Provider-sourced payout-destination readiness (design spec §2.7, MV4 Task 8):
 * {@see PayoutAccountRepository}/{@see PayoutAccountService} (attach, DNS-style
 * sync, the guarded apply) and the {@see PayoutService::execute()} reserve-time
 * readiness gate that replaces Task 7's non-gating `resolveDestination()` stub.
 */
final class PayoutAccountReadinessTest extends CommerceTestCase
{
    private const TENANT = '';
    private const SELLER = 'sellerREADY01';
    private const ACTOR = 'operatorREADY1';
    private const PROVIDER_A = 'payvia';
    private const PROVIDER_B = 'stripe';

    private LedgerRepository $ledger;
    private PayoutRepository $payouts;
    private PayoutAccountRepository $accounts;
    private SellerBalanceService $balances;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = new LedgerRepository();
        $this->payouts = new PayoutRepository();
        $this->accounts = new PayoutAccountRepository();
        $this->balances = new SellerBalanceService($this->ledger);

        // execute()'s readiness gate resolves its provider from this config key
        // (design spec §2.9's single-default-provider posture) -- fixed to
        // PROVIDER_A so every execute()-driving test below targets the exact
        // provider its readiness setup attaches/syncs.
        $this->context->overrideConfig('commerce.marketplace.payouts.default_provider', self::PROVIDER_A);
    }

    private function accountService(?PayoutCollector $collector = null): PayoutAccountService
    {
        return new PayoutAccountService($this->accounts, null, $collector);
    }

    private function payoutService(?PayoutCollector $collector, ?PayoutAccountService $accounts = null): PayoutService
    {
        return new PayoutService(
            $this->payouts,
            $this->ledger,
            new LedgerAccountLock(),
            $this->balances,
            null,
            $collector,
            $accounts ?? $this->accountService()
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
            'order_uuid' => 'orderREADYSD1',
            'idempotency_key' => 'orderREADYSD1:' . self::SELLER . ':sale_credit',
        ]);
    }

    // -----------------------------------------------------------------
    // Reserve-time readiness gate: refuses BEFORE any hold/row (design spec §2.7)
    // -----------------------------------------------------------------

    public function testExecuteRefusesWhenNoPayoutAccountRowExists(): void
    {
        $this->seedAvailable(1000);
        $collector = new ReadinessFakeCollector();
        $service = $this->payoutService($collector);

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
        self::assertCount(0, $collector->transferCalls, 'the collector must never be called when the gate refuses.');
    }

    public function testExecuteRefusesWhenAccountExistsButIsNotYetReady(): void
    {
        $this->seedAvailable(1000);
        $this->accountService()->attach(
            $this->context,
            self::TENANT,
            self::SELLER,
            self::PROVIDER_A,
            'acct-pending-1',
            self::ACTOR
        );
        // Freshly attached -- still `pending`, never synced ready.

        $collector = new ReadinessFakeCollector();
        $service = $this->payoutService($collector);

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

    public function testExecuteRefusesWhenNoPayoutAccountServiceIsBound(): void
    {
        $this->seedAvailable(1000);
        $service = new PayoutService(
            $this->payouts,
            $this->ledger,
            new LedgerAccountLock(),
            $this->balances,
            null,
            new ReadinessFakeCollector(),
            null
        );

        $threw = null;
        try {
            $service->execute($this->context, self::TENANT, self::SELLER, 'USD', 200, self::ACTOR);
        } catch (PayoutException $e) {
            $threw = $e;
        }
        self::assertInstanceOf(PayoutException::class, $threw);
        self::assertSame(0, $this->connection->table('commerce_payouts')->count());
    }

    // -----------------------------------------------------------------
    // sync(): readiness is provider-sourced, never operator-asserted
    // -----------------------------------------------------------------

    public function testSyncAppliesReadyStateFromInspectDestination(): void
    {
        $attached = $this->accountService()->attach(
            $this->context,
            self::TENANT,
            self::SELLER,
            self::PROVIDER_A,
            'acct-ready-1',
            self::ACTOR
        );
        self::assertSame('pending', $attached['readiness_state']);
        self::assertNull($attached['last_synced_at']);

        $collector = new ReadinessFakeCollector(inspectQueue: [new DestinationStatus(DestinationStatus::READY)]);
        $result = $this->accountService($collector)->sync($this->context, self::TENANT, self::SELLER, self::PROVIDER_A);

        self::assertSame('ready', $result['readiness_state']);
        self::assertNull($result['failure_code']);
        self::assertNotNull($result['last_synced_at']);
        self::assertCount(1, $collector->inspectCalls);
        self::assertSame('acct-ready-1', $collector->inspectCalls[0]['accountRef']);
        self::assertSame(self::PROVIDER_A, $collector->inspectCalls[0]['provider']);
    }

    public function testSyncAppliesRestrictedStateAndFailureCodeFromInspectDestination(): void
    {
        $this->accountService()->attach(
            $this->context,
            self::TENANT,
            self::SELLER,
            self::PROVIDER_A,
            'acct-restricted-1',
            self::ACTOR
        );

        $collector = new ReadinessFakeCollector(
            inspectQueue: [new DestinationStatus(DestinationStatus::RESTRICTED, 'kyc_required')]
        );
        $result = $this->accountService($collector)->sync($this->context, self::TENANT, self::SELLER, self::PROVIDER_A);

        self::assertSame('restricted', $result['readiness_state']);
        self::assertSame('kyc_required', $result['failure_code']);
    }

    public function testSyncThrowsNotFoundWhenNoAccountRowExists(): void
    {
        $threw = null;
        try {
            $this->accountService(new ReadinessFakeCollector())
                ->sync($this->context, self::TENANT, self::SELLER, self::PROVIDER_A);
        } catch (NotFoundException $e) {
            $threw = $e;
        }
        self::assertInstanceOf(NotFoundException::class, $threw);
    }

    // -----------------------------------------------------------------
    // Guarded apply: a concurrent account_ref change makes a stale result a no-op
    // -----------------------------------------------------------------

    public function testGuardedApplyIsANoOpWhenAccountRefChangesDuringInspection(): void
    {
        $accountService = $this->accountService();
        $accountService->attach($this->context, self::TENANT, self::SELLER, self::PROVIDER_A, 'acct-stale-A', self::ACTOR);

        // Runs INSIDE inspectDestination() -- simulating a concurrent attach() landing while
        // the provider I/O for 'acct-stale-A' is still in flight (design spec §2.7: inspection
        // I/O never runs under a DB lock, so exactly this race is possible in production).
        $concurrentAttach = function () use ($accountService): DestinationStatus {
            $accountService->attach(
                $this->context,
                self::TENANT,
                self::SELLER,
                self::PROVIDER_A,
                'acct-stale-B',
                self::ACTOR
            );

            return new DestinationStatus(DestinationStatus::READY);
        };

        $collector = new ReadinessFakeCollector(inspectQueue: [$concurrentAttach]);
        $result = $this->accountService($collector)->sync($this->context, self::TENANT, self::SELLER, self::PROVIDER_A);

        // The guarded apply's WHERE (uuid, provider, account_ref) no longer matches: the row was
        // reattached to 'acct-stale-B' (and reset to pending by attach()) WHILE the READY result
        // for the OLD 'acct-stale-A' ref was in flight. That stale READY result must never land.
        self::assertSame('acct-stale-B', $result['account_ref']);
        self::assertSame(
            'pending',
            $result['readiness_state'],
            'a stale inspection result for a superseded account_ref must be a no-op.'
        );
        self::assertNull($result['last_synced_at'], 'a no-op guarded apply must not stamp last_synced_at.');
    }

    // -----------------------------------------------------------------
    // Per-provider isolation
    // -----------------------------------------------------------------

    public function testAttachAndSyncForOneProviderNeverOverwritesAnother(): void
    {
        $accountService = $this->accountService();
        $accountService->attach($this->context, self::TENANT, self::SELLER, self::PROVIDER_A, 'acct-A', self::ACTOR);
        $accountService->attach($this->context, self::TENANT, self::SELLER, self::PROVIDER_B, 'acct-B', self::ACTOR);

        $collectorA = new ReadinessFakeCollector(inspectQueue: [new DestinationStatus(DestinationStatus::READY)]);
        $this->accountService($collectorA)->sync($this->context, self::TENANT, self::SELLER, self::PROVIDER_A);

        $rowA = $this->accounts->findBySellerProvider($this->context, self::TENANT, self::SELLER, self::PROVIDER_A);
        $rowB = $this->accounts->findBySellerProvider($this->context, self::TENANT, self::SELLER, self::PROVIDER_B);
        self::assertNotNull($rowA);
        self::assertNotNull($rowB);

        self::assertSame('ready', $rowA['readiness_state']);
        self::assertSame('acct-A', $rowA['account_ref']);
        self::assertSame('pending', $rowB['readiness_state'], 'syncing provider A must never touch provider B.');
        self::assertSame('acct-B', $rowB['account_ref']);
        self::assertNotSame($rowA['uuid'], $rowB['uuid']);

        // Re-attaching provider B must not touch provider A's already-ready row.
        $accountService->attach($this->context, self::TENANT, self::SELLER, self::PROVIDER_B, 'acct-B-2', self::ACTOR);
        $rowAAfter = $this->accounts->findBySellerProvider($this->context, self::TENANT, self::SELLER, self::PROVIDER_A);
        self::assertNotNull($rowAAfter);
        self::assertSame('ready', $rowAAfter['readiness_state']);
        self::assertSame('acct-A', $rowAAfter['account_ref']);
    }

    // -----------------------------------------------------------------
    // In-flight destination snapshot
    // -----------------------------------------------------------------

    public function testInFlightPayoutKeepsSnapshottedDestinationRefAcrossLaterAccountChange(): void
    {
        $this->seedAvailable(1000);
        $accountService = $this->accountService();
        $accountService->attach($this->context, self::TENANT, self::SELLER, self::PROVIDER_A, 'acct-original', self::ACTOR);
        $this->accountService(new ReadinessFakeCollector(inspectQueue: [new DestinationStatus(DestinationStatus::READY)]))
            ->sync($this->context, self::TENANT, self::SELLER, self::PROVIDER_A);

        $transferCollector = new ReadinessFakeCollector(
            transferQueue: [new PayoutResult(PayoutResult::PAID, 'prov-snap')]
        );
        $service = $this->payoutService($transferCollector, $accountService);

        $payout = $service->execute($this->context, self::TENANT, self::SELLER, 'USD', 300, self::ACTOR);
        self::assertSame('acct-original', $payout['destination_ref']);

        // A later account change must never retarget the already-reserved payout.
        $accountService->attach($this->context, self::TENANT, self::SELLER, self::PROVIDER_A, 'acct-changed', self::ACTOR);

        $persisted = $this->payouts->findByUuid($this->context, self::TENANT, (string) $payout['uuid']);
        self::assertNotNull($persisted);
        self::assertSame(
            'acct-original',
            $persisted['destination_ref'],
            'the payout row must keep its snapshotted destination_ref.'
        );
    }

    // -----------------------------------------------------------------
    // attach() validation
    // -----------------------------------------------------------------

    public function testAttachRejectsBlankAccountRef(): void
    {
        $threw = null;
        try {
            $this->accountService()->attach($this->context, self::TENANT, self::SELLER, self::PROVIDER_A, '   ', self::ACTOR);
        } catch (PayoutException $e) {
            $threw = $e;
        }
        self::assertInstanceOf(PayoutException::class, $threw);
        self::assertSame(0, $this->connection->table('commerce_seller_payout_accounts')->count());
    }

    public function testAttachRejectsBlankProvider(): void
    {
        $threw = null;
        try {
            $this->accountService()->attach($this->context, self::TENANT, self::SELLER, '   ', 'acct-1', self::ACTOR);
        } catch (PayoutException $e) {
            $threw = $e;
        }
        self::assertInstanceOf(PayoutException::class, $threw);
        self::assertSame(0, $this->connection->table('commerce_seller_payout_accounts')->count());
    }

    public function testAttachStoresOnlyTheOpaqueAccountReference(): void
    {
        $row = $this->accountService()->attach(
            $this->context,
            self::TENANT,
            self::SELLER,
            self::PROVIDER_A,
            'acct-opaque-1',
            self::ACTOR
        );

        self::assertSame(['id', 'uuid', 'tenant_uuid', 'seller_uuid', 'provider', 'account_ref', 'readiness_state',
            'last_synced_at', 'failure_code', 'created_at', 'updated_at',
        ], array_keys($row), 'commerce stores no raw bank/KYC/PII fields alongside the opaque account_ref.');
        self::assertSame('acct-opaque-1', $row['account_ref']);
    }
}

/**
 * Scripted fake payout collector for the readiness gate/sync suite. Distinct from
 * {@see FakePayoutCollector} (Task 7's PayoutSagaTest, same namespace) so both test files can
 * load in the same PHPUnit process without a class-name collision.
 *
 * `$inspectQueue` entries may be a plain {@see DestinationStatus} OR a
 * `callable(): DestinationStatus` -- the callable form runs arbitrary code (e.g. a concurrent
 * `attach()`) at the exact moment `inspectDestination()` is invoked, before returning its result,
 * to simulate the race the guarded apply exists for.
 */
final class ReadinessFakeCollector implements PayoutCollector
{
    /** @var list<PayoutResult|\Throwable> */
    private array $transferQueue;

    /** @var list<DestinationStatus|callable(): DestinationStatus> */
    private array $inspectQueue;

    /** @var list<array{provider: string, accountRef: string, idempotencyKey: string}> */
    public array $transferCalls = [];

    /** @var list<array{provider: string, accountRef: string}> */
    public array $inspectCalls = [];

    /**
     * @param list<PayoutResult|\Throwable> $transferQueue
     * @param list<DestinationStatus|callable(): DestinationStatus> $inspectQueue
     */
    public function __construct(array $transferQueue = [], array $inspectQueue = [])
    {
        $this->transferQueue = $transferQueue;
        $this->inspectQueue = $inspectQueue;
    }

    public function transfer(
        ApplicationContext $context,
        PayoutDestination $destination,
        PayoutRequest $request
    ): PayoutResult {
        $this->transferCalls[] = [
            'provider' => $destination->provider,
            'accountRef' => $destination->accountRef,
            'idempotencyKey' => $request->idempotencyKey,
        ];

        if ($this->transferQueue === []) {
            throw new \RuntimeException('ReadinessFakeCollector transfer queue exhausted.');
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
        throw new \LogicException('ReadinessFakeCollector::status() is not exercised by this suite.');
    }

    public function inspectDestination(
        ApplicationContext $context,
        PayoutDestination $destination
    ): DestinationStatus {
        $this->inspectCalls[] = [
            'provider' => $destination->provider,
            'accountRef' => $destination->accountRef,
        ];

        if ($this->inspectQueue === []) {
            throw new \RuntimeException('ReadinessFakeCollector inspect queue exhausted.');
        }

        $next = array_shift($this->inspectQueue);

        return is_callable($next) ? $next() : $next;
    }
}
