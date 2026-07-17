<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace\Contracts;

/**
 * The role/capability seam (design spec §2.6, Task 2 brief): the FIXED, shipped
 * vocabulary is `FixedSellerRoleAuthority` today, but nothing outside an
 * implementation of this interface may ever hard-code a role name for a
 * capability decision -- a later MV slice may rebind this to a per-seller
 * custom-role authority without touching any caller.
 *
 * Every method is total over its input: an unknown role name is never an
 * error here -- {@see self::capabilitiesFor()} returns an empty list and
 * {@see self::allows()} returns false, mirroring an unknown capability name.
 * Callers that need to REJECT an unknown role (e.g. membership grant/change)
 * do so explicitly by checking membership in {@see self::roles()}.
 */
interface SellerRoleAuthority
{
    /** @return list<string> every role name this authority recognizes */
    public function roles(): array;

    /**
     * @return list<string> capabilities granted to $role; empty for an
     *     unrecognized role
     */
    public function capabilitiesFor(string $role): array;

    /**
     * True only when $role is recognized AND $capability is in its granted
     * set. False for an unrecognized role or an unrecognized capability --
     * never throws.
     */
    public function allows(string $role, string $capability): bool;
}
