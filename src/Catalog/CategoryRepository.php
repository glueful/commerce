<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

use Glueful\Bootstrap\ApplicationContext;

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
