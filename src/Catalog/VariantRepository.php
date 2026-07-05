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
