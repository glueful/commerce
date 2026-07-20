<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Support\UtcNowSql;

/**
 * Seller-webhook delivery lifecycle PRIMITIVES (design spec §2.4/§2.7/§2.9,
 * MV5c-2 Task 3): the pause/resume/cancel building blocks
 * {@see SellerWebhookEndpointService::disable()}/`enable()`/`delete()` need
 * against ALREADY-EXISTING `commerce_seller_webhook_deliveries` rows.
 * `commerce_seller_webhook_deliveries` carries NO `deleted_at` column, so
 * every read here is a plain, unfiltered query (no soft-delete auto-filter
 * ever applies to this table).
 *
 * Deliberately narrow: this class does NOT insert deliveries (the durable
 * outbox write is `SellerWebhookOutboxPublisher`'s job, MV5c-2 Task 4) and
 * does NOT implement the crash-safe claim lease / retry-classification
 * primitives (Tasks 5-6) -- only what Task 3's endpoint-lifecycle
 * transitions need:
 *
 * - `disable()` pauses the endpoint's `pending` rows with
 *   `pause_reason = endpoint_disabled`, persisting the remaining retry
 *   delay (design spec §2.9's "freeze, don't merely expire" pause
 *   semantics, reused verbatim from the `seller_suspended` case a later
 *   task implements for capture).
 * - `enable()` resumes ONLY the rows THIS class paused for that SAME
 *   reason -- a `seller_suspended`-paused row is invisible to
 *   {@see self::findByEndpointStatusAndPauseReason()} unless that EXACT
 *   reason is passed, so an endpoint enable can never accidentally resume
 *   seller-suspension-paused work (design spec §2.9: "endpoint-disabled
 *   pause is independent").
 * - `delete()` terminally cancels every `pending`/`paused` row for the
 *   endpoint (design spec §2.9), retaining them (never removed) as audit
 *   history.
 */
final class SellerWebhookDeliveryRepository
{
    private const TABLE = 'commerce_seller_webhook_deliveries';

