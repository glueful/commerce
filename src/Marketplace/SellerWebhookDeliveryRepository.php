<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;

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
}
