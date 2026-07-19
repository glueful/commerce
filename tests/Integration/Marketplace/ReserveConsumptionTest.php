<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\ReserveConsumptionService;
use Glueful\Extensions\Commerce\Marketplace\ReserveRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerBalanceService;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * Shared FIFO reserve consumption (design spec §2.5, MV5a Task 9):
 * {@see ReserveConsumptionService::consume()}'s earliest-`release_at`-first walk
 * (NULL `release_at` -- a manual/indefinite hold -- sorting last), its per-reserve
 * locked-remaining re-derive via {@see LedgerRepository::remainingForReserve()},
 * the `held` (positive remainder) vs `consumed` (exhausted) status transition, the
 * per-`liabilityKind` correlation column (`chargeback_uuid`/`refund_uuid`), the
 * never-over-release invariant, and idempotent replay of the SAME
 * `(liabilityKind, liabilityUuid)`.
 *
 * Every test drives `consume()` under a MANUALLY-CLAIMED seller/currency
 * {@see LedgerAccountLock} (via {@see self::consumeUnderLock()}), mirroring
 * {@see ReserveHoldTest}/{@see ReserveReleaseSweepTest}'s convention that
 * `consume()` itself never claims a lock -- the caller (Task 11/Task 12 in
 * production, this test harness here) owns that.
 */
final class ReserveConsumptionTest extends CommerceTestCase
{
    private const TENANT = '';

