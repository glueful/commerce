<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tenancy;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Commerce-local host-integration seam for resolving the active tenant.
 *
 * This is deliberately NOT a shared extension contract (unlike
 * {@see \Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver}, which
 * glueful/tenancy and other packages bind against). It exists purely so a
 * host application can hand Commerce a tenant-resolution callback of its
 * own, without installing a full tenancy package. When bound in the
 * container, {@see \Glueful\Extensions\Commerce\CommerceServiceProvider::makeTenantResolver()}
 * takes priority over the shared-contract/sentinel selection and adapts
 * this seam to the `CurrentTenantResolver` shape Commerce's internals
 * consume, evaluating it fresh on every call (never latched).
 */
interface CommerceTenantResolution
{
    /** The tenant uuid Commerce operates under for the current call ('' = sentinel). */
    public function tenantUuid(ApplicationContext $context): string;
}
