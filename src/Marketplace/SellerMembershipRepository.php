<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;

final class SellerMembershipRepository
{
    /** @param array<string,mixed> $row */
    public function insert(ApplicationContext $context, array $row): void
    {
        db($context)->table('commerce_seller_memberships')->insert($row);
    }

    /** @return array<string,mixed>|null */
    public function findBySellerAndUser(
        ApplicationContext $context,
        string $tenant,
        string $sellerUuid,
        string $userUuid
    ): ?array {
        return db($context)->table('commerce_seller_memberships')
            ->where('tenant_uuid', '=', $tenant)
            ->where('seller_uuid', '=', $sellerUuid)
            ->where('user_uuid', '=', $userUuid)
            ->first();
    }

    /** @param array<string,mixed> $changes */
    public function update(ApplicationContext $context, string $tenant, string $uuid, array $changes): void
    {
        db($context)->table('commerce_seller_memberships')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->update($changes);
    }

    /**
     * The concurrent-safe last-owner count (design spec §2.6): callers MUST
     * count this AFTER claiming the seller's revision, never before -- see
     * {@see SellerMembershipService::claimAndRequireMutableSeller()}. Backed
     * by the `(seller_uuid, status, role)` index (migration 010).
     */
    public function countActiveByRole(
        ApplicationContext $context,
        string $tenant,
        string $sellerUuid,
        string $role
    ): int {
        return db($context)->table('commerce_seller_memberships')
            ->where('tenant_uuid', '=', $tenant)
            ->where('seller_uuid', '=', $sellerUuid)
            ->where('role', '=', $role)
            ->where('status', '=', 'active')
            ->count();
    }

    /**
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function listForSeller(
        ApplicationContext $context,
        string $tenant,
        string $sellerUuid,
        int $page,
        int $perPage
    ): array {
        $count = db($context)->table('commerce_seller_memberships')
            ->where('tenant_uuid', '=', $tenant)
            ->where('seller_uuid', '=', $sellerUuid);
        $rows = db($context)->table('commerce_seller_memberships')
            ->where('tenant_uuid', '=', $tenant)
            ->where('seller_uuid', '=', $sellerUuid);

        $items = $rows->orderBy('created_at', 'ASC')
            ->orderBy('uuid', 'ASC')
            ->limit($perPage)
            ->offset(max(0, $page - 1) * $perPage)
            ->get();

        return [
            'items' => $items,
            'total' => $count->count(),
        ];
    }

    /**
     * The "my sellers" read (design spec §2.5, MV1 Task 4): every ACTIVE
     * membership for $userUuid in $tenant, via the `(tenant_uuid, user_uuid)`
     * index (migration 010) -- the one read in this repository predicated by
     * the CALLER's own principal rather than a seller_uuid.
     *
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function listActiveForUser(
        ApplicationContext $context,
        string $tenant,
        string $userUuid,
        int $page,
        int $perPage
    ): array {
        $count = db($context)->table('commerce_seller_memberships')
            ->where('tenant_uuid', '=', $tenant)
            ->where('user_uuid', '=', $userUuid)
            ->where('status', '=', 'active');
        $rows = db($context)->table('commerce_seller_memberships')
            ->where('tenant_uuid', '=', $tenant)
            ->where('user_uuid', '=', $userUuid)
            ->where('status', '=', 'active');

        $items = $rows->orderBy('created_at', 'ASC')
            ->orderBy('uuid', 'ASC')
            ->limit($perPage)
            ->offset(max(0, $page - 1) * $perPage)
            ->get();

        return [
            'items' => $items,
            'total' => $count->count(),
        ];
    }

    /**
     * Bulk-revokes every currently-active membership for the seller (design
     * spec §2.4: close "atomically closes the seller AND deactivates all of
     * its memberships"). MUST run inside the SAME transaction as the
     * seller's `status = 'closed'` write -- see {@see SellerService::close()}.
     */
    public function deactivateAllForSeller(ApplicationContext $context, string $tenant, string $sellerUuid): void
    {
        db($context)->table('commerce_seller_memberships')
            ->where('tenant_uuid', '=', $tenant)
            ->where('seller_uuid', '=', $sellerUuid)
            ->where('status', '=', 'active')
            ->update(['status' => 'revoked']);
    }
}
