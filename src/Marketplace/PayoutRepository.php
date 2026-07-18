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
     * @return array<string,mixed>|null
     */
    public function claimRetryableForAttempt(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        int $maxAttempts
    ): ?array {
        $utcNow = UtcNowSql::expression(db($context)->getDriverName());
        $nextReconcileAt = gmdate(
            'Y-m-d H:i:s',
            time() + max(0, (int) config($context, 'commerce.marketplace.payouts.pending_reconcile_interval', 300))
        );

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
  AND next_attempt_at IS NOT NULL
  AND next_attempt_at <= {$utcNow}
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
