<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Support\LiteralLike;

final class TagRepository
{
    /** @param array<string,mixed> $row */
    public function insert(ApplicationContext $context, array $row): void
    {
        db($context)->table('commerce_tags')->insert($row);
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(ApplicationContext $context, string $tenant, string $uuid): ?array
    {
        return db($context)->table('commerce_tags')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();
    }

    /** @return array<string,mixed>|null */
    public function findBySlug(ApplicationContext $context, string $tenant, string $slug): ?array
    {
        return db($context)->table('commerce_tags')
            ->where('tenant_uuid', '=', $tenant)
            ->where('slug', '=', $slug)
            ->first();
    }

    /**
     * Tenant-scoped exact-slug resolution for the storefront product list's
     * `tag` filter (Layer 6 Global Constraints): one query, `null` for an
     * unknown or cross-tenant slug -- the caller (`Http\Storefront\ProductController`)
     * turns that into an enumeration-neutral empty page rather than a 404.
     */
    public function findUuidBySlug(ApplicationContext $context, string $tenant, string $slug): ?string
    {
        $row = $this->findBySlug($context, $tenant, $slug);

        return $row === null ? null : (string) $row['uuid'];
    }

    /** @return list<array<string,mixed>> */
    public function all(ApplicationContext $context, string $tenant): array
    {
        return db($context)->table('commerce_tags')
            ->where('tenant_uuid', '=', $tenant)
            ->orderBy('name', 'ASC')
            ->get();
    }

    /**
     * Paginated admin list (Layer 6 Global Constraints): `q` is a
     * case-insensitive literal substring match on name OR slug via
     * {@see LiteralLike}. Ordered `name ASC, uuid ASC` (stable tie-break); count
     * and row queries apply the identical predicate set.
     *
     * @param array<string,mixed> $filters 'q' (literal substring on name/slug)
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function paginatedFor(
        ApplicationContext $context,
        string $tenant,
        array $filters,
        int $page,
        int $perPage
    ): array {
        $count = db($context)->table('commerce_tags')->where('tenant_uuid', '=', $tenant);
        $rows = db($context)->table('commerce_tags')->where('tenant_uuid', '=', $tenant);

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
            'items' => $items,
            'total' => $count->count(),
        ];
    }

    public function delete(ApplicationContext $context, string $tenant, string $uuid): void
    {
        db($context)->table('commerce_tags')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->delete();
    }

    /**
     * Affected-row-checked serialization primitive for tag deletion (tags carry no
     * tree structure, so this is the only mutation that needs it — product/tag
     * set-lists claim the PRODUCT only and resolve tags by plain in-tenant lookup).
     * Returns false for an unknown or cross-tenant tag.
     */
    public function claimRevision(ApplicationContext $context, string $tenant, string $uuid): bool
    {
        $affected = db($context)->table('commerce_tags')->executeModification(
            <<<'SQL'
UPDATE commerce_tags SET revision = revision + 1 WHERE tenant_uuid = ? AND uuid = ?
SQL,
            [$tenant, $uuid]
        );

        return $affected === 1;
    }

    /** @param array<string,mixed> $changes */
    public function update(ApplicationContext $context, string $tenant, string $uuid, array $changes): void
    {
        db($context)->table('commerce_tags')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->update($changes);
    }

    /** Detaches every product from a tag being deleted. */
    public function detachProducts(ApplicationContext $context, string $tagUuid): void
    {
        db($context)->table('commerce_product_tags')
            ->where('tag_uuid', '=', $tagUuid)
            ->delete();
    }

    /** @return list<string> tag uuids currently attached to the product */
    public function tagUuidsForProduct(ApplicationContext $context, string $productUuid): array
    {
        $rows = db($context)->table('commerce_product_tags')
            ->where('product_uuid', '=', $productUuid)
            ->get();

        return array_map(static fn (array $row): string => (string) $row['tag_uuid'], $rows);
    }

    /** @return list<array<string,mixed>> full, tenant-scoped tag rows attached to the product */
    public function tagsForProduct(ApplicationContext $context, string $tenant, string $productUuid): array
    {
        return db($context)->table('commerce_product_tags')
            ->join('commerce_tags', 'commerce_product_tags.tag_uuid', '=', 'commerce_tags.uuid')
            ->select(['commerce_tags.*'])
            ->where('commerce_tags.tenant_uuid', '=', $tenant)
            ->where('commerce_product_tags.product_uuid', '=', $productUuid)
            ->orderBy('commerce_tags.name', 'ASC')
            ->get();
    }

    public function attachProduct(ApplicationContext $context, string $productUuid, string $tagUuid): void
    {
        db($context)->table('commerce_product_tags')->insert([
            'product_uuid' => $productUuid,
            'tag_uuid' => $tagUuid,
        ]);
    }

    public function detachProduct(ApplicationContext $context, string $productUuid, string $tagUuid): void
    {
        db($context)->table('commerce_product_tags')
            ->where('product_uuid', '=', $productUuid)
            ->where('tag_uuid', '=', $tagUuid)
            ->delete();
    }
}
