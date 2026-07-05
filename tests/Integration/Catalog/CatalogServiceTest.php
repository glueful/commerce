<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Catalog;

use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Validation\ValidationException;

final class CatalogServiceTest extends CommerceTestCase
{
    private function service(): CatalogService
    {
        return new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            new SentinelTenantResolver()
        );
    }

    public function testCreateProductWithVariantsAndOptions(): void
    {
        $product = $this->service()->createProduct($this->context, [
            'slug' => 'tee',
            'name' => 'Tee',
            'type' => 'physical',
            'status' => 'active',
            'options' => ['Size' => ['S', 'M']],
            'variants' => [
                [
                    'sku' => 'TEE-S',
                    'option_values' => ['Size' => 'S'],
                    'price' => 1500,
                    'currency' => 'USD',
                ],
                [
                    'sku' => 'TEE-M',
                    'option_values' => ['Size' => 'M'],
                    'price' => 1500,
                    'currency' => 'USD',
                ],
            ],
        ]);

        self::assertCount(2, $product['variants']);
        $stock = $this->connection->table('commerce_stock')
            ->where('variant_uuid', '=', $product['variants'][0]['uuid'])
            ->first();
        self::assertNotNull($stock);
        self::assertSame(1, (int) $stock['tracked']);
    }

    public function testOptionlessProductGetsOneDefaultVariant(): void
    {
        $product = $this->service()->createProduct($this->context, [
            'slug' => 'ebook',
            'name' => 'Ebook',
            'type' => 'digital',
            'status' => 'active',
            'variants' => [
                [
                    'sku' => 'EBOOK',
                    'option_values' => [],
                    'price' => 900,
                    'currency' => 'USD',
                ],
            ],
        ]);

        self::assertCount(1, $product['variants']);
        $stock = $this->connection->table('commerce_stock')
            ->where('variant_uuid', '=', $product['variants'][0]['uuid'])
            ->first();
        self::assertNotNull($stock);
        self::assertSame(0, (int) $stock['tracked']);
    }

    public function testVariantCurrencyMustMatchStoreCurrency(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->createProduct($this->context, [
            'slug' => 'tee2',
            'name' => 'Tee 2',
            'type' => 'physical',
            'status' => 'active',
            'variants' => [
                [
                    'sku' => 'TEE2',
                    'option_values' => [],
                    'price' => 1500,
                    'currency' => 'EUR',
                ],
            ],
        ]);
    }

    public function testDuplicateSkuRejected(): void
    {
        $service = $this->service();
        $service->createProduct($this->context, [
            'slug' => 'a',
            'name' => 'A',
            'type' => 'digital',
            'status' => 'active',
            'variants' => [
                [
                    'sku' => 'X1',
                    'option_values' => [],
                    'price' => 100,
                    'currency' => 'USD',
                ],
            ],
        ]);

        $this->expectException(ValidationException::class);

        $service->createProduct($this->context, [
            'slug' => 'b',
            'name' => 'B',
            'type' => 'digital',
            'status' => 'active',
            'variants' => [
                [
                    'sku' => 'X1',
                    'option_values' => [],
                    'price' => 100,
                    'currency' => 'USD',
                ],
            ],
        ]);
    }
}
