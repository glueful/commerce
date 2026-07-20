<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Support\UtcNowSql;

/**
 * Seller-webhook endpoint/secret/audit persistence (design spec §2.2/§2.10,
 * MV5c-2 Task 3): thin, tenant-scoped CRUD over three migration-019 tables --
 * `commerce_seller_webhook_endpoints` (the claimable endpoint row, revisioned
 * exactly like {@see SellerRepository}'s own sellers), `commerce_seller_webhook_secrets`
 * (one row PER minted secret generation, `current`/`previous` relationship),
 * and `commerce_seller_webhook_endpoint_events` (the append-only audit
 * trail, NEVER a secret) -- mirroring {@see SellerApiKeyRepository}'s exact
 * "thin repository, service owns the transaction/claim discipline"
 * convention.
 *
 * **Tombstone reads (CARRY-FORWARD from Task 2):** `commerce_seller_webhook_endpoints`
 * carries a `deleted_at` column, so the framework's `QueryBuilder`
 * auto-applies `WHERE deleted_at IS NULL` to every plain `get()`/`first()`/
 * `count()` against it -- exactly the "excluded from the seller-facing list"
 * behavior design spec §2.10 wants for {@see self::listForSeller()} and
 * {@see self::findByUuid()}. But a DELETE is a TOMBSTONE (design spec §2.2/
 * §2.9), not a row removal: {@see SellerWebhookEndpointService::delete()}'s
 * own idempotency re-read, and any future "retained history" read, must see
 * the tombstoned row -- {@see self::findByUuidIncludingDeleted()} is the
 * ONLY read path on this class that calls `withTrashed()` to disable that
 * auto-filter. `UPDATE`/raw-SQL claims are never subject to the auto-filter
 * at all (it only applies to the SELECT-family `get()`/`first()`/`count()`),
 * so {@see self::claimActiveRevision()} explicitly excludes `status =
 * 'deleted'` itself rather than relying on it.
 *
 * `commerce_seller_webhook_secrets` carries NO `deleted_at` column -- secret
 * rows are never soft-deleted, only marked `revoked_at` (design spec §2.2);
 * every read against that table is a plain, unfiltered query.
 */
final class SellerWebhookEndpointRepository
{
    private const ENDPOINTS_TABLE = 'commerce_seller_webhook_endpoints';
    private const SECRETS_TABLE = 'commerce_seller_webhook_secrets';
    private const EVENTS_TABLE = 'commerce_seller_webhook_endpoint_events';

    // -----------------------------------------------------------------
    // Endpoints
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $row */
    public function insert(ApplicationContext $context, string $tenant, array $row): void
    {
        $row['tenant_uuid'] = $tenant;
        db($context)->table(self::ENDPOINTS_TABLE)->insert($this->encodeEndpoint($row));
    }

