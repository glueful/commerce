<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Durable, append-only audit trail for every seller-lifecycle transition
 * (design spec §2.1/§2.2, MV5b Task 2): {@see SellerService} inserts exactly
 * one row per suspend/reactivate/close mutation, in the SAME transaction as
 * the `commerce_sellers.status` write itself -- a failure appending this row
 * rolls back the status change too (there is no such thing as an unaudited
 * lifecycle transition). Mirrors {@see ReservePolicyEventRepository}'s exact
 * idiom (the SAME pattern, a DIFFERENT table -- `commerce_seller_lifecycle_events`,
 * never `commerce_reserve_policy_events`).
 *
 * Deliberately INSERT/READ ONLY: no `update()`/`delete()` method exists on
 * this class, on purpose. A correction is a NEW row (a subsequent lifecycle
 * transition, itself audited), never an edit to history.
 */
final class SellerLifecycleEventRepository
{
    /**
     * @param array{
     *     uuid: string,
     *     seller_uuid: string,
     *     from_status: string,
     *     to_status: string,
     *     actor_uuid: string,
     *     reason: string
     * } $row
     */
    public function insert(ApplicationContext $context, string $tenant, array $row): void
    {
        db($context)->table('commerce_seller_lifecycle_events')->insert([
            'uuid' => $row['uuid'],
            'tenant_uuid' => $tenant,
            'seller_uuid' => $row['seller_uuid'],
            'from_status' => $row['from_status'],
            'to_status' => $row['to_status'],
            'actor_uuid' => $row['actor_uuid'],
            'reason' => $row['reason'],
        ]);
    }

    /**
     * Newest-first, matching the design spec §3 index
     * `(tenant_uuid, seller_uuid, created_at)`.
     *
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function paginatedForSeller(
        ApplicationContext $context,
        string $tenant,
        string $sellerUuid,
        int $page,
        int $perPage
    ): array {
        $count = db($context)->table('commerce_seller_lifecycle_events')
            ->where('tenant_uuid', '=', $tenant)
            ->where('seller_uuid', '=', $sellerUuid);
        $rows = db($context)->table('commerce_seller_lifecycle_events')
            ->where('tenant_uuid', '=', $tenant)
            ->where('seller_uuid', '=', $sellerUuid);

        $items = $rows->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit($perPage)
            ->offset(max(0, $page - 1) * $perPage)
            ->get();

        return [
            'items' => $items,
            'total' => $count->count(),
        ];
    }
}
