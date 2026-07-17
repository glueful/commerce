<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;

/**
 * The two-level marketplace switch gate (design spec §2.1): a config-only
 * INSTALL master switch, and a per-workspace ACTIVATION state layered on top
 * of it once installed.
 *
 * `installEnabled()` reads `commerce.marketplace.enabled` ONLY -- zero
 * database queries, ever. This is the MASTER-OFF FAST PATH every marketplace
 * caller (route registration, service wiring, `CatalogService::createProduct()`
 * in Task 3) checks FIRST: while it is false, ordinary Commerce request paths
 * must execute zero marketplace-table queries and behave byte-identically to
 * a pre-MV1 install.
 *
 * `activeFor()` reads the tenant's `commerce_marketplace_settings` row.
 * CALLERS MUST NEVER invoke `activeFor()` (or any other marketplace-table
 * query) when {@see self::installEnabled()} is false -- that contract is
 * enforced by callers, not by this method re-checking the switch on every
 * call. This class deliberately keeps the two checks independent so
 * maintenance surfaces that must stay marketplace-aware REGARDLESS of the
 * master switch (migrations, `DiagnosticsReport`, `commerce:tenancy:adopt` --
 * design spec §2.1 explicit exceptions) can read settings/seller state
 * without routing through an install-switch gate that does not apply to them.
 */
final class MarketplaceMode
{
    public function installEnabled(ApplicationContext $context): bool
    {
        return (bool) config($context, 'commerce.marketplace.enabled', false);
    }

    /**
     * True only when a `commerce_marketplace_settings` row exists for the
     * tenant AND its `status` is `active`. A workspace that has never
     * activated (no row) and one that was explicitly deactivated (an
     * existing `disabled` row, design spec §2.3 -- deactivation is
     * non-destructive) are both inactive; callers never need to tell the two
     * apart here.
     */
    public function activeFor(ApplicationContext $context, string $tenant): bool
    {
        $row = db($context)->table('commerce_marketplace_settings')
            ->where('tenant_uuid', '=', $tenant)
            ->first();

        return $row !== null && (string) $row['status'] === 'active';
    }
}
