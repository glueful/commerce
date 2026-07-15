<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

use Glueful\Bootstrap\ApplicationContext;

final class ProductRepository
{
    /** @param array<string,mixed> $row */
    public function insert(ApplicationContext $context, array $row): void
    {
        db($context)->table('commerce_products')->insert($this->encodeJson($row));
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(ApplicationContext $context, string $tenant, string $uuid): ?array
    {
        $row = db($context)->table('commerce_products')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();

        return $row === null ? null : $this->decodeJson($row);
    }

    /** @return array<string,mixed>|null */
    public function findBySlug(ApplicationContext $context, string $tenant, string $slug): ?array
    {
        $row = db($context)->table('commerce_products')
            ->where('tenant_uuid', '=', $tenant)
            ->where('slug', '=', $slug)
            ->first();

        return $row === null ? null : $this->decodeJson($row);
    }

    /** @return array{items: list<array<string,mixed>>, total: int} */
    public function listActive(ApplicationContext $context, string $tenant, int $page, int $perPage): array
    {
        $base = db($context)->table('commerce_products')
            ->where('tenant_uuid', '=', $tenant)
            ->where('status', '=', 'active')
            ->whereRaw('deleted_at IS NULL');

        $total = $base->count();
        $rows = db($context)->table('commerce_products')
            ->where('tenant_uuid', '=', $tenant)
            ->where('status', '=', 'active')
            ->whereRaw('deleted_at IS NULL')
            ->orderBy('created_at', 'DESC')
            ->limit($perPage)
            ->offset(max(0, $page - 1) * $perPage)
            ->get();

        return [
            'items' => array_map(fn (array $row): array => $this->decodeJson($row), $rows),
            'total' => $total,
        ];
    }

    /**
     * Affected-row-checked serialization primitive shared by every product-scoped
     * relationship/set-list mutation (media, categories, tags, attributes, children):
     * claim first via this `catalog_revision` bump, then re-read state and enforce
     * invariants — the claimed row lock is what actually serializes concurrent
     * mutations against the same product; the counter itself is just evidence.
     * Returns false for an unknown or cross-tenant product.
     */
    public function claimCatalogRevision(ApplicationContext $context, string $tenant, string $uuid): bool
    {
        $affected = db($context)->table('commerce_products')->executeModification(
            <<<'SQL'
UPDATE commerce_products
SET catalog_revision = catalog_revision + 1, updated_at = ?
WHERE tenant_uuid = ? AND uuid = ?
SQL,
            [
                db($context)->getDriver()->formatDateTime(),
                $tenant,
                $uuid,
            ]
        );

        return $affected === 1;
    }

    /**
     * Affected-row-checked rating rollup primitive (design spec §5): a review
     * approval calls this with `(+rating, +1)`; an approved->spam reversal calls
     * it with `(-rating, -1)`. Raw SQL, not the fluent `update()`, so a
     * soft-deleted product's row is still reachable -- its own affected-row
     * result IS the "product still exists in this tenant" guard ReviewService
     * relies on to roll back a moderation claim when it returns false.
     */
    public function adjustRating(
        ApplicationContext $context,
        string $tenant,
        string $uuid,
        int $ratingDelta,
        int $countDelta
    ): bool {
        $affected = db($context)->table('commerce_products')->executeModification(
            <<<'SQL'
UPDATE commerce_products
SET rating_sum = rating_sum + ?, rating_count = rating_count + ?, updated_at = ?
WHERE tenant_uuid = ? AND uuid = ?
SQL,
            [$ratingDelta, $countDelta, db($context)->getDriver()->formatDateTime(), $tenant, $uuid]
        );

        return $affected === 1;
    }

    /** @param array<string,mixed> $changes */
    public function update(ApplicationContext $context, string $tenant, string $uuid, array $changes): void
    {
        $changes['updated_at'] = db($context)->getDriver()->formatDateTime();

        db($context)->table('commerce_products')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->update($this->encodeJson($changes));
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function encodeJson(array $row): array
    {
        foreach (['options', 'metadata'] as $column) {
            if (isset($row[$column]) && is_array($row[$column])) {
                $row[$column] = json_encode($row[$column], JSON_THROW_ON_ERROR);
            }
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decodeJson(array $row): array
    {
        foreach (['options', 'metadata'] as $column) {
            if (isset($row[$column]) && is_string($row[$column]) && $row[$column] !== '') {
                $decoded = json_decode($row[$column], true);
                $row[$column] = is_array($decoded) ? $decoded : null;
            }
        }

        return $row;
    }
}
