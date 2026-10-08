<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Catalog;

use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\ProductSort;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/** listActive()'s order (1.14.0): newest, price both ways by the lowest active price, name. */
final class ProductSortTest extends CommerceTestCase
{
    private const TENANT = 'tenantPST001';

    /** @return list<string> */
    private function order(string $sort): array
    {
        $rows = (new ProductRepository())->listActive($this->context, self::TENANT, 1, 48, null, $sort)['items'];
        return array_map(static fn (array $r): string => (string) $r['name'], $rows);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->product('prodpstaaa01', 'Banana', [500, 900], '2026-01-03 00:00:00');
        $this->product('prodpstbbb01', 'apple', [700], '2026-01-01 00:00:00');
        $this->product('prodpstccc01', 'Cherry', [300], '2026-01-02 00:00:00');
        $this->product('prodpstddd01', 'Date', [], '2026-01-04 00:00:00'); // no active variant
        $this->product('prodpsteee01', 'Elder', [300], '2026-01-02 00:00:00');
    }

    public function testNewestIsTheDefaultAndBreaksTiesOnUuid(): void
    {
        self::assertSame(['Date', 'Banana', 'Cherry', 'Elder', 'apple'], $this->order(ProductSort::NEWEST));
        $rows = (new ProductRepository())->listActive($this->context, self::TENANT, 1, 48)['items'];
        self::assertSame('Date', $rows[0]['name']);
    }

    public function testPriceOrdersByTheLowestActivePriceWithUnpricedLast(): void
    {
        self::assertSame(['Cherry', 'Elder', 'Banana', 'apple', 'Date'], $this->order(ProductSort::PRICE_ASC));
        self::assertSame(['apple', 'Banana', 'Cherry', 'Elder', 'Date'], $this->order(ProductSort::PRICE_DESC));
    }

    public function testNameIsCaseInsensitiveThenUuid(): void
    {
        self::assertSame(['apple', 'Banana', 'Cherry', 'Date', 'Elder'], $this->order(ProductSort::NAME));
    }

    public function testAnUnknownSortIsNewest(): void
    {
        self::assertSame($this->order(ProductSort::NEWEST), $this->order('random'));
    }

    /** @param list<int> $prices */
    private function product(string $uuid, string $name, array $prices, string $createdAt): void
    {
        $this->connection->table('commerce_products')->insert([
            'uuid' => $uuid, 'tenant_uuid' => self::TENANT, 'slug' => 'slug-' . $uuid, 'name' => $name,
            'type' => 'physical', 'status' => 'active', 'created_at' => $createdAt,
        ]);
        foreach ($prices as $i => $price) {
            $this->connection->table('commerce_variants')->insert([
                'uuid' => substr($uuid, 0, 10) . 'v' . $i, 'tenant_uuid' => self::TENANT, 'product_uuid' => $uuid,
                'sku' => $uuid . '-' . $i, 'option_values' => '{}', 'price' => $price, 'currency' => 'USD',
                'status' => 'active',
            ]);
        }
    }
}
