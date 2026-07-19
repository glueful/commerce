<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Support\UtcNowSql;

/**
 * `commerce_seller_reserves` reads/writes (design spec §2.2/§3.2, MV5a Task
 * 7): the durable identity/policy-snapshot/lifecycle anchor for a rolling
 * reserve hold created at settlement by {@see ReserveService}. The
 * REMAINING amount of a hold is never stored here -- it is always DERIVED
 * from the `commerce_marketplace_ledger` rows carrying this row's own
 * `reserve_uuid` (Task 9/14 consumption/release); this repository only
 * ever persists/reads the immutable identity + policy-snapshot fields.
 *
 * `manual` holds (design spec §2.8, a LATER task) are out of scope here --
 * this class currently only knows how to write/find `source_kind=rolling`
 * rows.
 */
final class ReserveRepository
{
    /**
     * ONE INSERT per settled `(seller_order, seller)` (design spec §2.2):
     * always attempts the insert directly rather than checking-then-inserting
     * -- the house duplicate-key probe idiom ({@see LedgerAccountLock::ensureRow()},
     * {@see LedgerRepository::post()}). {@see ReserveService::holdForSettlement()}
     * already re-reads any EXISTING row itself (via {@see self::findRollingHold()})
     * before ever calling this method, so in practice this never races against
     * itself under the caller's own seller/currency account lock -- but the insert
     * still degrades cleanly (re-reads and returns the pre-existing row) rather than
     * fatally erroring if that assumption is ever violated.
     *
     * Runs inside a NESTED transaction (a SAVEPOINT) -- this method's contract is
     * to always run inside the caller's own open transaction (`postSale()`'s),
     * mirroring {@see LedgerAccountLock::ensureRow()}'s identical convention:
     * without the nesting, a duplicate-row race would poison the caller's WHOLE
     * transaction rather than just this one insert attempt.
     *
     * @param array{
     *     uuid: string,
     *     seller_uuid: string,
     *     currency: string,
     *     seller_order_uuid: string,
     *     amount: int,
     *     reserve_bps_snapshot: int,
     *     reserve_days_snapshot: int,
     *     held_at: string,
     *     release_at: string
     * } $row
     * @return array<string,mixed> the persisted row (freshly inserted, or the
     *     pre-existing row on a genuine duplicate race)
     */
    public function insertRollingHold(ApplicationContext $context, string $tenant, array $row): array
    {
        try {
            db($context)->transaction(function () use ($context, $tenant, $row): void {
                db($context)->table('commerce_seller_reserves')->insert([
                    'uuid' => $row['uuid'],
                    'tenant_uuid' => $tenant,
                    'seller_uuid' => $row['seller_uuid'],
                    'currency' => $row['currency'],
                    'source_kind' => 'rolling',
                    'seller_order_uuid' => $row['seller_order_uuid'],
                    'idempotency_key' => null,
                    'amount' => $row['amount'],
                    'reserve_bps_snapshot' => $row['reserve_bps_snapshot'],
                    'reserve_days_snapshot' => $row['reserve_days_snapshot'],
                    'status' => 'held',
                    'held_at' => $row['held_at'],
                    'release_at' => $row['release_at'],
                ]);
            });
        } catch (\PDOException $e) {
            $existing = $this->findRollingHold($context, $tenant, $row['seller_order_uuid'], $row['seller_uuid']);
            if ($existing === null) {
                // Unrelated failure -- never swallowed as a verified duplicate
                // (mirrors LedgerRepository::post()'s identical discipline).
                throw $e;
            }

            return $existing;
        }

        $inserted = $this->findRollingHold($context, $tenant, $row['seller_order_uuid'], $row['seller_uuid']);
        if ($inserted === null) {
            // Unreachable given the insert above just committed -- defensive only.
            throw new LedgerException(
                "Reserve hold insert failure: row for seller_order '{$row['seller_order_uuid']}' "
                    . "not found immediately after insert."
            );
        }

        return $inserted;
    }

