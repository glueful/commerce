<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Shipping;

use Glueful\Extensions\Commerce\Shipping\ZoneMatcher;
use PHPUnit\Framework\TestCase;

final class ZoneMatcherTest extends TestCase
{
    // -----------------------------------------------------------------
    // Zero locations = everywhere
    // -----------------------------------------------------------------

    public function testEmptyLocationsMatchAnyAddress(): void
    {
        self::assertTrue(ZoneMatcher::matches([], ['country' => 'US']));
        self::assertTrue(ZoneMatcher::matches([], []));
    }

    // -----------------------------------------------------------------
    // Country
    // -----------------------------------------------------------------

    public function testCountryMatch(): void
    {
        $locations = [['kind' => 'country', 'value' => 'US']];

        self::assertTrue(ZoneMatcher::matches($locations, ['country' => 'US']));
    }

    public function testCountryMismatch(): void
    {
        $locations = [['kind' => 'country', 'value' => 'US']];

        self::assertFalse(ZoneMatcher::matches($locations, ['country' => 'CA']));
    }

    public function testCountryMatchIsCaseInsensitiveAndTrimmed(): void
    {
        $locations = [['kind' => 'country', 'value' => 'US']];

        self::assertTrue(ZoneMatcher::matches($locations, ['country' => ' us ']));
    }

    public function testMissingAddressCountryFailsAgainstNonEmptyLocations(): void
    {
        $locations = [['kind' => 'country', 'value' => 'US']];

        self::assertFalse(ZoneMatcher::matches($locations, []));
    }

    // -----------------------------------------------------------------
    // State (country+region alternative to country)
    // -----------------------------------------------------------------

    public function testStateMatchComposesAddressCountryAndState(): void
    {
        $locations = [['kind' => 'state', 'value' => 'US:CA']];

        self::assertTrue(ZoneMatcher::matches($locations, ['country' => 'US', 'state' => 'CA']));
    }

    public function testStateMismatchDifferentRegion(): void
    {
        $locations = [['kind' => 'state', 'value' => 'US:CA']];

        self::assertFalse(ZoneMatcher::matches($locations, ['country' => 'US', 'state' => 'NY']));
    }

    public function testStateMismatchWhenCountryPrefixDiffers(): void
    {
        // Same bare region text but a different address country composes a
        // different composite -- "CA:CA" != "US:CA".
        $locations = [['kind' => 'state', 'value' => 'US:CA']];

        self::assertFalse(ZoneMatcher::matches($locations, ['country' => 'CA', 'state' => 'CA']));
    }

    public function testCountryAndStateAreAlternativesEitherSuffices(): void
    {
        // Zone only carries a state row for a DIFFERENT country's region, but a
        // sibling country row for the address's own country is enough.
        $locations = [
            ['kind' => 'country', 'value' => 'US'],
            ['kind' => 'state', 'value' => 'CA:ON'],
        ];

        self::assertTrue(ZoneMatcher::matches($locations, ['country' => 'US']));
    }

    public function testMissingAddressStateDoesNotMatchStateOnlyZone(): void
    {
        $locations = [['kind' => 'state', 'value' => 'US:CA']];

        self::assertFalse(ZoneMatcher::matches($locations, ['country' => 'US']));
    }

    // -----------------------------------------------------------------
    // Postcode narrowing (conjunctive -- spec §3 pinned rule)
    // -----------------------------------------------------------------

    public function testPostcodeExactMatchWithSiblingCountry(): void
    {
        $locations = [
            ['kind' => 'country', 'value' => 'US'],
            ['kind' => 'postcode_pattern', 'value' => '90210'],
        ];

        self::assertTrue(ZoneMatcher::matches($locations, ['country' => 'US', 'postcode' => '90210']));
    }

    public function testPostcodeWildcardMatchWithSiblingCountry(): void
    {
        $locations = [
            ['kind' => 'country', 'value' => 'US'],
            ['kind' => 'postcode_pattern', 'value' => '90*'],
        ];

        self::assertTrue(ZoneMatcher::matches($locations, ['country' => 'US', 'postcode' => '90210']));
    }

    /**
     * THE pinned conjunctive test (spec §3): a zone containing `country=US` and
     * `postcode_pattern=90*` does NOT match `US/10001` -- the country row
     * scopes the pattern, it does not independently bypass it.
     */
    public function testConjunctivePostcodeNarrowingRejectsNonMatchingPostcodeDespiteCountryMatch(): void
    {
        $locations = [
            ['kind' => 'country', 'value' => 'US'],
            ['kind' => 'postcode_pattern', 'value' => '90*'],
        ];

        self::assertFalse(ZoneMatcher::matches($locations, ['country' => 'US', 'postcode' => '10001']));
    }

    public function testPostcodeNarrowingRequiresTheSiblingCountrySpecificallyNotJustAnyGeoMatch(): void
    {
        // geoMatch is TRUE via the state row (US:NY), but the zone's country
        // rows are scoped to CA, which does not match the address's US
        // country -- postcode narrowing must fail rather than being bypassed
        // by the state match.
        $locations = [
            ['kind' => 'country', 'value' => 'CA'],
            ['kind' => 'state', 'value' => 'US:NY'],
            ['kind' => 'postcode_pattern', 'value' => '90*'],
        ];

        self::assertFalse(ZoneMatcher::matches(
            $locations,
            ['country' => 'US', 'state' => 'NY', 'postcode' => '90210']
        ));
    }

    public function testMissingAddressPostcodeFailsEvenWhenCountryMatches(): void
    {
        $locations = [
            ['kind' => 'country', 'value' => 'US'],
            ['kind' => 'postcode_pattern', 'value' => '90*'],
        ];

        self::assertFalse(ZoneMatcher::matches($locations, ['country' => 'US']));
    }

    public function testPostcodePatternMatchingIsCaseInsensitiveAndTrimmed(): void
    {
        $locations = [
            ['kind' => 'country', 'value' => 'US'],
            ['kind' => 'postcode_pattern', 'value' => '90*'],
        ];

        self::assertTrue(ZoneMatcher::matches($locations, ['country' => 'us', 'postcode' => ' 90210 ']));
    }

    public function testMultiplePostcodePatternsAnyOneSufficing(): void
    {
        $locations = [
            ['kind' => 'country', 'value' => 'US'],
            ['kind' => 'postcode_pattern', 'value' => '10001'],
            ['kind' => 'postcode_pattern', 'value' => '90*'],
        ];

        self::assertTrue(ZoneMatcher::matches($locations, ['country' => 'US', 'postcode' => '10001']));
        self::assertTrue(ZoneMatcher::matches($locations, ['country' => 'US', 'postcode' => '90210']));
        self::assertFalse(ZoneMatcher::matches($locations, ['country' => 'US', 'postcode' => '20001']));
    }
}
