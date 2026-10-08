<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Catalog;

use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\TagRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Commerce\Tests\Support\CountingPdoStatement;

/** Every category and tag of a list of products, in one query each (1.14.0). */
final class ProductTaxonomyProjectionsTest extends CommerceTestCase
{
    private const TENANT = 'tenantPTP001';

    public function testEveryCategoryInPositionThenNameOrderInOneQuery(): void
    {
        $this->category('catptpbbb001', 'Women', 1);
        $this->category('catptpaaa001', 'Men', 1);
        $this->category('catptpccc001', 'Sale', 0);
        $this->link('commerce_product_categories', 'category_uuid', 'prodptp00001', ['catptpbbb001', 'catptpaaa001', 'catptpccc001']);
        $this->link('commerce_product_categories', 'category_uuid', 'prodptp00002', ['catptpaaa001']);

        $pdo = $this->connection->getPDO();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [CountingPdoStatement::class]);
        // The connection's first statement carries a one-off setup statement: warm it, then count.
        (new CategoryRepository())->categoryProjectionsForProducts($this->context, self::TENANT, ['prodptp00001']);
        CountingPdoStatement::$count = 0;
        $result = (new CategoryRepository())->categoryProjectionsForProducts(
            $this->context,
            self::TENANT,
            ['prodptp00001', 'prodptp00002', 'prodptp00003'],
        );
        self::assertSame(1, CountingPdoStatement::$count);
        self::assertSame(['Sale', 'Men', 'Women'], array_column($result['prodptp00001'], 'name'));
        self::assertSame([['name' => 'Men', 'slug' => 'catptpaaa001']], $result['prodptp00002']);
        self::assertArrayNotHasKey('prodptp00003', $result);
        self::assertSame([], (new CategoryRepository())->categoryProjectionsForProducts($this->context, self::TENANT, []));
    }

    public function testEveryTagInNameOrderInOneQuery(): void
    {
        $this->tag('tagptpbbb001', 'Summer');
        $this->tag('tagptpaaa001', 'linen');
        $this->link('commerce_product_tags', 'tag_uuid', 'prodptp00004', ['tagptpbbb001', 'tagptpaaa001']);

        $result = (new TagRepository())->tagProjectionsForProducts($this->context, self::TENANT, ['prodptp00004']);
        self::assertSame(['linen', 'Summer'], array_column($result['prodptp00004'], 'name'));
    }

    private function category(string $uuid, string $name, int $position): void
    {
        $this->connection->table('commerce_categories')->insert([
            'uuid' => $uuid, 'tenant_uuid' => self::TENANT, 'slug' => $uuid, 'name' => $name, 'position' => $position,
        ]);
    }

    private function tag(string $uuid, string $name): void
    {
        $this->connection->table('commerce_tags')->insert([
            'uuid' => $uuid, 'tenant_uuid' => self::TENANT, 'slug' => $uuid, 'name' => $name,
        ]);
    }

    /** @param list<string> $targets */
    private function link(string $table, string $column, string $product, array $targets): void
    {
        foreach ($targets as $target) {
            $this->connection->table($table)->insert(['product_uuid' => $product, $column => $target]);
        }
    }
}
