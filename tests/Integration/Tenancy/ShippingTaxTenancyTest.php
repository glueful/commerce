<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Shipping\ConfigShippingRateProvider;
use Glueful\Extensions\Commerce\Shipping\DbShippingRateProvider;
use Glueful\Extensions\Commerce\Shipping\DelegatingShippingRateProvider;
use Glueful\Extensions\Commerce\Shipping\ShippingZoneRepository;
use Glueful\Extensions\Commerce\Support\DiagnosticsReport;
use Glueful\Extensions\Commerce\Tax\DbTaxCalculator;
use Glueful\Extensions\Commerce\Tax\DelegatingTaxCalculator;
use Glueful\Extensions\Commerce\Tax\FlatRateTaxCalculator;
use Glueful\Extensions\Commerce\Tax\TaxRateRepository;
use Glueful\Extensions\Commerce\Tenancy\TenantAdopter;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Helpers\Utils;

/**
 * Layer-4 two-tenant sweep for the three tenant-bearing shipping/tax tables
 * (`commerce_shipping_zones`, `commerce_shipping_classes`, `commerce_tax_rates`
 * -- their children, `commerce_shipping_zone_locations`/`commerce_shipping_methods`,
 * are reachable only through an in-tenant zone). T2/T3's admin-CRUD endpoint
 * tests already carry deep per-surface cross-tenant coverage for all three
 * (e.g. `ShippingZoneEndpointTest::testUpdateCrossTenantZoneThrowsNotFound()` /
 * `::testCreateMethodCrossTenantZoneThrowsNotFound()` /
 * `::testSetLocationsCrossTenantZoneThrowsNotFound()` proving zone children are
 * reachable only via an in-tenant zone; `ShippingClassEndpointTest::
 * testUpdateCrossTenantClassThrowsNotFound()`; `TaxRateEndpointTest::
 * testUpdateCrossTenantRateThrowsNotFound()`), and per-tenant name/slug reuse is
 * proven both at the DB layer (`ShippingTaxShapeTest`) and the service layer
 * (`ShippingZoneEndpointTest::testCreateZoneSameNameDifferentTenantSucceeds()`,
 * `ShippingClassEndpointTest::testCreateClassSameSlugDifferentTenantSucceeds()`)
 * -- this file does NOT repeat those cases. The exact-list registry/adopter
 * assertion for every tenant table this extension knows about (now including
 * these three) already lives in
 * `CatalogBreadthTenancyTest::testDiagnosticsReportAndAdopterCoverAllSixCatalogBreadthTables()`;
 * this file adds dedicated adopter-sentinel coverage for these three tables
 * specifically, mirroring `CustomerDeliveryTenancyTest`'s own per-layer style.
 *
 * What IS new here: proof that shipping and tax QUOTES themselves never cross
 * tenants -- the admin-CRUD tests above never exercise the pricing path.
 * `DbShippingRateProvider`/`DbTaxCalculator` scope every read by the CURRENT
 * tenant (`ShippingZoneRepository::all()`/`existsForTenant()`,
 * `TaxRateRepository::search()`/`existsForTenant()`), so tenant A's zone/rate
 * configuration must never leak into tenant B's quote even when both tenants
 * configure the SAME country with DIFFERENT amounts/rates, and a tenant with
 * zero rows of its own must fall back to config/flat-rate byte-identically
 * rather than ever seeing the other tenant's rows.
 */
final class ShippingTaxTenancyTest extends CommerceTestCase
{
    private const TENANT_A = 'tenantAAAA01';
    private const TENANT_B = 'tenantBBBB02';

    // -----------------------------------------------------------------
    // Quotes never cross tenants.
    // -----------------------------------------------------------------

