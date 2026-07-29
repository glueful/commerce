<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Support\UuidBatch;

final class AddonRepository
{
    /** @param array<string,mixed> $row */
    public function insert(ApplicationContext $context, array $row): void
    {
        db($context)->table('commerce_product_addons')->insert($this->encodeJson($row));
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(ApplicationContext $context, string $tenant, string $uuid): ?array
    {
        $row = db($context)->table('commerce_product_addons')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();

        return $row === null ? null : $this->decodeJson($row);
    }

    /** @return list<array<string,mixed>> every definition for the product, ordered by position */
    public function forProduct(ApplicationContext $context, string $tenant, string $productUuid): array
    {
        $rows = db($context)->table('commerce_product_addons')
            ->where('tenant_uuid', '=', $tenant)
            ->where('product_uuid', '=', $productUuid)
            ->orderBy('position', 'ASC')
            ->get();

        return array_map(fn (array $row): array => $this->decodeJson($row), $rows);
    }

    /**
     * ACTIVE-only definitions -- the pool {@see \Glueful\Extensions\Commerce\Cart\AddonSnapshot::build()}
     * validates selections against. An addon flipped to `inactive` is simply no
     * longer selectable for NEW lines; already-persisted snapshots are unaffected
     * (snapshot, don't reference).
     *
     * @return list<array<string,mixed>> ordered by position ascending
     */
    public function activeForProduct(ApplicationContext $context, string $tenant, string $productUuid): array
    {
        $rows = db($context)->table('commerce_product_addons')
            ->where('tenant_uuid', '=', $tenant)
            ->where('product_uuid', '=', $productUuid)
            ->where('status', '=', 'active')
            ->orderBy('position', 'ASC')
            ->get();

        return array_map(fn (array $row): array => $this->decodeJson($row), $rows);
    }

    /**
     * Batched storefront card presence read (storefront-v1 Task 1):
     * `product_uuid => true` exactly where an ACTIVE `required` add-on
     * definition exists, in ONE `IN (...)` query. Mirrors the pool
     * {@see self::activeForProduct()} feeds AddonSnapshot: an `inactive`
     * definition is no longer selectable, so it must not flag the product.
     * The `required` test is the same PHP-side `(bool)` cast every existing
     * consumer of this flag uses (portable across sqlite's 0/1 and pgsql's
     * real booleans, unlike a driver-specific `required = 1` predicate).
     * Input passes through {@see UuidBatch::normalize()} (malformed dropped,
     * first-occurrence dedupe, first-100 cap); an empty normalized set
     * issues NO query. Products without an active required add-on are absent.
     *
     * @param array<mixed> $productUuids
     * @return array<string, true> keyed by product_uuid
     */
    public function hasRequiredForProducts(ApplicationContext $context, string $tenant, array $productUuids): array
    {
        $productUuids = UuidBatch::normalize($productUuids);
        if ($productUuids === []) {
            return [];
        }

        $rows = db($context)->table('commerce_product_addons')
            ->select(['product_uuid', 'required'])
            ->where('tenant_uuid', '=', $tenant)
            ->whereIn('product_uuid', $productUuids)
            ->where('status', '=', 'active')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            if ((bool) $row['required']) {
                $result[(string) $row['product_uuid']] = true;
            }
        }

        return $result;
    }

    /** @param array<string,mixed> $changes */
    public function update(ApplicationContext $context, string $tenant, string $uuid, array $changes): void
    {
        db($context)->table('commerce_product_addons')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->update($this->encodeJson($changes));
    }

    public function delete(ApplicationContext $context, string $tenant, string $uuid): void
    {
        db($context)->table('commerce_product_addons')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->delete();
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function encodeJson(array $row): array
    {
        if (array_key_exists('choices', $row) && is_array($row['choices'])) {
            $row['choices'] = json_encode($row['choices'], JSON_THROW_ON_ERROR);
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decodeJson(array $row): array
    {
        if (isset($row['choices']) && is_string($row['choices']) && $row['choices'] !== '') {
            $decoded = json_decode($row['choices'], true);
            $row['choices'] = is_array($decoded) ? $decoded : null;
        }

        return $row;
    }
}
