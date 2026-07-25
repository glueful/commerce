<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Support;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Host seam for runtime-editable store settings (store-settings spec §3.2) — the second host
 * seam after {@see \Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution}, and shaped
 * the same way: Commerce defines the contract and stays app-agnostic; a host application MAY
 * bind an implementation (e.g. one backed by a tenant-scoped settings table) and every
 * {@see CommerceSettings} read consults it before falling back to config()/env.
 *
 * Why a seam and not a config overlay: the framework's ApplicationContext::overrideConfig() is
 * deliberately BOOT-ONLY (it throws after markBooted() — mid-request config mutation creates
 * split-brain services), and a tenant-scoped settings store cannot be read before tenant
 * resolution. Reading through this contract at USE time — every Commerce read site runs
 * mid-request, after tenant middleware — is the only ordering that works for both.
 */
interface CommerceSettingsOverride
{
    /**
     * The raw override value for a commerce config key ('commerce.currency',
     * 'commerce.tax.flat_rate_bps', …), or null when there is no override.
     *
     * Contract: implementations MUST return null — never throw — whenever they cannot answer
     * (absent tenant context, missing storage, any read failure), so config()/env remains the
     * always-working fallback and a storage problem can never 500 a commerce request.
     */
    public function value(ApplicationContext $context, string $key): ?string;
}
