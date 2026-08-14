<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Orders\Events\DraftOrderEvents;
use Glueful\Extensions\Commerce\Support\CommerceSettings;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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
 *
 * ## The second half: PURGE (cleanup-train Task 5)
 *
 * Cancellation is not disposal. A canceled draft leaves a permanent
 * `commerce_orders` row -- an ARTIFACT -- and until this task there was nothing
 * in the engine that ever removed one, so an install that creates walk-in drafts
 * accumulated them forever and showed every one of them in the admin orders
 * list. {@see self::deleteArtifact()} is the guarded hard delete that disposes
 * of one, and {@see self::purgeStale()} is the aged sweep that drives it from
 * the same cron tick. Both share ONE mechanic for exactly the reason
 * {@see self::cancelDraft()} is shared with Task 9's endpoint: two
 * implementations of a DESTRUCTIVE operation could drift, and only one of the
 * two would be the one anybody reviewed.
 */
final class DraftCleanupService
{
    /**
     * Deliberately modest: the sweep runs on the ordinary expiry cron tick, and
     * a small bound keeps each tick's work (and each row lock's lifetime)
     * predictable regardless of backlog size.
     */
    public const DEFAULT_BATCH_SIZE = 100;

    /** Log-line discriminators for the two callers of {@see self::deleteArtifact()}. */
    public const REASON_ADMIN = 'admin_delete';
    public const REASON_PURGE = 'purge_sweep';

    /** The one log channel artifact deletion writes to. */
    public const DELETED_LOG_MESSAGE = 'commerce.orders.artifact_deleted';

