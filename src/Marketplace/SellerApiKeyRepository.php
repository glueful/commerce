<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;

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
 * Only the surface {@see SellerApiKeyService::create()} needs is
 * implemented here (lineage insert/find, credential insert/find-by-
 * framework-key-uuid, event insert) -- rotation's demote/revoke-all
 * primitives land with MV5c-1 Task 5, mirroring how
 * {@see SellerLifecycleEventRepository} shipped insert/read-only in MV5b
 * before Task 5 added nothing further to IT specifically (a correction is a
 * NEW audit row, never an edit to history -- no `update()`/`delete()` here
 * either).
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
