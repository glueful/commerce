<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;

/**
 * Tenant-mode guard: a bound resolver returning the single-store sentinel means
 * no tenant was resolved for this request. Never let commerce read/write that
 * partition while tenancy is enabled.
 */
final class FailClosedTenantResolver implements CurrentTenantResolver
{
    public function __construct(private readonly CurrentTenantResolver $inner)
    {
    }

    public function tenantUuid(ApplicationContext $context): string
    {
        $tenantUuid = $this->inner->tenantUuid($context);
        if ($tenantUuid === '') {
            throw new TenantContextMissingException('Commerce tenant context is required.');
        }

        return $tenantUuid;
    }
}
