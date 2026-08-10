<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Orders\Events\DraftOrderEvents;
use Glueful\Extensions\Commerce\Support\CommerceSettings;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;

/**
 * Bounded, idempotent TTL cleanup for admin ("walk-in") DRAFT orders
 * (admin-order-creation cycle 2, Task 8) -- the draft-side sibling of
 * {@see ExpiryService}, wired into the SAME `commerce:orders:expire` cron
 * command.
 *
 * Why a separate service rather than a branch inside `ExpiryService`: the two
 * sweeps are not the same operation wearing different hats.
 * `ExpiryService::expireStale()` cancels REAL unpaid orders, so it releases
 * reserved stock, records stock movements, and captures an `order.canceled`
 * seller webhook for marketplace-partitioned orders. A draft holds no stock, has
 * no seller children, and was never visible to a customer or a seller -- so its
 * cancellation must do NONE of that. Sharing a code path would mean one method
 * permanently guarded by `if (isDraft)` at every step; two methods state the two
 * contracts honestly.
 *
 * The draft path therefore records an ORDER-EVENT ROW and nothing else
 * ({@see DraftOrderEvents}): no dispatched lifecycle event, so no
 * transactional mail, no webhook capture, no marketplace fan-out ever fires for
 * a draft.
 *
 * Three properties the cron depends on:
 *  - BOUNDED: one call cancels at most `$batchSize` drafts, so a backlog of a
 *    million abandoned drafts can never turn one cron tick into an unbounded
 *    transaction. Successive ticks (or successive calls) drain it.
 *  - IDEMPOTENT: every cancellation is a compare-and-set on `status = 'draft'`,
 *    so a re-run over already-canceled rows is a clean no-op and can never
 *    double-record an audit row -- and two overlapping cron ticks cannot both
 *    claim the same draft.
 *  - DETERMINISTIC: `$now` is a parameter, never `time()`. The cron supplies the
 *    real clock; tests supply a fixed instant and assert exact TTL boundaries
 *    with no tolerance window.
 */
final class DraftCleanupService
{
    /**
     * Deliberately modest: the sweep runs on the ordinary expiry cron tick, and
     * a small bound keeps each tick's work (and each row lock's lifetime)
     * predictable regardless of backlog size.
     */
    public const DEFAULT_BATCH_SIZE = 100;

    public function __construct(
        private OrderRepository $orders,
        private CurrentTenantResolver $tenants,
    ) {
    }

    /**
     * Cancels up to `$batchSize` drafts whose LAST TOUCH is older than
     * `commerce.orders.draft_ttl_days` before `$now`, recording
     * {@see DraftOrderEvents::EXPIRED} for each.
     *
     * Staleness reads `COALESCE(updated_at, created_at)`: a draft an operator
     * edited yesterday is not stale however long ago it was created, and a
     * never-edited draft (NULL `updated_at`) falls back to its creation time.
     * The comparison is strict (`<`), so a draft aged EXACTLY the TTL survives
     * one more tick -- the boundary always resolves in the operator's favor.
     *
     * `$now` is normalized to UTC so the CUTOFF this method computes is
     * timezone-stable regardless of what zone a caller hands over. Note the
     * honest caveat: the stored `created_at`/`updated_at` stamps it compares
     * against are written by the driver's own `formatDateTime()` (PHP-local),
     * not guaranteed UTC, so cutoff and column are not strictly the same
     * clock. At 30-day granularity a fixed hours-scale offset is immaterial to
     * which drafts are swept, and correcting the storage convention is a
     * schema-wide concern well outside this task -- documented rather than
     * silently assumed.
     *
     * @return int the number of drafts this call actually canceled (never more
     *     than `$batchSize`; 0 once drained)
     */
    public function cancelStale(
        ApplicationContext $context,
        \DateTimeImmutable $now,
        int $batchSize = self::DEFAULT_BATCH_SIZE
    ): int {
        if ($batchSize < 1) {
            return 0;
        }

        $tenant = $this->tenants->tenantUuid($context);
        $ttlDays = max(0, CommerceSettings::draftTtlDays($context));
        $cutoff = $now->setTimezone(new \DateTimeZone('UTC'))
            ->modify("-{$ttlDays} days")
            ->format('Y-m-d H:i:s');

        // Select-then-CAS rather than one bulk UPDATE: each cancellation needs
        // its own audit row, and the per-row CAS is what makes an overlapping
        // second sweep a no-op instead of a double-record. `id ASC` gives the
        // batching a stable order, so successive calls genuinely make progress
        // (the previous batch is no longer `draft`, so it drops out entirely).
        $rows = db($context)->table('commerce_orders')
            ->select(['uuid'])
            ->where('tenant_uuid', '=', $tenant)
            ->where('status', '=', OrderScope::DRAFT)
            ->whereRaw('COALESCE(updated_at, created_at) < ?', [$cutoff])
            ->orderBy('id', 'ASC')
            ->limit($batchSize)
            ->get();

        $canceled = 0;
        foreach ($rows as $row) {
            if ($this->cancelDraft($context, $tenant, (string) $row['uuid'], DraftOrderEvents::EXPIRED)) {
                $canceled++;
            }
        }

        return $canceled;
    }

    /**
     * The SHARED cancellation mechanic: one idempotent compare-and-set plus one
     * audit row, and nothing else.
     *
     * This is deliberately public and reason-parameterized because Task 9's
     * explicit admin draft-cancel endpoint is the same mechanic with a
     * different reason (`draft_canceled`) and an operator actor -- there must
     * not be two implementations of "cancel a draft" that could drift.
     *
     * Returns false (never throws) when the CAS matches nothing: an unknown or
     * cross-tenant uuid, an order that is not a draft, or a draft a concurrent
     * caller already canceled. Callers that need a 404/409 distinction make it
     * themselves from their own prior read; the sweep just skips.
     *
     * @param string $eventType one of {@see DraftOrderEvents}' constants
     */
    public function cancelDraft(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        string $eventType,
        ?string $actorUuid = null
    ): bool {
        $isDraft = OrderScope::isDraftSql();
        $affected = db($context)->table('commerce_orders')->executeModification(
            <<<SQL
UPDATE commerce_orders
SET status = 'canceled', updated_at = ?
WHERE tenant_uuid = ? AND uuid = ? AND {$isDraft}
SQL,
            [db($context)->getDriver()->formatDateTime(), $tenant, $uuid]
        );

        if ($affected !== 1) {
            return false;
        }

        // Audit ROW only -- see the class docblock. No `OrderCanceled`
        // dispatch, so no mail listener, no seller-webhook capture, and no
        // marketplace cancellation fan-out can observe a draft.
        $this->orders->recordEvent($context, $uuid, $eventType, [], $actorUuid);

        return true;
    }
}
