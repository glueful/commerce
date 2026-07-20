<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Support\UtcNowSql;

/**
 * The durable `commerce_payouts` row (design spec §2.10/§3.5/§3.1) -- the
 * operator-confirmed record of a manual payout, plus (MV4, Task 7) the
 * provider-payout saga's row: `{@see PayoutService::record()}` inserts a
 * terminal `manual`/`paid` row atomically alongside its `payout_debit` ledger
 * entry; `{@see PayoutService::execute()}` inserts a `provider`/`pending` row
 * at RESERVE time and this class's CAS primitives ({@see self::claimPending()},
 * {@see self::claimRetryableForAttempt()}) drive it through FINALIZE and
 * retry. Rows are never deleted; provider rows ARE updated in place by the
 * saga (unlike the purely append-only manual-only shape MV3 shipped).
 */
final class PayoutRepository
{
    /** @param array<string,mixed> $row */
    public function insert(ApplicationContext $context, array $row): void
    {
        db($context)->table('commerce_payouts')->insert($row);
    }

    /** @return array<string,mixed>|null */
    public function findByIdempotencyKey(ApplicationContext $context, string $tenant, string $idempotencyKey): ?array
    {
        return db($context)->table('commerce_payouts')
            ->where('tenant_uuid', '=', $tenant)
            ->where('idempotency_key', '=', $idempotencyKey)
            ->first();
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(ApplicationContext $context, string $tenant, string $uuid): ?array
    {
        return db($context)->table('commerce_payouts')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();
    }

    /**
     * Affected-row-checked `pending` -> `$to` claim (design spec §2.3): the
     * single-finalizer-wins idempotency point {@see PayoutService::finalize()}
     * claims BEFORE any balance-affecting post -- mirrors
     * {@see \Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository::claimPending()}
     * exactly. A `pending` -> `pending` claim (the PENDING/UNKNOWN outcomes,
     * which only update timing columns) is a legitimate, always-re-claimable
     * no-op transition -- only a transition AWAY from `pending` is genuinely
     * single-winner.
     *
     * @param array<string,mixed> $set
     */
    public function claimPending(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        string $to,
        array $set = []
    ): bool {
        $affected = db($context)->table('commerce_payouts')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->where('status', '=', 'pending')
            ->update($set + ['status' => $to, 'updated_at' => db($context)->getDriver()->formatDateTime()]);

        return $affected === 1;
    }

    /**
     * Task 9 sweep primitive (design spec §2.6): a guarded CAS from
     * `failed AND retryable AND attempt_count < $maxAttempts AND next_attempt_at <= now`
     * to `pending`. In the SAME update it increments `attempt_count`, clears
     * `next_attempt_at`, stamps `last_attempt_at`, and re-arms the
     * `next_reconcile_at` watchdog -- all BEFORE any provider I/O runs, so a
     * crash immediately after a successful claim is recovered by
     * reconciliation rather than a blind retry. `retryable = TRUE` and the
     * `next_attempt_at <= {utcNow}` comparison are written as literal SQL
     * (not bound parameters): PostgreSQL's `boolean` column rejects an
     * integer-bound comparison, and `UtcNowSql::expression()` is itself a raw
     * SQL fragment, not a bindable value (see its class docblock). Returns
     * the claimed (post-update) row, or null when nothing matched (already
     * claimed by a concurrent sweep, not yet due, exhausted, or not
     * retryable).
     *
     * `$ignoreDueTime` (design spec §2.6: "the operator retry uses the same claim but may
     * ignore the due time") drops ONLY the `next_attempt_at <= {utcNow}` predicate from the
     * WHERE clause below -- every OTHER guard (`status='failed'`, `retryable=TRUE`,
     * `attempt_count < $maxAttempts`, `next_attempt_at IS NOT NULL`) stays unconditional, so
     * a terminal/exhausted/non-retryable payout is still never claimable. Callers:
     * {@see PayoutService::retry()} passes `true` for the operator single-payout retry path
     * ({@see \Glueful\Extensions\Commerce\Http\Admin\AdminPayoutController::retryPayout()});
     * the retry sweep ({@see \Glueful\Extensions\Commerce\Console\PayoutsRetrySweepCommand})
     * keeps the default `false` -- due-gated, exactly as before.
     *
     * @return array<string,mixed>|null
     */
    public function claimRetryableForAttempt(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        int $maxAttempts,
        bool $ignoreDueTime = false
    ): ?array {
        $utcNow = UtcNowSql::expression(db($context)->getDriverName());
        $nextReconcileAt = gmdate(
            'Y-m-d H:i:s',
            time() + max(0, (int) config($context, 'commerce.marketplace.payouts.pending_reconcile_interval', 300))
        );

        $dueTimePredicate = $ignoreDueTime ? '' : "\n  AND next_attempt_at <= {$utcNow}";

        $affected = db($context)->table('commerce_payouts')->executeModification(
            <<<SQL
UPDATE commerce_payouts
SET status = 'pending',
    attempt_count = attempt_count + 1,
    next_attempt_at = NULL,
    last_attempt_at = {$utcNow},
    next_reconcile_at = ?,
    updated_at = {$utcNow}
WHERE tenant_uuid = ?
  AND uuid = ?
  AND status = 'failed'
  AND retryable = TRUE
  AND attempt_count < ?
  AND next_attempt_at IS NOT NULL{$dueTimePredicate}
SQL,
            [$nextReconcileAt, $tenant, $uuid, $maxAttempts]
        );

        if ($affected !== 1) {
            return null;
        }

        return $this->findByUuid($context, $tenant, $uuid);
    }

    /**
     * Records a non-terminal ambiguous outcome (design spec §2.3: `UNKNOWN`
     * or a transport throw from `PayoutCollector::transfer()`) WITHOUT
     * changing `status` -- the hold stays and only a reconcile may move the
     * payout forward. Mirrors
     * {@see \Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository::setFailureReason()},
     * extended with the re-armed watchdog this saga also needs. Guarded by
     * `status = 'pending'` so a call that lost a race against a genuine
     * finalize (which DOES change status) never stomps a terminal row.
     */
    public function markUnresolved(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        string $reason,
        string $nextReconcileAt
    ): void {
        db($context)->table('commerce_payouts')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->where('status', '=', 'pending')
            ->update([
                'failure_reason' => $reason,
                'next_reconcile_at' => $nextReconcileAt,
                'updated_at' => db($context)->getDriver()->formatDateTime(),
            ]);
    }

    /**
     * Retry-sweep candidates (design spec §2.6, Task 9): every `failed AND retryable
     * AND attempt_count < $maxAttempts AND next_attempt_at <= now` row -- the exact
     * predicate {@see self::claimRetryableForAttempt()} itself CASes against, so every
     * candidate this returns is (barring a concurrent sweep winning first) actually
     * claimable. `retryable = TRUE` and the `next_attempt_at <= {utcNow}` comparison are
     * literal SQL for the same PostgreSQL boolean-column reason documented on
     * {@see self::claimRetryableForAttempt()}.
     *
     * @return list<array<string,mixed>>
     */
    public function dueForRetry(ApplicationContext $context, string $tenant, int $maxAttempts): array
    {
        $utcNow = UtcNowSql::expression(db($context)->getDriverName());

        return db($context)->table('commerce_payouts')
            ->where('tenant_uuid', '=', $tenant)
            ->where('status', '=', 'failed')
            ->whereRaw('retryable = TRUE')
            ->whereNotNull('next_attempt_at')
            ->whereRaw("next_attempt_at <= {$utcNow}")
            ->where('attempt_count', '<', $maxAttempts)
            ->orderBy('id', 'ASC')
            ->get();
    }

    /**
     * Reconcile-sweep candidates (design spec §2.6, Task 9): due unresolved `pending`
     * rows (`next_reconcile_at IS NULL` is an immediate repair backstop -- a row whose
     * watchdog was somehow never armed is treated as due right away, never silently
     * skipped) PLUS due `paid` rows (the slower reversal-discovery cadence, §2.8).
     * Deliberately TWO separate selects merged in PHP rather than one OR'd query --
     * mirrors the "select both due unresolved pending payouts ... and due paid payouts"
     * phrasing verbatim and keeps each predicate simple. Never selects `failed` (that
     * status' own `next_reconcile_at` watchdog belongs to the RETRY sweep, not this one
     * -- {@see self::dueForRetry()}) or `reversed` (nothing left to reconcile).
     *
     * @return list<array<string,mixed>>
     */
    public function dueForReconcile(ApplicationContext $context, string $tenant): array
    {
        $utcNow = UtcNowSql::expression(db($context)->getDriverName());

        $pending = db($context)->table('commerce_payouts')
            ->where('tenant_uuid', '=', $tenant)
            ->where('status', '=', 'pending')
            ->whereRaw("(next_reconcile_at IS NULL OR next_reconcile_at <= {$utcNow})")
            ->orderBy('id', 'ASC')
            ->get();

        $paid = db($context)->table('commerce_payouts')
            ->where('tenant_uuid', '=', $tenant)
            ->where('status', '=', 'paid')
            ->whereNotNull('next_reconcile_at')
            ->whereRaw("next_reconcile_at <= {$utcNow}")
            ->orderBy('id', 'ASC')
            ->get();

        return [...$pending, ...$paid];
    }

    /**
     * Unconditional `next_reconcile_at` re-arm (design spec §2.6, Task 9): used when a
     * reconcile attempt learns nothing new -- a `status()` call that itself threw, or a
     * provider observation that changed nothing actionable -- so the row stays a live
     * candidate for a LATER sweep instead of being reconciled again on every tick
     * (immediately due) or silently dropped. Deliberately unguarded by `status`: it never
     * touches money or the state machine, only the watchdog timestamp.
     */
    public function scheduleReconcile(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        string $nextReconcileAt
    ): void {
        db($context)->table('commerce_payouts')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->update([
                'next_reconcile_at' => $nextReconcileAt,
                'updated_at' => db($context)->getDriver()->formatDateTime(),
            ]);
    }

    /**
     * Affected-row-checked apply of a provider-reported reversal delta (design spec
     * §2.6/§2.8, Task 9) -- mirrors {@see self::claimPending()}'s CAS shape, guarded by
     * BOTH `status = 'paid'` and `reversed_total = $expectedReversedTotal`. Callers
     * ({@see PayoutService::reconcile()}) always claim the seller account lock and
     * re-read `reversed_total` fresh UNDER that lock immediately before calling this, so
     * in practice the CAS can only ever fail if the caller's own invariants are broken --
     * this is defense in depth, not the primary serialization mechanism (the account
     * lock is).
     *
     * @param array<string,mixed> $set additional columns (`reversed_total`,
     *     `next_reconcile_at`) merged into the same UPDATE
     */
    public function applyReversal(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        int $expectedReversedTotal,
        string $to,
        array $set
    ): bool {
        $affected = db($context)->table('commerce_payouts')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->where('status', '=', 'paid')
            ->where('reversed_total', '=', $expectedReversedTotal)
            ->update($set + ['status' => $to, 'updated_at' => db($context)->getDriver()->formatDateTime()]);

        return $affected === 1;
    }

    /**
     * Every payout for a seller, newest first (design spec §6.2, MV3 Task 11)
     * -- the seller/operator financial surfaces
     * ({@see \Glueful\Extensions\Commerce\Http\Seller\SellerFinancialController},
     * {@see \Glueful\Extensions\Commerce\Http\Admin\AdminMarketplaceFinancialController})
     * expose. Optionally currency-scoped, mirroring the ledger's own
     * currency-separated balances (§2.9). House pagination (array_slice) is
     * applied by the caller, the same convention
     * {@see SellerOrderRepository::confirmedForSeller()} + `SellerOrderService::list()`
     * already establish.
     *
     * @return list<array<string,mixed>>
     */
    public function forSeller(
        ApplicationContext $context,
        string $tenant,
        string $sellerUuid,
        ?string $currency = null
    ): array {
        $query = db($context)->table('commerce_payouts')
            ->where('tenant_uuid', '=', $tenant)
            ->where('seller_uuid', '=', $sellerUuid);

        if ($currency !== null && $currency !== '') {
            $query->where('currency', '=', $currency);
        }

        return $query->orderBy('id', 'DESC')->get();
    }
}
