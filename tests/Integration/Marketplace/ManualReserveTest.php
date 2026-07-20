<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Extensions\Commerce\Console\ReservesReleaseSweepCommand;
use Glueful\Extensions\Commerce\Marketplace\AdjustmentService;
use Glueful\Extensions\Commerce\Marketplace\ChargebackRepository;
use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\ManualReserveConflictException;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceWorkspaceLock;
use Glueful\Extensions\Commerce\Marketplace\ReservePolicyEventRepository;
use Glueful\Extensions\Commerce\Marketplace\ReservePolicyService;
use Glueful\Extensions\Commerce\Marketplace\ReserveRepository;
use Glueful\Extensions\Commerce\Marketplace\ReserveService;
use Glueful\Extensions\Commerce\Marketplace\SellerBalanceService;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Validation\ValidationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Manual operator reserve hold/release + audited debt forgiveness (design spec §2.8,
 * MV5a Task 15): {@see ReserveService::manualHold()}'s validate-before-any-write
 * discipline, its `(tenant, idempotency_key)` replay-vs-conflict arbiter, the durable
 * `source_kind=manual` row it creates (indefinite `release_at=NULL`, permanently
 * excluded from {@see ReserveService::releaseDue()}'s scheduled sweep),
 * {@see ReserveService::manualRelease()}'s locked-remaining release (reused against
 * ANY reserve, manual or rolling), and operator debt forgiveness via the EXISTING
 * {@see AdjustmentService} (never a mutation of chargeback rows). Service-layer only --
 * the HTTP surface is a later task.
 */
final class ManualReserveTest extends CommerceTestCase
{
    private const TENANT = '';
    private const SELLER = 'sellerMANUAL01';
    private const ACTOR = 'operatorMANUL1';

