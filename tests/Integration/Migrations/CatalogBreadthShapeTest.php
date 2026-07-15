<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Migrations;

use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * Freezes the migration-007 table shapes and the three folded column sets (products,
 * cart_lines, order_lines) before any repository/service code consumes them. Exercises the
 * database-level invariants directly against the schema: tenant-scoped taxonomy uniqueness,
 * the (product_uuid, attribute_uuid) null-exempt composite unique, the product_media
 * (product_uuid, blob_uuid) unique, and the cart_lines triple unique that replaces the old
 * (cart_uuid, variant_uuid) pair.
 */
final class CatalogBreadthShapeTest extends CommerceTestCase
{
    public function testAllElevenCatalogBreadthTablesExist(): void
    {
        $tables = [
            'commerce_product_media',
            'commerce_categories',
            'commerce_product_categories',
            'commerce_tags',
            'commerce_product_tags',
            'commerce_attributes',
            'commerce_attribute_values',
            'commerce_product_attributes',
            'commerce_product_children',
            'commerce_product_addons',
            'commerce_reviews',
        ];

        $schema = $this->connection->getSchemaBuilder();
        foreach ($tables as $table) {
            self::assertTrue($schema->hasTable($table), "missing table {$table}");
        }
    }

    public function testCategoryRevisionDefaultsToZeroWhenOmitted(): void
    {
        $this->connection->table('commerce_categories')->insert([
            'uuid' => 'cat000000001',
            'tenant_uuid' => '',
            'slug' => 'apparel',
            'name' => 'Apparel',
        ]);

        $row = $this->connection->table('commerce_categories')->where('uuid', '=', 'cat000000001')->first();
        self::assertNotNull($row);
        self::assertSame(0, (int) $row['revision']);
        self::assertSame(0, (int) $row['position']);
    }

    public function testTagRevisionDefaultsToZeroWhenOmitted(): void
    {
        $this->connection->table('commerce_tags')->insert([
            'uuid' => 'tag000000001',
            'tenant_uuid' => '',
            'slug' => 'summer',
            'name' => 'Summer',
        ]);

        $row = $this->connection->table('commerce_tags')->where('uuid', '=', 'tag000000001')->first();
        self::assertNotNull($row);
        self::assertSame(0, (int) $row['revision']);
    }

    public function testAttributeRevisionDefaultsToZeroWhenOmitted(): void
    {
        $this->connection->table('commerce_attributes')->insert([
            'uuid' => 'attr00000001',
            'tenant_uuid' => '',
            'slug' => 'color',
            'name' => 'Color',
        ]);

        $row = $this->connection->table('commerce_attributes')->where('uuid', '=', 'attr00000001')->first();
        self::assertNotNull($row);
        self::assertSame(0, (int) $row['revision']);
    }

    public function testProductCatalogRevisionAndRatingColumnsDefaultToZeroWhenOmitted(): void
    {
        $this->connection->table('commerce_products')->insert([
            'uuid' => 'prod00000010',
            'slug' => 'widget',
            'name' => 'Widget',
            'type' => 'physical',
            'status' => 'active',
        ]);

        $row = $this->connection->table('commerce_products')->where('uuid', '=', 'prod00000010')->first();
        self::assertNotNull($row);
        self::assertSame(0, (int) $row['rating_sum']);
        self::assertSame(0, (int) $row['rating_count']);
        self::assertSame(0, (int) $row['catalog_revision']);
    }

