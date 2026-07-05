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
