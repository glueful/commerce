<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Extensions\Commerce\Console\ReservesReleaseSweepCommand;
use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceWorkspaceLock;
use Glueful\Extensions\Commerce\Marketplace\ReservePolicyEventRepository;
use Glueful\Extensions\Commerce\Marketplace\ReservePolicyService;
use Glueful\Extensions\Commerce\Marketplace\ReserveRepository;
use Glueful\Extensions\Commerce\Marketplace\ReserveService;
use Glueful\Extensions\Commerce\Marketplace\SellerBalanceService;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Scheduled reserve-release sweep (design spec §2.3, MV5a Task 8):
 * {@see ReserveService::releaseDue()}'s claim-lock -> re-read -> derive-remaining ->
 * conditional-post -> CAS-mark-released flow, {@see ReserveRepository::dueForRelease()}'s
 * due-selection filter (due rolling holds only -- never a not-yet-due or manual/indefinite
 * hold), and {@see ReservesReleaseSweepCommand}'s per-row independence discipline.
 */
final class ReserveReleaseSweepTest extends CommerceTestCase
{
    private const TENANT = '';

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

    private function past(int $secondsAgo = 3600): string
    {
        return gmdate('Y-m-d H:i:s', time() - $secondsAgo);
    }

    private function future(int $secondsAhead = 3600): string
    {
        return gmdate('Y-m-d H:i:s', time() + $secondsAhead);
    }

    /**
     * Seeds a `rolling`, `held` reserve row + its matching `sale_credit`/`reserve_hold`
     * ledger pair, so that pre-release `available = 0` and `reserved = $amount` -- a clean
     * baseline for asserting the release's effect on both components.
     *
     * @return array<string,mixed> the persisted reserve row (mirrors what
     *     {@see ReserveRepository::dueForRelease()} itself returns)
     */
    private function seedHeldRollingReserve(
        string $uuid,
        string $seller,
        int $amount,
        string $releaseAt,
        string $currency = 'USD'
    ): array {
        $this->ledger->post($this->context, self::TENANT, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller($seller),
            'seller_uuid' => $seller,
            'currency' => $currency,
            'entry_type' => 'sale_credit',
            'amount' => $amount,
            'order_uuid' => 'order' . $uuid,
            'idempotency_key' => 'order' . $uuid . ':' . $seller . ':sale_credit',
        ]);

        $row = $this->reserves->insertRollingHold($this->context, self::TENANT, [
            'uuid' => $uuid,
            'seller_uuid' => $seller,
            'currency' => $currency,
            'seller_order_uuid' => 'selord' . $uuid,
            'amount' => $amount,
            'reserve_bps_snapshot' => 250,
            'reserve_days_snapshot' => 7,
            'held_at' => $this->past(86400),
            'release_at' => $releaseAt,
        ]);

