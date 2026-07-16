<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;

/**
 * Single-store fallback: every row lives under the '' sentinel tenant.
 * Never bind this under the shared contract id; construct it inline only when
 * no tenancy implementation is bound.
 */
final class SentinelTenantResolver implements CurrentTenantResolver
{
    public function tenantUuid(ApplicationContext $context): string
    {
        return '';
    }
}
