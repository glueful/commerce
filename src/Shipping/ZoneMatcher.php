<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Shipping;

/**
 * Pure zone/address matching logic (design spec §3). Stateless -- no DB access,
 * no tenant awareness; {@see DbShippingRateProvider} supplies a zone's already
 * tenant-scoped location rows and the caller's address.
 *
 * Address shape (the loose `$shippingAddress` array checkout already accepts):
 * `country` (ISO-3166 alpha-2, case-insensitive), optional `state` (the BARE
 * region, e.g. `'CA'` -- composed with the address's own `country` into the
 * `COUNTRY:REGION` form zone `state` locations store, e.g. `'US:CA'`), and
 * optional `postcode`. This is the same convention the Layer 4 tax rate matcher
 * (Task 5) matches against.
 *
 * Matching rules (pinned):
 * - Zero locations = "everywhere" -- matches any address.
 * - Otherwise the address must match at least one geographic location: a
 *   `country` row equal to the address country, OR a `state` row equal to the
 *   address's `COUNTRY:REGION` composite. Country and state are alternatives
 *   within that geographic group (either is sufficient).
 * - If the zone carries ANY `postcode_pattern` rows, they NARROW the match:
 *   the address must additionally match one of those patterns (exact, or a
 *   single trailing wildcard), AND at least one sibling `country` row must
 *   equal the address country specifically -- a `state` match alone does not
 *   satisfy this because the postcode pattern is scoped to a country, not a
 *   region. This is why a zone with `country=US` + `postcode_pattern=90*`
 *   does NOT match `US/10001`: the country row matches, but the postcode
 *   pattern does not, and postcode presence makes that pattern mandatory
 *   rather than an independent OR-match.
 */
final class ZoneMatcher
{
    /**
     * @param list<array<string,mixed>> $locations rows from
     *   {@see ShippingZoneRepository::locationsForZone()} (kind, value)
     * @param array<string,mixed> $address
     */
    public static function matches(array $locations, array $address): bool
    {
        if ($locations === []) {
            return true;
        }

        $countries = [];
        $states = [];
        $postcodePatterns = [];
        foreach ($locations as $location) {
            $value = (string) ($location['value'] ?? '');
            match ((string) ($location['kind'] ?? '')) {
                'country' => $countries[] = $value,
                'state' => $states[] = $value,
                'postcode_pattern' => $postcodePatterns[] = $value,
                default => null,
            };
        }

        $country = self::addressCountry($address);
        $stateComposite = self::addressStateComposite($address, $country);

        $countryMatches = $country !== '' && in_array($country, $countries, true);
        $stateMatches = $stateComposite !== null && in_array($stateComposite, $states, true);

        if (!$countryMatches && !$stateMatches) {
            return false;
        }

        if ($postcodePatterns !== []) {
            // Postcode rows narrow the match: they require their OWN sibling
            // country to match (not merely "some geo match happened"), and the
            // address postcode must satisfy at least one pattern.
            if (!$countryMatches) {
                return false;
            }

            $postcode = self::addressPostcode($address);
            if ($postcode === null || !self::matchesAnyPattern($postcode, $postcodePatterns)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $patterns */
    private static function matchesAnyPattern(string $postcode, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (self::matchesPattern($postcode, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private static function matchesPattern(string $postcode, string $pattern): bool
    {
        if (str_ends_with($pattern, '*')) {
            return str_starts_with($postcode, substr($pattern, 0, -1));
        }

        return $postcode === $pattern;
    }

    /** @param array<string,mixed> $address */
    private static function addressCountry(array $address): string
    {
        $raw = $address['country'] ?? null;
        if (!is_string($raw) && !is_int($raw)) {
            return '';
        }

        return strtoupper(trim((string) $raw));
    }

    /** @param array<string,mixed> $address */
    private static function addressStateComposite(array $address, string $country): ?string
    {
        $raw = $address['state'] ?? null;
        if (!is_string($raw) && !is_int($raw)) {
            return null;
        }

        $region = strtoupper(trim((string) $raw));
        if ($region === '' || $country === '') {
            return null;
        }

        return $country . ':' . $region;
    }

    /** @param array<string,mixed> $address */
    private static function addressPostcode(array $address): ?string
    {
        $raw = $address['postcode'] ?? null;
        if (!is_string($raw) && !is_int($raw)) {
            return null;
        }

        $postcode = strtoupper(trim((string) $raw));

        return $postcode === '' ? null : $postcode;
    }
}