    /**
     * Excludes a tombstoned (`deleted_at IS NOT NULL`) row -- the framework's
     * automatic soft-delete filter (see this class's own docblock).
     *
     * @return array<string,mixed>|null
     */
    public function findByUuid(ApplicationContext $context, string $tenant, string $uuid): ?array
    {
        $row = db($context)->table(self::ENDPOINTS_TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();

        return $row === null ? null : $this->decodeEndpoint($row);
    }

    /**
     * The ONLY read on this class that sees a tombstoned row -- used by
     * {@see SellerWebhookEndpointService::delete()}'s own post-tombstone
     * reload and by any caller that must classify "never existed" apart
     * from "deleted" internally (the SERVICE layer then collapses both into
     * the SAME non-revealing 404, design spec §2.2 CARRY-FORWARD).
     *
     * @return array<string,mixed>|null
     */
    public function findByUuidIncludingDeleted(ApplicationContext $context, string $tenant, string $uuid): ?array
    {
        $row = db($context)->table(self::ENDPOINTS_TABLE)
            ->withTrashed()
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();

        return $row === null ? null : $this->decodeEndpoint($row);
    }

    /**
     * The seller's OWN self-service endpoint list (design spec §2.10):
     * every NON-deleted endpoint for this seller, oldest-first -- mirrors
     * {@see SellerApiKeyRepository::listForSeller()}'s identical
     * `(created_at, uuid)` ordering. Never includes a secret -- there is
     * none on this table to include.
     *
     * @return list<array<string,mixed>>
     */
    public function listForSeller(ApplicationContext $context, string $tenant, string $sellerUuid): array
    {
        $rows = db($context)->table(self::ENDPOINTS_TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('seller_uuid', '=', $sellerUuid)
            ->orderBy('created_at', 'ASC')
            ->orderBy('uuid', 'ASC')
            ->get();

        return array_map(fn (array $row): array => $this->decodeEndpoint($row), $rows);
    }

    /**
     * The claimable active-endpoint revision (design spec §2.2/§2.10 lock
     * order: `seller revision -> endpoint revision -> ...`) -- the SAME
     * affected-row-checked `UPDATE ... SET revision = revision + 1` idiom as
     * {@see SellerRepository::claimRevision()}/{@see SellerApiKeyRepository::claimActiveLineageRevision()},
     * scoped to `status != 'deleted'` so a TOMBSTONED endpoint is NEVER
     * claimable: a 0-row result means either the endpoint doesn't exist for
     * this tenant, belongs to a different seller, or is already deleted --
     * {@see SellerWebhookEndpointService} treats ALL THREE identically as a
     * non-revealing 404 (design spec §2.2 CARRY-FORWARD -- "a deleted
     * endpoint can never be re-enabled", and a second `delete()` must be a
     * stable, non-revealing outcome).
     *
     * Raw SQL via `executeModification()` bypasses the ORM-level soft-delete
     * auto-filter entirely (it only ever applies to `get()`/`first()`/
     * `count()`), so `status != 'deleted'` is the load-bearing guard here,
     * not the `deleted_at` column.
     */
    public function claimActiveRevision(ApplicationContext $context, string $tenant, string $uuid): bool
    {
        $utcNow = UtcNowSql::expression(db($context)->getDriverName());

        $affected = db($context)->table(self::ENDPOINTS_TABLE)->executeModification(
            <<<SQL
UPDATE commerce_seller_webhook_endpoints
SET revision = revision + 1, updated_at = {$utcNow}
WHERE tenant_uuid = ? AND uuid = ? AND status != 'deleted'
SQL,
            [$tenant, $uuid]
        );

        return $affected === 1;
    }

    /** @param array<string,mixed> $changes */
    public function update(ApplicationContext $context, string $tenant, string $uuid, array $changes): void
    {
        db($context)->table(self::ENDPOINTS_TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->update($this->encodeEndpoint($changes));
    }

    public function markDisabled(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        ?string $reason,
        string $disabledAt
    ): void {
        db($context)->table(self::ENDPOINTS_TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->update([
                'status' => 'disabled',
                'disabled_at' => $disabledAt,
                'disabled_reason' => $reason,
                'updated_at' => $disabledAt,
            ]);
    }

    /**
     * Re-activation (design spec §2.2): resets `consecutive_failures` to 0
     * (a success-reset-only counter that must start clean after an explicit
     * re-enable) and clears the disablement marker fields.
     */
    public function markEnabled(ApplicationContext $context, string $tenant, string $uuid, string $updatedAt): void
    {
        db($context)->table(self::ENDPOINTS_TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->update([
                'status' => 'active',
                'consecutive_failures' => 0,
                'disabled_at' => null,
                'disabled_reason' => null,
                'updated_at' => $updatedAt,
            ]);
    }

    /**
     * The tombstone write itself (design spec §2.2/§2.9): sets `status =
     * 'deleted'` + `deleted_at`, NEVER a row removal. The row remains
     * durably queryable via {@see self::findByUuidIncludingDeleted()}
     * forever after.
     */
    public function markDeleted(ApplicationContext $context, string $tenant, string $uuid, string $deletedAt): void
    {
        db($context)->table(self::ENDPOINTS_TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->update([
                'status' => 'deleted',
                'deleted_at' => $deletedAt,
                'updated_at' => $deletedAt,
            ]);
    }

    // -----------------------------------------------------------------
    // Secrets
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $row */
    public function insertSecret(ApplicationContext $context, string $tenant, array $row): void
    {
        db($context)->table(self::SECRETS_TABLE)->insert([
            'uuid' => $row['uuid'],
            'tenant_uuid' => $tenant,
            'endpoint_uuid' => $row['endpoint_uuid'],
            'secret_ciphertext' => $row['secret_ciphertext'],
            'secret_fingerprint' => $row['secret_fingerprint'] ?? null,
            'relationship' => $row['relationship'],
            'overlap_expires_at' => $row['overlap_expires_at'] ?? null,
        ]);
    }

    /**
     * The exact-current-secret lookup {@see SellerWebhookSecretService}
     * needs both to mint a successor's AAD-independent context and to
     * decrypt for signing (design spec §2.2/§2.5) -- excludes an already
     * REVOKED row (design spec: a deleted endpoint's secrets are all
     * revoked, so this correctly starts returning null once that happens).
     *
     * @return array<string,mixed>|null
     */
    public function findCurrentSecret(ApplicationContext $context, string $tenant, string $endpointUuid): ?array
    {
        return db($context)->table(self::SECRETS_TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('endpoint_uuid', '=', $endpointUuid)
            ->where('relationship', '=', 'current')
            ->whereRaw('revoked_at IS NULL')
            ->first();
    }

    /**
     * The unexpired `previous` secret (design spec §2.2: "at most one
     * unexpired previous secret") -- {@see SellerWebhookEndpointService::rotateSecret()}
     * retires whatever this returns BEFORE demoting the current secret, so
     * the invariant never has two `previous` rows live at once.
     *
     * @return array<string,mixed>|null
     */
    public function findPreviousSecret(ApplicationContext $context, string $tenant, string $endpointUuid): ?array
    {
        return db($context)->table(self::SECRETS_TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('endpoint_uuid', '=', $endpointUuid)
            ->where('relationship', '=', 'previous')
            ->whereRaw('revoked_at IS NULL')
            ->first();
    }

    /**
     * Demotes the CURRENT secret to `previous` (design spec §2.2): the
     * ciphertext/fingerprint are left untouched -- rotation NEVER
     * re-encrypts an existing secret, only changes its relationship/overlap
     * window, so a delivery already signed with it stays verifiable through
     * `overlap_expires_at`.
     */
    public function demoteCurrentSecretToPrevious(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        string $overlapExpiresAt
    ): void {
        db($context)->table(self::SECRETS_TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->update(['relationship' => 'previous', 'overlap_expires_at' => $overlapExpiresAt]);
    }

    /** Retires ONE stale `previous` secret (design spec §2.2: "retires any older previous"). */
    public function retireSecret(ApplicationContext $context, string $tenant, string $uuid, string $revokedAt): void
    {
        db($context)->table(self::SECRETS_TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->update(['revoked_at' => $revokedAt]);
    }

    /**
     * Whole-endpoint secret revocation (design spec §2.2/§2.9): part of
     * {@see SellerWebhookEndpointService::delete()}'s tombstone sweep --
     * every not-yet-revoked secret (current AND any unexpired previous) is
     * revoked in the SAME transaction as the tombstone write.
     */
    public function revokeAllSecretsForEndpoint(
        ApplicationContext $context,
        string $tenant,
        string $endpointUuid,
        string $revokedAt
    ): void {
        db($context)->table(self::SECRETS_TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('endpoint_uuid', '=', $endpointUuid)
            ->whereRaw('revoked_at IS NULL')
            ->update(['revoked_at' => $revokedAt]);
    }

    // -----------------------------------------------------------------
    // Audit (append-only)
    // -----------------------------------------------------------------

    /**
     * Append-only (design spec §2.10) -- deliberately no `update()`/
     * `delete()` method on this class, mirroring
     * {@see SellerApiKeyRepository::insertEvent()}.
     *
     * @param array<string,mixed> $row
     */
    public function insertEvent(ApplicationContext $context, string $tenant, array $row): void
    {
        db($context)->table(self::EVENTS_TABLE)->insert([
            'uuid' => $row['uuid'],
            'tenant_uuid' => $tenant,
            'endpoint_uuid' => $row['endpoint_uuid'],
            'seller_uuid' => $row['seller_uuid'],
            'action' => $row['action'],
            'actor_uuid' => $row['actor_uuid'] ?? null,
            'reason' => $row['reason'] ?? null,
            'detail' => $row['detail'] ?? null,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function listEventsForEndpoint(ApplicationContext $context, string $tenant, string $endpointUuid): array
    {
        return db($context)->table(self::EVENTS_TABLE)
            ->where('tenant_uuid', '=', $tenant)
            ->where('endpoint_uuid', '=', $endpointUuid)
            ->orderBy('created_at', 'ASC')
            ->orderBy('uuid', 'ASC')
            ->get();
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function encodeEndpoint(array $row): array
    {
        if (isset($row['subscribed_events']) && is_array($row['subscribed_events'])) {
            $row['subscribed_events'] = json_encode($row['subscribed_events'], JSON_THROW_ON_ERROR);
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decodeEndpoint(array $row): array
    {
        if (
            isset($row['subscribed_events'])
            && is_string($row['subscribed_events'])
            && $row['subscribed_events'] !== ''
        ) {
            $decoded = json_decode($row['subscribed_events'], true);
            $row['subscribed_events'] = is_array($decoded) ? array_values($decoded) : [];
        }

        return $row;
    }
}