    public function __construct(
        private OrderRepository $orders,
        private CurrentTenantResolver $tenants,
        // APPENDED OPTIONAL collaborator (the codebase's standing convention for
        // widening a constructor), so every pre-Task-5 construction site --
        // tests included -- stays source-compatible and an install with no bound
        // logger simply records nothing extra.
        private ?LoggerInterface $logger = null,
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

    /**
     * Hard-deletes ONE draft ARTIFACT and every child it can have, in ONE
     * transaction. The SHARED mechanic behind both the admin
     * `DELETE /orders/{uuid}/artifact` endpoint and {@see self::purgeStale()}.
     *
     * ## Why this deletion is legal at all
     *
     * The guard is {@see OrderScope::deletableArtifactSql()}: `order_number IS
     * NULL AND status = 'canceled'`. That pair is a STRUCTURAL proof the row
     * never touched money -- an order number is allocated only by finalization
     * and storefront checkout, and every financial child (payments, invoices,
     * stock claims, payment links, refunds, marketplace rows) is created on or
     * after that allocation. So the three tables swept below are the COMPLETE
     * set of things that can reference an artifact, and there is nothing here to
     * orphan, reconcile, or explain to an accountant.
     *
     * ## Why the CAS is the authority, not the caller's precheck
     *
     * The order-row DELETE carries the guard in its own `WHERE` clause and runs
     * FIRST. A caller that classified the row a moment earlier (the endpoint
     * does, in order to tell a 404 from a 409) has no lock and no promise; if
     * anything made the row stop being an artifact in between, the DELETE
     * matches ZERO rows and this method reports false having written nothing.
     * Running the CAS first also means the ROW LOCK is taken before any child
     * lock, which is the lock order every other order-touching authority in this
     * engine uses (order, then children) -- the reverse would deadlock against a
     * concurrent finalize on PostgreSQL rather than merely losing.
     *
     * There is consequently no rollback path to lose a zero-row CAS through:
     * nothing has been written when it happens. The transaction is still what
     * makes the operation atomic in the OTHER direction -- a child delete that
     * fails after the order row is gone restores the order row, so its lines and
     * events can never be orphaned behind a vanished parent (every tenant-scoped
     * child reader in this engine joins back through `commerce_orders`, so an
     * orphan would be permanently unreachable AND permanently undeletable).
     *
     * ## Audit posture: DELIBERATELY UNAUDITED, and this is the only one
     *
     * Every other destructive-ish order operation in this engine records a
     * `commerce_order_events` row. This one cannot: the audit row would
     * reference an order that no longer exists, and `eventsForOrder()` joins
     * through `commerce_orders`, so it would be unreadable the moment it was
     * written. Deletion leaves NO row behind BY DEFINITION. That is acceptable
     * for exactly one class of row -- one the guard has just proven never
     * touched money -- and for nothing else, which is why the guard is a
     * database predicate rather than a caller's promise. What IS recorded is an
     * app-log line ({@see self::DELETED_LOG_MESSAGE}) carrying the actor, the
     * uuid, the tenant and the reason, and NO customer PII: the artifact's name,
     * email and phone die with the row and must not be copied into a log on the
     * way out.
     *
     * @param string|null $actorUuid the operator, or null for the sweep
     * @param string $reason one of {@see self::REASON_ADMIN} / {@see self::REASON_PURGE}
     * @return bool true iff THIS call deleted the artifact; false (never a
     *     throw) for an unknown uuid, a cross-tenant uuid, a row that is not an
     *     artifact, and a row a concurrent caller already deleted
     */
    public function deleteArtifact(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        ?string $actorUuid = null,
        string $reason = self::REASON_ADMIN
    ): bool {
        $isArtifact = OrderScope::deletableArtifactSql();

        /** @var bool $deleted */
        $deleted = db($context)->transaction(function () use ($context, $tenant, $uuid, $isArtifact): bool {
            $affected = db($context)->table('commerce_orders')->executeModification(
                <<<SQL
DELETE FROM commerce_orders
WHERE uuid = ? AND tenant_uuid = ? AND {$isArtifact}
SQL,
                [$uuid, $tenant]
            );

            if ($affected !== 1) {
                return false;
            }

            // The COMPLETE child set for an artifact. Addressed by the order
            // uuid the compare-and-set just proved is ours, so no child table
            // needs a tenant column of its own -- except the attempt ledger,
            // which has one and is scoped by both.
            db($context)->table('commerce_order_lines')
                ->where('order_uuid', '=', $uuid)
                ->delete();
            db($context)->table('commerce_order_events')
                ->where('order_uuid', '=', $uuid)
                ->delete();
            db($context)->table('commerce_order_draft_attempts')
                ->where('tenant_uuid', '=', $tenant)
                ->where('order_uuid', '=', $uuid)
                ->delete();

            return true;
        });

        if ($deleted) {
            // AFTER commit, so the log records what actually happened rather
            // than what was attempted -- a throw above never reaches this line.
            ($this->logger ?? new NullLogger())->info(self::DELETED_LOG_MESSAGE, [
                'order_uuid' => $uuid,
                'tenant_uuid' => $tenant,
                'actor_uuid' => $actorUuid,
                'reason' => $reason,
            ]);
        }

        return $deleted;
    }

    /**
     * Hard-deletes up to `$batchSize` draft ARTIFACTS whose LAST TOUCH is older
     * than `commerce.orders.draft_purge_days` before `$now`.
     *
     * The disposal half of this class, and the reason hosts get purging with NO
     * new cron obligation: it is a third independent sweep on the existing
     * `commerce:orders:expire` tick ({@see \Glueful\Extensions\Commerce\Console\OrdersExpireCommand}).
     *
     * Same three properties as {@see self::cancelStale()}, for the same reasons:
     *  - BOUNDED by `$batchSize`, drained by successive ticks;
     *  - IDEMPOTENT, because each row's disposal is its own compare-and-set --
     *    two OVERLAPPING sweeps therefore double-delete nothing, the loser
     *    simply finds its candidates already gone and reports what it actually
     *    did;
     *  - DETERMINISTIC, `$now` is a parameter.
     *
     * Staleness reads `COALESCE(updated_at, created_at)` with a STRICT `<`,
     * exactly like the TTL sweep. Two consequences worth stating out loud:
     * `cancelStale()` stamps `updated_at` when it cancels a draft, so an
     * artifact created by the sweep always gets the FULL purge window before the
     * purge can see it -- the two sweeps never destroy a draft on the tick that
     * canceled it -- and an artifact aged exactly the window survives one more
     * tick. The same driver-clock caveat documented on `cancelStale()` applies
     * here unchanged and is immaterial at 30-day granularity.
     *
     * @return int the number of artifacts this call actually deleted
     */
    public function purgeStale(
        ApplicationContext $context,
        \DateTimeImmutable $now,
        int $batchSize = self::DEFAULT_BATCH_SIZE
    ): int {
        if ($batchSize < 1) {
            return 0;
        }

        $tenant = $this->tenants->tenantUuid($context);
        $purgeDays = CommerceSettings::draftPurgeDays($context);
        $cutoff = $now->setTimezone(new \DateTimeZone('UTC'))
            ->modify("-{$purgeDays} days")
            ->format('Y-m-d H:i:s');

        // Select-then-CAS for the same reason `cancelStale()` uses it: a bulk
        // `DELETE ... LIMIT` is not portable across this engine's drivers, each
        // disposal must carry its own children, and the per-row compare-and-set
        // is what makes an overlapping second sweep a no-op rather than a race.
        $rows = db($context)->table('commerce_orders')
            ->select(['uuid'])
            ->where('tenant_uuid', '=', $tenant)
            ->whereRaw(OrderScope::deletableArtifactSql())
            ->whereRaw('COALESCE(updated_at, created_at) < ?', [$cutoff])
            ->orderBy('id', 'ASC')
            ->limit($batchSize)
            ->get();

        $purged = 0;
        foreach ($rows as $row) {
            if ($this->deleteArtifact($context, $tenant, (string) $row['uuid'], null, self::REASON_PURGE)) {
                $purged++;
            }
        }

        return $purged;
    }
}
