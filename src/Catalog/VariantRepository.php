<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

use Glueful\Bootstrap\ApplicationContext;

final class VariantRepository
{
    /** @param array<string,mixed> $row */
    public function insert(ApplicationContext $context, array $row): void
    {
        db($context)->table('commerce_variants')->insert($this->encodeJson($row));
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(ApplicationContext $context, string $tenant, string $uuid): ?array
    {
        $row = db($context)->table('commerce_variants')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();

        return $row === null ? null : $this->decodeJson($row);
    }

    /**
     * Batched `findByUuid()` (Task 7 hardening: shipping-class N+1 fix):
     * one `IN (...)` query for a LIST of variant uuids, tenant-scoped --
     * used by {@see \Glueful\Extensions\Commerce\Cart\CartService::pricedLines()}'s
     * shipping-class pre-pass so it can batch-resolve every distinct class a
     * WHOLE cart references in one {@see \Glueful\Extensions\Commerce\Shipping\ShippingClassRepository::slugsByUuids()}
     * call, mirroring {@see self::forProducts()}'s existing batching
     * pattern. A missing key means the uuid doesn't resolve for this
     * tenant.
     *
     * @param list<string> $uuids
     * @return array<string,array<string,mixed>> keyed by uuid
     */
    public function findByUuids(ApplicationContext $context, string $tenant, array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        $rows = db($context)->table('commerce_variants')
            ->where('tenant_uuid', '=', $tenant)
            ->whereIn('uuid', $uuids)
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['uuid']] = $this->decodeJson($row);
        }

        return $result;
    }

    /** @return array<string,mixed>|null */
    public function findBySku(ApplicationContext $context, string $tenant, string $sku): ?array
    {
        $row = db($context)->table('commerce_variants')
            ->where('tenant_uuid', '=', $tenant)
            ->where('sku', '=', $sku)
            ->first();

        return $row === null ? null : $this->decodeJson($row);
    }

    /** @return list<array<string,mixed>> */
    public function forProduct(ApplicationContext $context, string $tenant, string $productUuid): array
    {
        $rows = db($context)->table('commerce_variants')
            ->where('tenant_uuid', '=', $tenant)
            ->where('product_uuid', '=', $productUuid)
            ->orderBy('position', 'ASC')
            ->get();

        return array_map(fn (array $row): array => $this->decodeJson($row), $rows);
    }

    /**
     * Cheapest possible "does this tenant have ANY priced product?" probe: LIMIT 1, no
     * ordering, no decode. Used by hosts to warn before a setup-time currency change
     * reinterprets existing draft prices (store-settings spec §3.4, revised -- the LOCK
     * predicate is {@see \Glueful\Extensions\Commerce\Orders\OrderRepository::anyExistsForTenant},
     * i.e. recorded money history, not catalog prices).
     */
    public function anyExistsForTenant(ApplicationContext $context, string $tenant): bool
    {
        $rows = db($context)->table('commerce_variants')
            ->select(['uuid'])
            ->where('tenant_uuid', '=', $tenant)
            ->limit(1)
            ->get();

        return $rows !== [];
    }

    /**
     * Setup-time store-currency change (store-settings spec §3.4, revised): rewrite the
     * `currency` CODE on every variant this tenant owns, keeping the integer amounts exactly as
     * typed -- the merchant's own draft prices are reinterpreted, not converted. Required for
     * consistency, not cosmetics: checkout HARD-REJECTS any variant whose currency no longer
     * matches the store currency, so changing the store without rewriting rows would brick every
     * existing product at checkout. Only ever called while the currency is UNLOCKED (no recorded
     * orders), so no historical money is touched.
     */
    public function reassignCurrencyForTenant(ApplicationContext $context, string $tenant, string $currency): void
    {
        db($context)->table('commerce_variants')
            ->where('tenant_uuid', '=', $tenant)
            ->update(['currency' => $currency]);
    }

    /**
     * Batched `forProduct()`: one query for every variant across a LIST of
     * products via IN, grouped by product_uuid (ordered by position within each
     * group, same ordering as `forProduct()`) -- avoids one query per product
     * when resolving variants for e.g. the storefront product index.
     *
     * @param list<string> $productUuids
     * @return array<string,list<array<string,mixed>>> keyed by product_uuid
     */
    public function forProducts(ApplicationContext $context, string $tenant, array $productUuids): array
    {
        if ($productUuids === []) {
            return [];
        }

        $rows = db($context)->table('commerce_variants')
            ->where('tenant_uuid', '=', $tenant)
            ->whereIn('product_uuid', $productUuids)
            ->orderBy('position', 'ASC')
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row['product_uuid']][] = $this->decodeJson($row);
        }

        return $grouped;
    }

    /** @param array<string,mixed> $changes */
    public function update(ApplicationContext $context, string $tenant, string $uuid, array $changes): void
    {
        $changes['updated_at'] = db($context)->getDriver()->formatDateTime();

        db($context)->table('commerce_variants')
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
        if (isset($row['option_values']) && is_array($row['option_values'])) {
            $row['option_values'] = json_encode($row['option_values'], JSON_THROW_ON_ERROR);
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decodeJson(array $row): array
    {
        if (isset($row['option_values']) && is_string($row['option_values']) && $row['option_values'] !== '') {
            $decoded = json_decode($row['option_values'], true);
            $row['option_values'] = is_array($decoded) ? $decoded : [];
        }

        return $row;
    }
}