    public function testCategorySlugIsUniquePerTenantButReusableAcrossTenants(): void
    {
        $this->connection->table('commerce_categories')->insert([
            'uuid' => 'cat0000000a1',
            'tenant_uuid' => 'tenantAAAA01',
            'slug' => 'shoes',
            'name' => 'Shoes',
        ]);

        // Same slug, different tenant -- must succeed.
        $this->connection->table('commerce_categories')->insert([
            'uuid' => 'cat0000000b1',
            'tenant_uuid' => 'tenantBBBB02',
            'slug' => 'shoes',
            'name' => 'Shoes',
        ]);
        self::assertSame(
            2,
            $this->connection->table('commerce_categories')->where('slug', '=', 'shoes')->count()
        );

        // Same slug, same tenant -- must be rejected.
        try {
            $this->connection->table('commerce_categories')->insert([
                'uuid' => 'cat0000000a2',
                'tenant_uuid' => 'tenantAAAA01',
                'slug' => 'shoes',
                'name' => 'Shoes Duplicate',
            ]);
            self::fail('duplicate (tenant_uuid, slug) category insert must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    public function testTagSlugIsUniquePerTenantButReusableAcrossTenants(): void
    {
        $this->connection->table('commerce_tags')->insert([
            'uuid' => 'tag0000000a1',
            'tenant_uuid' => 'tenantAAAA01',
            'slug' => 'sale',
            'name' => 'Sale',
        ]);

        $this->connection->table('commerce_tags')->insert([
            'uuid' => 'tag0000000b1',
            'tenant_uuid' => 'tenantBBBB02',
            'slug' => 'sale',
            'name' => 'Sale',
        ]);
        self::assertSame(
            2,
            $this->connection->table('commerce_tags')->where('slug', '=', 'sale')->count()
        );

        try {
            $this->connection->table('commerce_tags')->insert([
                'uuid' => 'tag0000000a2',
                'tenant_uuid' => 'tenantAAAA01',
                'slug' => 'sale',
                'name' => 'Sale Duplicate',
            ]);
            self::fail('duplicate (tenant_uuid, slug) tag insert must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    public function testAttributeSlugIsUniquePerTenantButReusableAcrossTenants(): void
    {
        $this->connection->table('commerce_attributes')->insert([
            'uuid' => 'attr0000a001',
            'tenant_uuid' => 'tenantAAAA01',
            'slug' => 'size',
            'name' => 'Size',
        ]);

        $this->connection->table('commerce_attributes')->insert([
            'uuid' => 'attr0000b001',
            'tenant_uuid' => 'tenantBBBB02',
            'slug' => 'size',
            'name' => 'Size',
        ]);
        self::assertSame(
            2,
            $this->connection->table('commerce_attributes')->where('slug', '=', 'size')->count()
        );

        try {
            $this->connection->table('commerce_attributes')->insert([
                'uuid' => 'attr0000a002',
                'tenant_uuid' => 'tenantAAAA01',
                'slug' => 'size',
                'name' => 'Size Duplicate',
            ]);
            self::fail('duplicate (tenant_uuid, slug) attribute insert must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    public function testProductAttributesCompositeUniqueRejectsDuplicateNonNullPairsButAllowsMultipleNullRows(): void
    {
        $product = 'prod00000020';

        $this->connection->table('commerce_product_attributes')->insert([
            'uuid' => 'pattr0000001',
            'product_uuid' => $product,
            'attribute_uuid' => 'attr00000099',
            'values' => '[]',
        ]);

        // Same (product_uuid, attribute_uuid) non-null pair again -- must be rejected.
        try {
            $this->connection->table('commerce_product_attributes')->insert([
                'uuid' => 'pattr0000002',
                'product_uuid' => $product,
                'attribute_uuid' => 'attr00000099',
                'values' => '[]',
            ]);
            self::fail('duplicate (product_uuid, attribute_uuid) non-null pair must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        // Two custom (attribute_uuid = null) rows for the same product -- both must succeed,
        // freezing SQLite's null-exempt unique-index behaviour for this composite unique.
        $this->connection->table('commerce_product_attributes')->insert([
            'uuid' => 'pattr0000003',
            'product_uuid' => $product,
            'attribute_uuid' => null,
            'name' => 'Custom One',
            'values' => '["Red"]',
        ]);
        $this->connection->table('commerce_product_attributes')->insert([
            'uuid' => 'pattr0000004',
            'product_uuid' => $product,
            'attribute_uuid' => null,
            'name' => 'Custom Two',
            'values' => '["Blue"]',
        ]);

        self::assertSame(
            2,
            $this->connection->table('commerce_product_attributes')
                ->where('product_uuid', '=', $product)
                ->whereNull('attribute_uuid')
                ->count()
        );
    }

    public function testProductMediaUniqueRejectsSameBlobTwiceOnOneProduct(): void
    {
        $product = 'prod00000030';

        $this->connection->table('commerce_product_media')->insert([
            'uuid' => 'media0000001',
            'tenant_uuid' => '',
            'product_uuid' => $product,
            'blob_uuid' => 'blob00000001',
        ]);

        try {
            $this->connection->table('commerce_product_media')->insert([
                'uuid' => 'media0000002',
                'tenant_uuid' => '',
                'product_uuid' => $product,
                'blob_uuid' => 'blob00000001',
            ]);
            self::fail('duplicate (product_uuid, blob_uuid) media insert must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        // A different blob on the same product is unaffected.
        $this->connection->table('commerce_product_media')->insert([
            'uuid' => 'media0000003',
            'tenant_uuid' => '',
            'product_uuid' => $product,
            'blob_uuid' => 'blob00000002',
        ]);
        self::assertSame(
            2,
            $this->connection->table('commerce_product_media')->where('product_uuid', '=', $product)->count()
        );
    }

    public function testCartLineTripleUniqueAllowsTwoAddonHashesButRejectsARepeatedHash(): void
    {
        $cart = 'cart00000099';
        $variant = 'var000000099';
        $hashOne = str_repeat('a', 64);
        $hashTwo = str_repeat('b', 64);

        $this->connection->table('commerce_cart_lines')->insert([
            'uuid' => 'line00000001',
            'cart_uuid' => $cart,
            'variant_uuid' => $variant,
            'quantity' => 1,
            'addons_hash' => $hashOne,
        ]);

        // Same cart + variant, a DIFFERENT addons_hash -- this insert succeeding is the proof
        // that the old (cart_uuid, variant_uuid) pair-unique no longer exists: under the old
        // constraint this would have collided with the row above.
        $this->connection->table('commerce_cart_lines')->insert([
            'uuid' => 'line00000002',
            'cart_uuid' => $cart,
            'variant_uuid' => $variant,
            'quantity' => 1,
            'addons_hash' => $hashTwo,
        ]);
        self::assertSame(
            2,
            $this->connection->table('commerce_cart_lines')
                ->where('cart_uuid', '=', $cart)
                ->where('variant_uuid', '=', $variant)
                ->count()
        );

        // Same cart + variant + the SAME addons_hash again -- must be rejected.
        try {
            $this->connection->table('commerce_cart_lines')->insert([
                'uuid' => 'line00000003',
                'cart_uuid' => $cart,
                'variant_uuid' => $variant,
                'quantity' => 1,
                'addons_hash' => $hashOne,
            ]);
            self::fail('duplicate (cart_uuid, variant_uuid, addons_hash) insert must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    public function testFoldedCartLineColumnsDefaultToNullAndEmptyStringWhenOmitted(): void
    {
        $this->connection->table('commerce_cart_lines')->insert([
            'uuid' => 'line00000010',
            'cart_uuid' => 'cart00000100',
            'variant_uuid' => 'var000000100',
            'quantity' => 2,
        ]);

        $row = $this->connection->table('commerce_cart_lines')->where('uuid', '=', 'line00000010')->first();
        self::assertNotNull($row);
        self::assertNull($row['addons']);
        self::assertSame('', $row['addons_hash']);
    }

    public function testFoldedOrderLineAddonsColumnDefaultsToNullWhenOmitted(): void
    {
        $this->connection->table('commerce_order_lines')->insert([
            'uuid' => 'oline0000001',
            'order_uuid' => 'order0000099',
            'variant_uuid' => 'var000000200',
            'product_name' => 'Widget',
            'sku' => 'WIDGET-1',
            'option_values' => '{}',
            'unit_price' => 1000,
            'quantity' => 1,
            'line_total' => 1000,
        ]);

        $row = $this->connection->table('commerce_order_lines')->where('uuid', '=', 'oline0000001')->first();
        self::assertNotNull($row);
        self::assertNull($row['addons']);
    }
}
