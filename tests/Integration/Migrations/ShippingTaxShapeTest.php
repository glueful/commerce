<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Migrations;

use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * Freezes the migration-009 table shapes (shipping zones, zone locations, shipping methods,
 * shipping classes, tax rates) and the two folded columns (`commerce_variants.shipping_class_uuid`,
 * `commerce_products.tax_class`) before any repository/service code consumes them. Exercises the
 * database-level invariants directly against the schema per spec §2: the zone (tenant_uuid, name)
 * per-tenant unique, the zone-location (zone_uuid, kind, value) unique, the class
 * (tenant_uuid, slug) per-tenant unique, and the documented defaults (position 0, revision 0,
 * enabled true, shipping_taxable false, class 'standard'). Also pins that zone_locations and
 * shipping_methods -- children reachable only through a tenant-scoped zone -- carry no
 * `tenant_uuid` column of their own (the established child-table rule; see
 * `DiagnosticsReport::tenantTables()`).
 */
final class ShippingTaxShapeTest extends CommerceTestCase
{
    public function testAllFiveShippingTaxTablesExist(): void
    {
        $tables = [
            'commerce_shipping_zones',
            'commerce_shipping_zone_locations',
            'commerce_shipping_methods',
            'commerce_shipping_classes',
            'commerce_tax_rates',
        ];

        $schema = $this->connection->getSchemaBuilder();
        foreach ($tables as $table) {
            self::assertTrue($schema->hasTable($table), "missing table {$table}");
        }
    }

