<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Shipping;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;

/**
 * Data-driven shipping quotes from `commerce_shipping_zones`/`_zone_locations`/
 * `_methods` (design spec §2/§3). Never consulted directly by the contract
 * default -- {@see DelegatingShippingRateProvider} routes to this only when the
 * tenant has at least one zone row.
 *
 * Zone selection: zones are walked in `position ASC, uuid ASC` order (the same
 * order {@see ShippingZoneRepository::all()} returns); the FIRST zone whose
 * locations match the address ({@see ZoneMatcher}) wins and NO later zone is
 * consulted, matching Woo's no-fall-through semantics. That zone's methods are
 * then filtered to `enabled = true`, already ordered `position ASC, uuid ASC`
 * by {@see ShippingZoneRepository::methodsForZone()}; if none are enabled the
 * result is `[]` -- the provider does not fall through to a later zone or to
 * config. No zone matches at all -- also `[]`.
 *
 * Digital-only carts return `[]` before any zone work (rule preserved from
 * {@see ConfigShippingRateProvider}). Quotes use the method row's `uuid` as
 * {@see ShippingQuote::$id} (spec pinned decision #1 -- stable across reorders).
 */
final class DbShippingRateProvider implements ShippingRateProvider
{
    public function __construct(
        private ShippingZoneRepository $zones,
        private CurrentTenantResolver $tenants,
    ) {
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @param array<string,mixed> $shippingAddress
     * @return list<ShippingQuote>
     */
    public function quote(ApplicationContext $context, array $lines, array $shippingAddress): array
    {
        if ($lines === [] || $this->isDigitalOnly($lines)) {
            return [];
        }

        $tenant = $this->tenants->tenantUuid($context);
        foreach ($this->zones->all($context, $tenant) as $zone) {
            $zoneUuid = (string) $zone['uuid'];
            $locations = $this->zones->locationsForZone($context, $zoneUuid);
            if (!ZoneMatcher::matches($locations, $shippingAddress)) {
                continue;
            }

            return $this->quoteMethods($context, $zoneUuid, $lines);
        }

        return [];
    }

    /**
     * Delegation predicate (spec §4): whether the CURRENT tenant has opted into
     * data-driven shipping at all. Kept here rather than duplicated onto the
     * delegator so the delegator's constructor stays to exactly its two
     * providers (Db + Config) -- only this class needs to know about
     * {@see ShippingZoneRepository}/{@see CurrentTenantResolver}.
     */
    public function hasZonesForCurrentTenant(ApplicationContext $context): bool
    {
        return $this->zones->existsForTenant($context, $this->tenants->tenantUuid($context));
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return list<ShippingQuote>
     */
    private function quoteMethods(ApplicationContext $context, string $zoneUuid, array $lines): array
    {
        $quotes = [];
        foreach ($this->zones->methodsForZone($context, $zoneUuid) as $method) {
            if (!(bool) ($method['enabled'] ?? false)) {
                continue;
            }

            $config = $this->decodeConfig($method['config'] ?? null);
            $amount = $this->priceMethod((string) $method['kind'], $config, $lines);

            $quotes[] = new ShippingQuote((string) $method['uuid'], (string) $method['label'], $amount);
        }

        return $quotes;
    }

    /** @return array<string,mixed> */
    private function decodeConfig(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,mixed> $config
     * @param list<array<string,mixed>> $lines
     */
    private function priceMethod(string $kind, array $config, array $lines): int
    {
        return match ($kind) {
            'flat' => (int) ($config['amount'] ?? 0),
            'free_over' => $this->priceFreeOver($config, $lines),
            'per_class_table' => $this->pricePerClassTable($config, $lines),
            default => 0,
        };
    }

    /**
     * @param array<string,mixed> $config
     * @param list<array<string,mixed>> $lines
     */
    private function priceFreeOver(array $config, array $lines): int
    {
        $amount = (int) ($config['amount'] ?? 0);
        $freeOver = (int) ($config['free_over'] ?? 0);

        // ALL-line subtotal (digital lines count too) -- deliberately mirrors
        // ConfigShippingRateProvider's mixed-cart behavior (spec §2) rather than
        // restricting the threshold to physical lines.
        return $this->subtotal($lines) >= $freeOver ? 0 : $amount;
    }

    /**
     * One contribution per DISTINCT shipping class among PHYSICAL lines, plus
     * ONE no-class default bucket counted once regardless of how many
     * class-less physical lines exist (spec §2, pinned decision #2).
     *
     * @param array<string,mixed> $config
     * @param list<array<string,mixed>> $lines
     */
    private function pricePerClassTable(array $config, array $lines): int
    {
        $default = (int) ($config['default_amount'] ?? 0);
        $classAmounts = is_array($config['classes'] ?? null) ? $config['classes'] : [];

        $distinctClasses = [];
        $hasNoClassLine = false;
        foreach ($lines as $line) {
            if (($line['type'] ?? 'physical') === 'digital') {
                continue;
            }

            $class = $line['shipping_class'] ?? null;
            if (!is_string($class) || $class === '') {
                $hasNoClassLine = true;
                continue;
            }

            $distinctClasses[$class] = true;
        }

        $total = $hasNoClassLine ? $default : 0;
        foreach (array_keys($distinctClasses) as $slug) {
            $total += (int) ($classAmounts[$slug] ?? $default);
        }

        return $total;
    }

    /** @param list<array<string,mixed>> $lines */
    private function subtotal(array $lines): int
    {
        $subtotal = 0;
        foreach ($lines as $line) {
            $subtotal += (int) ($line['unit_price'] ?? 0) * (int) ($line['quantity'] ?? 0);
        }

        return $subtotal;
    }

    /** @param list<array<string,mixed>> $lines */
    private function isDigitalOnly(array $lines): bool
    {
        foreach ($lines as $line) {
            if (($line['type'] ?? 'physical') !== 'digital') {
                return false;
            }
        }

        return true;
    }
}
