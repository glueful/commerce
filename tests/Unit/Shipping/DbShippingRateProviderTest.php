<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Shipping;

use Glueful\Extensions\Commerce\Shipping\DbShippingRateProvider;
use Glueful\Extensions\Commerce\Shipping\ShippingZoneRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

final class DbShippingRateProviderTest extends CommerceTestCase
{
    // -----------------------------------------------------------------
    // Digital-only / empty-cart short circuit
    // -----------------------------------------------------------------

    public function testDigitalOnlyCartReturnsEmptyBeforeAnyZoneWork(): void
    {
        // A zone that would otherwise match with an enabled method -- if the
        // digital-only short-circuit didn't fire first, this would return a
        // quote.
        $zone = $this->insertZone('Everywhere', 0);
        $this->insertMethod($zone, 'flat', 'Standard', ['amount' => 500]);

        $quotes = $this->provider()->quote(
            $this->context,
            [$this->line('digital', 900)],
            ['country' => 'US']
        );

        self::assertSame([], $quotes);
    }

    public function testEmptyLinesReturnsEmpty(): void
    {
        self::assertSame([], $this->provider()->quote($this->context, [], ['country' => 'US']));
    }

    // -----------------------------------------------------------------
    // Zone selection
    // -----------------------------------------------------------------

    public function testNoZoneRowsReturnsEmpty(): void
    {
        $quotes = $this->provider()->quote(
            $this->context,
            [$this->line('physical', 1000)],
            ['country' => 'US']
        );

        self::assertSame([], $quotes);
    }

    public function testZeroLocationZoneMatchesEverywhere(): void
    {
        $zone = $this->insertZone('Everywhere', 0);
        $this->insertMethod($zone, 'flat', 'Standard', ['amount' => 500]);

        $quotes = $this->provider()->quote(
            $this->context,
            [$this->line('physical', 1000)],
            ['country' => 'GH']
        );

        self::assertCount(1, $quotes);
        self::assertSame(500, $quotes[0]->amount);
    }

    public function testFirstMatchingZoneWinsByPositionAndLaterZoneNeverConsulted(): void
    {
        $us = $this->insertZone('United States', 0);
        $this->insertLocation($us, 'country', 'US');
        $this->insertMethod($us, 'flat', 'US Standard', ['amount' => 500]);

        $everywhere = $this->insertZone('Everywhere', 1);
        $this->insertMethod($everywhere, 'flat', 'Fallback', ['amount' => 999]);

        $quotes = $this->provider()->quote(
            $this->context,
            [$this->line('physical', 1000)],
            ['country' => 'US']
        );

        self::assertCount(1, $quotes);
        self::assertSame('US Standard', $quotes[0]->label);
        self::assertSame(500, $quotes[0]->amount);
    }

    public function testZonesWithEqualPositionBreakTiesByUuidAscending(): void
    {
        $second = $this->insertZone('Zone B', 0, 'zoneuuidbb02');
        $this->insertMethod($second, 'flat', 'From B', ['amount' => 700]);

        $first = $this->insertZone('Zone A', 0, 'zoneuuidaa01');
        $this->insertMethod($first, 'flat', 'From A', ['amount' => 300]);

        // Both zones are "everywhere" (no locations); uuid tiebreak must pick
        // 'zoneuuidaa01' first regardless of insertion order.
        $quotes = $this->provider()->quote(
            $this->context,
            [$this->line('physical', 1000)],
            ['country' => 'US']
        );

        self::assertCount(1, $quotes);
        self::assertSame('From A', $quotes[0]->label);
    }

    public function testNoMatchingZoneReturnsEmpty(): void
    {
        $zone = $this->insertZone('Canada Only', 0);
        $this->insertLocation($zone, 'country', 'CA');
        $this->insertMethod($zone, 'flat', 'CA Standard', ['amount' => 500]);

        $quotes = $this->provider()->quote(
            $this->context,
            [$this->line('physical', 1000)],
            ['country' => 'US']
        );

        self::assertSame([], $quotes);
    }

    public function testMatchedZoneWithNoEnabledMethodsReturnsEmptyWithoutFallThrough(): void
    {
        $matched = $this->insertZone('United States', 0);
        $this->insertLocation($matched, 'country', 'US');
        $this->insertMethod($matched, 'flat', 'Disabled', ['amount' => 500], enabled: false);

        // A later, also-matching zone with an enabled method must NOT be
        // consulted -- the first match already decided the outcome.
        $everywhere = $this->insertZone('Everywhere', 1);
        $this->insertMethod($everywhere, 'flat', 'Should not appear', ['amount' => 999]);

        $quotes = $this->provider()->quote(
            $this->context,
            [$this->line('physical', 1000)],
            ['country' => 'US']
        );

        self::assertSame([], $quotes);
    }

