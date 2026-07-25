<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Invoices;

use Glueful\Extensions\Commerce\Support\CommerceSettings;
use Glueful\Bootstrap\ApplicationContext;

/**
 * Default seller identity: the null-tolerant `commerce.seller.*` keys, read through
 * {@see CommerceSettings} (store-settings spec §3.6 — hosts make them runtime-editable via the
 * settings seam; config/env stays the fallback), ignoring $tenantUuid entirely at THIS level (a
 * bound settings override is itself tenant-scoped in tenant-aware hosts). Applications may still
 * rebind {@see SellerIdentityProvider} outright without touching the invoice contract.
 */
final class ConfigSellerIdentityProvider implements SellerIdentityProvider
{
    public function forTenant(ApplicationContext $context, string $tenantUuid): array
    {
        return [
            'name' => CommerceSettings::sellerName($context),
            'address' => CommerceSettings::sellerAddress($context),
            'tax_id' => CommerceSettings::sellerTaxId($context),
        ];
    }

}
