<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Support\UtcNowSql;

/**
 * Seller-API-key lineage/credential/audit persistence (design spec
 * §2.2/§2.10, MV5c-1 Task 3): thin, tenant-scoped CRUD over the three
 * migration-018 tables --
 * `commerce_seller_api_keys` (the claimable logical LINEAGE row -- its
 * `uuid` IS the stable lineage identity, design spec §2.2),
 * `commerce_seller_api_key_credentials` (one row PER GENERATED FRAMEWORK
 * KEY, resolved by the exact `framework_key_uuid` the framework
 * authenticates), and `commerce_seller_api_key_events` (the append-only
 * audit trail, NEVER a secret) -- mirroring
 * {@see SellerRepository}/{@see SellerLifecycleEventRepository}'s exact
 * "thin repository, service owns the transaction/claim discipline"
 * convention.
 *
 * {@see SellerApiKeyService::create()} (Task 3) needs lineage insert/find,
 * credential insert/find-by-framework-key-uuid, and event insert. Task 4
 * adds {@see self::recordAuthDenied()}, the BOUNDED `auth_denied` write
 * {@see SellerApiKeyAuthorizer::recordDenied()} calls on every per-request
 * authorization denial (design spec §2.10).
 *
 * Task 5 (design spec §2.9) adds the rotation/revocation primitives: the
 * active-lineage revision claim ({@see self::claimActiveLineageRevision()},
 * the SAME "affected-row-checked UPDATE, 0 rows means re-read to classify"
 * idiom as {@see SellerRepository::claimRevision()}, scoped to
 * `status = 'active'` so an already-revoked lineage is never claimable), a
 * direct credential lookup by ITS OWN uuid
 * ({@see self::findCredentialByUuid()}, resolving `current_credential_uuid`
 * -- distinct from {@see self::findCredentialByFrameworkKeyUuid()}, which
 * resolves the AUTHENTICATED framework key instead), the whole-lineage
 * credential enumeration whole-lineage revocation needs
 * ({@see self::findCredentialsForLineage()}), and the demote/advance/revoke
 * mutations ({@see self::demoteCredentialToPredecessor()},
 * {@see self::advanceLineageCurrentCredential()},
 * {@see self::markCredentialRevoked()}, {@see self::markLineageRevoked()}).
 * `commerce_seller_api_key_events` stays append-only throughout -- no
 * `update()`/`delete()` on that table, ever; a correction is always a NEW
 * audit row.
 */
final class SellerApiKeyRepository
{
    /** @param array<string,mixed> $row */
    public function insertLineage(ApplicationContext $context, string $tenant, array $row): void
    {
        db($context)->table('commerce_seller_api_keys')->insert($this->encodeLineage($tenant, $row));
    }

    /** @return array<string,mixed>|null */
    public function findLineageByUuid(ApplicationContext $context, string $tenant, string $uuid): ?array
    {
        $row = db($context)->table('commerce_seller_api_keys')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();

        return $row === null ? null : $this->decodeLineage($row);
    }

    /**
     * The seller's OWN self-service lineage list (design spec §2.8, MV5c-1
     * Task 6, `GET /{sellerUuid}/api-keys`) -- every lineage recorded for
     * this seller, active AND revoked (a seller may legitimately want to see
     * its own key HISTORY, not only what is currently active), ordered
     * oldest-first -- mirrors {@see SellerMembershipRepository::listForSeller()}'s
     * identical `(created_at, uuid)` ordering convention. Never includes a
     * secret -- there is none to include; the framework key material is
     * never persisted anywhere Commerce reads.
     *
     * @return list<array<string,mixed>>
     */
    public function listForSeller(ApplicationContext $context, string $tenant, string $sellerUuid): array
    {
        $rows = db($context)->table('commerce_seller_api_keys')
            ->where('tenant_uuid', '=', $tenant)
            ->where('seller_uuid', '=', $sellerUuid)
            ->orderBy('created_at', 'ASC')
            ->orderBy('uuid', 'ASC')
            ->get();

        return array_map(fn (array $row): array => $this->decodeLineage($row), $rows);
    }

