<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Extensions\Commerce\Marketplace\Contracts\SellerRoleAuthority;

/**
 * The shipped, code-defined role vocabulary (design spec §2.6 / plan Global
 * Constraints -- "the Thallo lesson: vocabulary in code, decisions in data,
 * but NO per-seller overrides/custom roles in v1"). Vocabulary and capability
 * matrix are copied verbatim from the design spec:
 *
 * | capability                        | owner | admin | staff | analyst |
 * |------------------------------------|:-----:|:-----:|:-----:|:-------:|
 * | commerce.seller.catalog.read       |   x   |   x   |   x   |    x    |
 * | commerce.seller.catalog.write      |   x   |   x   |       |         |
 * | commerce.seller.inventory.read     |   x   |   x   |   x   |    x    |
 * | commerce.seller.inventory.write    |   x   |   x   |   x   |         |
 * | commerce.seller.members.manage     |   x   |       |       |         |
 * | commerce.seller.orders.read        |   x   |   x   |   x   |    x    |
 * | commerce.seller.orders.fulfill     |   x   |   x   |   x   |         |
 * | commerce.seller.reports.read       |   x   |   x   |       |    x    |
 * | commerce.seller.payouts.read       |   x   |   x   |       |    x    |
 * | commerce.seller.apikeys.manage     |   x   |   x   |       |         |
 * | commerce.seller.webhooks.manage    |   x   |   x   |       |         |
 *
 * `commerce.seller.orders.{read,fulfill}` (design spec §6.1/§2.6, MV2 Task 8)
 * gate the seller order surfaces -- `fulfill` deliberately mirrors
 * `inventory.write`'s owner/admin/staff-not-analyst shape, since fulfillment
 * is an operational write an analyst role must never perform.
 * `commerce.seller.reports.read`/`commerce.seller.payouts.read` (design spec
 * §6.2, MV3 Task 11) gate the read-only financial surfaces (report/balance/
 * commission-policy inspection, and payouts respectively) -- granted to
 * owner/admin/analyst, deliberately NOT `seller_staff`: financial visibility
 * is an owner/management/analytics concern, the mirror image of
 * `orders.fulfill`'s operational-only shape above. No per-seller overrides
 * or custom roles in v1.
 *
 * `commerce.seller.apikeys.manage` (design spec §2.8, MV5c-1 Task 2) gates
 * the seller self-service API-key management surface (create/rotate/revoke/
 * list) -- granted to owner/admin ONLY, deliberately NOT staff/analyst:
 * minting machine credentials is a credential-administration concern, never
 * an operational or read-only-analytics one. This capability itself can
 * NEVER be declared on a key (design spec §2.5) -- see
 * {@see \Glueful\Extensions\Commerce\Marketplace\SellerApiKeyCapabilityCatalog},
 * the separate, dedicated machine-grantable catalog that intentionally does
 * NOT live on this class.
 *
 * `commerce.seller.webhooks.manage` (design spec §2.10, MV5c-2 Task 2) gates
 * the seller self-service webhook-endpoint management surface (register/
 * update/rotate-secret/disable/enable/delete/replay) -- granted to
 * owner/admin ONLY, the identical shape to `apikeys.manage` above:
 * registering an outbound destination that will receive signed event data
 * (and rotating the secret that authenticates it) is a credential/endpoint-
 * administration concern, never an operational or read-only-analytics one.
 * Like `apikeys.manage`, this capability can NEVER be declared on a seller
 * API key (design spec §2.10) -- {@see \Glueful\Extensions\Commerce\Marketplace\SellerApiKeyCapabilityCatalog}
 * excludes it by the same omission-from-the-grantable-set mechanism.
 */
final class FixedSellerRoleAuthority implements SellerRoleAuthority
{
    private const CATALOG_READ = 'commerce.seller.catalog.read';
    private const CATALOG_WRITE = 'commerce.seller.catalog.write';
    private const INVENTORY_READ = 'commerce.seller.inventory.read';
    private const INVENTORY_WRITE = 'commerce.seller.inventory.write';
    private const MEMBERS_MANAGE = 'commerce.seller.members.manage';
    private const ORDERS_READ = 'commerce.seller.orders.read';
    private const ORDERS_FULFILL = 'commerce.seller.orders.fulfill';
    private const REPORTS_READ = 'commerce.seller.reports.read';
    private const PAYOUTS_READ = 'commerce.seller.payouts.read';
    public const APIKEYS_MANAGE = 'commerce.seller.apikeys.manage';
    public const WEBHOOKS_MANAGE = 'commerce.seller.webhooks.manage';

    /** @var array<string,list<string>> */
    private const CAPABILITY_MATRIX = [
        'seller_owner' => [
            self::CATALOG_READ,
            self::CATALOG_WRITE,
            self::INVENTORY_READ,
            self::INVENTORY_WRITE,
            self::MEMBERS_MANAGE,
            self::ORDERS_READ,
            self::ORDERS_FULFILL,
            self::REPORTS_READ,
            self::PAYOUTS_READ,
            self::APIKEYS_MANAGE,
            self::WEBHOOKS_MANAGE,
        ],
        'seller_admin' => [
            self::CATALOG_READ,
            self::CATALOG_WRITE,
            self::INVENTORY_READ,
            self::INVENTORY_WRITE,
            self::ORDERS_READ,
            self::ORDERS_FULFILL,
            self::REPORTS_READ,
            self::PAYOUTS_READ,
            self::APIKEYS_MANAGE,
            self::WEBHOOKS_MANAGE,
        ],
        'seller_staff' => [
            self::CATALOG_READ,
            self::INVENTORY_READ,
            self::INVENTORY_WRITE,
            self::ORDERS_READ,
            self::ORDERS_FULFILL,
        ],
        'seller_analyst' => [
            self::CATALOG_READ,
            self::INVENTORY_READ,
            self::ORDERS_READ,
            self::REPORTS_READ,
            self::PAYOUTS_READ,
        ],
    ];

    public function roles(): array
    {
        return array_keys(self::CAPABILITY_MATRIX);
    }

    public function capabilitiesFor(string $role): array
    {
        return self::CAPABILITY_MATRIX[$role] ?? [];
    }

    public function allows(string $role, string $capability): bool
    {
        return in_array($capability, $this->capabilitiesFor($role), true);
    }
}
