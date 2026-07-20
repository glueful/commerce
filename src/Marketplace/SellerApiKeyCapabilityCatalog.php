<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

/**
 * The API-key-grantable capability catalog (design spec §2.5, Task 2 brief):
 * an explicit ALLOW-list of the exact capabilities that MAY appear in a
 * seller API key's `declared_scopes` (design spec §3). Deliberately a
 * DEDICATED, standalone catalog -- NOT a method on `SellerRoleAuthority` /
 * `FixedSellerRoleAuthority` (design spec §2.5/§3):
 *
 * - `SellerRoleAuthority` answers "what does this ROLE hold right now"
 *   (rebindable, per-seller-role vocabulary).
 * - `SellerApiKeyCapabilityCatalog` answers "may a MACHINE credential ever
 *   carry this capability at all" (fixed, code-defined, independent of any
 *   role).
 *
 * Effective access (design spec §2.4) is always the INTERSECTION of a key's
 * validated declared scopes, the subject user's current role capabilities,
 * and this catalog -- so even a role that is later granted a non-grantable
 * capability (or a key that somehow declares one) can never exercise it
 * through a machine credential.
 *
 * NEVER grantable, by design (§2.5): `commerce.seller.apikeys.manage`
 * (credential administration -- a stolen key must never mint more
 * credentials), `commerce.seller.members.manage` (ownership/membership
 * administration), `commerce.seller.webhooks.manage` (MV5c-2 design spec
 * §2.10 -- webhook-endpoint/secret administration; a seller API key must
 * NEVER be able to register/redirect an endpoint or read/rotate its signing
 * secret), and there is no payout-EXECUTION or policy-administration
 * capability in the shipped vocabulary at all -- `payouts.read` (financial
 * visibility) is the only payout-related capability, and it IS grantable.
 * Every excluded capability is enforced the SAME way: simply never added to
 * {@see self::GRANTABLE} below, so {@see self::contains()} returns false for
 * it by construction -- there is no separate denylist to keep in sync.
 */
final class SellerApiKeyCapabilityCatalog
{
    private const CATALOG_READ = 'commerce.seller.catalog.read';
    private const CATALOG_WRITE = 'commerce.seller.catalog.write';
    private const INVENTORY_READ = 'commerce.seller.inventory.read';
    private const INVENTORY_WRITE = 'commerce.seller.inventory.write';
    private const ORDERS_READ = 'commerce.seller.orders.read';
    private const ORDERS_FULFILL = 'commerce.seller.orders.fulfill';
    private const REPORTS_READ = 'commerce.seller.reports.read';
    private const PAYOUTS_READ = 'commerce.seller.payouts.read';

    /** @var list<string> */
    private const GRANTABLE = [
        self::CATALOG_READ,
        self::CATALOG_WRITE,
        self::INVENTORY_READ,
        self::INVENTORY_WRITE,
        self::ORDERS_READ,
        self::ORDERS_FULFILL,
        self::REPORTS_READ,
        self::PAYOUTS_READ,
    ];

    /** @return list<string> every capability a seller API key may ever declare */
    public static function all(): array
    {
        return self::GRANTABLE;
    }

    /** True only for an exact capability slug in the grantable set -- never a pattern/wildcard. */
    public static function contains(string $capability): bool
    {
        return in_array($capability, self::GRANTABLE, true);
    }
}