    /** @return list<array<string,mixed>> */
    public function findByEndpointAndStatus(
        ApplicationContext $context,
        string $tenant,
        string $endpointUuid,
        string $status
    ): array {
        return db($context)->table(self::TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('endpoint_uuid', '=', $endpointUuid)
            ->where('status', '=', $status)
            ->get();
    }

    /**
     * Scoped by BOTH status AND the exact `pause_reason` -- the guard that
     * keeps {@see SellerWebhookEndpointService::enable()} from ever
     * resuming a `seller_suspended`-paused delivery (see this class's own
     * docblock).
     *
     * @return list<array<string,mixed>>
     */
    public function findByEndpointStatusAndPauseReason(
        ApplicationContext $context,
        string $tenant,
        string $endpointUuid,
        string $status,
        string $pauseReason
    ): array {
        return db($context)->table(self::TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('endpoint_uuid', '=', $endpointUuid)
            ->where('status', '=', $status)
            ->where('pause_reason', '=', $pauseReason)
            ->get();
    }

    /**
     * Pauses ONE `pending` row (design spec §2.9): the WHERE clause's own
     * `status = 'pending'` is an affected-row safety net -- a row this
     * caller already read as `pending` moments earlier stays consistent
     * even if something else raced it inside the SAME transaction.
     */
    public function pauseOne(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        string $pauseReason,
        string $pausedAt,
        int $remainingSeconds
    ): void {
        db($context)->table(self::TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->where('status', '=', 'pending')
            ->update([
                'status' => 'paused',
                'pause_reason' => $pauseReason,
                'paused_at' => $pausedAt,
                'paused_remaining_seconds' => $remainingSeconds,
                'updated_at' => $pausedAt,
            ]);
    }

    /**
     * Resumes ONE row paused for the EXACT `$pauseReason` given (design
     * spec §2.9): `next_attempt_at` is reconstructed from DB-time +
     * whatever remaining delay was persisted at pause time, never merely
     * cleared -- attempts and every other historical field are left
     * untouched.
     */
    public function resumeOne(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        string $pauseReason,
        string $nextAttemptAt,
        string $updatedAt
    ): void {
        db($context)->table(self::TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->where('status', '=', 'paused')
            ->where('pause_reason', '=', $pauseReason)
            ->update([
                'status' => 'pending',
                'next_attempt_at' => $nextAttemptAt,
                'paused_at' => null,
                'paused_remaining_seconds' => null,
                'pause_reason' => null,
                'updated_at' => $updatedAt,
            ]);
    }

    /**
     * Terminal cancellation (design spec §2.9): every `pending`/`paused`
     * row for the endpoint -- REGARDLESS of pause reason -- moves to
     * `canceled`, retained for audit, never replayable. Used by
     * {@see SellerWebhookEndpointService::delete()}'s tombstone sweep.
     */
    public function cancelPendingAndPausedForEndpoint(
        ApplicationContext $context,
        string $tenant,
        string $endpointUuid,
        string $updatedAt
    ): int {
        return db($context)->table(self::TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('endpoint_uuid', '=', $endpointUuid)
            ->whereIn('status', ['pending', 'paused'])
            ->update(['status' => 'canceled', 'updated_at' => $updatedAt]);
    }

    // -----------------------------------------------------------------
    // Seller-scoped lifecycle primitives (MV5c-2 Task 6, design spec §2.9):
    // the SAME shape as the endpoint-scoped primitives above, scoped by
    // `seller_uuid` instead of `endpoint_uuid` -- {@see SellerService}'s
    // suspend()/reactivate()/close() wiring uses these to act across EVERY
    // one of a seller's endpoints in a single sweep, while the
    // `pause_reason` filter on {@see self::findBySellerStatusAndPauseReason()}
    // keeps an `endpoint_disabled` pause completely invisible to a seller
    // reinstatement -- the exact same non-interference guarantee
    // {@see self::findByEndpointStatusAndPauseReason()} gives the endpoint
    // side against a `seller_suspended` pause.
    // -----------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function findBySellerAndStatus(
        ApplicationContext $context,
        string $tenant,
        string $sellerUuid,
        string $status
    ): array {
        return db($context)->table(self::TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('seller_uuid', '=', $sellerUuid)
            ->where('status', '=', $status)
            ->get();
    }

    /**
     * Scoped by BOTH status AND the exact `pause_reason` -- the guard that
     * keeps a seller reinstatement from ever resuming an
     * `endpoint_disabled`-paused delivery (see this class's own docblock).
     *
     * @return list<array<string,mixed>>
     */
    public function findBySellerStatusAndPauseReason(
        ApplicationContext $context,
        string $tenant,
        string $sellerUuid,
        string $status,
        string $pauseReason
    ): array {
        return db($context)->table(self::TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('seller_uuid', '=', $sellerUuid)
            ->where('status', '=', $status)
            ->where('pause_reason', '=', $pauseReason)
            ->get();
    }

    /**
     * Terminal cancellation (design spec §2.9): every `pending`/`paused`
     * row for the SELLER -- across every one of its endpoints, REGARDLESS
     * of pause reason -- moves to `canceled`, retained for audit, never
     * replayable. Used by {@see SellerService::close()}'s wiring.
     */
    public function cancelPendingAndPausedForSeller(
        ApplicationContext $context,
        string $tenant,
        string $sellerUuid,
        string $updatedAt
    ): int {
        return db($context)->table(self::TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('seller_uuid', '=', $sellerUuid)
            ->whereIn('status', ['pending', 'paused'])
            ->update(['status' => 'canceled', 'updated_at' => $updatedAt]);
    }

    /**
     * Best-effort single-row terminal cancellation (design spec §2.9, MV5c-2
     * Task 6 / T5-review M1 fix): the SAME `WHERE status = 'pending'`
     * affected-row safety net {@see self::pauseOne()} uses, except the
     * landing state is `canceled` instead of `paused` -- used by
     * {@see SellerWebhookDeliveryService}'s claim-time refusal when the
     * owning seller is freshly re-read as `closed` (never `paused`, never
     * resumable, never replayable -- unlike the `suspended` case, which
     * pauses).
     */
    public function cancelOne(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        string $updatedAt
    ): void {
        db($context)->table(self::TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->where('status', '=', 'pending')
            ->update(['status' => 'canceled', 'updated_at' => $updatedAt]);
    }

    // -----------------------------------------------------------------
    // Outbox writes (MV5c-2 Task 4, design spec §2.4/§2.9): the durable
    // per-endpoint delivery rows {@see SellerWebhookOutboxPublisher::capture()}
    // inserts INSIDE the authoritative business transaction, one per matched
    // ACTIVE endpoint. Deliberately narrow -- these two methods are the only
    // way a delivery row is ever born; every other mutation in this class
    // (pause/resume/cancel) only ever transitions an ALREADY-EXISTING row.
    // -----------------------------------------------------------------

    /**
     * A brand-new delivery for an ACTIVE seller (design spec §2.4): `status
     * = 'pending'`, zero attempts, due immediately (`next_attempt_at =
     * DB_NOW`, passed in by the caller so every row from the same capture()
     * call shares an identical timestamp).
     *
     * @param array{
     *     uuid: string,
     *     endpoint_uuid: string,
     *     webhook_event_uuid: string,
     *     seller_uuid: string,
     *     next_attempt_at: string
     * } $row
     */
    public function insertPending(ApplicationContext $context, string $tenant, array $row): void
    {
        db($context)->table(self::TABLE)->insert([
            'uuid' => $row['uuid'],
            'tenant_uuid' => $tenant,
            'endpoint_uuid' => $row['endpoint_uuid'],
            'webhook_event_uuid' => $row['webhook_event_uuid'],
            'seller_uuid' => $row['seller_uuid'],
            'status' => 'pending',
            'attempts' => 0,
            'next_attempt_at' => $row['next_attempt_at'],
        ]);
    }

    /**
     * A brand-new delivery born ALREADY paused (design spec §2.4/§2.9): the
     * seller was already `suspended` at the moment capture() re-read its
     * lifecycle status under the freshly-claimed revision -- `pause_reason =
     * 'seller_suspended'`, zero attempts, zero `paused_remaining_seconds`
     * ("new suspended events use zero" -- mirrors {@see SellerWebhookEndpointService::disable()}'s
     * sibling `endpoint_disabled` pause semantics, never merely expiring
     * while paused). Reinstatement ({@see self::resumeOne()}) reconstructs
     * `next_attempt_at` from DB-time at that point; this row starts with none.
     *
     * @param array{
     *     uuid: string,
     *     endpoint_uuid: string,
     *     webhook_event_uuid: string,
     *     seller_uuid: string
     * } $row
     */
    public function insertPaused(ApplicationContext $context, string $tenant, array $row, string $pausedAt): void
    {
        db($context)->table(self::TABLE)->insert([
            'uuid' => $row['uuid'],
            'tenant_uuid' => $tenant,
            'endpoint_uuid' => $row['endpoint_uuid'],
            'webhook_event_uuid' => $row['webhook_event_uuid'],
            'seller_uuid' => $row['seller_uuid'],
            'status' => 'paused',
            'attempts' => 0,
            'pause_reason' => 'seller_suspended',
            'paused_at' => $pausedAt,
            'paused_remaining_seconds' => 0,
        ]);
    }

    /**
     * A brand-new delivery ATTEMPT LINEAGE created by a replay (design spec
     * §2.8, MV5c-2 Task 6): `status = 'pending'`, zero attempts (a replay is
     * a fresh attempt budget, never a continuation of the original's), due
     * immediately, referencing the EXACT SAME `webhook_event_uuid` snapshot
     * the original delivery signed -- never a re-projected payload -- and
     * `replay_of_uuid` pointing back at the original. The original row
     * itself is NEVER touched by this insert (append-only history, design
     * spec: "WITHOUT mutating historical attempts").
     *
     * @param array{
     *     uuid: string,
     *     endpoint_uuid: string,
     *     webhook_event_uuid: string,
     *     seller_uuid: string,
     *     replay_of_uuid: string,
     *     next_attempt_at: string
     * } $row
     */
    public function insertReplay(ApplicationContext $context, string $tenant, array $row): void
    {
        db($context)->table(self::TABLE)->insert([
            'uuid' => $row['uuid'],
            'tenant_uuid' => $tenant,
            'endpoint_uuid' => $row['endpoint_uuid'],
            'webhook_event_uuid' => $row['webhook_event_uuid'],
            'seller_uuid' => $row['seller_uuid'],
            'status' => 'pending',
            'attempts' => 0,
            'next_attempt_at' => $row['next_attempt_at'],
            'replay_of_uuid' => $row['replay_of_uuid'],
        ]);
    }

    // -----------------------------------------------------------------
    // Crash-safe claim lease + token-checked finalize (MV5c-2 Task 5,
    // design spec §2.7/§2.9): the CAS primitives {@see SellerWebhookDeliveryService}
    // drives through claim -> HTTP attempt -> finalize/reclaim. Every write
    // here is an affected-row-checked CAS -- never a blind update -- so a
    // stale worker (its lease already reclaimed by the sweep, or a
    // concurrent finalize that already ran) always loses the race silently
    // (0 rows affected) rather than corrupting a newer attempt.
    // -----------------------------------------------------------------

    /**
     * Tenant-scoped point read used by claim/finalize/reclaim to load a
     * delivery's current row before mutating it.
     *
     * @return array<string,mixed>|null
     */
    public function findByUuid(ApplicationContext $context, string $tenant, string $uuid): ?array
    {
        return db($context)->table(self::TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();
    }

    /**
     * Tenant-UNSCOPED point read (design spec §2.4 CARRY-FORWARD): Task 4's
     * `SellerWebhookOutboxPublisher::pushQueueHints()` afterCommit() hint
     * pushes ONLY `['delivery_uuid' => ...]` -- no tenant -- so
     * {@see \Glueful\Extensions\Commerce\Queue\Jobs\DeliverSellerWebhookJob}
     * has no tenant to scope by until it first resolves which tenant a
     * hinted delivery_uuid belongs to. Used ONLY for that one resolution
     * step; every subsequent read/write in the claim/finalize protocol is
     * fully tenant-scoped again from that point on, so a hypothetical
     * cross-tenant uuid collision can never cause a cross-tenant mutation --
     * the CAS predicates below always include `tenant_uuid = ?`.
     *
     * @return array<string,mixed>|null
     */
    public function findByUuidAnyTenant(ApplicationContext $context, string $uuid): ?array
    {
        return db($context)->table(self::TABLE)
            ->where('uuid', '=', $uuid)
            ->first();
    }

    /**
     * Unlocked candidate discovery (design spec §2.7): every `pending` row
     * whose `next_attempt_at` is due, oldest-first, batch-limited. Candidate
     * selection is NOT the claim -- {@see self::claimForDelivery()} is the
     * actual CAS a worker must win before delivering.
     *
     * @return list<array<string,mixed>>
     */
    public function duePending(ApplicationContext $context, string $tenant, int $limit): array
    {
        $utcNow = UtcNowSql::expression(db($context)->getDriverName());

        return db($context)->table(self::TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('status', '=', 'pending')
            ->whereRaw("next_attempt_at <= {$utcNow}")
            ->orderBy('next_attempt_at', 'ASC')
            ->orderBy('uuid', 'ASC')
            ->limit($limit)
            ->get();
    }

    /**
     * Unlocked candidate discovery for the recovery sweep (design spec §2.7):
     * every `delivering` row whose crash-safe claim lease has expired,
     * oldest-first, batch-limited. {@see self::reclaimExpired()} is the
     * actual CAS a sweep must win before touching one of these rows.
     *
     * @return list<array<string,mixed>>
     */
    public function dueDelivering(ApplicationContext $context, string $tenant, int $limit): array
    {
        $utcNow = UtcNowSql::expression(db($context)->getDriverName());

        return db($context)->table(self::TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('status', '=', 'delivering')
            ->whereRaw("claim_expires_at <= {$utcNow}")
            ->orderBy('claim_expires_at', 'ASC')
            ->orderBy('uuid', 'ASC')
            ->limit($limit)
            ->get();
    }

    /**
     * The crash-safe claim CAS (design spec §2.7): `pending` -> `delivering`,
     * a fresh random `claim_token` + `claim_expires_at`, `attempts + 1`
     * (atomic increment, raw SQL -- never a read-then-write), and
     * `last_attempt_at`. Guarded by `status = 'pending'` so a row already
     * claimed by a concurrent worker (or reclaimed out from under a dead one
     * and re-claimed already) loses this race with 0 affected rows. This
     * commit -- BEFORE any HTTP I/O runs -- is the in-flight linearization
     * point (design spec §2.9).
     */
    public function claimForDelivery(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        string $claimToken,
        string $claimExpiresAt,
        string $nowStr
    ): bool {
        $utcNow = UtcNowSql::expression(db($context)->getDriverName());

        $affected = db($context)->table(self::TABLE)->executeModification(
            <<<SQL
UPDATE commerce_seller_webhook_deliveries
SET status = 'delivering',
    claim_token = ?,
    claim_expires_at = ?,
    attempts = attempts + 1,
    last_attempt_at = ?,
    updated_at = {$utcNow}
WHERE tenant_uuid = ? AND uuid = ? AND status = 'pending'
SQL,
            [$claimToken, $claimExpiresAt, $nowStr, $tenant, $uuid]
        );

        return $affected === 1;
    }

    /**
     * Token-checked finalize (design spec §2.7/§2.9): `WHERE status =
     * 'delivering' AND claim_token = ?` -- a stale worker whose lease was
     * already reclaimed (a new token, or the row moved on entirely) always
     * affects 0 rows here and MUST treat that as "do not touch counters or
     * endpoint state" (see {@see SellerWebhookDeliveryService}). NOT
     * expiry-gated -- an in-time finalize's lease has not expired yet, so
     * gating on `claim_expires_at` here would wrongly reject a healthy
     * on-time completion; {@see self::reclaimExpired()} is the sweep's
     * separate, expiry-gated sibling. `$changes` always determines the
     * landing `status` (`delivered|pending|dead_letter`); claim fields are
     * ALWAYS cleared on an accepted finalize.
     *
     * @param array<string,mixed> $changes
     */
    public function finalize(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        string $claimToken,
        array $changes,
        string $updatedAt
    ): bool {
        $changes['claim_token'] = null;
        $changes['claim_expires_at'] = null;
        $changes['updated_at'] = $updatedAt;

        $affected = db($context)->table(self::TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->where('status', '=', 'delivering')
            ->where('claim_token', '=', $claimToken)
            ->update($changes);

        return $affected === 1;
    }

    // -----------------------------------------------------------------
    // Seller self-service SANITIZED read (MV5c-2 Task 7, design spec §2.10):
    // the ONE read this table exposes to the JWT-interactive seller
    // management surface -- deliberately a hand-picked column allowlist at
    // the SQL level (never `SELECT *`), so a future column added to this
    // table is excluded by default rather than leaked by default.
    // -----------------------------------------------------------------

    /**
     * The seller-facing delivery-history read
     * ({@see \Glueful\Extensions\Commerce\Http\Seller\SellerWebhookController::deliveries()}):
     * every column here is deliberately safe to hand back verbatim --
     * status/attempts/timestamps/last response code/replay lineage. Excludes
     * `id`/`tenant_uuid`/`endpoint_uuid`/`webhook_event_uuid`/`seller_uuid`
     * (internal identifiers the caller -- already scoped to ONE endpoint by
     * the controller's own ownership check -- has no use for) and, above
     * all, `claim_token`/`claim_expires_at` (the internal crash-safe-lease
     * bookkeeping {@see self::claimForDelivery()}/{@see self::finalize()}
     * drive -- never meaningful to a seller). `last_error` is always ALREADY
     * a generic, pre-sanitized string by the time it lands in this column
     * (design spec §2.6: an SSRF-safety failure's message is the fixed
     * literal "Webhook delivery blocked by safety validation.", never a
     * resolved internal address) -- nothing further needs to be stripped
     * from it here. Newest-first (design spec §2.10: "read retained delivery
     * history").
     *
     * @return list<array<string,mixed>>
     */
    public function deliveriesForEndpoint(
        ApplicationContext $context,
        string $tenant,
        string $endpointUuid
    ): array {
        return db($context)->table(self::TABLE)
            ->select([
                'uuid',
                'status',
                'attempts',
                'next_attempt_at',
                'paused_at',
                'paused_remaining_seconds',
                'pause_reason',
                'last_attempt_at',
                'last_status_code',
                'last_error',
                'replay_of_uuid',
                'created_at',
                'updated_at',
            ])
            ->where('tenant_uuid', '=', $tenant)
            ->where('endpoint_uuid', '=', $endpointUuid)
            ->orderBy('created_at', 'DESC')
            ->orderBy('uuid', 'DESC')
            ->get();
    }

    /**
     * The sweep's reclaim CAS (design spec §2.7): `WHERE status =
     * 'delivering' AND claim_token = ? AND claim_expires_at <= {utcNow}` --
     * the SAME token check as {@see self::finalize()}, PLUS an expiry gate
     * so the sweep can never reclaim a lease that is still healthy (only
     * {@see self::dueDelivering()}'s own unlocked candidate read raced a
     * lease renewal that never actually happens in this design, belt and
     * suspenders). Never touches `attempts` -- the claim already incremented
     * it (design spec §2.7: "an expired claim counts as the already-
     * incremented attempt").
     *
     * Raw SQL (like {@see self::claimForDelivery()}), NOT the fluent
     * `->update()` builder: the framework query builder does not yet support
     * a raw/complex WHERE predicate (the `claim_expires_at <= {utcNow}`
     * comparison) combined with an UPDATE. `$changes`' keys are always
     * internal, fixed column names from THIS class's own call sites (never
     * user input), so building the SET clause from them is safe.
     *
     * @param array<string,mixed> $changes
     */
    public function reclaimExpired(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        string $claimToken,
        array $changes,
        string $updatedAt
    ): bool {
        $changes['claim_token'] = null;
        $changes['claim_expires_at'] = null;
        $changes['updated_at'] = $updatedAt;

        $utcNow = UtcNowSql::expression(db($context)->getDriverName());

        $setClauses = [];
        $bindings = [];
        foreach ($changes as $column => $value) {
            $setClauses[] = "{$column} = ?";
            $bindings[] = $value;
        }
        $bindings[] = $tenant;
        $bindings[] = $uuid;
        $bindings[] = $claimToken;

        $sql = 'UPDATE commerce_seller_webhook_deliveries SET ' . implode(', ', $setClauses)
            . " WHERE tenant_uuid = ? AND uuid = ? AND status = 'delivering' AND claim_token = ?"
            . " AND claim_expires_at <= {$utcNow}";

        $affected = db($context)->table(self::TABLE)->executeModification($sql, $bindings);

        return $affected === 1;
    }
}
