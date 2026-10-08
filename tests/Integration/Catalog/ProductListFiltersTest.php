<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Catalog;

use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\ResolvedProductFilters;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * The grid's filters (1.14.0): categories and tags are any-of lists, ANDed with each other; on sale
 * is any active variant priced below its compare-at price; in stock is any active variant that is
 * untracked or tracked above zero.
 */
final class ProductListFiltersTest extends CommerceTestCase
{
    private const TENANT = 'tenantPLF001';

    private ProductRepository $products;

    protected function setUp(): void
    {
        parent::setUp();
        $this->products = new ProductRepository();
    }

    /** @return list<string> */
    private function uuids(ResolvedProductFilters $filters): array
    {
        $rows = $this->products->listActive($this->context, self::TENANT, 1, 48, $filters)['items'];
        $uuids = array_map(static fn (array $r): string => (string) $r['uuid'], $rows);
        sort($uuids);
        return $uuids;
    }

    public function testCategoriesAreAnyOfAndDeduplicated(): void
    {
        $this->product('prodplfmen01');
        $this->product('prodplfwom01');
        $this->product('prodplfbot01');
        $this->product('prodplfnon01');
        $this->category('catplfmen001', ['prodplfmen01', 'prodplfbot01']);
        $this->category('catplfwom001', ['prodplfwom01', 'prodplfbot01']);

        self::assertSame(
            ['prodplfbot01', 'prodplfmen01', 'prodplfwom01'],
            $this->uuids(new ResolvedProductFilters(categoryUuids: ['catplfmen001', 'catplfwom001'])),
        );
        self::assertSame(
            3,
            $this->products->listActive($this->context, self::TENANT, 1, 48, new ResolvedProductFilters(
                categoryUuids: ['catplfmen001', 'catplfwom001'],
            ))['total'],
            'a product in both categories counts once',
        );
    }

    public function testCategoriesAndTagsMustBothMatch(): void
    {
        $this->product('prodplfboth1');
        $this->product('prodplfcato1');
        $this->category('catplfmen002', ['prodplfboth1', 'prodplfcato1']);
        $this->tag('tagplfsum001', ['prodplfboth1']);

        self::assertSame(['prodplfboth1'], $this->uuids(new ResolvedProductFilters(
            categoryUuids: ['catplfmen002'],
            tagUuids: ['tagplfsum001'],
        )));
    }

    public function testOnSaleIsAnyActiveVariantBelowItsCompareAtPrice(): void
    {
        $this->product('prodplfsale1', [['price' => 800, 'compare_at_price' => 1000]]);
        $this->product('prodplfeqal1', [['price' => 1000, 'compare_at_price' => 1000]]);
        $this->product('prodplfnull1', [['price' => 1000, 'compare_at_price' => null]]);
        $this->product('prodplfdrft1', [['price' => 800, 'compare_at_price' => 1000, 'status' => 'draft']]);
        $this->product('prodplfmix01', [
            ['price' => 1000, 'compare_at_price' => null],
            ['price' => 900, 'compare_at_price' => 1200],
        ]);

        self::assertSame(['prodplfmix01', 'prodplfsale1'], $this->uuids(new ResolvedProductFilters(onSale: true)));
    }

    public function testInStockIsAnyActiveVariantUntrackedOrAboveZero(): void
    {
        $tracked = $this->product('prodplfstck1');
        $empty = $this->product('prodplfempt1');
        $untracked = $this->product('prodplfuntr1');
        $this->product('prodplfnost1'); // no stock row: untracked by default
        $this->stock($tracked, 3, true);
        $this->stock($empty, 0, true);
        $this->stock($untracked, 0, false);

        self::assertSame(
            ['prodplfnost1', 'prodplfstck1', 'prodplfuntr1'],
            $this->uuids(new ResolvedProductFilters(inStock: true)),
            'the tracked-at-zero product is the only one dropped',
        );
    }

    public function testEmptyListsDoNotRestrict(): void
    {
        $this->product('prodplfany01');
        self::assertSame(['prodplfany01'], $this->uuids(new ResolvedProductFilters()));
        self::assertTrue((new ResolvedProductFilters())->isEmpty());
        self::assertFalse((new ResolvedProductFilters(inStock: true))->isEmpty());
    }

    /** @param list<array<string,mixed>> $variants */
    private function product(string $uuid, array $variants = [['price' => 1000]]): string
    {
        $this->connection->table('commerce_products')->insert([
            'uuid' => $uuid, 'tenant_uuid' => self::TENANT, 'slug' => 'slug-' . $uuid,
            'name' => 'Product ' . $uuid, 'type' => 'physical', 'status' => 'active',
        ]);
        foreach ($variants as $i => $variant) {
            $this->connection->table('commerce_variants')->insert([
                'uuid' => substr($uuid, 0, 10) . 'v' . $i, 'tenant_uuid' => self::TENANT,
                'product_uuid' => $uuid, 'sku' => $uuid . '-' . $i, 'option_values' => '{}',
                'price' => $variant['price'], 'compare_at_price' => $variant['compare_at_price'] ?? null,
                'currency' => 'USD', 'status' => $variant['status'] ?? 'active',
            ]);
        }
        return $uuid;
    }

    /** @param list<string> $products */
    private function category(string $uuid, array $products): void
    {
        $this->connection->table('commerce_categories')->insert([
            'uuid' => $uuid, 'tenant_uuid' => self::TENANT, 'slug' => $uuid, 'name' => $uuid,
        ]);
        foreach ($products as $product) {
            $this->connection->table('commerce_product_categories')->insert([
                'product_uuid' => $product, 'category_uuid' => $uuid,
            ]);
        }
    }

    /** @param list<string> $products */
    private function tag(string $uuid, array $products): void
    {
        $this->connection->table('commerce_tags')->insert([
            'uuid' => $uuid, 'tenant_uuid' => self::TENANT, 'slug' => $uuid, 'name' => $uuid,
        ]);
        foreach ($products as $product) {
            $this->connection->table('commerce_product_tags')->insert(['product_uuid' => $product, 'tag_uuid' => $uuid]);
        }
    }

    private function stock(string $product, int $quantity, bool $tracked): void
    {
        $this->connection->table('commerce_stock')->insert([
            'uuid' => substr($product, 0, 10) . 'st', 'tenant_uuid' => self::TENANT,
            'variant_uuid' => substr($product, 0, 10) . 'v0', 'quantity' => $quantity, 'tracked' => $tracked,
        ]);
    }
}