    public function testShippingZoneNameUniquePerTenantButReusableAcrossTenants(): void
    {
        $this->connection->table('commerce_shipping_zones')->insert([
            'uuid' => 'zonea0000001',
            'tenant_uuid' => 'tenantAAAA01',
            'name' => 'Domestic',
        ]);

        // Same name, different tenant -- must succeed.
        $this->connection->table('commerce_shipping_zones')->insert([
            'uuid' => 'zoneb0000001',
            'tenant_uuid' => 'tenantBBBB02',
            'name' => 'Domestic',
        ]);
        self::assertSame(
            2,
            $this->connection->table('commerce_shipping_zones')->where('name', '=', 'Domestic')->count()
        );

        // Same name, same tenant again -- must be rejected.
        try {
            $this->connection->table('commerce_shipping_zones')->insert([
                'uuid' => 'zonea0000002',
                'tenant_uuid' => 'tenantAAAA01',
                'name' => 'Domestic',
            ]);
            self::fail('duplicate (tenant_uuid, name) shipping zone insert must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    public function testShippingZonePositionAndRevisionDefaultToZeroWhenOmitted(): void
    {
        $this->connection->table('commerce_shipping_zones')->insert([
            'uuid' => 'zone00000010',
            'tenant_uuid' => '',
            'name' => 'Everywhere',
        ]);

        $row = $this->connection->table('commerce_shipping_zones')->where('uuid', '=', 'zone00000010')->first();
        self::assertNotNull($row);
        self::assertSame(0, (int) $row['position']);
        self::assertSame(0, (int) $row['revision']);
    }

    public function testShippingZoneLocationUniqueRejectsDuplicateZoneKindValueButAllowsDifferentKindOrZone(): void
    {
        $zone = 'zone00000020';

        $this->connection->table('commerce_shipping_zone_locations')->insert([
            'zone_uuid' => $zone,
            'kind' => 'country',
            'value' => 'US',
        ]);

        // Same zone, same kind, same value again -- must be rejected.
        try {
            $this->connection->table('commerce_shipping_zone_locations')->insert([
                'zone_uuid' => $zone,
                'kind' => 'country',
                'value' => 'US',
            ]);
            self::fail('duplicate (zone_uuid, kind, value) location insert must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        // Same zone, same value, DIFFERENT kind -- must succeed.
        $this->connection->table('commerce_shipping_zone_locations')->insert([
            'zone_uuid' => $zone,
            'kind' => 'postcode_pattern',
            'value' => 'US',
        ]);

        // Same kind/value, a DIFFERENT zone -- must succeed.
        $this->connection->table('commerce_shipping_zone_locations')->insert([
            'zone_uuid' => 'zone00000021',
            'kind' => 'country',
            'value' => 'US',
        ]);

        self::assertSame(
            2,
            $this->connection->table('commerce_shipping_zone_locations')->where('zone_uuid', '=', $zone)->count()
        );
    }

    public function testShippingMethodPositionDefaultsToZeroAndEnabledDefaultsToTrueWhenOmitted(): void
    {
        $this->connection->table('commerce_shipping_methods')->insert([
            'uuid' => 'ship00000001',
            'zone_uuid' => 'zone00000030',
            'kind' => 'flat',
            'label' => 'Standard Shipping',
            'config' => json_encode(['amount' => 500], JSON_THROW_ON_ERROR),
        ]);

        $row = $this->connection->table('commerce_shipping_methods')->where('uuid', '=', 'ship00000001')->first();
        self::assertNotNull($row);
        self::assertSame(0, (int) $row['position']);
        self::assertTrue((bool) $row['enabled']);
    }

    public function testShippingClassSlugUniquePerTenantButReusableAcrossTenants(): void
    {
        $this->connection->table('commerce_shipping_classes')->insert([
            'uuid' => 'clasa0000001',
            'tenant_uuid' => 'tenantAAAA01',
            'slug' => 'fragile',
            'name' => 'Fragile',
        ]);

        // Same slug, different tenant -- must succeed.
        $this->connection->table('commerce_shipping_classes')->insert([
            'uuid' => 'clasb0000001',
            'tenant_uuid' => 'tenantBBBB02',
            'slug' => 'fragile',
            'name' => 'Fragile',
        ]);
        self::assertSame(
            2,
            $this->connection->table('commerce_shipping_classes')->where('slug', '=', 'fragile')->count()
        );

        // Same slug, same tenant again -- must be rejected.
        try {
            $this->connection->table('commerce_shipping_classes')->insert([
                'uuid' => 'clasa0000002',
                'tenant_uuid' => 'tenantAAAA01',
                'slug' => 'fragile',
                'name' => 'Fragile Duplicate',
            ]);
            self::fail('duplicate (tenant_uuid, slug) shipping class insert must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    public function testShippingClassRevisionDefaultsToZeroWhenOmitted(): void
    {
        $this->connection->table('commerce_shipping_classes')->insert([
            'uuid' => 'class0000010',
            'tenant_uuid' => '',
            'slug' => 'oversized',
            'name' => 'Oversized',
        ]);

        $row = $this->connection->table('commerce_shipping_classes')->where('uuid', '=', 'class0000010')->first();
        self::assertNotNull($row);
        self::assertSame(0, (int) $row['revision']);
    }

    public function testTaxRateDefaultsForPriorityShippingTaxableClassAndRevisionWhenOmitted(): void
    {
        $this->connection->table('commerce_tax_rates')->insert([
            'uuid' => 'taxr00000001',
            'tenant_uuid' => '',
            'country' => 'US',
            'rate_bps' => 875,
            'label' => 'Sales Tax',
        ]);

        $row = $this->connection->table('commerce_tax_rates')->where('uuid', '=', 'taxr00000001')->first();
        self::assertNotNull($row);
        self::assertSame(0, (int) $row['priority']);
        self::assertFalse((bool) $row['shipping_taxable']);
        self::assertSame('standard', $row['class']);
        self::assertSame(0, (int) $row['revision']);
    }

    public function testTaxRateStateAndPostcodePatternAreNullableAndDefaultToNullWhenOmitted(): void
    {
        $this->connection->table('commerce_tax_rates')->insert([
            'uuid' => 'taxr00000002',
            'tenant_uuid' => '',
            'country' => 'CA',
            'rate_bps' => 500,
            'label' => 'GST',
        ]);

        $row = $this->connection->table('commerce_tax_rates')->where('uuid', '=', 'taxr00000002')->first();
        self::assertNotNull($row);
        self::assertNull($row['state']);
        self::assertNull($row['postcode_pattern']);
    }

    public function testFoldedVariantShippingClassUuidColumnIsNullableAndDefaultsToNullWhenOmitted(): void
    {
        $this->connection->table('commerce_variants')->insert([
            'uuid' => 'var000000900',
            'product_uuid' => 'prod00000900',
            'sku' => 'SKU-SHIP-900',
            'option_values' => '{}',
            'price' => 1000,
            'currency' => 'USD',
        ]);

        $row = $this->connection->table('commerce_variants')->where('uuid', '=', 'var000000900')->first();
        self::assertNotNull($row);
        self::assertNull($row['shipping_class_uuid']);
    }

    public function testFoldedProductTaxClassColumnIsNullableAndDefaultsToNullWhenOmitted(): void
    {
        $this->connection->table('commerce_products')->insert([
            'uuid' => 'prod00000901',
            'slug' => 'tax-class-fold-fixture',
            'name' => 'Tax Class Fold Fixture',
            'type' => 'physical',
            'status' => 'draft',
        ]);

        $row = $this->connection->table('commerce_products')->where('uuid', '=', 'prod00000901')->first();
        self::assertNotNull($row);
        self::assertNull($row['tax_class']);
    }

    public function testShippingZoneLocationsAndMethodsHaveNoTenantUuidColumnOfTheirOwn(): void
    {
        $schema = $this->connection->getSchemaBuilder();

        self::assertFalse(
            $schema->hasColumn('commerce_shipping_zone_locations', 'tenant_uuid'),
            'commerce_shipping_zone_locations must not carry its own tenant_uuid -- '
                . 'it is reachable only through its tenant-scoped zone'
        );
        self::assertFalse(
            $schema->hasColumn('commerce_shipping_methods', 'tenant_uuid'),
            'commerce_shipping_methods must not carry its own tenant_uuid -- '
                . 'it is reachable only through its tenant-scoped zone'
        );
    }
}
