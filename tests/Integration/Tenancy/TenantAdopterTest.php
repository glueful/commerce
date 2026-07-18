<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Tenancy;

use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tenancy\TenantAdopter;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

final class TenantAdopterTest extends CommerceTestCase
{
    public function testAdoptRekeysSentinelRowsAndRefusesMixedData(): void
    {
        $this->seedSentinelCatalog();

        $result = (new TenantAdopter())->adopt($this->context, 'tenantAAAA01');

        self::assertGreaterThan(0, $result['tables']['commerce_products']);
        self::assertSame(0, $this->connection->table('commerce_products')->where('tenant_uuid', '=', '')->count());
        self::assertGreaterThan(0, $this->connection->table('commerce_products')
            ->where('tenant_uuid', '=', 'tenantAAAA01')
            ->count());

        $this->expectException(\RuntimeException::class);
        (new TenantAdopter())->adopt($this->context, 'tenantCCCC03');
    }

    /**
     * Design spec §2.1 explicit exception: `commerce:tenancy:adopt` stays
     * marketplace-aware REGARDLESS of `commerce.marketplace.enabled`, so a
     * workspace's marketplace foundation data (created before, or while, the
     * install switch is off) is never stranded when the switch flips on later.
     * The master switch is left at its default (off, unset) for this whole
     * test -- {@see \Glueful\Extensions\Commerce\Support\DiagnosticsReport::tenantTables()}
     * feeds `TenantAdopter` unconditionally.
     */
    public function testAdoptRekeysMarketplaceTablesEvenWhenTheMasterSwitchIsOff(): void
    {
        self::assertFalse(
            (bool) config($this->context, 'commerce.marketplace.enabled', false),
            'this test relies on the master switch being off (the default)'
        );

        $this->connection->table('commerce_marketplace_settings')->insert([
            'uuid' => 'mktadoptset1',
            'tenant_uuid' => '',
            'status' => 'disabled',
        ]);
        $this->connection->table('commerce_sellers')->insert([
            'uuid' => 'mktadoptsel1',
            'tenant_uuid' => '',
            'slug' => 'sentinel-seller',
            'name' => 'Sentinel Seller',
        ]);
        $this->connection->table('commerce_seller_memberships')->insert([
            'uuid' => 'mktadoptmem1',
            'tenant_uuid' => '',
            'seller_uuid' => 'mktadoptsel1',
            'user_uuid' => 'user000000001',
            'role' => 'seller_owner',
        ]);

        $result = (new TenantAdopter())->adopt($this->context, 'tenantMKT0001');

        self::assertSame(1, $result['tables']['commerce_marketplace_settings']);
        self::assertSame(1, $result['tables']['commerce_sellers']);
        self::assertSame(1, $result['tables']['commerce_seller_memberships']);

        foreach (['commerce_marketplace_settings', 'commerce_sellers', 'commerce_seller_memberships'] as $table) {
            self::assertSame(
                0,
                $this->connection->table($table)->where('tenant_uuid', '=', '')->count(),
                "{$table} must have no sentinel rows left behind"
            );
            self::assertSame(
                1,
                $this->connection->table($table)->where('tenant_uuid', '=', 'tenantMKT0001')->count(),
                "{$table} row must be rekeyed to the adopted tenant"
            );
        }
    }

    /**
     * Design spec §7/§3.3, MV2 Task 10: `commerce_seller_orders` is
     * marketplace-aware regardless of the master switch, exactly like the
     * MV1 trio above -- {@see \Glueful\Extensions\Commerce\Support\DiagnosticsReport::tenantTables()}
     * lists it unconditionally, so `TenantAdopter` rekeys it too, switch off
     * or on.
     */
    public function testAdoptRekeysSellerOrdersTableEvenWhenTheMasterSwitchIsOff(): void
    {
        self::assertFalse(
            (bool) config($this->context, 'commerce.marketplace.enabled', false),
            'this test relies on the master switch being off (the default)'
        );

        $this->connection->table('commerce_seller_orders')->insert([
            'uuid' => 'mktadoptso01',
            'tenant_uuid' => '',
            'order_uuid' => 'mktadoptord1',
            'seller_uuid' => 'mktadoptsel1',
            'seller_name_snapshot' => 'Sentinel Seller',
            'partition_number' => 1,
            'seller_reference' => 'MKTADOPT-1',
            'currency' => 'USD',
            'subtotal' => 1000,
            'attributed_total' => 1000,
            'tax_attribution_method' => 'aggregate_allocated',
        ]);

        $result = (new TenantAdopter())->adopt($this->context, 'tenantMKT0002');

        self::assertSame(1, $result['tables']['commerce_seller_orders']);
        self::assertSame(
            0,
            $this->connection->table('commerce_seller_orders')->where('tenant_uuid', '=', '')->count(),
            'commerce_seller_orders must have no sentinel rows left behind'
        );
        self::assertSame(
            1,
            $this->connection->table('commerce_seller_orders')
                ->where('tenant_uuid', '=', 'tenantMKT0002')
                ->count(),
            'commerce_seller_orders row must be rekeyed to the adopted tenant'
        );
    }

    private function seedSentinelCatalog(): void
    {
        (new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            new SentinelTenantResolver(),
            new StockRepository()
        ))->createProduct($this->context, [
            'slug' => 'tee',
            'name' => 'Tee',
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => 'TEE',
                'option_values' => [],
                'price' => 100,
                'currency' => 'USD',
            ]],
        ]);
    }
}