    public function testMatchedZoneWithZeroMethodsReturnsEmpty(): void
    {
        $zone = $this->insertZone('United States', 0);
        $this->insertLocation($zone, 'country', 'US');

        $quotes = $this->provider()->quote(
            $this->context,
            [$this->line('physical', 1000)],
            ['country' => 'US']
        );

        self::assertSame([], $quotes);
    }

    // -----------------------------------------------------------------
    // Method filtering / ordering / identity
    // -----------------------------------------------------------------

    public function testDisabledMethodsOmittedEnabledOnesReturned(): void
    {
        $zone = $this->insertZone('Domestic', 0);
        $this->insertMethod($zone, 'flat', 'Disabled', ['amount' => 100], enabled: false);
        $this->insertMethod($zone, 'flat', 'Enabled', ['amount' => 500], enabled: true);

        $quotes = $this->provider()->quote(
            $this->context,
            [$this->line('physical', 1000)],
            ['country' => 'US']
        );

        self::assertCount(1, $quotes);
        self::assertSame('Enabled', $quotes[0]->label);
    }

    public function testEnabledMethodsOrderedByPositionThenUuid(): void
    {
        $zone = $this->insertZone('Domestic', 0);
        $this->insertMethod($zone, 'flat', 'Second', ['amount' => 100], position: 1);
        $this->insertMethod($zone, 'flat', 'First', ['amount' => 200], position: 0);

        $quotes = $this->provider()->quote(
            $this->context,
            [$this->line('physical', 1000)],
            ['country' => 'US']
        );

        self::assertSame(['First', 'Second'], array_map(static fn ($q) => $q->label, $quotes));
    }

    public function testQuoteUsesMethodUuidAsId(): void
    {
        $zone = $this->insertZone('Domestic', 0);
        $methodUuid = $this->insertMethod($zone, 'flat', 'Standard', ['amount' => 500]);

        $quotes = $this->provider()->quote(
            $this->context,
            [$this->line('physical', 1000)],
            ['country' => 'US']
        );

        self::assertSame($methodUuid, $quotes[0]->id);
    }

    // -----------------------------------------------------------------
    // Method pricing: flat
    // -----------------------------------------------------------------

    public function testFlatPricing(): void
    {
        $zone = $this->insertZone('Domestic', 0);
        $this->insertMethod($zone, 'flat', 'Standard', ['amount' => 750]);

        $quotes = $this->provider()->quote(
            $this->context,
            [$this->line('physical', 1000)],
            ['country' => 'US']
        );

        self::assertSame(750, $quotes[0]->amount);
    }

    // -----------------------------------------------------------------
    // Method pricing: free_over (ALL-line subtotal, mixed-cart parity)
    // -----------------------------------------------------------------

    public function testFreeOverBelowThresholdKeepsAmount(): void
    {
        $zone = $this->insertZone('Domestic', 0);
        $this->insertMethod($zone, 'free_over', 'Std', ['amount' => 500, 'free_over' => 5000]);

        $quotes = $this->provider()->quote(
            $this->context,
            [$this->line('physical', 4999, 1)],
            ['country' => 'US']
        );

        self::assertSame(500, $quotes[0]->amount);
    }

    public function testFreeOverAtOrAboveThresholdZeroesAmount(): void
    {
        $zone = $this->insertZone('Domestic', 0);
        $this->insertMethod($zone, 'free_over', 'Std', ['amount' => 500, 'free_over' => 5000]);

        $quotes = $this->provider()->quote(
            $this->context,
            [$this->line('physical', 5000, 1)],
            ['country' => 'US']
        );

        self::assertSame(0, $quotes[0]->amount);
    }

    public function testFreeOverThresholdCountsDigitalLinesTooMixedCartParity(): void
    {
        // Mirrors ConfigShippingRateProvider: the free_over threshold is
        // computed against the subtotal of ALL lines, digital included, even
        // though only physical lines exist for shipping cost purposes.
        $zone = $this->insertZone('Domestic', 0);
        $this->insertMethod($zone, 'free_over', 'Std', ['amount' => 500, 'free_over' => 5000]);

        $quotes = $this->provider()->quote(
            $this->context,
            [
                $this->line('physical', 3000, 1),
                $this->line('digital', 3000, 1),
            ],
            ['country' => 'US']
        );

        self::assertSame(0, $quotes[0]->amount, 'digital line subtotal must count toward the free_over threshold');
    }

    // -----------------------------------------------------------------
    // Method pricing: per_class_table
    // -----------------------------------------------------------------