    private LedgerRepository $ledger;
    private ReserveRepository $reserves;
    private SellerBalanceService $balances;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = new LedgerRepository();
        $this->reserves = new ReserveRepository();
        $this->balances = new SellerBalanceService($this->ledger);
    }

    private function reserveService(): ReserveService
    {
        return new ReserveService(
            new ReservePolicyService(
                new SellerRepository(),
                new MarketplaceWorkspaceLock(),
                new ReservePolicyEventRepository()
            ),
            $this->reserves,
            $this->ledger,
            new LedgerAccountLock()
        );
    }

    private function accountKey(string $seller = self::SELLER): string
    {
        return LedgerRepository::accountKeyForSeller($seller);
    }

    /** @return array<string,mixed> */
    private function reserveRow(string $uuid): array
    {
        return $this->connection->table('commerce_seller_reserves')->where('uuid', '=', $uuid)->first();
    }

    /** @return list<array<string,mixed>> */
    private function ledgerRowsForReserve(string $reserveUuid): array
    {
        return $this->connection->table('commerce_marketplace_ledger')
            ->where('reserve_uuid', '=', $reserveUuid)
            ->orderBy('id', 'ASC')
            ->get();
    }

    private function reserveRowCount(): int
    {
        return $this->connection->table('commerce_seller_reserves')->count();
    }

    private function ledgerRowCount(): int
    {
        return $this->connection->table('commerce_marketplace_ledger')->count();
    }

    // ===================================================================
    // 1. manualHold: creates a tracked row + ledger reserve_hold.
    // ===================================================================

    public function testManualHoldCreatesTrackedManualReserveRowAndLedgerHold(): void
    {
        $row = $this->reserveService()->manualHold(
            $this->context,
            self::TENANT,
            self::SELLER,
            'USD',
            1000,
            'idem-mh-1',
            self::ACTOR,
            'fraud investigation hold'
        );

        self::assertSame('manual', $row['source_kind']);
        self::assertNull($row['seller_order_uuid']);
        self::assertSame(0, (int) $row['reserve_bps_snapshot']);
        self::assertSame(0, (int) $row['reserve_days_snapshot']);
        self::assertNull($row['release_at']);
        self::assertSame('idem-mh-1', $row['idempotency_key']);
        self::assertSame(self::ACTOR, $row['created_by']);
        self::assertSame('fraud investigation hold', $row['reason']);
        self::assertSame('held', $row['status']);
        self::assertSame(1000, (int) $row['amount']);

        $ledgerRows = $this->ledgerRowsForReserve((string) $row['uuid']);
        self::assertCount(1, $ledgerRows);
        $entry = $ledgerRows[0];
        self::assertSame('reserve_hold', $entry['entry_type']);
        self::assertSame(-1000, (int) $entry['amount']);
        self::assertNull($entry['payout_uuid']);
        self::assertSame((string) $row['uuid'], $entry['reserve_uuid']);
        self::assertSame(self::ACTOR, $entry['created_by']);
        self::assertSame('fraud investigation hold', $entry['reason']);
        self::assertSame('manual:idem-mh-1:reserve_hold', $entry['idempotency_key']);

        $balance = $this->balances->balance($this->context, self::TENANT, self::SELLER, 'USD');
        self::assertSame(1000, $balance['reserved']);
        self::assertSame(-1000, $balance['available']);
    }

    // ===================================================================
    // 2. manualHold: NEVER auto-releases -- release_at NULL keeps it out of
    //    the scheduled sweep entirely, even when the sweep actually runs.
    // ===================================================================

    public function testManualHoldNeverAutoReleasesEvenWhenTheScheduledSweepRuns(): void
    {
        $row = $this->reserveService()->manualHold(
            $this->context,
            self::TENANT,
            self::SELLER,
            'USD',
            500,
            'idem-mh-sweep',
            self::ACTOR,
            'emergency hold pending review'
        );

        $candidates = $this->reserves->dueForRelease($this->context, self::TENANT, 100);
        self::assertSame([], $candidates, 'a manual/indefinite hold must never be a sweep candidate.');

        $this->bind(ReserveRepository::class, $this->reserves);
        $this->bind(ReserveService::class, $this->reserveService());

        $command = new ReservesReleaseSweepCommand($this->context->getContainer(), $this->context);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('held', $this->reserveRow((string) $row['uuid'])['status']);
        self::assertCount(1, $this->ledgerRowsForReserve((string) $row['uuid']), 'the sweep must post nothing.');
    }

    // ===================================================================
    // 3. manualHold: validation -- rejected BEFORE any write.
    // ===================================================================

    public function testRejectsBlankActorBeforeAnyWrite(): void
    {
        $this->expectException(ValidationException::class);
        try {
            $this->reserveService()->manualHold(
                $this->context,
                self::TENANT,
                self::SELLER,
                'USD',
                100,
                'idem-blank-actor',
                '',
                'reason'
            );
        } finally {
            self::assertSame(0, $this->reserveRowCount());
            self::assertSame(0, $this->ledgerRowCount());
        }
    }

    public function testRejectsBlankReasonBeforeAnyWrite(): void
    {
        $this->expectException(ValidationException::class);
        try {
            $this->reserveService()->manualHold(
                $this->context,
                self::TENANT,
                self::SELLER,
                'USD',
                100,
                'idem-blank-reason',
                self::ACTOR,
                ''
            );
        } finally {
            self::assertSame(0, $this->reserveRowCount());
            self::assertSame(0, $this->ledgerRowCount());
        }
    }

    public function testRejectsBlankIdempotencyKeyBeforeAnyWrite(): void
    {
        $this->expectException(ValidationException::class);
        try {
            $this->reserveService()->manualHold(
                $this->context,
                self::TENANT,
                self::SELLER,
                'USD',
                100,
                '',
                self::ACTOR,
                'reason'
            );
        } finally {
            self::assertSame(0, $this->reserveRowCount());
            self::assertSame(0, $this->ledgerRowCount());
        }
    }

    public function testRejectsIdempotencyKeyLongerThan128CharsBeforeAnyWrite(): void
    {
        $this->expectException(ValidationException::class);
        try {
            $this->reserveService()->manualHold(
                $this->context,
                self::TENANT,
                self::SELLER,
                'USD',
                100,
                str_repeat('k', 129),
                self::ACTOR,
                'reason'
            );
        } finally {
            self::assertSame(0, $this->reserveRowCount());
            self::assertSame(0, $this->ledgerRowCount());
        }
    }

    public function testAllowsAnIdempotencyKeyOfExactly128Chars(): void
    {
        $key = str_repeat('k', 128);

        $row = $this->reserveService()->manualHold(
            $this->context,
            self::TENANT,
            self::SELLER,
            'USD',
            100,
            $key,
            self::ACTOR,
            'reason'
        );

        self::assertSame($key, $row['idempotency_key']);
    }

    public function testRejectsANonPositiveAmountBeforeAnyWrite(): void
    {
        foreach ([0, -50] as $i => $amount) {
            try {
                $this->reserveService()->manualHold(
                    $this->context,
                    self::TENANT,
                    self::SELLER,
                    'USD',
                    $amount,
                    'idem-badamt-' . $i,
                    self::ACTOR,
                    'reason'
                );
                self::fail('Expected a ValidationException.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        self::assertSame(0, $this->reserveRowCount());
        self::assertSame(0, $this->ledgerRowCount());
    }

    // ===================================================================
    // 4. manualHold: idempotency -- exact replay vs. conflicting reuse.
    // ===================================================================

    public function testExactReplayReturnsTheSameReserveRowWithoutASecondHold(): void
    {
        $service = $this->reserveService();

        $first = $service->manualHold(
            $this->context,
            self::TENANT,
            self::SELLER,
            'USD',
            750,
            'idem-mh-replay',
            self::ACTOR,
            'same reason'
        );

        $second = $service->manualHold(
            $this->context,
            self::TENANT,
            self::SELLER,
            'USD',
            750,
            'idem-mh-replay',
            self::ACTOR,
            'same reason'
        );

        self::assertSame($first['uuid'], $second['uuid']);
        self::assertSame(1, $this->reserveRowCount());
        self::assertCount(1, $this->ledgerRowsForReserve((string) $first['uuid']));

        $balance = $this->balances->balance($this->context, self::TENANT, self::SELLER, 'USD');
        self::assertSame(750, $balance['reserved'], 'a replay must never double-hold.');
    }

    /**
     * Review fix (task-15-review.md IMPORTANT finding): `actor` is deliberately EXCLUDED
     * from {@see ReserveService::verifyManualIdentity()}'s replay-vs-conflict identity check
     * -- an operator's own legitimate idempotency-key retry (e.g. after a session/token
     * refresh changes their recorded actor id) must be a clean replay, never a 409. Before
     * the fix, `manualHold()` unconditionally RE-POSTED the `reserve_hold` ledger entry with
     * the CURRENT caller's actor as `created_by` under the SAME deterministic idempotency
     * key the original call already committed under a DIFFERENT `created_by` --
     * `LedgerRepository::VERIFIED_FIELDS` includes `created_by`, so that re-post
     * deterministically threw an unhandled `LedgerException` instead of returning the
     * existing row. The fix: a verified replay (up-front OR race-recovered) returns the
     * existing row WITHOUT re-posting -- the row's existence already proves its
     * `reserve_hold` entry committed atomically with it on the original call.
     */
    public function testExactReplayWithADifferentActorReturnsTheSameRowWithoutRepostingTheLedger(): void
    {
        $service = $this->reserveService();

        $first = $service->manualHold(
            $this->context,
            self::TENANT,
            self::SELLER,
            'USD',
            750,
            'idem-mh-replay-actor',
            self::ACTOR,
            'same reason'
        );

        $second = $service->manualHold(
            $this->context,
            self::TENANT,
            self::SELLER,
            'USD',
            750,
            'idem-mh-replay-actor',
            'operatorDIFFACT',
            'same reason'
        );

        self::assertSame($first['uuid'], $second['uuid']);
        self::assertSame(1, $this->reserveRowCount());
        self::assertSame(
            self::ACTOR,
            $second['created_by'],
            "the reserve row's ORIGINAL creator is preserved -- a replay never overwrites it."
        );

        $ledgerRows = $this->ledgerRowsForReserve((string) $first['uuid']);
        self::assertCount(1, $ledgerRows, 'a replay by a different actor must never re-post the ledger hold.');
        self::assertSame('reserve_hold', $ledgerRows[0]['entry_type']);
        self::assertSame(
            self::ACTOR,
            $ledgerRows[0]['created_by'],
            'the ledger entry keeps the ORIGINAL actor, not the replaying one.'
        );

        $balance = $this->balances->balance($this->context, self::TENANT, self::SELLER, 'USD');
        self::assertSame(750, $balance['reserved'], 'a replay must never double-hold, even with a different actor.');
    }

    public function testConflictingReuseWithADifferentAmountThrowsAConflict(): void
    {
        $service = $this->reserveService();
        $service->manualHold(
            $this->context,
            self::TENANT,
            self::SELLER,
            'USD',
            400,
            'idem-mh-conflict-amt',
            self::ACTOR,
            'reason'
        );

        $this->expectException(ManualReserveConflictException::class);
        try {
            $service->manualHold(
                $this->context,
                self::TENANT,
                self::SELLER,
                'USD',
                999,
                'idem-mh-conflict-amt',
                self::ACTOR,
                'reason'
            );
        } finally {
            self::assertSame(1, $this->reserveRowCount(), 'a conflicting reuse must never create a second row.');
        }
    }

    public function testConflictingReuseWithADifferentSellerThrowsAConflict(): void
    {
        $service = $this->reserveService();
        $service->manualHold(
            $this->context,
            self::TENANT,
            self::SELLER,
            'USD',
            400,
            'idem-mh-conflict-seller',
            self::ACTOR,
            'reason'
        );

        $this->expectException(ManualReserveConflictException::class);
        $service->manualHold(
            $this->context,
            self::TENANT,
            'sellerOTHER001',
            'USD',
            400,
            'idem-mh-conflict-seller',
            self::ACTOR,
            'reason'
        );
    }

    public function testConflictingReuseWithADifferentCurrencyThrowsAConflict(): void
    {
        $service = $this->reserveService();
        $service->manualHold(
            $this->context,
            self::TENANT,
            self::SELLER,
            'USD',
            400,
            'idem-mh-conflict-cur',
            self::ACTOR,
            'reason'
        );

        $this->expectException(ManualReserveConflictException::class);
        $service->manualHold(
            $this->context,
            self::TENANT,
            self::SELLER,
            'EUR',
            400,
            'idem-mh-conflict-cur',
            self::ACTOR,
            'reason'
        );
    }

    public function testConflictingReuseWithADifferentReasonThrowsAConflict(): void
    {
        $service = $this->reserveService();
        $service->manualHold(
            $this->context,
            self::TENANT,
            self::SELLER,
            'USD',
            400,
            'idem-mh-conflict-reason',
            self::ACTOR,
            'original reason'
        );

        $this->expectException(ManualReserveConflictException::class);
        $service->manualHold(
            $this->context,
            self::TENANT,
            self::SELLER,
            'USD',
            400,
            'idem-mh-conflict-reason',
            self::ACTOR,
            'a completely different reason'
        );
    }

    public function testAPreExistingDuplicateClaimOnTheSameKeyCannotBeDoubleHeld(): void
    {
        // Simulates a concurrent duplicate request that already fully committed --
        // both the reserve row AND its ledger reserve_hold -- under this exact
        // idempotency key, BEFORE this call's own manualHold() ever runs.
        $preExisting = $this->reserves->insertManualHold($this->context, self::TENANT, [
            'uuid' => 'resvMANUALRACE',
            'seller_uuid' => self::SELLER,
            'currency' => 'USD',
            'amount' => 600,
            'idempotency_key' => 'idem-mh-race',
            'created_by' => self::ACTOR,
            'reason' => 'raced hold',
            'held_at' => $this->connection->getDriver()->formatDateTime(),
        ]);
        $this->ledger->post($this->context, self::TENANT, [
            'account_kind' => 'seller',
            'account_key' => $this->accountKey(),
            'seller_uuid' => self::SELLER,
            'currency' => 'USD',
            'entry_type' => 'reserve_hold',
            'amount' => -600,
            'payout_uuid' => null,
            'reserve_uuid' => (string) $preExisting['uuid'],
            'created_by' => self::ACTOR,
            'reason' => 'raced hold',
            'idempotency_key' => 'manual:idem-mh-race:reserve_hold',
        ]);

        $row = $this->reserveService()->manualHold(
            $this->context,
            self::TENANT,
            self::SELLER,
            'USD',
            600,
            'idem-mh-race',
            self::ACTOR,
            'raced hold'
        );

        self::assertSame($preExisting['uuid'], $row['uuid']);
        self::assertSame(1, $this->reserveRowCount());
        self::assertCount(1, $this->ledgerRowsForReserve((string) $preExisting['uuid']), 'never double-held.');
    }

    // ===================================================================
    // 5. manualRelease: derives the locked remaining, records the actor.
    // ===================================================================

    public function testManualReleaseDerivesLockedRemainingAndRecordsTheActor(): void
    {
        $service = $this->reserveService();
        $row = $service->manualHold(
            $this->context,
            self::TENANT,
            self::SELLER,
            'USD',
            800,
            'idem-mr-1',
            self::ACTOR,
            'hold pending review'
        );

        $released = $service->manualRelease($this->context, self::TENANT, (string) $row['uuid'], 'operatorRELEAS');

        self::assertSame(800, $released);

        $updated = $this->reserveRow((string) $row['uuid']);
        self::assertSame('released', $updated['status']);
        self::assertNotNull($updated['closed_at']);
        // The ORIGINAL hold creator is preserved -- only the ledger release entry
        // carries the releasing actor.
        self::assertSame(self::ACTOR, $updated['created_by']);

        $ledgerRows = $this->ledgerRowsForReserve((string) $row['uuid']);
        self::assertCount(2, $ledgerRows, 'reserve_hold + reserve_release');
        $release = $ledgerRows[1];
        self::assertSame('reserve_release', $release['entry_type']);
        self::assertSame(800, (int) $release['amount']);
        self::assertNull($release['payout_uuid']);
        self::assertSame((string) $row['uuid'], $release['reserve_uuid']);
        self::assertSame('operatorRELEAS', $release['created_by']);
        self::assertSame('manual:' . $row['uuid'] . ':release', $release['idempotency_key']);

        $balance = $this->balances->balance($this->context, self::TENANT, self::SELLER, 'USD');
        self::assertSame(0, $balance['reserved']);
        self::assertSame(0, $balance['available']);
    }

    public function testManualReleaseRejectsABlankActor(): void
    {
        $service = $this->reserveService();
        $row = $service->manualHold(
            $this->context,
            self::TENANT,
            self::SELLER,
            'USD',
            200,
            'idem-mr-blankactor',
            self::ACTOR,
            'reason'
        );

        $this->expectException(ValidationException::class);
        $service->manualRelease($this->context, self::TENANT, (string) $row['uuid'], '');
    }

    public function testManualReleaseOfAnUnknownReserveThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->reserveService()->manualRelease($this->context, self::TENANT, 'doesNotExist1', self::ACTOR);
    }

    public function testReplayingManualReleaseIsAVerifiedNoOp(): void
    {
        $service = $this->reserveService();
        $row = $service->manualHold(
            $this->context,
            self::TENANT,
            self::SELLER,
            'USD',
            300,
            'idem-mr-replay',
            self::ACTOR,
            'reason'
        );

        $first = $service->manualRelease($this->context, self::TENANT, (string) $row['uuid'], 'operatorRELEAS');
        self::assertSame(300, $first);

        $second = $service->manualRelease($this->context, self::TENANT, (string) $row['uuid'], 'operatorRELEAS');
        self::assertSame(0, $second, 'a replay must never post a second release.');

        self::assertCount(2, $this->ledgerRowsForReserve((string) $row['uuid']), 'exactly one hold + one release.');
    }

    public function testManualReleaseOfAPartiallyConsumedReserveReleasesOnlyTheRemainder(): void
    {
        $service = $this->reserveService();
        $row = $service->manualHold(
            $this->context,
            self::TENANT,
            self::SELLER,
            'USD',
            1000,
            'idem-mr-partial',
            self::ACTOR,
            'reason'
        );

        // Simulate an earlier proportional reserve-consumption release (e.g. a
        // chargeback drawing from this same reserve) landing BEFORE the manual
        // release -- mirrors ReserveReleaseSweepTest::seedPartialConsumption().
        $this->ledger->post($this->context, self::TENANT, [
            'account_kind' => 'seller',
            'account_key' => $this->accountKey(),
            'seller_uuid' => self::SELLER,
            'currency' => 'USD',
            'entry_type' => 'reserve_release',
            'amount' => 400,
            'payout_uuid' => null,
            'reserve_uuid' => (string) $row['uuid'],
            'chargeback_uuid' => 'cbMANUALPART1',
            'idempotency_key' => 'chargeback:cbMANUALPART1:' . self::SELLER . ':' . $row['uuid'] . ':reserve_release',
        ]);

        $released = $service->manualRelease($this->context, self::TENANT, (string) $row['uuid'], 'operatorRELEAS');

        self::assertSame(600, $released, 'only the derived remainder (1000 - 400) must release.');
        self::assertSame('released', $this->reserveRow((string) $row['uuid'])['status']);
    }

    public function testManualReleaseAlsoWorksOnARollingReserveReleasedEarly(): void
    {
        // A rolling (settlement-created) hold, NOT yet due -- proves manualRelease()
        // bypasses releaseDue()'s release_at gate entirely for an operator override.
        $future = gmdate('Y-m-d H:i:s', time() + 3600 * 24 * 30);
        $row = $this->reserves->insertRollingHold($this->context, self::TENANT, [
            'uuid' => 'resvMANUALROLL',
            'seller_uuid' => self::SELLER,
            'currency' => 'USD',
            'seller_order_uuid' => 'selordMANROLL',
            'amount' => 250,
            'reserve_bps_snapshot' => 250,
            'reserve_days_snapshot' => 30,
            'held_at' => gmdate('Y-m-d H:i:s'),
            'release_at' => $future,
        ]);
        $this->ledger->post($this->context, self::TENANT, [
            'account_kind' => 'seller',
            'account_key' => $this->accountKey(),
            'seller_uuid' => self::SELLER,
            'currency' => 'USD',
            'entry_type' => 'reserve_hold',
            'amount' => -250,
            'order_uuid' => 'orderMANROLL',
            'seller_order_uuid' => 'selordMANROLL',
            'payout_uuid' => null,
            'reserve_uuid' => (string) $row['uuid'],
            'idempotency_key' => 'orderMANROLL:' . self::SELLER . ':reserve_hold',
        ]);

        $released = $this->reserveService()->manualRelease(
            $this->context,
            self::TENANT,
            (string) $row['uuid'],
            self::ACTOR
        );

        self::assertSame(250, $released);
        self::assertSame('released', $this->reserveRow((string) $row['uuid'])['status']);

        // Never selected by the sweep afterward either (already released).
        self::assertSame([], $this->reserves->dueForRelease($this->context, self::TENANT, 100));
    }

    // ===================================================================
    // 6. Debt forgiveness: an explicit audited `adjustment` credit via the
    //    EXISTING AdjustmentService -- chargeback rows stay byte-unchanged.
    // ===================================================================

    public function testDebtForgivenessCreditsASellerOutOfDebtWithoutTouchingChargebackRows(): void
    {
        $seller = 'sellerDEBT0001';
        $accountKey = $this->accountKey($seller);
        $chargebackUuid = 'cbDEBTFORGIVE1';

        // Drive the seller into debt: 2000 earned, a 2500 chargeback debit with no
        // reserve to absorb it (mirrors ChargebackService's own chargeback_debit
        // posting shape).
        $this->ledger->post($this->context, self::TENANT, [
            'account_kind' => 'seller',
            'account_key' => $accountKey,
            'seller_uuid' => $seller,
            'currency' => 'USD',
            'entry_type' => 'sale_credit',
            'amount' => 2000,
            'order_uuid' => 'orderDEBT00001',
            'idempotency_key' => 'orderDEBT00001:' . $seller . ':sale_credit',
        ]);

        $chargebacks = new ChargebackRepository();
        $chargebackRow = $chargebacks->insert($this->context, self::TENANT, [
            'provider' => 'stripe',
            'provider_event_id' => 'evt_debt_forgive_1',
            'payment_reference' => 'pay_ref_debt_1',
            'order_uuid' => 'orderDEBT00001',
            'amount' => 2500,
            'currency' => 'USD',
            'reason_code' => 'fraudulent',
            'occurred_at' => '2026-07-01 12:00:00',
            'kind' => 'chargeback',
            'related_chargeback_uuid' => null,
            'status' => 'received',
        ]);

        $this->ledger->post($this->context, self::TENANT, [
            'account_kind' => 'seller',
            'account_key' => $accountKey,
            'seller_uuid' => $seller,
            'currency' => 'USD',
            'entry_type' => 'chargeback_debit',
            'amount' => -2500,
            'order_uuid' => 'orderDEBT00001',
            'chargeback_uuid' => $chargebackUuid,
            'idempotency_key' => $chargebackUuid . ':' . $seller . ':chargeback_debit',
        ]);

        $before = $this->balances->balance($this->context, self::TENANT, $seller, 'USD');
        self::assertSame(-500, $before['available']);
        self::assertSame(500, $before['debt']);

        $chargebackSnapshot = $this->connection->table('commerce_chargebacks')
            ->where('uuid', '=', (string) $chargebackRow['uuid'])
            ->first();

        $adjustments = new AdjustmentService($this->ledger, new LedgerAccountLock());
        $adjustments->post(
            $this->context,
            self::TENANT,
            $accountKey,
            'USD',
            500,
            'debt forgiveness: chargeback ' . $chargebackUuid,
            'forgive-' . $chargebackUuid,
            self::ACTOR
        );

        $after = $this->balances->balance($this->context, self::TENANT, $seller, 'USD');
        self::assertSame(0, $after['available']);
        self::assertSame(0, $after['debt']);
        self::assertSame(500, $after['adjustments']);

        // The chargeback row itself is byte-for-byte unchanged -- forgiveness is a
        // NEW compensating credit, never a mutation of chargeback history.
        $chargebackAfter = $this->connection->table('commerce_chargebacks')
            ->where('uuid', '=', (string) $chargebackRow['uuid'])
            ->first();
        self::assertSame($chargebackSnapshot, $chargebackAfter);
    }

    public function testDebtForgivenessReplayOfTheSameIdempotencyKeyNeverDoubleCredits(): void
    {
        $seller = 'sellerDEBT0002';
        $accountKey = $this->accountKey($seller);

        $this->ledger->post($this->context, self::TENANT, [
            'account_kind' => 'seller',
            'account_key' => $accountKey,
            'seller_uuid' => $seller,
            'currency' => 'USD',
            'entry_type' => 'chargeback_debit',
            'amount' => -300,
            'chargeback_uuid' => 'cbDEBTREPLAY01',
            'idempotency_key' => 'cbDEBTREPLAY01:' . $seller . ':chargeback_debit',
        ]);

        $adjustments = new AdjustmentService($this->ledger, new LedgerAccountLock());
        $adjustments->post(
            $this->context,
            self::TENANT,
            $accountKey,
            'USD',
            300,
            'debt forgiveness',
            'forgive-replay-1',
            self::ACTOR
        );
        $adjustments->post(
            $this->context,
            self::TENANT,
            $accountKey,
            'USD',
            300,
            'debt forgiveness',
            'forgive-replay-1',
            self::ACTOR
        );

        $balance = $this->balances->balance($this->context, self::TENANT, $seller, 'USD');
        self::assertSame(0, $balance['available'], 'a replay must never double-credit.');
        self::assertSame(
            1,
            $this->connection->table('commerce_marketplace_ledger')
                ->where('idempotency_key', '=', 'adjustment:' . $accountKey . ':forgive-replay-1')
                ->count()
        );
    }
}
