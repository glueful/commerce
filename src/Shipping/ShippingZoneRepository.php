<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Shipping;

use Glueful\Bootstrap\ApplicationContext;

final class ShippingZoneRepository
{
    // --- Zones ---

    /** @param array<string,mixed> $row */
    public function insert(ApplicationContext $context, array $row): void
    {
        db($context)->table('commerce_shipping_zones')->insert($row);
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(ApplicationContext $context, string $tenant, string $uuid): ?array
    {
        return db($context)->table('commerce_shipping_zones')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();
    }

    /** @return array<string,mixed>|null */
    public function findByName(ApplicationContext $context, string $tenant, string $name): ?array
    {
        return db($context)->table('commerce_shipping_zones')
            ->where('tenant_uuid', '=', $tenant)
            ->where('name', '=', $name)
            ->first();
    }

    /**
     * @return list<array<string,mixed>> every zone for the tenant, ordered position ASC,
     *   uuid ASC -- the same precedence order zone matching uses at quote time (spec §3),
     *   so index() and shadows_later_zones both walk the list in evaluation order.
     */
    public function all(ApplicationContext $context, string $tenant): array
    {
        return db($context)->table('commerce_shipping_zones')
            ->where('tenant_uuid', '=', $tenant)
            ->orderBy('position', 'ASC')
            ->orderBy('uuid', 'ASC')
            ->get();
    }

    /** @param array<string,mixed> $changes */
    public function update(ApplicationContext $context, string $tenant, string $uuid, array $changes): void
    {
        db($context)->table('commerce_shipping_zones')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->update($changes);
    }

    /**
     * Delegation existence check (spec §4): one index-covered query per quote
     * deciding DB-vs-config -- a tenant with ANY zone row is wholly on the
     * data-driven shipping path (no per-request mixing across sources, spec §3).
     */
    public function existsForTenant(ApplicationContext $context, string $tenant): bool
    {
        return db($context)->table('commerce_shipping_zones')
            ->where('tenant_uuid', '=', $tenant)
            ->count() > 0;
    }

    public function delete(ApplicationContext $context, string $tenant, string $uuid): void
    {
        db($context)->table('commerce_shipping_zones')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->delete();
    }

    /**
     * Affected-row-checked serialization primitive shared by every zone-scoped
     * mutation (rename/reposition, locations set-list, method create/update/delete,
     * zone delete): all of them claim the ZONE's revision, never a child row's,
     * because neither `commerce_shipping_zone_locations` nor `commerce_shipping_methods`
     * carries a revision of its own -- see ShippingZoneService's class docblock.
     * Returns false for an unknown or cross-tenant zone, which callers turn into a
     * non-revealing 404.
     */
    public function claimRevision(ApplicationContext $context, string $tenant, string $uuid): bool
    {
        $affected = db($context)->table('commerce_shipping_zones')->executeModification(
            <<<'SQL'
UPDATE commerce_shipping_zones SET revision = revision + 1 WHERE tenant_uuid = ? AND uuid = ?
SQL,
            [$tenant, $uuid]
        );

        return $affected === 1;
    }

    // --- Locations (children of zone; no tenant_uuid or revision of their own) ---

    /** @param array<string,mixed> $row */
    public function insertLocation(ApplicationContext $context, array $row): void
    {
        db($context)->table('commerce_shipping_zone_locations')->insert($row);
    }

    /** @return list<array<string,mixed>> every location for the zone, ordered kind then value */
    public function locationsForZone(ApplicationContext $context, string $zoneUuid): array
    {
        return db($context)->table('commerce_shipping_zone_locations')
            ->where('zone_uuid', '=', $zoneUuid)
            ->orderBy('kind', 'ASC')
            ->orderBy('value', 'ASC')
            ->get();
    }

    /** Wipes every location for the zone (set-list full replace). */
    public function deleteLocationsForZone(ApplicationContext $context, string $zoneUuid): void
    {
        db($context)->table('commerce_shipping_zone_locations')
            ->where('zone_uuid', '=', $zoneUuid)
            ->delete();
    }

    // --- Methods (children of zone; no tenant_uuid or revision of their own) ---

    /** @param array<string,mixed> $row */
    public function insertMethod(ApplicationContext $context, array $row): void
    {
        db($context)->table('commerce_shipping_methods')->insert($row);
    }

    /**
     * Tenant-agnostic lookup by uuid -- `commerce_shipping_methods` carries no
     * `tenant_uuid` of its own; a method is only reachable through its owning zone.
     * Callers MUST verify the returned row's `zone_uuid` resolves in the caller's
     * tenant (via {@see self::claimRevision()} on that zone_uuid) before trusting
     * anything else about it.
     *
     * @return array<string,mixed>|null
     */
    public function findMethodByUuid(ApplicationContext $context, string $uuid): ?array
    {
        return db($context)->table('commerce_shipping_methods')
            ->where('uuid', '=', $uuid)
            ->first();
    }

    /**
     * @return list<array<string,mixed>> every method for the zone, ordered position ASC,
     *   uuid ASC -- the same order a matched zone returns its methods in at quote time.
     */
    public function methodsForZone(ApplicationContext $context, string $zoneUuid): array
    {
        return db($context)->table('commerce_shipping_methods')
            ->where('zone_uuid', '=', $zoneUuid)
            ->orderBy('position', 'ASC')
            ->orderBy('uuid', 'ASC')
            ->get();
    }

    /** @param array<string,mixed> $changes */
    public function updateMethod(ApplicationContext $context, string $uuid, array $changes): void
    {
        db($context)->table('commerce_shipping_methods')
            ->where('uuid', '=', $uuid)
            ->update($changes);
    }

    public function deleteMethod(ApplicationContext $context, string $uuid): void
    {
        db($context)->table('commerce_shipping_methods')
            ->where('uuid', '=', $uuid)
            ->delete();
    }

    /** Deletes every method belonging to a zone being deleted (delete cascade). */
    public function deleteMethodsForZone(ApplicationContext $context, string $zoneUuid): void
    {
        db($context)->table('commerce_shipping_methods')
            ->where('zone_uuid', '=', $zoneUuid)
            ->delete();
    }
}