    public function testShippingQuoteNeverCrossesTenantsEvenWithTheSameCountryConfiguredDifferently(): void
    {
        $this->seedZoneWithFlatMethod(self::TENANT_A, 'US Zone A', 'US', 700);
        $this->seedZoneWithFlatMethod(self::TENANT_B, 'US Zone B', 'US', 900);

        $lines = [['type' => 'physical', 'unit_price' => 1000, 'quantity' => 1]];

        $quotesA = $this->shippingProvider(self::TENANT_A)->quote($this->context, $lines, ['country' => 'US']);
        $quotesB = $this->shippingProvider(self::TENANT_B)->quote($this->context, $lines, ['country' => 'US']);

        self::assertCount(1, $quotesA);
        self::assertSame(700, $quotesA[0]->amount, "Tenant A's quote must use its OWN zone/method, never tenant B's.");

        self::assertCount(1, $quotesB);
        self::assertSame(900, $quotesB[0]->amount, "Tenant B's quote must use its OWN zone/method, never tenant A's.");
    }

    public function testShippingDelegatorFallsBackToConfigByteIdenticallyWhenOnlyTheOtherTenantHasZoneRows(): void
    {
        $this->seedZoneWithFlatMethod(self::TENANT_A, 'US Zone A', 'US', 700);

        $lines = [['type' => 'physical', 'unit_price' => 1000, 'quantity' => 1]];
        $delegator = new DelegatingShippingRateProvider(
            new DbShippingRateProvider(new ShippingZoneRepository(), $this->fixedTenant(self::TENANT_B)),
            new ConfigShippingRateProvider()
        );

        $quotesB = $delegator->quote($this->context, $lines, ['country' => 'US']);
        $expected = (new ConfigShippingRateProvider())->quote($this->context, $lines, ['country' => 'US']);

        self::assertEquals(
            $expected,
            $quotesB,
            'Tenant B has zero zone rows of its own -- it must fall back to config byte-identically, '
                . "never see tenant A's rows."
        );
    }

    public function testTaxQuoteNeverCrossesTenantsEvenWithTheSameCountryConfiguredDifferently(): void
    {
        $this->insertRate(self::TENANT_A, ['country' => 'US', 'rate_bps' => 1000, 'label' => 'A Rate']);
        $this->insertRate(self::TENANT_B, ['country' => 'US', 'rate_bps' => 500, 'label' => 'B Rate']);

        $quoteA = $this->taxCalculator(self::TENANT_A)->quote($this->context, 1000, ['country' => 'US']);
        $quoteB = $this->taxCalculator(self::TENANT_B)->quote($this->context, 1000, ['country' => 'US']);

        self::assertSame(100, $quoteA->amount, "Tenant A's tax quote must use its OWN rate, never tenant B's.");
        self::assertSame('A Rate', $quoteA->label);

        self::assertSame(50, $quoteB->amount, "Tenant B's tax quote must use its OWN rate, never tenant A's.");
        self::assertSame('B Rate', $quoteB->label);
    }

    public function testTaxDelegatorFallsBackToFlatRateByteIdenticallyWhenOnlyTheOtherTenantHasRateRows(): void
    {
        $this->insertRate(self::TENANT_A, ['country' => 'US', 'rate_bps' => 1000, 'label' => 'A Rate']);
        $this->context->overrideConfig('commerce.tax.flat_rate_bps', 825);

        $delegator = new DelegatingTaxCalculator(
            new DbTaxCalculator(new TaxRateRepository(), $this->fixedTenant(self::TENANT_B)),
            new FlatRateTaxCalculator()
        );

        $quoteB = $delegator->quote($this->context, 1000, ['country' => 'US']);
        $expected = (new FlatRateTaxCalculator())->quote($this->context, 1000, ['country' => 'US']);

        self::assertSame(
            $expected->amount,
            $quoteB->amount,
            'Tenant B has zero rate rows of its own -- it must fall back to flat-rate byte-identically, '
                . "never see tenant A's rate."
        );
        self::assertNotSame(
            100,
            $quoteB->amount,
            "Tenant B's fallback amount must not coincidentally equal what tenant A's rate would have produced."
        );
    }

    // -----------------------------------------------------------------
    // Registry + adopter coverage.
    // -----------------------------------------------------------------

