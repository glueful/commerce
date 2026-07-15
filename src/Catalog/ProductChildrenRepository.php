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

    /** @return list<array<string,mixed>> tenant-scoped child PRODUCT rows, ordered by position */
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
