<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tax;

use Glueful\Bootstrap\ApplicationContext;

final class TaxRateRepository
{
    /** @param array<string,mixed> $row */
    public function insert(ApplicationContext $context, array $row): void
    {
        db($context)->table('commerce_tax_rates')->insert($row);
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(ApplicationContext $context, string $tenant, string $uuid): ?array
    {
        return db($context)->table('commerce_tax_rates')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();
    }

    /**
     * @return list<array<string,mixed>> tenant's tax rates, optionally filtered by
     *   country/class (spec §6: "list filterable by country/class"), ordered
     *   country ASC, priority ASC, uuid ASC -- priority-then-uuid mirrors the rate
     *   selection order the calculator uses at quote time (spec §5).
     */
    public function search(ApplicationContext $context, string $tenant, ?string $country, ?string $class): array
    {
        $query = db($context)->table('commerce_tax_rates')->where('tenant_uuid', '=', $tenant);
        if ($country !== null) {
            $query = $query->where('country', '=', $country);
        }
        if ($class !== null) {
            $query = $query->where('class', '=', $class);
        }

        return $query->orderBy('country', 'ASC')->orderBy('priority', 'ASC')->orderBy('uuid', 'ASC')->get();
    }

    /** @param array<string,mixed> $changes */
    public function update(ApplicationContext $context, string $tenant, string $uuid, array $changes): void
    {
        db($context)->table('commerce_tax_rates')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->update($changes);
    }

    public function delete(ApplicationContext $context, string $tenant, string $uuid): void
    {
        db($context)->table('commerce_tax_rates')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->delete();
    }

    /**
     * Affected-row-checked serialization primitive for tax-rate PATCH/DELETE
     * (URL's primary resource -- a failed claim is a non-revealing 404).
     */
    public function claimRevision(ApplicationContext $context, string $tenant, string $uuid): bool
    {
        $affected = db($context)->table('commerce_tax_rates')->executeModification(
            <<<'SQL'
UPDATE commerce_tax_rates SET revision = revision + 1 WHERE tenant_uuid = ? AND uuid = ?
SQL,
            [$tenant, $uuid]
        );

        return $affected === 1;
    }
}