    /**
     * The rolling-hold identity lookup (design spec §3.2 unique
     * `(tenant_uuid, seller_order_uuid, seller_uuid)`): the ONE reserve row
     * (if any) for this settled seller-order. {@see ReserveService::holdForSettlement()}
     * calls this FIRST, before ever resolving policy or computing anything, to
     * detect a settlement replay -- the existing row's own snapshot is the
     * verdict from then on, never recomputed current policy or current time
     * (design spec §2.2).
     *
     * @return array<string,mixed>|null
     */
    public function findRollingHold(
        ApplicationContext $context,
        string $tenant,
        string $sellerOrderUuid,
        string $sellerUuid
    ): ?array {
        return db($context)->table('commerce_seller_reserves')
            ->where('tenant_uuid', '=', $tenant)
            ->where('seller_order_uuid', '=', $sellerOrderUuid)
            ->where('seller_uuid', '=', $sellerUuid)
            ->where('source_kind', '=', 'rolling')
            ->first();
    }

    /** The identity lookup by the row's own `uuid` (design spec §2.3, MV5a Task 8): used by
     * {@see ReserveService::releaseDue()} to RE-READ a candidate under the freshly-claimed
     * seller/currency account lock -- the row {@see self::dueForRelease()} returns is an
     * UNLOCKED hint only.
     *
     * @return array<string,mixed>|null
     */
    public function findByUuid(ApplicationContext $context, string $tenant, string $uuid): ?array
    {
        return db($context)->table('commerce_seller_reserves')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();
    }

    /**
     * Release-sweep candidate scan (design spec §2.3, MV5a Task 8): every `status='held'
     * AND release_at IS NOT NULL AND release_at <= now` row, batch-limited by
     * `commerce.marketplace.reserves.release_sweep_batch_size` (the CALLER's job to read
     * that config and pass `$limit` -- this method just applies it). A manual/indefinite
     * hold (`release_at IS NULL`, design spec §2.8) never matches the `whereNotNull`
     * predicate, so it can NEVER be a sweep candidate -- no `source_kind` filter is needed
     * on top of that. Ordered oldest-due-first (`release_at`, then `id` for a stable
     * tiebreak) -- the exact leading columns of the `commerce_seller_reserves_status_release_index`
     * this scan is built to use. This is an UNLOCKED read and a candidate HINT ONLY (the
     * `positiveAvailableCandidates()`/`dueForRetry()` idiom) --
     * {@see ReserveService::releaseDue()} re-reads each row fresh UNDER its own
     * seller/currency lock before deriving or posting anything, so a stale hint here is
     * never a correctness risk, only a harmlessly-skipped candidate.
     *
     * @return list<array<string,mixed>>
     */
    public function dueForRelease(ApplicationContext $context, string $tenant, int $limit): array
    {
        $utcNow = UtcNowSql::expression(db($context)->getDriverName());

        return db($context)->table('commerce_seller_reserves')
            ->where('tenant_uuid', '=', $tenant)
            ->where('status', '=', 'held')
            ->whereNotNull('release_at')
            ->whereRaw("release_at <= {$utcNow}")
            ->orderBy('release_at', 'ASC')
            ->orderBy('id', 'ASC')
            ->limit($limit)
            ->get();
    }

    /**
     * Affected-row-checked `held` -> `released` claim (design spec §2.3, MV5a Task 8):
     * mirrors {@see \Glueful\Extensions\Commerce\Marketplace\PayoutRepository::claimPending()}'s
     * CAS shape. {@see ReserveService::releaseDue()} only ever calls this AFTER it has
     * already re-read this exact row under the claimed account lock and confirmed
     * `status='held'` there, so in ordinary operation this always succeeds
     * (`affected === 1`) -- the guard is defense in depth, not the primary serialization
     * mechanism (the account lock is). A `false` return is a legitimate no-op: the row was
     * already moved out of `held` by another path between that re-read and this call.
     */
    public function markReleased(ApplicationContext $context, string $tenant, string $uuid): bool
    {
        $now = db($context)->getDriver()->formatDateTime();

        $affected = db($context)->table('commerce_seller_reserves')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->where('status', '=', 'held')
            ->update([
                'status' => 'released',
                'closed_at' => $now,
                'updated_at' => $now,
            ]);

        return $affected === 1;
    }
}
