<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration;

use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

final class MigrationsTest extends CommerceTestCase
{
    public function testAllTablesExist(): void
    {
        $tables = [
            'commerce_products',
            'commerce_variants',
            'commerce_stock',
            'commerce_stock_movements',
            'commerce_carts',
            'commerce_cart_lines',
            'commerce_orders',
            'commerce_order_lines',
            'commerce_order_events',
            'commerce_sequences',
            'commerce_discounts',
            'commerce_discount_redemptions',
        ];

        $schema = $this->connection->getSchemaBuilder();
        foreach ($tables as $table) {
            self::assertTrue($schema->hasTable($table), "missing table {$table}");
        }
    }

    public function testSentinelTenantUniquenessActuallyEnforces(): void
    {
        $this->connection->table('commerce_products')->insert([
            'uuid' => 'prod00000001',
            'slug' => 'tee',
            'name' => 'Tee',
            'type' => 'physical',
            'status' => 'active',
        ]);

        $this->expectException(\Throwable::class);

        $this->connection->table('commerce_products')->insert([
            'uuid' => 'prod00000002',
            'slug' => 'tee',
            'name' => 'Tee 2',
            'type' => 'physical',
            'status' => 'active',
        ]);
    }

    public function testSkuOrderNumberCodeAndTokenHashUniquesEnforceUnderSentinel(): void
    {
        $db = $this->connection;

        $db->table('commerce_variants')->insert([
            'uuid' => 'var000000001',
            'product_uuid' => 'prod00000001',
            'sku' => 'TEE-S',
            'option_values' => '{}',
            'price' => 1000,
            'currency' => 'USD',
            'status' => 'active',
        ]);

        try {
            $db->table('commerce_variants')->insert([
                'uuid' => 'var000000002',
                'product_uuid' => 'prod00000001',
                'sku' => 'TEE-S',
                'option_values' => '{}',
                'price' => 1000,
                'currency' => 'USD',
                'status' => 'active',
            ]);
            self::fail('duplicate sentinel SKU must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        $db->table('commerce_carts')->insert([
            'uuid' => 'cart00000001',
            'token_hash' => str_repeat('a', 64),
            'status' => 'active',
        ]);

        try {
            $db->table('commerce_carts')->insert([
                'uuid' => 'cart00000002',
                'token_hash' => str_repeat('a', 64),
                'status' => 'active',
            ]);
            self::fail('duplicate sentinel cart token_hash must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        $db->table('commerce_orders')->insert([
            'uuid' => 'order0000001',
            'order_number' => '1001',
            'email' => 'buyer@example.com',
            'guest_token_hash' => str_repeat('b', 64),
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
        ]);

        try {
            $db->table('commerce_orders')->insert([
                'uuid' => 'order0000002',
                'order_number' => '1001',
                'email' => 'buyer2@example.com',
                'guest_token_hash' => str_repeat('c', 64),
                'currency' => 'USD',
                'subtotal' => 1000,
                'grand_total' => 1000,
            ]);
            self::fail('duplicate sentinel order number must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        $db->table('commerce_discounts')->insert([
            'uuid' => 'disc00000001',
            'code' => 'SAVE10',
            'type' => 'percentage',
            'value' => 1000,
            'status' => 'active',
        ]);

        try {
            $db->table('commerce_discounts')->insert([
                'uuid' => 'disc00000002',
                'code' => 'SAVE10',
                'type' => 'percentage',
                'value' => 1000,
                'status' => 'active',
            ]);
            self::fail('duplicate sentinel discount code must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }
}
