<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Invoices;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Default seller identity: reads the null-tolerant `commerce.seller.*` config
 * block, ignoring $tenantUuid entirely (single-store posture). Tenant-aware
 * applications rebind {@see SellerIdentityProvider} to a per-tenant reader
 * without touching the invoice contract.
 */
final class ConfigSellerIdentityProvider implements SellerIdentityProvider
{
    public function forTenant(ApplicationContext $context, string $tenantUuid): array
    {
        return [
            'name' => $this->stringOrNull(config($context, 'commerce.seller.name')),
            'address' => $this->stringOrNull(config($context, 'commerce.seller.address')),
            'tax_id' => $this->stringOrNull(config($context, 'commerce.seller.tax_id')),
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
