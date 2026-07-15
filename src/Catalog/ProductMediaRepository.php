<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

use Glueful\Bootstrap\ApplicationContext;

final class ProductMediaRepository
{
    /** @param array<string,mixed> $row */
    public function insert(ApplicationContext $context, array $row): void
    {
        db($context)->table('commerce_product_media')->insert($row);
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(ApplicationContext $context, string $tenant, string $uuid): ?array
    {
        return db($context)->table('commerce_product_media')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();
    }

    /** @return list<array<string,mixed>> ordered by position ascending */
    public function forProduct(ApplicationContext $context, string $tenant, string $productUuid): array
    {
        return db($context)->table('commerce_product_media')
            ->where('tenant_uuid', '=', $tenant)
            ->where('product_uuid', '=', $productUuid)
            ->orderBy('position', 'ASC')
            ->get();
    }

    /** @return array<string,mixed>|null the product's current cover row, if any */
    public function coverFor(ApplicationContext $context, string $tenant, string $productUuid): ?array
    {
        return db($context)->table('commerce_product_media')
            ->where('tenant_uuid', '=', $tenant)
            ->where('product_uuid', '=', $productUuid)
            ->where('role', '=', 'cover')
            ->first();
    }

    /**
     * Batched `coverFor()`: one cover row per product uuid (at most one, per the
     * at-most-one-cover invariant `demoteCover()` enforces), in a single IN
     * query -- avoids one query per product when resolving cover urls for a
     * LIST of products (e.g. the storefront index or a grouped product's
     * children payload).
     *
     * @param list<string> $productUuids
     * @return array<string,array<string,mixed>> keyed by product_uuid
     */
    public function coversForProducts(ApplicationContext $context, string $tenant, array $productUuids): array
    {
        if ($productUuids === []) {
            return [];
        }

        $rows = db($context)->table('commerce_product_media')
            ->where('tenant_uuid', '=', $tenant)
            ->whereIn('product_uuid', $productUuids)
            ->where('role', '=', 'cover')
            ->get();

        $covers = [];
        foreach ($rows as $row) {
            $covers[(string) $row['product_uuid']] = $row;
        }

        return $covers;
    }

    /** @param array<string,mixed> $changes */
    public function update(ApplicationContext $context, string $tenant, string $uuid, array $changes): void
    {
        db($context)->table('commerce_product_media')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->update($changes);
    }

    public function delete(ApplicationContext $context, string $tenant, string $uuid): void
    {
        db($context)->table('commerce_product_media')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->delete();
    }

    /**
     * Demotes any existing cover(s) for the product to `gallery`, enforcing the
     * at-most-one-cover invariant deterministically (never rejects). Callers run
     * this under the product's `catalog_revision` claim, so the caller has already
     * serialized against concurrent cover mutations for the same product.
     */
    public function demoteCover(
        ApplicationContext $context,
        string $tenant,
        string $productUuid,
        ?string $exceptUuid = null
    ): void {
        $query = db($context)->table('commerce_product_media')
            ->where('tenant_uuid', '=', $tenant)
            ->where('product_uuid', '=', $productUuid)
            ->where('role', '=', 'cover');

        if ($exceptUuid !== null) {
            $query->where('uuid', '!=', $exceptUuid);
        }

        $query->update(['role' => 'gallery']);
    }
}