    /** @param array<string,mixed> $row */
    public function insertCredential(ApplicationContext $context, string $tenant, array $row): void
    {
        db($context)->table('commerce_seller_api_key_credentials')->insert([
            'uuid' => $row['uuid'],
            'tenant_uuid' => $tenant,
            'lineage_uuid' => $row['lineage_uuid'],
            'framework_key_uuid' => $row['framework_key_uuid'],
            'generation' => $row['generation'],
            'relationship' => $row['relationship'],
            'grace_expires_at' => $row['grace_expires_at'] ?? null,
        ]);
    }

    /**
     * The exact-credential lookup the per-request authorizer (MV5c-1 Task 4)
     * runs on every API-key request (design spec §2.3 step 1) -- resolved by
     * the framework's OWN authenticated key uuid, never a Commerce-side id.
     *
     * @return array<string,mixed>|null
     */
    public function findCredentialByFrameworkKeyUuid(
        ApplicationContext $context,
        string $tenant,
        string $frameworkKeyUuid
    ): ?array {
        return db($context)->table('commerce_seller_api_key_credentials')
            ->where('tenant_uuid', '=', $tenant)
            ->where('framework_key_uuid', '=', $frameworkKeyUuid)
            ->first();
    }

    /**
     * Resolves a credential by ITS OWN uuid -- used by
     * {@see SellerApiKeyService::rotate()} to re-read the lineage's
     * `current_credential_uuid` (design spec §2.9), distinct from
     * {@see self::findCredentialByFrameworkKeyUuid()}, which resolves by the
     * framework's AUTHENTICATED key uuid on the per-request read path.
     *
     * @return array<string,mixed>|null
     */
    public function findCredentialByUuid(ApplicationContext $context, string $tenant, string $uuid): ?array
    {
        return db($context)->table('commerce_seller_api_key_credentials')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();
    }

    /**
     * EVERY credential row recorded for a lineage -- current, any grace
     * predecessor, and any already-revoked generation -- the whole-lineage
     * enumeration {@see SellerApiKeyService::revoke()} needs (design spec
     * §2.9: "enumerates every credential row for the lineage, making
     * whole-lineage revocation explicit and deterministic").
     *
     * @return list<array<string,mixed>>
     */
    public function findCredentialsForLineage(ApplicationContext $context, string $tenant, string $lineageUuid): array
    {
        return db($context)->table('commerce_seller_api_key_credentials')
            ->where('tenant_uuid', '=', $tenant)
            ->where('lineage_uuid', '=', $lineageUuid)
            ->get();
    }

    /**
     * The claimable active-lineage revision (design spec §2.9 lock order:
     * `... -> lineage revision -> framework/credential writes`) -- the SAME
     * affected-row-checked `UPDATE ... SET revision = revision + 1` idiom as
     * {@see SellerRepository::claimRevision()}, scoped to `status = 'active'`
     * so an already-revoked lineage is NEVER claimable: a 0-row result means
     * either the lineage doesn't exist for this tenant, belongs to a
     * different seller, or is already revoked -- {@see SellerApiKeyService}
     * re-reads (never claims) to classify which, rather than collapsing
     * these into one outcome.
     */
    public function claimActiveLineageRevision(ApplicationContext $context, string $tenant, string $lineageUuid): bool
    {
        $utcNow = UtcNowSql::expression(db($context)->getDriverName());

        $affected = db($context)->table('commerce_seller_api_keys')->executeModification(
            <<<SQL
UPDATE commerce_seller_api_keys
SET revision = revision + 1, updated_at = {$utcNow}
WHERE tenant_uuid = ? AND uuid = ? AND status = 'active'
SQL,
            [$tenant, $lineageUuid]
        );

        return $affected === 1;
    }

