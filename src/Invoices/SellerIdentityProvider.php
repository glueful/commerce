<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Invoices;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Commerce-local port for seller identity on invoice-data payloads. Kept local
 * (not an extension-contracts shared port) so Layer 1 stays free of settings
 * infrastructure; tenant-aware host applications rebind this service id to a
 * per-tenant implementation without changing the invoice contract.
 */
interface SellerIdentityProvider
{
    /** @return array{name:?string,address:?string,tax_id:?string} */
    public function forTenant(ApplicationContext $context, string $tenantUuid): array;
}
