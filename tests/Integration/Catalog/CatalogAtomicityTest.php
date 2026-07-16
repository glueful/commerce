<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Catalog;

use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Validation\ValidationException;

final class CatalogAtomicityTest extends CommerceTestCase
{
    public function testDuplicateSkuBatchLeavesNoPartialCatalogRows(): void
    {
        $catalog = new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            new SentinelTenantResolver(),
            new StockRepository()
        );

        try {
            $catalog->createProduct($this->context, [
                'slug' => 'duplicate-sku-batch',
                'name' => 'Duplicate SKU batch',
                'type' => 'physical',
                'status' => 'active',
                'variants' => [
                    ['sku' => 'DUPLICATE-SKU', 'price' => 1000, 'currency' => 'USD'],
                    ['sku' => 'DUPLICATE-SKU', 'price' => 1200, 'currency' => 'USD'],
                ],
            ]);
            self::fail('Expected duplicate SKUs in one request to be rejected.');
        } catch (ValidationException) {
            self::assertSame(0, $this->connection->table('commerce_products')->count());
            self::assertSame(0, $this->connection->table('commerce_variants')->count());
            self::assertSame(0, $this->connection->table('commerce_stock')->count());
        }
    }
}