    /**
     * Demotes the predecessor credential (design spec §2.9): `relationship`
     * moves to `predecessor` and `grace_expires_at` is set to the EXACT
     * value {@see \Glueful\Auth\ApiKey\ApiKeyService::rotate()} applied to
     * the framework key's own `expires_at` (the earlier of its prior expiry
     * and the grace deadline) -- carried here verbatim, never recomputed.
     */
    public function demoteCredentialToPredecessor(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        string $graceExpiresAt
    ): void {
        db($context)->table('commerce_seller_api_key_credentials')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->update(['relationship' => 'predecessor', 'grace_expires_at' => $graceExpiresAt]);
    }

    /**
     * Advances the lineage's current-credential pointer and `last_rotated_at`
     * after a successful rotation (design spec §2.9) -- issued AFTER the
     * revision claim already serialized this lineage against concurrent
     * rotate/revoke attempts, so a plain conditional UPDATE (no further
     * affected-row check) is safe here.
     */
    public function advanceLineageCurrentCredential(
        ApplicationContext $context,
        string $tenant,
        string $lineageUuid,
        string $newCredentialUuid,
        string $rotatedAt
    ): void {
        db($context)->table('commerce_seller_api_keys')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $lineageUuid)
            ->update([
                'current_credential_uuid' => $newCredentialUuid,
                'last_rotated_at' => $rotatedAt,
                'updated_at' => $rotatedAt,
            ]);
    }

    /**
     * Marks ONE credential row `revoked` -- part of the whole-lineage sweep
     * in {@see SellerApiKeyService::revoke()}.
     */
    public function markCredentialRevoked(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        string $revokedAt
    ): void {
        db($context)->table('commerce_seller_api_key_credentials')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->update(['relationship' => 'revoked', 'revoked_at' => $revokedAt]);
    }

    /**
     * Marks the lineage itself `revoked` -- the final write in
     * {@see SellerApiKeyService::revoke()}'s sweep.
     */
    public function markLineageRevoked(
        ApplicationContext $context,
        string $tenant,
        string $lineageUuid,
        string $revokedAt
    ): void {
        db($context)->table('commerce_seller_api_keys')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $lineageUuid)
            ->update(['status' => 'revoked', 'revoked_at' => $revokedAt, 'updated_at' => $revokedAt]);
    }

    /**
     * Append-only (design spec §2.10) -- deliberately no `update()`/
     * `delete()` method on this class.
     *
     * @param array<string,mixed> $row
     */
    public function insertEvent(ApplicationContext $context, string $tenant, array $row): void
    {
        db($context)->table('commerce_seller_api_key_events')->insert([
            'uuid' => $row['uuid'],
            'tenant_uuid' => $tenant,
            'lineage_uuid' => $row['lineage_uuid'],
            'seller_uuid' => $row['seller_uuid'],
            'subject_user_uuid' => $row['subject_user_uuid'],
            'action' => $row['action'],
            'actor_uuid' => $row['actor_uuid'] ?? null,
            'reason_code' => $row['reason_code'] ?? null,
            'bucket_start' => $row['bucket_start'] ?? null,
            'predecessor_key_uuid' => $row['predecessor_key_uuid'] ?? null,
            'successor_key_uuid' => $row['successor_key_uuid'] ?? null,
            'grace_expires_at' => $row['grace_expires_at'] ?? null,
            'detail' => $row['detail'] ?? null,
        ]);
    }

    /**
     * The BOUNDED `auth_denied` audit write (design spec §2.10, MV5c-1 Task
     * 4): always attempts the insert directly -- the house duplicate-key
     * probe idiom ({@see LedgerRepository::post()},
     * {@see ReserveRepository::insertRollingHold()}) -- never a
     * check-then-insert race. The migration-018
     * `commerce_seller_api_key_events_dedupe_unique` constraint
     * (`tenant_uuid, lineage_uuid, action, reason_code, bucket_start`) caps
     * this at ONE row per lineage/reason/UTC-minute.
     *
     * On a caught `\PDOException`, RE-READS for a row matching this EXACT
     * tuple: found means a genuine dedupe-backstop collision (a repeat
     * denial within the same minute -- expected under a hammering
     * stolen/misused key, not a fault, so nothing is logged); NOT found
     * means the exception had some OTHER cause (e.g. an unrelated uuid
     * collision) -- a genuine write failure, which IS error-logged. Either
     * way this method NEVER rethrows and NEVER inserts a second row:
     * {@see SellerApiKeyAuthorizer::recordDenied()}'s contract is
     * fail-closed regardless of whether this write succeeds -- the caller
     * has already decided to deny access before calling this method, and a
     * persistence failure here must never propagate and risk being
     * mishandled into an accidental allow.
     *
     * The classification RE-READ itself is wrapped in its OWN nested
     * try/catch (MV5c-1 Task 5 hardening): if the re-read query itself
     * throws (e.g. a connection blip on the SAME failing connection that
     * just raised the outer `\PDOException`), that failure is swallowed and
     * error-logged too, never left to propagate out of this method. Without
     * this, a re-read failure would defeat the "NEVER throws" contract this
     * docblock promises -- the caller has ALREADY decided to deny access
     * (see {@see SellerApiKeyAuthorizer::authorize()}), so the request stays
     * fail-closed (a clean 404, never a 500) regardless of whether either
     * the write OR the classification re-read succeeds.
     *
     * @param array{
     *     uuid: string,
     *     lineage_uuid: string,
     *     seller_uuid: string,
     *     subject_user_uuid: string,
     *     reason_code: string,
     *     bucket_start: string
     * } $row
     */
    public function recordAuthDenied(ApplicationContext $context, string $tenant, array $row): void
    {
        try {
            $this->insertEvent($context, $tenant, [
                'uuid' => $row['uuid'],
                'lineage_uuid' => $row['lineage_uuid'],
                'seller_uuid' => $row['seller_uuid'],
                'subject_user_uuid' => $row['subject_user_uuid'],
                'action' => 'auth_denied',
                'reason_code' => $row['reason_code'],
                'bucket_start' => $row['bucket_start'],
            ]);
        } catch (\PDOException $e) {
            try {
                $existing = db($context)->table('commerce_seller_api_key_events')
                    ->where('tenant_uuid', '=', $tenant)
                    ->where('lineage_uuid', '=', $row['lineage_uuid'])
                    ->where('action', '=', 'auth_denied')
                    ->where('reason_code', '=', $row['reason_code'])
                    ->where('bucket_start', '=', $row['bucket_start'])
                    ->first();
            } catch (\Throwable $reReadError) {
                error_log(
                    '[Commerce] Failed to classify seller API key auth_denied write failure '
                        . '(re-read itself failed): ' . $reReadError->getMessage()
                );
                return;
            }

            if ($existing === null) {
                error_log('[Commerce] Failed to record seller API key auth_denied event: ' . $e->getMessage());
            }
        } catch (\Throwable $e) {
            error_log('[Commerce] Failed to record seller API key auth_denied event: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function encodeLineage(string $tenant, array $row): array
    {
        return [
            'uuid' => $row['uuid'],
            'tenant_uuid' => $tenant,
            'seller_uuid' => $row['seller_uuid'],
            'subject_user_uuid' => $row['subject_user_uuid'],
            'declared_scopes' => json_encode($row['declared_scopes'], JSON_THROW_ON_ERROR),
            'name' => $row['name'],
            'status' => $row['status'] ?? 'active',
            'current_credential_uuid' => $row['current_credential_uuid'],
            'expires_at' => $row['expires_at'] ?? null,
            'created_by' => $row['created_by'],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decodeLineage(array $row): array
    {
        if (
            isset($row['declared_scopes'])
            && is_string($row['declared_scopes'])
            && $row['declared_scopes'] !== ''
        ) {
            $decoded = json_decode($row['declared_scopes'], true);
            $row['declared_scopes'] = is_array($decoded) ? array_values($decoded) : [];
        }

        return $row;
    }
}
