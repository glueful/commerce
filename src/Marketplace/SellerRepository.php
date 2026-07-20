<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Support\LiteralLike;
use Glueful\Extensions\Commerce\Support\UtcNowSql;

final class SellerRepository
{
    /** @param array<string,mixed> $row */
    public function insert(ApplicationContext $context, array $row): void
    {
        db($context)->table('commerce_sellers')->insert($this->encodeJson($row));
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(ApplicationContext $context, string $tenant, string $uuid): ?array
    {
        $row = db($context)->table('commerce_sellers')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();

        return $row === null ? null : $this->decodeJson($row);
    }

    /** @return array<string,mixed>|null */
    public function findBySlug(ApplicationContext $context, string $tenant, string $slug): ?array
    {
        $row = db($context)->table('commerce_sellers')
            ->where('tenant_uuid', '=', $tenant)
            ->where('slug', '=', $slug)
            ->first();

        return $row === null ? null : $this->decodeJson($row);
    }

    /**
     * Admin list projection: `q` is a case-insensitive literal substring match
     * on name OR slug via {@see LiteralLike}; `status` is an exact match.
     * Count and row queries apply the identical predicate set. Ordered
     * `name ASC, uuid ASC` (stable tie-break).
     *
     * @param array<string,mixed> $filters 'q' (literal substring), 'status' (exact)
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function paginatedFor(
        ApplicationContext $context,
        string $tenant,
        array $filters,
        int $page,
        int $perPage
    ): array {
        $count = db($context)->table('commerce_sellers')->where('tenant_uuid', '=', $tenant);
        $rows = db($context)->table('commerce_sellers')->where('tenant_uuid', '=', $tenant);

        $status = isset($filters['status']) ? trim((string) $filters['status']) : '';
        if ($status !== '') {
            $count->where('status', '=', $status);
            $rows->where('status', '=', $status);
        }

        $q = isset($filters['q']) ? trim((string) $filters['q']) : '';
        if ($q !== '') {
            $pattern = LiteralLike::pattern($q);
            $condition = "(LOWER(name) LIKE ? ESCAPE '!' OR LOWER(slug) LIKE ? ESCAPE '!')";
            $count->whereRaw($condition, [$pattern, $pattern]);
            $rows->whereRaw($condition, [$pattern, $pattern]);
        }

        $items = $rows->orderBy('name', 'ASC')
            ->orderBy('uuid', 'ASC')
            ->limit($perPage)
            ->offset(max(0, $page - 1) * $perPage)
            ->get();

        return [
            'items' => array_map(fn (array $row): array => $this->decodeJson($row), $items),
            'total' => $count->count(),
        ];
    }

    /** @param array<string,mixed> $changes */
    public function update(ApplicationContext $context, string $tenant, string $uuid, array $changes): void
    {
        db($context)->table('commerce_sellers')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->update($this->encodeJson($changes));
    }

    /**
     * Affected-row-checked serialization primitive (design spec §4 lock
     * order): every seller-scoped mutation -- update/lifecycle/membership
     * grant/change/revoke -- claims this FIRST, then re-reads fresh state
     * before acting. Mirrors
     * {@see \Glueful\Extensions\Commerce\Catalog\ProductRepository::claimCatalogRevision()}'s
     * claim discipline, using {@see UtcNowSql} for the embedded timestamp
     * (raw SQL, never a bound formatDateTime() parameter -- driver-correct
     * UTC regardless of PostgreSQL session timezone). Returns false for an
     * unknown or cross-tenant seller.
     */
    public function claimRevision(ApplicationContext $context, string $tenant, string $uuid): bool
    {
        $utcNow = UtcNowSql::expression(db($context)->getDriverName());

        $affected = db($context)->table('commerce_sellers')->executeModification(
            <<<SQL
UPDATE commerce_sellers SET revision = revision + 1, updated_at = {$utcNow} WHERE tenant_uuid = ? AND uuid = ?
SQL,
            [$tenant, $uuid]
        );

        return $affected === 1;
    }

    /**
     * The CLOSE guard read (design spec §2.4): true when the seller owns at
     * least one non-deleted product. Reads `commerce_products` directly
     * rather than through `ProductRepository`/`CatalogService` -- the real
     * seller-scoped catalog attribution write path lands in Task 3; until
     * then tests seed `seller_uuid` via a direct DB insert (see
     * SellerLifecycleTest).
     */
    public function hasLiveProducts(ApplicationContext $context, string $tenant, string $sellerUuid): bool
    {
        return db($context)->table('commerce_products')
            ->where('tenant_uuid', '=', $tenant)
            ->where('seller_uuid', '=', $sellerUuid)
            ->whereRaw('deleted_at IS NULL')
            ->count() > 0;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function encodeJson(array $row): array
    {
        if (isset($row['metadata']) && is_array($row['metadata'])) {
            $row['metadata'] = json_encode($row['metadata'], JSON_THROW_ON_ERROR);
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decodeJson(array $row): array
    {
        if (isset($row['metadata']) && is_string($row['metadata']) && $row['metadata'] !== '') {
            $decoded = json_decode($row['metadata'], true);
            $row['metadata'] = is_array($decoded) ? $decoded : null;
        }

        return $row;
    }
}
