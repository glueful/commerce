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
 *
 * Refunds/reports/payouts capabilities arrive with their own slices --
 * deliberately absent here. No per-seller overrides or custom roles in v1.
 * `commerce.seller.orders.{read,fulfill}` (design spec §6.1/§2.6, MV2 Task 8)
 * gate the seller order surfaces -- `fulfill` deliberately mirrors
 * `inventory.write`'s owner/admin/staff-not-analyst shape, since fulfillment
 * is an operational write an analyst role must never perform.
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
        ],
        'seller_admin' => [
            self::CATALOG_READ,
            self::CATALOG_WRITE,
            self::INVENTORY_READ,
            self::INVENTORY_WRITE,
            self::ORDERS_READ,
            self::ORDERS_FULFILL,
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
