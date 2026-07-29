<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Support\UuidBatch;

final class CategoryRepository
{
    /** @param array<string,mixed> $row */
    public function insert(ApplicationContext $context, array $row): void
    {
        db($context)->table('commerce_categories')->insert($row);
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(ApplicationContext $context, string $tenant, string $uuid): ?array
    {
        return db($context)->table('commerce_categories')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();
    }

    /** @return array<string,mixed>|null */
    public function findBySlug(ApplicationContext $context, string $tenant, string $slug): ?array
    {
        return db($context)->table('commerce_categories')
            ->where('tenant_uuid', '=', $tenant)
            ->where('slug', '=', $slug)
            ->first();
    }

    /**
     * Tenant-scoped exact-slug resolution for the storefront product list's
     * `category` filter (Layer 6 Global Constraints): one query, `null` for an
     * unknown or cross-tenant slug -- the caller (`Http\Storefront\ProductController`)
     * turns that into an enumeration-neutral empty page rather than a 404.
     */
    public function findUuidBySlug(ApplicationContext $context, string $tenant, string $slug): ?string
    {
        $row = $this->findBySlug($context, $tenant, $slug);

        return $row === null ? null : (string) $row['uuid'];
    }

    /** @return list<array<string,mixed>> every category for the tenant, position then name */
    public function all(ApplicationContext $context, string $tenant): array
    {
        return db($context)->table('commerce_categories')
            ->where('tenant_uuid', '=', $tenant)
            ->orderBy('position', 'ASC')
            ->orderBy('name', 'ASC')
            ->get();
    }

    /** @return list<array<string,mixed>> direct children of $parentUuid (null = roots) */
    public function children(ApplicationContext $context, string $tenant, ?string $parentUuid): array
    {
        $query = db($context)->table('commerce_categories')->where('tenant_uuid', '=', $tenant);
        $query = $parentUuid === null
            ? $query->whereNull('parent_uuid')
            : $query->where('parent_uuid', '=', $parentUuid);

        return $query->orderBy('position', 'ASC')->get();
    }

    /** @param array<string,mixed> $changes */
    public function update(ApplicationContext $context, string $tenant, string $uuid, array $changes): void
    {
        db($context)->table('commerce_categories')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->update($changes);
    }

    public function delete(ApplicationContext $context, string $tenant, string $uuid): void
    {
        db($context)->table('commerce_categories')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->delete();
    }

    /**
     * Affected-row-checked serialization primitive shared by every structural
     * category mutation: create-with-parent claims the parent (a child-attach event
     * on it), reparent claims the node plus its new parent, delete claims
     * target+parent+children — always in sorted-uuid order, mirroring
     * ProductRepository::claimCatalogRevision. Returns false for an unknown or
     * cross-tenant category, which callers turn into a non-revealing 404 (URL-named
     * targets) or 422 (referenced body fields like parent_uuid).
     */
    public function claimRevision(ApplicationContext $context, string $tenant, string $uuid): bool
    {
        $affected = db($context)->table('commerce_categories')->executeModification(
            <<<'SQL'
UPDATE commerce_categories SET revision = revision + 1 WHERE tenant_uuid = ? AND uuid = ?
SQL,
            [$tenant, $uuid]
        );

        return $affected === 1;
    }

    /** Re-parents every direct child of $oldParentUuid to $newParentUuid (delete's re-parent step). */
    public function reparentChildren(
        ApplicationContext $context,
        string $tenant,
        string $oldParentUuid,
        ?string $newParentUuid
    ): void {
        db($context)->table('commerce_categories')
            ->where('tenant_uuid', '=', $tenant)
            ->where('parent_uuid', '=', $oldParentUuid)
            ->update(['parent_uuid' => $newParentUuid]);
    }

    /** Detaches every product from a category being deleted. */
    public function detachProducts(ApplicationContext $context, string $categoryUuid): void
    {
        db($context)->table('commerce_product_categories')
            ->where('category_uuid', '=', $categoryUuid)
            ->delete();
    }

    /** @return list<string> category uuids currently attached to the product */
    public function categoryUuidsForProduct(ApplicationContext $context, string $productUuid): array
    {
        $rows = db($context)->table('commerce_product_categories')
            ->where('product_uuid', '=', $productUuid)
            ->get();

        return array_map(static fn (array $row): string => (string) $row['category_uuid'], $rows);
    }

    /** @return list<array<string,mixed>> full, tenant-scoped category rows attached to the product */
    public function categoriesForProduct(ApplicationContext $context, string $tenant, string $productUuid): array
    {
        return db($context)->table('commerce_product_categories')
            ->join('commerce_categories', 'commerce_product_categories.category_uuid', '=', 'commerce_categories.uuid')
            ->select(['commerce_categories.*'])
            ->where('commerce_categories.tenant_uuid', '=', $tenant)
            ->where('commerce_product_categories.product_uuid', '=', $productUuid)
            ->orderBy('commerce_categories.name', 'ASC')
            ->get();
    }

    /**
     * Whitelisted product->category read projection (single-page product
     * editor plan, Task A2): ONE join, selecting ONLY `uuid`/`name`/`slug` --
     * never raw `commerce_categories.*` rows (Global Constraints: whitelisted
     * projections only, no raw rows). Ordered `name ASC, uuid ASC` -- a
     * deterministic tie-break so item order is stable and testable
     * regardless of attachment order. Categories may be hierarchical, but
     * this returns ONLY the directly-assigned rows in
     * `commerce_product_categories` -- no ancestor expansion.
     *
     * @return list<array{uuid: string, name: string, slug: string}>
     */
    public function categoryProjectionsForProduct(
        ApplicationContext $context,
        string $tenant,
        string $productUuid
    ): array {
        return db($context)->table('commerce_product_categories')
            ->join(
                'commerce_categories',
                'commerce_product_categories.category_uuid',
                '=',
                'commerce_categories.uuid'
            )
            ->select(['commerce_categories.uuid', 'commerce_categories.name', 'commerce_categories.slug'])
            ->where('commerce_categories.tenant_uuid', '=', $tenant)
            ->where('commerce_product_categories.product_uuid', '=', $productUuid)
            ->orderBy('commerce_categories.name', 'ASC')
            ->orderBy('commerce_categories.uuid', 'ASC')
            ->get();
    }

    /**
     * Batched storefront card read (storefront-v1 Task 1): each product's
     * FIRST directly-assigned category as a `{name, slug}` projection, in
     * ONE join query. "First" is pinned as `position ASC, name ASC, uuid ASC`
     * over the tenant's category rows -- the read is ordered
     * `product_uuid, position, name, uuid` and the per-product first-row
     * reduction happens in PHP. Direct assignments only (mirrors
     * {@see self::categoryProjectionsForProduct()}: no ancestor expansion);
     * tenant scoping rides on `commerce_categories.tenant_uuid`, exactly like
     * every other join through the tenant-less `commerce_product_categories`
     * table. Input passes through {@see UuidBatch::normalize()} (malformed
     * dropped, first-occurrence dedupe, first-100 cap); an empty normalized
     * set issues NO query. A product with no direct assignment is absent.
     *
     * @param array<mixed> $productUuids
     * @return array<string, array{name: string, slug: string}> keyed by product_uuid
     */
    public function firstCategoryProjectionsForProducts(
        ApplicationContext $context,
        string $tenant,
        array $productUuids
    ): array {
        $productUuids = UuidBatch::normalize($productUuids);
        if ($productUuids === []) {
            return [];
        }

        $rows = db($context)->table('commerce_product_categories')
            ->join(
                'commerce_categories',
                'commerce_product_categories.category_uuid',
                '=',
                'commerce_categories.uuid'
            )
            ->select([
                'commerce_product_categories.product_uuid',
                'commerce_categories.name',
                'commerce_categories.slug',
            ])
            ->where('commerce_categories.tenant_uuid', '=', $tenant)
            ->whereIn('commerce_product_categories.product_uuid', $productUuids)
            ->orderBy('commerce_product_categories.product_uuid', 'ASC')
            ->orderBy('commerce_categories.position', 'ASC')
            ->orderBy('commerce_categories.name', 'ASC')
            ->orderBy('commerce_categories.uuid', 'ASC')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $key = (string) $row['product_uuid'];
            if (!isset($result[$key])) {
                $result[$key] = [
                    'name' => (string) $row['name'],
                    'slug' => (string) $row['slug'],
                ];
            }
        }

        return $result;
    }

    public function attachProduct(ApplicationContext $context, string $productUuid, string $categoryUuid): void
    {
        db($context)->table('commerce_product_categories')->insert([
            'product_uuid' => $productUuid,
            'category_uuid' => $categoryUuid,
        ]);
    }

    public function detachProduct(ApplicationContext $context, string $productUuid, string $categoryUuid): void
    {
        db($context)->table('commerce_product_categories')
            ->where('product_uuid', '=', $productUuid)
            ->where('category_uuid', '=', $categoryUuid)
            ->delete();
    }
}