    public function testPerClassTableDistinctClassSummedOnceRegardlessOfLineCount(): void
    {
        $zone = $this->insertZone('Domestic', 0);
        $this->insertMethod($zone, 'per_class_table', 'By class', [
            'default_amount' => 100,
            'classes' => ['fragile' => 800],
        ]);

        $quotes = $this->provider()->quote(
            $this->context,
            [
                $this->line('physical', 1000, 1, 'fragile'),
                $this->line('physical', 1000, 1, 'fragile'),
            ],
            ['country' => 'US']
        );

        self::assertSame(800, $quotes[0]->amount, 'two lines sharing a class contribute ONE class amount, not two');
    }

    public function testPerClassTableNoClassBucketCountsOnceRegardlessOfLineCount(): void
    {
        $zone = $this->insertZone('Domestic', 0);
        $this->insertMethod($zone, 'per_class_table', 'By class', [
            'default_amount' => 150,
            'classes' => ['fragile' => 800],
        ]);

        $quotes = $this->provider()->quote(
            $this->context,
            [
                $this->line('physical', 1000, 1, null),
                $this->line('physical', 1000, 1, null),
                $this->line('physical', 1000, 1, null),
            ],
            ['country' => 'US']
        );

        self::assertSame(150, $quotes[0]->amount, 'the no-class bucket is counted ONCE per order, not per line');
    }

    public function testPerClassTableUnknownSlugFallsBackToDefaultAmount(): void
    {
        $zone = $this->insertZone('Domestic', 0);
        $this->insertMethod($zone, 'per_class_table', 'By class', [
            'default_amount' => 150,
            'classes' => ['fragile' => 800],
        ]);

        $quotes = $this->provider()->quote(
            $this->context,
            [$this->line('physical', 1000, 1, 'oversized')],
            ['country' => 'US']
        );

        self::assertSame(150, $quotes[0]->amount);
    }

    public function testPerClassTableSumsMultipleDistinctClassesPlusNoClassBucket(): void
    {
        $zone = $this->insertZone('Domestic', 0);
        $this->insertMethod($zone, 'per_class_table', 'By class', [
            'default_amount' => 100,
            'classes' => ['fragile' => 800, 'oversized' => 400],
        ]);

        $quotes = $this->provider()->quote(
            $this->context,
            [
                $this->line('physical', 1000, 1, 'fragile'),
                $this->line('physical', 1000, 1, 'oversized'),
                $this->line('physical', 1000, 1, null),
            ],
            ['country' => 'US']
        );

        self::assertSame(1300, $quotes[0]->amount, '800 (fragile) + 400 (oversized) + 100 (no-class bucket)');
    }

    public function testPerClassTableIgnoresDigitalLinesForClassAndNoClassBucket(): void
    {
        $zone = $this->insertZone('Domestic', 0);
        $this->insertMethod($zone, 'per_class_table', 'By class', [
            'default_amount' => 100,
            'classes' => ['fragile' => 800],
        ]);

        $quotes = $this->provider()->quote(
            $this->context,
            [
                $this->line('physical', 1000, 1, 'fragile'),
                $this->line('digital', 1000, 1, null),
            ],
            ['country' => 'US']
        );

        self::assertSame(800, $quotes[0]->amount, 'digital lines never contribute to per_class_table pricing');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function provider(): DbShippingRateProvider
    {
        return new DbShippingRateProvider(new ShippingZoneRepository(), new SentinelTenantResolver());
    }

    /** @return array<string,mixed> */
    private function line(string $type, int $unitPrice, int $quantity = 1, ?string $shippingClass = null): array
    {
        return [
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'type' => $type,
            'shipping_class' => $shippingClass,
        ];
    }

    private function insertZone(string $name, int $position, ?string $uuid = null): string
    {
        $uuid ??= 'zone' . substr(md5($name . random_int(0, PHP_INT_MAX)), 0, 8);
        $this->connection->table('commerce_shipping_zones')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => '',
            'name' => $name,
            'position' => $position,
        ]);

        return $uuid;
    }

    private function insertLocation(string $zoneUuid, string $kind, string $value): void
    {
        $this->connection->table('commerce_shipping_zone_locations')->insert([
            'zone_uuid' => $zoneUuid,
            'kind' => $kind,
            'value' => $value,
        ]);
    }

    /** @param array<string,mixed> $config */
    private function insertMethod(
        string $zoneUuid,
        string $kind,
        string $label,
        array $config,
        ?int $position = null,
        bool $enabled = true,
    ): string {
        $uuid = 'meth' . substr(md5($zoneUuid . $label . random_int(0, PHP_INT_MAX)), 0, 8);
        $this->connection->table('commerce_shipping_methods')->insert([
            'uuid' => $uuid,
            'zone_uuid' => $zoneUuid,
            'kind' => $kind,
            'label' => $label,
            'config' => json_encode($config, JSON_THROW_ON_ERROR),
            'position' => $position ?? 0,
            'enabled' => $enabled,
        ]);

        return $uuid;
    }
}
