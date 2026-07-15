<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Shipping;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Validation\ValidationException;

/**
 * Shipping zone CRUD, zone-location set-list replace, and per-zone shipping
 * method CRUD (design spec §2, §3, §6).
 *
 * Claim discipline: `commerce_shipping_zones` carries the only revision column in
 * this subtree. Every zone-scoped mutation -- rename/reposition, the locations
 * set-list replace, method create/update/delete, and zone delete -- claims the
 * ZONE's revision (affected-row-checked, {@see ShippingZoneRepository::claimRevision()})
 * even when the URL names a method uuid, because neither
 * `commerce_shipping_zone_locations` nor `commerce_shipping_methods` carries a
 * revision of its own: the owning zone's revision is the single serialization
 * point for everything beneath it, mirroring AttributeService's attribute->value
 * claim discipline. A failed claim on the zone (URL's primary resource, or the
 * zone resolved from a method uuid) is a non-revealing 404. After every claim
 * succeeds, the mutation re-reads fresh state before deciding anything (the
 * house post-claim-re-read discipline) -- never trusts a pre-claim snapshot for a
 * business decision.
 *
 * Method mutations resolve their owning zone via a tenant-agnostic peek on
 * `findMethodByUuid()` purely to discover which zone to claim; the claim itself
 * is what actually proves tenant membership, and the transaction re-reads the
 * method row again immediately after the claim succeeds (same two-step
 * discipline as AttributeService::updateValue()/deleteValue()).
 *
 * Zone delete claims the zone, then -- inside the same transaction -- cascades:
 * deletes every method, deletes every location, then deletes the zone row itself.
 *
 * Location set-list (`setLocations`) validates the POSTED set as a whole before
 * touching the database (kind/value grammar per spec §2, and the
 * postcode-needs-a-sibling-country rule), then -- once the zone is claimed --
 * replaces the location set wholesale (delete all, insert the normalized list).
 * A duplicate (kind, value) pair within the posted set is deduped rather than
 * rejected; the DB's own (zone_uuid, kind, value) unique index is a defensive
 * backstop, not the primary guard.
 *
 * Method config is validated per kind at write time (non-negative integers).
 * `per_class_table` config may reference shipping-class slugs that don't exist
 * yet (spec: "classes may be created later") -- an unknown slug is WARN-but-allow,
 * not rejected: the mutation succeeds and the returned method payload carries a
 * `warnings` list naming the unknown slugs.
 */
final class ShippingZoneService
{
    private const METHOD_KINDS = ['flat', 'free_over', 'per_class_table'];
    private const LOCATION_KINDS = ['country', 'state', 'postcode_pattern'];

    public function __construct(
        private ShippingZoneRepository $zones,
        private ShippingClassRepository $classes,
        private CurrentTenantResolver $tenants,
    ) {
    }

