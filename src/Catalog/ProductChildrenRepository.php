<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

use Glueful\Bootstrap\ApplicationContext;

/**
 * `commerce_product_children` join-row access. Carries no `tenant_uuid` of its
 * own (per the design's join-table rule) — every read/write here is reached
 * through a tenant-scoped parent/child product resolved by the caller
 * (CatalogService), never queried bare by uuid alone.
 */
final class ProductChildrenRepository
{
    /** @return list<string> child uuids currently attached to $productUuid, unordered */
    public function childUuidsForProduct(ApplicationContext $context, string $productUuid): array
    {
        $rows = db($context)->table('commerce_product_children')
            ->where('product_uuid', '=', $productUuid)
            ->get();

        return array_map(static fn (array $row): string => (string) $row['child_uuid'], $rows);
    }

    /**
     * Tenant-scoped child PRODUCT rows, ordered by position -- UNFILTERED by the
     * child's own status/deleted_at (an admin managing a grouped product's
     * children needs to see every attached child, including a draft one, to
     * manage the set-list at all). Storefront reads must use
     * `visibleChildProductsForProduct()` instead; see that method's docblock.
     *
     * @return list<array<string,mixed>>
     */
    public function childProductsForProduct(ApplicationContext $context, string $tenant, string $productUuid): array
    {
        return db($context)->table('commerce_product_children')
            ->join('commerce_products', 'commerce_product_children.child_uuid', '=', 'commerce_products.uuid')
            ->select(['commerce_products.*'])
            ->where('commerce_products.tenant_uuid', '=', $tenant)
            ->where('commerce_product_children.product_uuid', '=', $productUuid)
            ->orderBy('commerce_product_children.position', 'ASC')
            ->get();
    }

    /**
     * Storefront variant of `childProductsForProduct()`: same tenant-scoped join,
     * additionally filtered to the SAME visibility rule
     * `ProductController::show()` applies to its own primary resource
     * (`status === 'active'` AND `deleted_at IS NULL`). A child's status/
     * deleted_at can change independently of the children join row (set-list
     * only re-validates physical/digital at attach time, not on every later
     * read), so without this filter a grouped product's storefront `children`
     * echo could leak a draft or soft-deleted child.
     *
     * @return list<array<string,mixed>>
     */
    public function visibleChildProductsForProduct(
        ApplicationContext $context,
        string $tenant,
        string $productUuid
    ): array {
        return db($context)->table('commerce_product_children')
            ->join('commerce_products', 'commerce_product_children.child_uuid', '=', 'commerce_products.uuid')
            ->select(['commerce_products.*'])
            ->where('commerce_products.tenant_uuid', '=', $tenant)
            ->where('commerce_products.status', '=', 'active')
            ->whereRaw('commerce_products.deleted_at IS NULL')
            ->where('commerce_product_children.product_uuid', '=', $productUuid)
            ->orderBy('commerce_product_children.position', 'ASC')
            ->get();
    }

    /**
     * Whitelisted product->child read projection (single-page product editor
     * plan, Task A4): ONE join, but -- unlike {@see self::childProductsForProduct()}
     * and {@see self::visibleChildProductsForProduct()} -- deliberately carries NO
     * live/visible filter on the child row at all (Global Constraints: "Admin
     * children reads never hide existing attachments"). An attached TOMBSTONED
     * child is included exactly like a live one; its tombstone state surfaces as a
     * real `deleted` boolean (derived from `deleted_at`, never exposed raw) rather
     * than being hidden or defaulted. Selects ONLY `uuid`/`name`/`slug`/`status`/
     * `deleted_at` from `commerce_products` plus `commerce_product_children.position`
     * -- never a raw row. Ordered `commerce_product_children.position ASC`, then
     * the child's own `uuid ASC` as a deterministic tie-break.
     *
     * @return list<array{uuid: string, name: string, slug: string, status: string, deleted: bool, position: int}>
     */
    public function childProjectionsForProduct(
        ApplicationContext $context,
        string $tenant,
        string $productUuid
    ): array {
        $rows = db($context)->table('commerce_product_children')
            ->join('commerce_products', 'commerce_product_children.child_uuid', '=', 'commerce_products.uuid')
            ->select([
                'commerce_products.uuid',
                'commerce_products.name',
                'commerce_products.slug',
                'commerce_products.status',
                'commerce_products.deleted_at',
                'commerce_product_children.position',
            ])
            ->where('commerce_products.tenant_uuid', '=', $tenant)
            ->where('commerce_product_children.product_uuid', '=', $productUuid)
            ->orderBy('commerce_product_children.position', 'ASC')
            ->orderBy('commerce_products.uuid', 'ASC')
            ->get();

        return array_map(static function (array $row): array {
            return [
                'uuid' => (string) $row['uuid'],
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
                'status' => (string) $row['status'],
                'deleted' => $row['deleted_at'] !== null,
                'position' => (int) $row['position'],
            ];
        }, $rows);
    }

    /** True when $productUuid has any children attached to it (is a parent). */
    public function isParentAnywhere(ApplicationContext $context, string $productUuid): bool
    {
        return db($context)->table('commerce_product_children')
            ->where('product_uuid', '=', $productUuid)
            ->count() > 0;
    }

    /** True when $productUuid is attached as a child under any parent. */
    public function isChildAnywhere(ApplicationContext $context, string $productUuid): bool
    {
        return db($context)->table('commerce_product_children')
            ->where('child_uuid', '=', $productUuid)
            ->count() > 0;
    }

    /**
     * Wholesale replace: delete every existing row for $productUuid, then insert
     * $orderedChildUuids in order (position = list index). Idempotent in CONTENT
     * — the same ordered list submitted twice produces the same resulting rows,
     * not byte-identical internal ids, mirroring AttributeRepository's
     * product-attribute set-list replacement.
     *
     * @param list<string> $orderedChildUuids
     */
    public function replaceChildren(ApplicationContext $context, string $productUuid, array $orderedChildUuids): void
    {
        db($context)->table('commerce_product_children')
            ->where('product_uuid', '=', $productUuid)
            ->delete();

        foreach (array_values($orderedChildUuids) as $position => $childUuid) {
            db($context)->table('commerce_product_children')->insert([
                'product_uuid' => $productUuid,
                'child_uuid' => $childUuid,
                'position' => $position,
            ]);
        }
    }
}