        $this->ledger->post($this->context, self::TENANT, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller($seller),
            'seller_uuid' => $seller,
            'currency' => $currency,
            'entry_type' => 'reserve_hold',
            'amount' => -$amount,
            'order_uuid' => 'order' . $uuid,
            'seller_order_uuid' => 'selord' . $uuid,
            'payout_uuid' => null,
            'reserve_uuid' => $uuid,
            'idempotency_key' => 'order' . $uuid . ':' . $seller . ':reserve_hold',
        ]);

        return $row;
    }

    /**
     * Seeds a partial `reserve_release` against `$reserveUuid` carrying a `chargeback_uuid`
     * -- simulating an earlier proportional reserve-consumption release (design spec §2.5,
     * a later task) landing BEFORE this hold's own scheduled release becomes due.
     */
    private function seedPartialConsumption(
        string $reserveUuid,
        string $seller,
        int $amount,
        string $chargebackUuid,
        string $currency = 'USD'
    ): void {
        $this->ledger->post($this->context, self::TENANT, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller($seller),
            'seller_uuid' => $seller,
            'currency' => $currency,
            'entry_type' => 'reserve_release',
            'amount' => $amount,
            'payout_uuid' => null,
            'reserve_uuid' => $reserveUuid,
            'chargeback_uuid' => $chargebackUuid,
            'idempotency_key' => "{$chargebackUuid}:{$seller}:{$reserveUuid}:reserve_release",
        ]);
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

    // -----------------------------------------------------------------
    // A due held reserve releases its FULL remaining amount -- back to
    // available, out of reserved.
    // -----------------------------------------------------------------

    public function testDueHeldReserveReleasesFullRemainingAndReturnsToAvailable(): void
    {
        $reserve = $this->seedHeldRollingReserve('resvREL00001', 'sellerREL0001', 500, $this->past());

        $balanceBefore = $this->balances->balance($this->context, self::TENANT, 'sellerREL0001', 'USD');
        self::assertSame(0, $balanceBefore['available']);
        self::assertSame(500, $balanceBefore['reserved']);

        $result = $this->reserveService()->releaseDue($this->context, self::TENANT, $reserve);

        self::assertSame('released', $result['status']);
        self::assertSame(500, $result['released_amount']);

        $row = $this->reserveRow('resvREL00001');
        self::assertSame('released', $row['status']);
        self::assertNotNull($row['closed_at']);

        $ledgerRows = $this->ledgerRowsForReserve('resvREL00001');
        self::assertCount(2, $ledgerRows, 'reserve_hold + reserve_release');
        $release = $ledgerRows[1];
        self::assertSame('reserve_release', $release['entry_type']);
        self::assertSame(500, (int) $release['amount']);
        self::assertNull($release['payout_uuid']);
        self::assertSame('resvREL00001', $release['reserve_uuid']);
        self::assertSame('resvREL00001:scheduled_release', $release['idempotency_key']);

        $balanceAfter = $this->balances->balance($this->context, self::TENANT, 'sellerREL0001', 'USD');
        self::assertSame(500, $balanceAfter['available'], 'the released amount must return to available.');
        self::assertSame(0, $balanceAfter['reserved'], 'the released hold must drop out of reserved.');
    }

    // -----------------------------------------------------------------
    // Partial consumption: releases ONLY the derived remainder.
    // -----------------------------------------------------------------

    public function testPartiallyConsumedReserveReleasesOnlyTheDerivedRemainder(): void
    {
        $reserve = $this->seedHeldRollingReserve('resvREL00002', 'sellerREL0002', 500, $this->past());
        $this->seedPartialConsumption('resvREL00002', 'sellerREL0002', 200, 'cbREL0000001');

        $result = $this->reserveService()->releaseDue($this->context, self::TENANT, $reserve);

        self::assertSame('released', $result['status']);
        self::assertSame(300, $result['released_amount'], 'only the derived remainder (500 - 200) must release.');

        $ledgerRows = $this->ledgerRowsForReserve('resvREL00002');
        self::assertCount(3, $ledgerRows, 'reserve_hold + partial reserve_release + scheduled reserve_release');
        $scheduled = $ledgerRows[2];
        self::assertSame('reserve_release', $scheduled['entry_type']);
        self::assertSame(300, (int) $scheduled['amount']);
        self::assertSame('resvREL00002:scheduled_release', $scheduled['idempotency_key']);
        self::assertNull($scheduled['chargeback_uuid'], 'the SCHEDULED release itself carries no chargeback_uuid.');

        self::assertSame('released', $this->reserveRow('resvREL00002')['status']);
    }

    // -----------------------------------------------------------------
    // Fully consumed: marks released, NO ledger post.
    // -----------------------------------------------------------------

    public function testFullyConsumedReserveMarksReleasedWithoutAnyLedgerPost(): void
    {
        $reserve = $this->seedHeldRollingReserve('resvREL00003', 'sellerREL0003', 500, $this->past());
        $this->seedPartialConsumption('resvREL00003', 'sellerREL0003', 500, 'cbREL0000002');

        $result = $this->reserveService()->releaseDue($this->context, self::TENANT, $reserve);

        self::assertSame('released', $result['status']);
        self::assertSame(0, $result['released_amount']);

        $ledgerRows = $this->ledgerRowsForReserve('resvREL00003');
        self::assertCount(2, $ledgerRows, 'reserve_hold + the earlier full consumption -- NO third row.');

        $row = $this->reserveRow('resvREL00003');
        self::assertSame('released', $row['status']);
        self::assertNotNull($row['closed_at']);
    }

    // -----------------------------------------------------------------
    // Due-selection: not-yet-due stays held; a manual (release_at NULL)
    // hold is NEVER selected.
    // -----------------------------------------------------------------

    public function testDueSelectionSkipsNotYetDueAndNeverSelectsAManualIndefiniteHold(): void
    {
        $this->seedHeldRollingReserve('resvREL00004', 'sellerREL0004', 100, $this->past());
        $this->seedHeldRollingReserve('resvREL00005', 'sellerREL0005', 100, $this->future());

        // A manual/indefinite hold (design spec §2.8): source_kind=manual, no
        // seller_order_uuid, a caller idempotency_key instead, release_at NULL.
        $this->connection->table('commerce_seller_reserves')->insert([
            'uuid' => 'resvREL00006',
            'tenant_uuid' => self::TENANT,
            'seller_uuid' => 'sellerREL0006',
            'currency' => 'USD',
            'source_kind' => 'manual',
            'seller_order_uuid' => null,
            'idempotency_key' => 'manualHoldREL1',
            'amount' => 100,
            'reserve_bps_snapshot' => 0,
            'reserve_days_snapshot' => 0,
            'status' => 'held',
            'held_at' => $this->past(86400),
            'release_at' => null,
        ]);

        $candidates = $this->reserves->dueForRelease($this->context, self::TENANT, 100);
        $uuids = array_map(static fn (array $row): string => (string) $row['uuid'], $candidates);

        self::assertSame(['resvREL00004'], $uuids, 'only the due rolling hold must be selected.');

        // Confirm neither the not-yet-due nor the manual hold were touched by selection.
        self::assertSame('held', $this->reserveRow('resvREL00005')['status']);
        self::assertSame('held', $this->reserveRow('resvREL00006')['status']);
    }

    // -----------------------------------------------------------------
    // Idempotent replay: a second call on the same reserve never
    // double-releases.
    // -----------------------------------------------------------------

    public function testRerunningTheSweepOnTheSameReserveIsAnIdempotentNoOp(): void
    {
        $reserve = $this->seedHeldRollingReserve('resvREL00007', 'sellerREL0007', 500, $this->past());

        $service = $this->reserveService();
        $first = $service->releaseDue($this->context, self::TENANT, $reserve);
        self::assertSame('released', $first['status']);
        self::assertSame(500, $first['released_amount']);

        // Replay with the SAME (now-stale) candidate row -- exactly what a second sweep
        // invocation racing/re-running against the same unlocked hint would pass.
        $second = $service->releaseDue($this->context, self::TENANT, $reserve);
        self::assertSame('released', $second['status']);
        self::assertSame(0, $second['released_amount'], 'a replay must never post a second release.');

        $ledgerRows = $this->ledgerRowsForReserve('resvREL00007');
        self::assertCount(2, $ledgerRows, 'exactly one reserve_hold + one reserve_release, never a second release.');
    }

    // -----------------------------------------------------------------
    // Independence: one row's failure never aborts the sweep.
    // -----------------------------------------------------------------

    public function testOneFailingReserveDoesNotAbortTheSweep(): void
    {
        $failing = $this->seedHeldRollingReserve('resvRELFAIL1', 'sellerRELFAIL', 400, $this->past());
        $this->seedHeldRollingReserve('resvREL00OK1', 'sellerRELOK01', 300, $this->past());

        // Force a genuine integrity failure for the FIRST reserve only: a conflicting row
        // already occupies its exact scheduled-release idempotency key, with a mismatched
        // amount -- LedgerRepository::post() will hit the real unique-constraint conflict,
        // re-read, and throw on the amount mismatch. Deliberately NO `reserve_uuid` on this
        // seeded row: `remainingForReserve()` sums BY `reserve_uuid`, so tagging it would
        // silently absorb it into the derived remainder (driving remaining to 0 and skipping
        // the post entirely) instead of colliding with it.
        $this->connection->table('commerce_marketplace_ledger')->insert([
            'uuid' => 'ldgRELFAILCJ',
            'tenant_uuid' => self::TENANT,
            'account_key' => LedgerRepository::accountKeyForSeller('sellerRELFAIL'),
            'account_kind' => 'seller',
            'seller_uuid' => 'sellerRELFAIL',
            'currency' => 'USD',
            'entry_type' => 'reserve_release',
            'amount' => 999,
            'payout_uuid' => null,
            'reserve_uuid' => null,
            'idempotency_key' => 'resvRELFAIL1:scheduled_release',
        ]);
        self::assertNotNull($failing);

        $this->bind(ReserveRepository::class, $this->reserves);
        $this->bind(ReserveService::class, $this->reserveService());

        $command = new ReservesReleaseSweepCommand($this->context->getContainer(), $this->context);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode, 'a failing candidate must surface as a failed exit code.');

        // The failing reserve's own transaction rolled back entirely -- still held, no
        // spurious release mark.
        self::assertSame('held', $this->reserveRow('resvRELFAIL1')['status']);

        // The OTHER candidate was still processed despite the first one failing.
        self::assertSame('released', $this->reserveRow('resvREL00OK1')['status']);
        self::assertCount(2, $this->ledgerRowsForReserve('resvREL00OK1'));
    }
}