    /**
     * Zone list projection (spec §6): each zone carries its locations, its methods
     * (decoded config, ordered position ASC/uuid ASC), and a derived
     * `shadows_later_zones` -- true when the zone has zero locations (an
     * "everywhere" zone, spec §3) AND at least one other zone follows it in the
     * same (position, uuid) evaluation order.
     *
     * @return list<array<string,mixed>>
     */
    public function list(ApplicationContext $c): array
    {
        $tenant = $this->tenants->tenantUuid($c);
        $zones = $this->zones->all($c, $tenant);
        $last = count($zones) - 1;

        $result = [];
        foreach (array_values($zones) as $index => $zone) {
            $locations = $this->zones->locationsForZone($c, (string) $zone['uuid']);
            $methods = array_map(
                fn (array $method): array => $this->decodeMethod($method),
                $this->zones->methodsForZone($c, (string) $zone['uuid'])
            );

            $zone['locations'] = $locations;
            $zone['methods'] = $methods;
            $zone['shadows_later_zones'] = $locations === [] && $index < $last;

            $result[] = $zone;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function create(ApplicationContext $c, array $input): array
    {
        $tenant = $this->tenants->tenantUuid($c);
        $name = $this->requiredString($input, 'name');

        if ($this->zones->findByName($c, $tenant, $name) !== null) {
            throw ValidationException::forField('name', 'Name already in use.');
        }

        $uuid = Utils::generateNanoID();
        $this->zones->insert($c, [
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'name' => $name,
            'position' => isset($input['position']) ? (int) $input['position'] : 0,
        ]);

        $zone = $this->zones->findByUuid($c, $tenant, $uuid);
        if ($zone === null) {
            throw new \RuntimeException('Created zone could not be reloaded.');
        }

        return $zone;
    }

    /**
     * @param array<string,mixed> $changes name/position -- only present keys are applied
     * @return array<string,mixed>
     */
    public function update(ApplicationContext $c, string $uuid, array $changes): array
    {
        $tenant = $this->tenants->tenantUuid($c);

        return db($c)->transaction(function () use ($c, $tenant, $uuid, $changes): array {
            if (!$this->zones->claimRevision($c, $tenant, $uuid)) {
                throw new NotFoundException('Resource not found.');
            }

            $current = $this->zones->findByUuid($c, $tenant, $uuid);
            if ($current === null) {
                throw new NotFoundException('Resource not found.');
            }

            $set = [];
            if (array_key_exists('name', $changes) && $changes['name'] !== null) {
                $name = trim((string) $changes['name']);
                $existing = $this->zones->findByName($c, $tenant, $name);
                if ($existing !== null && (string) $existing['uuid'] !== $uuid) {
                    throw ValidationException::forField('name', 'Name already in use.');
                }
                $set['name'] = $name;
            }
            if (array_key_exists('position', $changes) && $changes['position'] !== null) {
                $set['position'] = (int) $changes['position'];
            }

            if ($set !== []) {
                $this->zones->update($c, $tenant, $uuid, $set);
            }

            $zone = $this->zones->findByUuid($c, $tenant, $uuid);
            if ($zone === null) {
                throw new \RuntimeException('Updated zone could not be reloaded.');
            }

            return $zone;
        });
    }

    /**
     * Claims the zone, then -- inside the same transaction -- cascades: deletes
     * every method, deletes every location, then deletes the zone row itself. A
     * concurrent method-create or locations-replace cannot land on this zone once
     * we hold its claim: its own claim on this same row either blocks until we
     * commit (then fails, 0 rows -- a non-revealing 404) or, if it committed
     * first, its new rows are simply swept up by our cascade.
     */
    public function delete(ApplicationContext $c, string $uuid): void
    {
        $tenant = $this->tenants->tenantUuid($c);

        db($c)->transaction(function () use ($c, $tenant, $uuid): void {
            if (!$this->zones->claimRevision($c, $tenant, $uuid)) {
                throw new NotFoundException('Resource not found.');
            }
            if ($this->zones->findByUuid($c, $tenant, $uuid) === null) {
                throw new NotFoundException('Resource not found.');
            }

            $this->zones->deleteMethodsForZone($c, $uuid);
            $this->zones->deleteLocationsForZone($c, $uuid);
            $this->zones->delete($c, $tenant, $uuid);
        });
    }

    /**
     * Idempotent set-list replace: normalizes and validates the POSTED set as a
     * whole first (kind/value grammar, postcode-needs-country) -- pure input
     * validation, no DB state needed -- then claims the zone and replaces the
     * location set wholesale (delete all, insert the normalized list) inside one
     * transaction. Submitting the same set twice is a no-op in effect (the
     * resulting row set is identical); an empty list is valid and meaningful (the
     * zone becomes "everywhere", spec §3).
     *
     * @param list<array<string,mixed>> $locations raw posted rows
     * @return list<array<string,mixed>>
     */
    public function setLocations(ApplicationContext $c, string $zoneUuid, array $locations): array
    {
        $tenant = $this->tenants->tenantUuid($c);
        $normalized = $this->normalizeLocations($locations);

        return db($c)->transaction(function () use ($c, $tenant, $zoneUuid, $normalized): array {
            if (!$this->zones->claimRevision($c, $tenant, $zoneUuid)) {
                throw new NotFoundException('Resource not found.');
            }
            if ($this->zones->findByUuid($c, $tenant, $zoneUuid) === null) {
                throw new NotFoundException('Resource not found.');
            }

            $this->zones->deleteLocationsForZone($c, $zoneUuid);
            foreach ($normalized as $location) {
                $this->zones->insertLocation($c, [
                    'zone_uuid' => $zoneUuid,
                    'kind' => $location['kind'],
                    'value' => $location['value'],
                ]);
            }

            return $this->zones->locationsForZone($c, $zoneUuid);
        });
    }

    /** @return list<array<string,mixed>> */
    public function listMethods(ApplicationContext $c, string $zoneUuid): array
    {
        $tenant = $this->tenants->tenantUuid($c);
        if ($this->zones->findByUuid($c, $tenant, $zoneUuid) === null) {
            throw new NotFoundException('Resource not found.');
        }

        return array_map(
            fn (array $method): array => $this->decodeMethod($method),
            $this->zones->methodsForZone($c, $zoneUuid)
        );
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createMethod(ApplicationContext $c, string $zoneUuid, array $input): array
    {
        $tenant = $this->tenants->tenantUuid($c);
        $kind = $this->requiredString($input, 'kind');
        if (!in_array($kind, self::METHOD_KINDS, true)) {
            throw ValidationException::forField(
                'kind',
                'kind must be one of ' . implode(', ', self::METHOD_KINDS) . '.'
            );
        }
        $label = $this->requiredString($input, 'label');
        $rawConfig = $input['config'] ?? null;
        if (!is_array($rawConfig)) {
            throw ValidationException::forField('config', 'config is required and must be an object.');
        }

        return db($c)->transaction(function () use ($c, $tenant, $zoneUuid, $kind, $label, $rawConfig, $input): array {
            if (!$this->zones->claimRevision($c, $tenant, $zoneUuid)) {
                throw new NotFoundException('Resource not found.');
            }
            if ($this->zones->findByUuid($c, $tenant, $zoneUuid) === null) {
                throw new NotFoundException('Resource not found.');
            }

            [$config, $warnings] = $this->validateMethodConfig($c, $tenant, $kind, $rawConfig);

            $uuid = Utils::generateNanoID();
            $this->zones->insertMethod($c, [
                'uuid' => $uuid,
                'zone_uuid' => $zoneUuid,
                'kind' => $kind,
                'label' => $label,
                'config' => json_encode($config, JSON_THROW_ON_ERROR),
                'position' => isset($input['position'])
                    ? (int) $input['position']
                    : count($this->zones->methodsForZone($c, $zoneUuid)),
                'enabled' => isset($input['enabled']) ? (bool) $input['enabled'] : true,
            ]);

            $method = $this->zones->findMethodByUuid($c, $uuid);
            if ($method === null) {
                throw new \RuntimeException('Created method could not be reloaded.');
            }

            return $this->decodeMethod($method, $warnings);
        });
    }

    /**
     * @param array<string,mixed> $changes label/config/position/enabled -- only present
     *   keys are applied; `kind` is immutable and cannot be changed once created
     * @return array<string,mixed>
     */
    public function updateMethod(ApplicationContext $c, string $methodUuid, array $changes): array
    {
        $tenant = $this->tenants->tenantUuid($c);

        // Tenant-agnostic snapshot read purely to discover which zone to claim --
        // the URL carries no zone uuid. Never trusted by itself; the transaction
        // re-reads this row again immediately after the claim succeeds.
        $peek = $this->zones->findMethodByUuid($c, $methodUuid);
        if ($peek === null) {
            throw new NotFoundException('Resource not found.');
        }
        $zoneUuid = (string) $peek['zone_uuid'];

        return db($c)->transaction(function () use ($c, $tenant, $zoneUuid, $methodUuid, $changes): array {
            if (!$this->zones->claimRevision($c, $tenant, $zoneUuid)) {
                throw new NotFoundException('Resource not found.');
            }

            $current = $this->zones->findMethodByUuid($c, $methodUuid);
            if ($current === null || (string) $current['zone_uuid'] !== $zoneUuid) {
                throw new NotFoundException('Resource not found.');
            }

            $set = [];
            $warnings = [];

            if (array_key_exists('label', $changes) && $changes['label'] !== null) {
                $set['label'] = (string) $changes['label'];
            }
            if (array_key_exists('position', $changes) && $changes['position'] !== null) {
                $set['position'] = (int) $changes['position'];
            }
            if (array_key_exists('enabled', $changes) && $changes['enabled'] !== null) {
                $set['enabled'] = (bool) $changes['enabled'];
            }
            if (array_key_exists('config', $changes) && $changes['config'] !== null) {
                if (!is_array($changes['config'])) {
                    throw ValidationException::forField('config', 'config must be an object.');
                }
                [$config, $warnings] = $this->validateMethodConfig(
                    $c,
                    $tenant,
                    (string) $current['kind'],
                    $changes['config']
                );
                $set['config'] = json_encode($config, JSON_THROW_ON_ERROR);
            }

            if ($set !== []) {
                $this->zones->updateMethod($c, $methodUuid, $set);
            }

            $method = $this->zones->findMethodByUuid($c, $methodUuid);
            if ($method === null) {
                throw new \RuntimeException('Updated method could not be reloaded.');
            }

            return $this->decodeMethod($method, $warnings);
        });
    }

    public function deleteMethod(ApplicationContext $c, string $methodUuid): void
    {
        $tenant = $this->tenants->tenantUuid($c);

        $peek = $this->zones->findMethodByUuid($c, $methodUuid);
        if ($peek === null) {
            throw new NotFoundException('Resource not found.');
        }
        $zoneUuid = (string) $peek['zone_uuid'];

        db($c)->transaction(function () use ($c, $tenant, $zoneUuid, $methodUuid): void {
            if (!$this->zones->claimRevision($c, $tenant, $zoneUuid)) {
                throw new NotFoundException('Resource not found.');
            }

            $current = $this->zones->findMethodByUuid($c, $methodUuid);
            if ($current === null || (string) $current['zone_uuid'] !== $zoneUuid) {
                throw new NotFoundException('Resource not found.');
            }

            $this->zones->deleteMethod($c, $methodUuid);
        });
    }

    /**
     * Validates and normalizes the POSTED location set as a whole (spec §2):
     * `kind` must be one of country/state/postcode_pattern; `value` is
     * trimmed+uppercased then format-checked per kind; a set containing any
     * `postcode_pattern` row must also contain at least one `country` row (a
     * postcode-only zone is rejected). Identical (kind, value) pairs within the
     * posted set are deduped rather than rejected.
     *
     * @param mixed $raw
     * @return list<array{kind:string,value:string}>
     */
    private function normalizeLocations(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw ValidationException::forField('locations', 'locations must be an array.');
        }

        $result = [];
        $seen = [];
        $hasCountry = false;
        $hasPostcode = false;

        foreach ($raw as $index => $row) {
            if (!is_array($row)) {
                throw ValidationException::forField("locations.{$index}", 'Each location must be an object.');
            }

            $kind = is_string($row['kind'] ?? null) ? trim((string) $row['kind']) : '';
            if (!in_array($kind, self::LOCATION_KINDS, true)) {
                throw ValidationException::forField(
                    "locations.{$index}.kind",
                    'kind must be one of ' . implode(', ', self::LOCATION_KINDS) . '.'
                );
            }

            $value = is_string($row['value'] ?? null) || is_int($row['value'] ?? null)
                ? trim(strtoupper((string) $row['value']))
                : '';

            if ($kind === 'country') {
                if (!preg_match('/^[A-Z]{2}$/', $value)) {
                    throw ValidationException::forField(
                        "locations.{$index}.value",
                        'country value must be an ISO-3166 alpha-2 code.'
                    );
                }
                $hasCountry = true;
            } elseif ($kind === 'state') {
                if (!preg_match('/^[A-Z]{2}:[A-Z0-9]+$/', $value)) {
                    throw ValidationException::forField(
                        "locations.{$index}.value",
                        'state value must be COUNTRY:REGION with a valid ISO-3166 alpha-2 country prefix.'
                    );
                }
            } else { // postcode_pattern
                if (!preg_match('/^[A-Z0-9]+\*?$/', $value)) {
                    throw ValidationException::forField(
                        "locations.{$index}.value",
                        'postcode_pattern must be an exact value or end with a single trailing wildcard (*).'
                    );
                }
                $hasPostcode = true;
            }

            $key = $kind . '|' . $value;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = ['kind' => $kind, 'value' => $value];
        }

        if ($hasPostcode && !$hasCountry) {
            throw ValidationException::forField(
                'locations',
                'A zone with postcode_pattern locations must also include at least one country location.'
            );
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $raw
     * @return array{0: array<string,mixed>, 1: list<string>} [normalized config, warnings]
     */
    private function validateMethodConfig(ApplicationContext $c, string $tenant, string $kind, array $raw): array
    {
        return match ($kind) {
            'flat' => [['amount' => $this->nonNegativeInt($raw, 'amount', 'config.amount')], []],
            'free_over' => [
                [
                    'amount' => $this->nonNegativeInt($raw, 'amount', 'config.amount'),
                    'free_over' => $this->nonNegativeInt($raw, 'free_over', 'config.free_over'),
                ],
                [],
            ],
            'per_class_table' => $this->validatePerClassTableConfig($c, $tenant, $raw),
            default => throw new \LogicException("Unhandled shipping method kind: {$kind}"),
        };
    }

    /**
     * @param array<string,mixed> $raw
     * @return array{0: array<string,mixed>, 1: list<string>}
     */
    private function validatePerClassTableConfig(ApplicationContext $c, string $tenant, array $raw): array
    {
        $default = $this->nonNegativeInt($raw, 'default_amount', 'config.default_amount');

        $rawClasses = $raw['classes'] ?? [];
        if (!is_array($rawClasses)) {
            throw ValidationException::forField(
                'config.classes',
                'config.classes must be an object mapping slug to amount.'
            );
        }

        $classes = [];
        foreach ($rawClasses as $slug => $amount) {
            $slug = (string) $slug;
            if (trim($slug) === '') {
                throw ValidationException::forField('config.classes', 'config.classes keys must be non-empty slugs.');
            }
            if (!is_int($amount) || $amount < 0) {
                throw ValidationException::forField(
                    "config.classes.{$slug}",
                    'config.classes amounts must be non-negative integers.'
                );
            }
            $classes[$slug] = $amount;
        }

        $warnings = [];
        if ($classes !== []) {
            $known = $this->classes->existingClassSlugs($c, $tenant, array_keys($classes));
            foreach (array_diff(array_keys($classes), $known) as $slug) {
                $warnings[] = "Unknown shipping class slug: {$slug}";
            }
        }

        return [['default_amount' => $default, 'classes' => $classes], $warnings];
    }

    /** @param array<string,mixed> $raw */
    private function nonNegativeInt(array $raw, string $key, string $field): int
    {
        $value = $raw[$key] ?? null;
        if (!is_int($value) || $value < 0) {
            throw ValidationException::forField($field, "{$field} must be a non-negative integer.");
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $row
     * @param list<string> $warnings
     * @return array<string,mixed>
     */
    private function decodeMethod(array $row, array $warnings = []): array
    {
        $raw = $row['config'] ?? null;
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        $row['config'] = is_array($decoded) ? $decoded : [];
        $row['enabled'] = (bool) ($row['enabled'] ?? true);
        $row['warnings'] = $warnings;

        return $row;
    }

    /** @param array<string,mixed> $input */
    private function requiredString(array $input, string $field): string
    {
        $value = trim((string) ($input[$field] ?? ''));
        if ($value === '') {
            throw ValidationException::forField($field, ucfirst($field) . ' is required.');
        }

        return $value;
    }
}