    private LedgerRepository $ledger;
    private ReserveRepository $reserves;
    private ReserveConsumptionService $consumption;
    private SellerBalanceService $balances;
    private LedgerAccountLock $lock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = new LedgerRepository();
        $this->reserves = new ReserveRepository();
        $this->consumption = new ReserveConsumptionService($this->reserves, $this->ledger);
        $this->balances = new SellerBalanceService($this->ledger);
        $this->lock = new LedgerAccountLock();
    }

    private function past(int $secondsAgo): string
    {
        return gmdate('Y-m-d H:i:s', time() - $secondsAgo);
    }

    /**
     * Claims the seller/currency account lock INSIDE its own transaction (the
     * `consume()` precondition), then calls `consume()` under that held lock --
     * exactly the shape a real caller (Task 11/Task 12) uses. `consume()` itself
     * never claims a lock.
     */
    private function consumeUnderLock(
        string $sellerUuid,
        string $currency,
        int $liability,
        string $liabilityKind,
        string $liabilityUuid
    ): int {
        return $this->connection->transaction(function () use (
            $sellerUuid,
            $currency,
            $liability,
            $liabilityKind,
            $liabilityUuid
        ): int {
            $accountKey = LedgerRepository::accountKeyForSeller($sellerUuid);
            $this->lock->claim($this->context, self::TENANT, $accountKey, $currency);

            return $this->consumption->consume(
                $this->context,
                self::TENANT,
                $sellerUuid,
                $currency,
                $liability,
                $liabilityKind,
                $liabilityUuid
            );
        });
    }

    /**
     * Seeds a `rolling`, `held` reserve row + its matching `reserve_hold` ledger
     * entry (mirrors {@see ReserveReleaseSweepTest::seedHeldRollingReserve()}, minus
     * the `sale_credit` leg -- these tests only assert `reserved`, which never reads
     * `sale_credit`).
     *
     * @return array<string,mixed> the persisted reserve row
     */
    private function seedHeldReserve(
        string $uuid,
        string $seller,
        int $amount,
        ?string $releaseAt,
        string $currency = 'USD'
    ): array {
        $row = $this->reserves->insertRollingHold($this->context, self::TENANT, [
            'uuid' => $uuid,
            'seller_uuid' => $seller,
            'currency' => $currency,
            'seller_order_uuid' => 'selord' . $uuid,
            'amount' => $amount,
            'reserve_bps_snapshot' => 250,
            'reserve_days_snapshot' => 7,
            'held_at' => $this->past(86400),
            'release_at' => $releaseAt ?? $this->past(3600),
        ]);

        if ($releaseAt === null) {
            // Force the row back to a NULL release_at (a manual/indefinite hold,
            // design spec §2.8) -- insertRollingHold requires a non-null value, so
            // rewrite it directly, mirroring the manual-hold fixture shape
            // ReserveReleaseSweepTest builds inline.
            $this->connection->table('commerce_seller_reserves')
                ->where('uuid', '=', $uuid)
                ->update(['source_kind' => 'manual', 'release_at' => null]);
            $row['source_kind'] = 'manual';
            $row['release_at'] = null;
        }

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

    /** @return array<string,mixed> */
    private function reserveRow(string $uuid): array
    {
        return $this->connection->table('commerce_seller_reserves')->where('uuid', '=', $uuid)->first();
    }

    /** @return list<array<string,mixed>> */
    private function releasesForReserve(string $reserveUuid): array
    {
        return $this->connection->table('commerce_marketplace_ledger')
            ->where('reserve_uuid', '=', $reserveUuid)
            ->where('entry_type', '=', 'reserve_release')
            ->orderBy('id', 'ASC')
            ->get();
    }

    // -----------------------------------------------------------------
    // Liability smaller than the (only) reserve's remaining: partial
    // consumption, the reserve stays held with the correct remainder.
    // -----------------------------------------------------------------

    public function testLiabilitySmallerThanFirstReserveRemainingPartiallyConsumesOneReserveAndStaysHeld(): void
    {
        $this->seedHeldReserve('resvCON00001', 'sellerCON0001', 500, $this->past(3600));

        $consumed = $this->consumeUnderLock('sellerCON0001', 'USD', 300, 'chargeback', 'cbCON00000001');

        self::assertSame(300, $consumed);

        $row = $this->reserveRow('resvCON00001');
        self::assertSame('held', $row['status'], 'a positive remainder must keep the reserve held.');
        self::assertNull($row['closed_at']);

        self::assertSame(
            200,
            $this->ledger->remainingForReserve($this->context, self::TENANT, 'resvCON00001'),
            'derived remaining must reflect the 300 slice taken from the original 500.'
        );

        $releases = $this->releasesForReserve('resvCON00001');
        self::assertCount(1, $releases);
        self::assertSame(300, (int) $releases[0]['amount']);
        self::assertNull($releases[0]['payout_uuid']);
        self::assertSame('resvCON00001', $releases[0]['reserve_uuid']);
        self::assertSame('cbCON00000001', $releases[0]['chargeback_uuid']);
        self::assertNull($releases[0]['refund_uuid']);
        self::assertSame(
            'chargeback:cbCON00000001:sellerCON0001:resvCON00001:reserve_release',
            $releases[0]['idempotency_key']
        );
    }

    // -----------------------------------------------------------------
    // Liability spanning three reserves: FIFO by release_at, exhausted
    // reserves become consumed, the last partially-consumed one stays held.
    // -----------------------------------------------------------------

    public function testLiabilitySpanningThreeReservesConsumesFifoByReleaseAtAndExhaustsInOrder(): void
    {
        $this->seedHeldReserve('resvCON00002', 'sellerCON0002', 300, $this->past(300)); // earliest
        $this->seedHeldReserve('resvCON00003', 'sellerCON0002', 300, $this->past(200)); // middle
        $this->seedHeldReserve('resvCON00004', 'sellerCON0002', 300, $this->past(100)); // latest

        $consumed = $this->consumeUnderLock('sellerCON0002', 'USD', 700, 'refund', 'rfCON00000001');

        self::assertSame(700, $consumed);

        // Earliest two fully exhausted -> consumed.
        self::assertSame('consumed', $this->reserveRow('resvCON00002')['status']);
        self::assertNotNull($this->reserveRow('resvCON00002')['closed_at']);
        self::assertSame('consumed', $this->reserveRow('resvCON00003')['status']);
        self::assertNotNull($this->reserveRow('resvCON00003')['closed_at']);

        // Latest (by release_at) only partially consumed -> stays held.
        $last = $this->reserveRow('resvCON00004');
        self::assertSame('held', $last['status']);
        self::assertNull($last['closed_at']);
        self::assertSame(200, $this->ledger->remainingForReserve($this->context, self::TENANT, 'resvCON00004'));

        self::assertSame(300, (int) $this->releasesForReserve('resvCON00002')[0]['amount']);
        self::assertSame(300, (int) $this->releasesForReserve('resvCON00003')[0]['amount']);
        self::assertSame(100, (int) $this->releasesForReserve('resvCON00004')[0]['amount']);

        foreach (['resvCON00002', 'resvCON00003', 'resvCON00004'] as $uuid) {
            self::assertSame('rfCON00000001', $this->releasesForReserve($uuid)[0]['refund_uuid']);
        }
    }

    // -----------------------------------------------------------------
    // A manual (NULL release_at) hold sorts LAST -- a rolling reserve with
    // any real release_at is consumed before it, regardless of insertion order.
    // -----------------------------------------------------------------

    public function testManualIndefiniteHoldSortsLastAfterAnyRollingReserve(): void
    {
        // Insert the manual hold FIRST (proving order isn't insertion order),
        // then the rolling one.
        $this->seedHeldReserve('resvCON00006', 'sellerCON0003', 300, null); // manual, release_at NULL
        $this->seedHeldReserve('resvCON00005', 'sellerCON0003', 200, $this->past(3600)); // rolling

        self::assertNull($this->reserveRow('resvCON00006')['release_at']);
        self::assertSame('manual', $this->reserveRow('resvCON00006')['source_kind']);

        $consumed = $this->consumeUnderLock('sellerCON0003', 'USD', 250, 'chargeback', 'cbCON00000002');

        self::assertSame(250, $consumed);

        // The rolling reserve (has a real release_at) is consumed FIRST and fully.
        self::assertSame('consumed', $this->reserveRow('resvCON00005')['status']);
        self::assertSame(200, (int) $this->releasesForReserve('resvCON00005')[0]['amount']);

        // The manual (NULL release_at) hold is touched only for the remainder.
        self::assertSame('held', $this->reserveRow('resvCON00006')['status']);
        self::assertSame(50, (int) $this->releasesForReserve('resvCON00006')[0]['amount']);
        self::assertSame(250, $this->ledger->remainingForReserve($this->context, self::TENANT, 'resvCON00006'));
    }

    // -----------------------------------------------------------------
    // Liability exceeds total held reserve: consumes ALL held reserve,
    // returns the total held (not the full liability) -- shortfall is the
    // caller's problem. reserved never goes negative.
    // -----------------------------------------------------------------

    public function testLiabilityExceedingTotalHeldReserveConsumesAllAndReturnsTheTotalHeld(): void
    {
        $this->seedHeldReserve('resvCON00007', 'sellerCON0004', 200, $this->past(300));
        $this->seedHeldReserve('resvCON00008', 'sellerCON0004', 150, $this->past(200));

        $balanceBefore = $this->balances->balance($this->context, self::TENANT, 'sellerCON0004', 'USD');
        self::assertSame(350, $balanceBefore['reserved']);

        $consumed = $this->consumeUnderLock('sellerCON0004', 'USD', 1000, 'chargeback', 'cbCON00000003');

        self::assertSame(350, $consumed, 'only the total held reserve (350) is consumed, never the full 1000.');

        self::assertSame('consumed', $this->reserveRow('resvCON00007')['status']);
        self::assertSame('consumed', $this->reserveRow('resvCON00008')['status']);

        $balanceAfter = $this->balances->balance($this->context, self::TENANT, 'sellerCON0004', 'USD');
        self::assertSame(0, $balanceAfter['reserved'], 'reserved must land at exactly 0, never negative.');
    }

    // -----------------------------------------------------------------
    // Idempotent replay of the SAME (liabilityKind, liabilityUuid): the
    // harder case, spanning both an EXHAUSTED (now status=consumed) reserve
    // and a partially-consumed (still held) one -- proving the replay
    // doesn't just work when every touched reserve is still 'held'.
    // -----------------------------------------------------------------

    public function testReplayingTheSameLiabilityIsIdempotentAcrossAnExhaustedAndAPartiallyConsumedReserve(): void
    {
        $this->seedHeldReserve('resvCON00009', 'sellerCON0005', 200, $this->past(300));
        $this->seedHeldReserve('resvCON00010', 'sellerCON0005', 300, $this->past(200));

        $first = $this->consumeUnderLock('sellerCON0005', 'USD', 350, 'refund', 'rfCON00000002');
        self::assertSame(350, $first);
        self::assertSame('consumed', $this->reserveRow('resvCON00009')['status']);
        self::assertSame('held', $this->reserveRow('resvCON00010')['status']);

        $second = $this->consumeUnderLock('sellerCON0005', 'USD', 350, 'refund', 'rfCON00000002');
        self::assertSame(350, $second, 'a replay must return the SAME total.');

        // No second release row for either reserve.
        self::assertCount(1, $this->releasesForReserve('resvCON00009'));
        self::assertCount(1, $this->releasesForReserve('resvCON00010'));

        // Status/remaining unchanged by the replay.
        self::assertSame('consumed', $this->reserveRow('resvCON00009')['status']);
        self::assertSame('held', $this->reserveRow('resvCON00010')['status']);
        self::assertSame(150, $this->ledger->remainingForReserve($this->context, self::TENANT, 'resvCON00010'));
    }

    // -----------------------------------------------------------------
    // Per-kind correlation: a chargeback consumption carries chargeback_uuid
    // (never refund_uuid); a refund consumption carries refund_uuid (never
    // chargeback_uuid).
    // -----------------------------------------------------------------

    public function testChargebackConsumptionCarriesChargebackUuidOnly(): void
    {
        $this->seedHeldReserve('resvCON00011', 'sellerCON0006', 400, $this->past(300));

        $this->consumeUnderLock('sellerCON0006', 'USD', 400, 'chargeback', 'cbCON00000004');

        $release = $this->releasesForReserve('resvCON00011')[0];
        self::assertSame('cbCON00000004', $release['chargeback_uuid']);
        self::assertNull($release['refund_uuid']);
    }

    public function testRefundConsumptionCarriesRefundUuidOnly(): void
    {
        $this->seedHeldReserve('resvCON00012', 'sellerCON0007', 400, $this->past(300));

        $this->consumeUnderLock('sellerCON0007', 'USD', 400, 'refund', 'rfCON00000003');

        $release = $this->releasesForReserve('resvCON00012')[0];
        self::assertSame('rfCON00000003', $release['refund_uuid']);
        self::assertNull($release['chargeback_uuid']);
    }

    // -----------------------------------------------------------------
    // reserved drops by exactly the consumed total, and a zero-liability
    // call is a clean no-op.
    // -----------------------------------------------------------------

    public function testReservedDropsByExactlyTheConsumedTotalAndAZeroLiabilityIsANoOp(): void
    {
        $this->seedHeldReserve('resvCON00013', 'sellerCON0008', 500, $this->past(300));

        $reservedBefore = $this->balances->balance($this->context, self::TENANT, 'sellerCON0008', 'USD')['reserved'];
        self::assertSame(500, $reservedBefore);

        $consumed = $this->consumeUnderLock('sellerCON0008', 'USD', 180, 'chargeback', 'cbCON00000005');
        self::assertSame(180, $consumed);

        $reservedAfter = $this->balances->balance($this->context, self::TENANT, 'sellerCON0008', 'USD')['reserved'];
        self::assertSame($reservedBefore - $consumed, $reservedAfter);

        $noop = $this->consumeUnderLock('sellerCON0008', 'USD', 0, 'chargeback', 'cbCON00000006');
        self::assertSame(0, $noop);
        self::assertSame(
            $reservedAfter,
            $this->balances->balance($this->context, self::TENANT, 'sellerCON0008', 'USD')['reserved'],
            'a zero-liability call must never touch a reserve.'
        );
        self::assertCount(1, $this->releasesForReserve('resvCON00013'), 'the zero-liability call posted nothing new.');
    }

    // -----------------------------------------------------------------
    // An unsupported liabilityKind is rejected -- never silently mapped to
    // either correlation column.
    // -----------------------------------------------------------------

    public function testUnsupportedLiabilityKindIsRejected(): void
    {
        $this->seedHeldReserve('resvCON00014', 'sellerCON0009', 200, $this->past(300));

        $this->expectException(\InvalidArgumentException::class);

        $this->consumeUnderLock('sellerCON0009', 'USD', 100, 'payout', 'poCON00000001');
    }
}