    public function testDiagnosticsAndAdopterCoverTheThreeShippingTaxTables(): void
    {
        foreach ([
            'commerce_shipping_zones',
            'commerce_shipping_classes',
            'commerce_tax_rates',
        ] as $table) {
            self::assertContains($table, DiagnosticsReport::tenantTables());
        }

        // Children of a zone carry no tenant_uuid of their own -- reachable only
        // through their tenant-scoped parent zone -- so they must never be
        // treated as tenant tables in their own right.
        foreach (['commerce_shipping_zone_locations', 'commerce_shipping_methods'] as $childTable) {
            self::assertNotContains($childTable, DiagnosticsReport::tenantTables());
        }

        $sentinels = [
            'commerce_shipping_zones' => [
                'uuid' => Utils::generateNanoID(),
                'tenant_uuid' => '',
                'name' => 'Sentinel Zone',
            ],
            'commerce_shipping_classes' => [
                'uuid' => Utils::generateNanoID(),
                'tenant_uuid' => '',
                'slug' => 'sentinel-class',
                'name' => 'Sentinel Class',
            ],
            'commerce_tax_rates' => [
                'uuid' => Utils::generateNanoID(),
                'tenant_uuid' => '',
                'country' => 'US',
                'rate_bps' => 500,
                'label' => 'Sentinel Rate',
            ],
        ];

        foreach ($sentinels as $table => $row) {
            $this->connection->table($table)->insert($row);
        }

        $result = (new TenantAdopter())->adopt($this->context, self::TENANT_A);

        foreach ($sentinels as $table => $row) {
            self::assertArrayHasKey($table, $result['tables']);
            self::assertSame(1, $result['tables'][$table], "Adopter should have found exactly 1 sentinel row in {$table}.");

            $adopted = $this->connection->table($table)->where('uuid', '=', $row['uuid'])->first();
            self::assertNotNull($adopted);
            self::assertSame(self::TENANT_A, $adopted['tenant_uuid']);
        }
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function seedZoneWithFlatMethod(string $tenant, string $zoneName, string $country, int $amount): void
    {
        $zoneUuid = 'zone' . substr(md5($tenant . $zoneName), 0, 8);
        $this->connection->table('commerce_shipping_zones')->insert([
            'uuid' => $zoneUuid,
            'tenant_uuid' => $tenant,
            'name' => $zoneName,
            'position' => 0,
        ]);
        $this->connection->table('commerce_shipping_zone_locations')->insert([
            'zone_uuid' => $zoneUuid,
            'kind' => 'country',
            'value' => $country,
        ]);
        $this->connection->table('commerce_shipping_methods')->insert([
            'uuid' => 'meth' . substr(md5($tenant . $zoneName), 0, 8),
            'zone_uuid' => $zoneUuid,
            'kind' => 'flat',
            'label' => 'Standard',
            'config' => json_encode(['amount' => $amount], JSON_THROW_ON_ERROR),
            'position' => 0,
        ]);
    }

    /** @param array<string,mixed> $overrides */
    private function insertRate(string $tenant, array $overrides): void
    {
        $uuid = 'rate' . substr(md5($tenant . (string) ($overrides['label'] ?? 'Tax')), 0, 8);
        $this->connection->table('commerce_tax_rates')->insert(array_merge([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'country' => 'US',
            'state' => null,
            'postcode_pattern' => null,
            'rate_bps' => 0,
            'label' => 'Tax',
            'priority' => 0,
            'shipping_taxable' => false,
            'class' => 'standard',
        ], $overrides));
    }

    private function shippingProvider(string $tenant): DbShippingRateProvider
    {
        return new DbShippingRateProvider(new ShippingZoneRepository(), $this->fixedTenant($tenant));
    }

    private function taxCalculator(string $tenant): DbTaxCalculator
    {
        return new DbTaxCalculator(new TaxRateRepository(), $this->fixedTenant($tenant));
    }

    private function fixedTenant(string $tenant): CurrentTenantResolver
    {
        return new class ($tenant) implements CurrentTenantResolver {
            public function __construct(private string $tenant)
            {
            }

            public function tenantUuid(ApplicationContext $context): string
            {
                return $this->tenant;
            }
        };
    }
}
