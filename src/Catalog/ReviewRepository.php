<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Review CRUD plus the two affected-row-checked primitives moderation relies on
 * (design spec §5): `claimTransition()` is the status claim every approve/spam
 * transition claims BEFORE any rollup mutation runs (same discipline as
 * `RefundRepository::claimPending()`); `guardedDelete()` is a SINGLE guarded
 * `DELETE ... WHERE status IN ('pending','spam')` -- no read-then-delete, so an
 * approved review can never disappear out from under its own rollup contribution.
 */
final class ReviewRepository
{
    /** @param array<string,mixed> $row */
    public function insert(ApplicationContext $context, array $row): void
    {
        db($context)->table('commerce_reviews')->insert($row);
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(ApplicationContext $context, string $tenant, string $uuid): ?array
    {
        return db($context)->table('commerce_reviews')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();
    }

    /**
     * @param array<string,mixed> $filters 'status' and/or 'product' (product_uuid)
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function paginatedFor(
        ApplicationContext $context,
        string $tenant,
        array $filters,
        int $page,
        int $perPage
    ): array {
        $count = db($context)->table('commerce_reviews')->where('tenant_uuid', '=', $tenant);
        $rows = db($context)->table('commerce_reviews')->where('tenant_uuid', '=', $tenant);

        if (isset($filters['status'])) {
            $count->where('status', '=', (string) $filters['status']);
            $rows->where('status', '=', (string) $filters['status']);
        }
        if (isset($filters['product'])) {
            $count->where('product_uuid', '=', (string) $filters['product']);
            $rows->where('product_uuid', '=', (string) $filters['product']);
        }

        return [
            'items' => $rows->orderBy('created_at', 'DESC')
                ->limit($perPage)
                ->offset(max(0, $page - 1) * $perPage)
                ->get(),
            'total' => $count->count(),
        ];
    }

    /**
     * Affected-row-checked `$from -> $to` status claim -- the binding moderation
     * primitive (design spec §5): `UPDATE commerce_reviews SET status = ? WHERE
     * tenant_uuid = ? AND uuid = ? AND status = ?`. Returns false for an unknown or
     * cross-tenant review, OR a review that exists but isn't currently `$from` --
     * ReviewService distinguishes the two by re-reading afterward (see
     * ReviewService::throwTransitionFailure()).
     */
    public function claimTransition(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        string $from,
        string $to
    ): bool {
        $affected = db($context)->table('commerce_reviews')->executeModification(
            <<<'SQL'
UPDATE commerce_reviews SET status = ?, updated_at = ? WHERE tenant_uuid = ? AND uuid = ? AND status = ?
SQL,
            [$to, db($context)->getDriver()->formatDateTime(), $tenant, $uuid, $from]
        );

        return $affected === 1;
    }

    /**
     * ONE guarded delete, no read-then-delete: only `pending`/`spam` reviews may be
     * removed -- an `approved` review must be spammed first so its rollup
     * contribution is reversed before the row disappears (design spec §5). Returns
     * false for an unknown/cross-tenant review OR one that's currently `approved`
     * -- ReviewService maps every false to the same non-revealing 404.
     *
     * Raw SQL via `executeModification()`, not the fluent `where()->delete()`:
     * the query builder's UPDATE/DELETE path does not support an `IN (...)`
     * condition (only simple equality `where()` chains), so the `status IN
     * ('pending','spam')` guard has to be issued directly.
     */
    public function guardedDelete(ApplicationContext $context, string $tenant, string $uuid): bool
    {
        $affected = db($context)->table('commerce_reviews')->executeModification(
            <<<'SQL'
DELETE FROM commerce_reviews WHERE tenant_uuid = ? AND uuid = ? AND status IN (?, ?)
SQL,
            [$tenant, $uuid, 'pending', 'spam']
        );

        return $affected === 1;
    }
}
