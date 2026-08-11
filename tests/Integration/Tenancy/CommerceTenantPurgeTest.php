<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Tenancy;

use Glueful\Extensions\Commerce\Support\DiagnosticsReport;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantPurge;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Helpers\Utils;

/**
 * Task 3: the `CommerceTenantPurge` service. Data-destruction code -- every
 * assertion here is a proof that purge touches ONLY the target tenant's rows,
 * across EVERY table `DiagnosticsReport::tenantTables()` lists AND every
 * child table that carries no `tenant_uuid` of its own (order lines/events,
 * refund lines, cart lines, taxonomy joins, shipping-zone children), and
 * nothing else.
 *
 * Review finding (cs1-commerce-review.md §4): the original implementation
 * only reached `tenantTables()`, so deleting a tenant's parent rows
 * (`commerce_orders`, `commerce_carts`, `commerce_products`,
 * `commerce_shipping_zones`, `commerce_refunds`) orphaned every child row
 * permanently, and `countTenantRows()`/`verify()` reported a false "zero
 * remaining" while the tenant's order line-items, event history, and refund
 * lines physically survived. These tests assert genuine, literal deletion of
 * those child rows -- not merely that they become unreachable via a join --
 * so a regression back to the old behavior cannot pass silently.
 */
final class CommerceTenantPurgeTest extends CommerceTestCase
{
    private const TENANT_A = 'tenantAAAA01';
    private const TENANT_B = 'tenantBBBB02';

    /**
     * Child tables with no `tenant_uuid` column of their own, keyed by table
     * name, mapping to [joinColumn, parentTable]. Mirrors
     * `CommerceTenantPurge::CHILD_TABLES` -- kept as an independent literal
     * here (not read from the class under test) so the test can't pass
     * vacuously if that map is ever accidentally emptied.
     *
     * @var array<string,array{0:string,1:string}>
     */
    private const CHILD_TABLES = [
        'commerce_order_lines' => ['order_uuid', 'commerce_orders'],
        'commerce_order_events' => ['order_uuid', 'commerce_orders'],
        'commerce_refund_lines' => ['refund_uuid', 'commerce_refunds'],
        'commerce_cart_lines' => ['cart_uuid', 'commerce_carts'],
        'commerce_product_categories' => ['product_uuid', 'commerce_products'],
        'commerce_product_tags' => ['product_uuid', 'commerce_products'],
        'commerce_product_attributes' => ['product_uuid', 'commerce_products'],
        'commerce_product_children' => ['product_uuid', 'commerce_products'],
        'commerce_attribute_values' => ['attribute_uuid', 'commerce_attributes'],
        'commerce_shipping_zone_locations' => ['zone_uuid', 'commerce_shipping_zones'],
        'commerce_shipping_methods' => ['zone_uuid', 'commerce_shipping_zones'],
    ];

    public function testPurgeRemovesOnlyTargetTenantRowsAcrossEveryTenantTable(): void
    {
        $this->seedTenant(self::TENANT_A);
        $this->seedTenant(self::TENANT_B);

        $purge = new CommerceTenantPurge();
        $result = $purge->purgeTenant($this->context, self::TENANT_A);

        // The returned per-table map must match what was actually deleted:
        // exactly 1 row per seeded tenant-column table for tenant A, 0 for
        // every other table in the list.
        $seededTenantTables = [
            'commerce_products', 'commerce_orders', 'commerce_sellers', 'commerce_carts',
            'commerce_categories', 'commerce_tags', 'commerce_attributes',
            'commerce_shipping_zones', 'commerce_refunds',
            'commerce_wishlists', 'commerce_wishlist_items',
            'commerce_order_draft_attempts',
            'commerce_payment_links',
        ];
        foreach ($this->existingTenantTables() as $table) {
            $expected = in_array($table, $seededTenantTables, true) ? 1 : 0;
            self::assertSame($expected, $result[$table], "unexpected delete count for {$table}");
        }

        // The returned map must include every child table, each showing
        // exactly 1 row deleted for tenant A.
        foreach (array_keys(self::CHILD_TABLES) as $table) {
            self::assertArrayHasKey($table, $result, "purge result missing child table {$table}");
            self::assertSame(1, $result[$table], "unexpected child delete count for {$table}");
        }

        // Tenant A is gone everywhere the service claims to certify.
        $counts = $purge->countTenantRows($this->context, self::TENANT_A);
        foreach ($this->existingTenantTables() as $table) {
            self::assertSame(0, $counts[$table], "{$table} must have zero rows left for tenant A");
        }
        foreach (array_keys(self::CHILD_TABLES) as $table) {
            self::assertSame(0, $counts[$table], "{$table} must have zero reachable rows left for tenant A");
        }

        // Tenant B is fully intact.
        self::assertSame(
            1,
            $this->connection->table('commerce_products')->where('tenant_uuid', '=', self::TENANT_B)->count()
        );
        self::assertSame(
            1,
            $this->connection->table('commerce_orders')->where('tenant_uuid', '=', self::TENANT_B)->count()
        );
        self::assertSame(
            1,
            $this->connection->table('commerce_sellers')->where('tenant_uuid', '=', self::TENANT_B)->count()
        );

        $bCounts = $purge->countTenantRows($this->context, self::TENANT_B);
        self::assertSame(1, $bCounts['commerce_products']);
        self::assertSame(1, $bCounts['commerce_orders']);
        self::assertSame(1, $bCounts['commerce_sellers']);
        foreach (array_keys(self::CHILD_TABLES) as $table) {
            self::assertSame(1, $bCounts[$table], "{$table} must still report 1 reachable row for tenant B");
        }
    }

    /**
     * The critical proof the review demanded: child rows must be GENUINELY
     * deleted (a raw literal count against the child table by its known
     * parent uuid, independent of any join), not merely unreachable once
     * their parent is gone. B's child rows, keyed by B's own parent uuids,
     * must survive untouched.
     */
    public function testPurgeLeavesZeroTenantReachableChildRowsAndLeavesOtherTenantIntact(): void
    {
        $a = $this->seedTenant(self::TENANT_A);
        $b = $this->seedTenant(self::TENANT_B);

        $purge = new CommerceTenantPurge();
        $purge->purgeTenant($this->context, self::TENANT_A);

        foreach (self::CHILD_TABLES as $table => [$joinColumn, $parentTable]) {
            self::assertSame(
                0,
                $this->connection->table($table)->where($joinColumn, '=', $a[$parentTable])->count(),
                "{$table} row for tenant A's {$parentTable} ({$a[$parentTable]}) must be physically deleted"
            );
            self::assertSame(
                1,
                $this->connection->table($table)->where($joinColumn, '=', $b[$parentTable])->count(),
                "{$table} row for tenant B's {$parentTable} ({$b[$parentTable]}) must survive untouched"
            );
        }

        // The parents themselves are gone for A, intact for B.
        foreach (['commerce_orders', 'commerce_carts', 'commerce_products', 'commerce_attributes',
                  'commerce_shipping_zones', 'commerce_refunds'] as $parentTable) {
            self::assertSame(
                0,
                $this->connection->table($parentTable)->where('uuid', '=', $a[$parentTable])->count()
            );
            self::assertSame(
                1,
                $this->connection->table($parentTable)->where('uuid', '=', $b[$parentTable])->count()
            );
        }
    }

    public function testCountTenantRowsReflectsChildRowsBeforeAndAfterPurge(): void
    {
        $this->seedTenant(self::TENANT_A);
        $this->seedTenant(self::TENANT_B);

        $purge = new CommerceTenantPurge();

        $before = $purge->countTenantRows($this->context, self::TENANT_A);
        foreach (array_keys(self::CHILD_TABLES) as $table) {
            self::assertSame(1, $before[$table], "{$table} must count 1 reachable row for tenant A before purge");
        }

        $purge->purgeTenant($this->context, self::TENANT_A);

        $after = $purge->countTenantRows($this->context, self::TENANT_A);
        foreach (array_keys(self::CHILD_TABLES) as $table) {
            self::assertSame(0, $after[$table], "{$table} must count 0 reachable rows for tenant A after purge");
        }

        $bAfter = $purge->countTenantRows($this->context, self::TENANT_B);
        foreach (array_keys(self::CHILD_TABLES) as $table) {
            self::assertSame(1, $bAfter[$table], "{$table} must still count 1 reachable row for tenant B");
        }
    }

    /**
     * Payment-links Task 5 (spec §2.2): the PHYSICAL destruction proof. The
     * seeded-table loop above would pass vacuously if the table were silently
     * missing from `tenantTables()`, so this asserts deletion directly against
     * the table. The inventory MEMBERSHIP that makes it happen is asserted
     * beside the rest of the shape, in
     * {@see \Glueful\Extensions\Commerce\Tests\Integration\Migrations\PaymentLinkSchemaTest}
     * (the convention `SellerApiKeyShapeTest` et al. already follow).
     */
    public function testPurgeDestroysPaymentLinksForTheTargetTenantOnly(): void
    {
        $this->seedTenant(self::TENANT_A);
        $this->seedTenant(self::TENANT_B);

        $purge = new CommerceTenantPurge();
        $result = $purge->purgeTenant($this->context, self::TENANT_A);

        self::assertSame(1, $result['commerce_payment_links']);
        self::assertSame(
            0,
            $this->connection->table('commerce_payment_links')
                ->where('tenant_uuid', '=', self::TENANT_A)
                ->count(),
            "a purged tenant's hashed payment-link credentials must be physically gone"
        );
        self::assertSame(
            1,
            $this->connection->table('commerce_payment_links')
                ->where('tenant_uuid', '=', self::TENANT_B)
                ->count()
        );
    }

    public function testPurgeRefusesTheSentinelTenant(): void
    {
        $purge = new CommerceTenantPurge();

        $this->expectException(\InvalidArgumentException::class);
        $purge->purgeTenant($this->context, '');
    }

    /**
     * Seeds one row per tenant-column table this test cares about, plus one
     * row per child table in {@see self::CHILD_TABLES}, all owned by the
     * given tenant's parents.
     *
     * @return array<string,string> parent-table identity => uuid
     */
    private function seedTenant(string $tenant): array
    {
        $productUuid = Utils::generateNanoID();
        $orderUuid = Utils::generateNanoID();
        $sellerUuid = Utils::generateNanoID();
        $cartUuid = Utils::generateNanoID();
        $categoryUuid = Utils::generateNanoID();
        $tagUuid = Utils::generateNanoID();
        $attributeUuid = Utils::generateNanoID();
        $zoneUuid = Utils::generateNanoID();
        $refundUuid = Utils::generateNanoID();

        $this->connection->table('commerce_products')->insert([
            'uuid' => $productUuid,
            'tenant_uuid' => $tenant,
            'slug' => 'tee-' . $tenant,
            'name' => 'Tee',
        ]);
        // Wishlist parent + item: listing the tables in the inventory is not coverage --
        // an unseeded table passes the "expect 0 deletes" branch while proving nothing.
        $this->connection->table('commerce_wishlists')->insert([
            'uuid' => Utils::generateNanoID(),
            'tenant_uuid' => $tenant,
            'user_uuid' => substr('user' . $tenant, 0, 12),
            'revision' => 0,
        ]);
        $this->connection->table('commerce_wishlist_items')->insert([
            'uuid' => Utils::generateNanoID(),
            'tenant_uuid' => $tenant,
            'user_uuid' => substr('user' . $tenant, 0, 12),
            'product_uuid' => $productUuid,
            'position' => 0,
        ]);
        $this->connection->table('commerce_orders')->insert([
            'uuid' => $orderUuid,
            'tenant_uuid' => $tenant,
            'order_number' => 'ORD-' . $tenant,
            'email' => 'buyer@example.test',
            'guest_token_hash' => 'hash-' . $tenant,
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
        ]);
        $this->connection->table('commerce_sellers')->insert([
            'uuid' => $sellerUuid,
            'tenant_uuid' => $tenant,
            'slug' => 'seller-' . $tenant,
            'name' => 'Seller',
        ]);
        $this->connection->table('commerce_carts')->insert([
            'uuid' => $cartUuid,
            'tenant_uuid' => $tenant,
            'token_hash' => 'token-' . $tenant,
        ]);
        $this->connection->table('commerce_categories')->insert([
            'uuid' => $categoryUuid,
            'tenant_uuid' => $tenant,
            'slug' => 'cat-' . $tenant,
            'name' => 'Category',
        ]);
        $this->connection->table('commerce_tags')->insert([
            'uuid' => $tagUuid,
            'tenant_uuid' => $tenant,
            'slug' => 'tag-' . $tenant,
            'name' => 'Tag',
        ]);
        $this->connection->table('commerce_attributes')->insert([
            'uuid' => $attributeUuid,
            'tenant_uuid' => $tenant,
            'slug' => 'attr-' . $tenant,
            'name' => 'Attribute',
        ]);
        $this->connection->table('commerce_shipping_zones')->insert([
            'uuid' => $zoneUuid,
            'tenant_uuid' => $tenant,
            'name' => 'Zone-' . $tenant,
        ]);
        $this->connection->table('commerce_refunds')->insert([
            'uuid' => $refundUuid,
            'tenant_uuid' => $tenant,
            'order_uuid' => $orderUuid,
            'idempotency_key' => 'idem-' . $tenant,
            'request_fingerprint' => md5($tenant),
            'amount' => 100,
            'currency' => 'USD',
            'method' => 'manual',
        ]);
        $this->connection->table('commerce_order_draft_attempts')->insert([
            'tenant_uuid' => $tenant,
            'idempotency_key' => 'draft-idem-' . $tenant,
            'request_fingerprint' => md5('draft-' . $tenant),
            'order_uuid' => $orderUuid,
            'status' => 'pending',
        ]);
        // Payment-links Task 5 (spec §2.2): a payment link carries `tenant_uuid`
        // directly, so purging a workspace must destroy its outstanding payment
        // links too -- a surviving row would keep a hashed bearer credential
        // resolvable against a tenant that no longer exists.
        $this->connection->table('commerce_payment_links')->insert([
            'uuid' => substr('plink' . $tenant, 0, 12),
            'tenant_uuid' => $tenant,
            'order_uuid' => $orderUuid,
            'token_hash' => hash('sha256', 'payment-link-' . $tenant),
            'status' => 'active',
            'expires_at' => '2026-09-01 00:00:00',
            'created_by' => substr('actor' . $tenant, 0, 12),
        ]);

        // --- Children: no tenant_uuid of their own, reachable only via the parent above ---

        $this->connection->table('commerce_order_lines')->insert([
            'uuid' => Utils::generateNanoID(),
            'order_uuid' => $orderUuid,
            'variant_uuid' => Utils::generateNanoID(),
            'product_name' => 'Tee',
            'sku' => 'SKU-' . $tenant,
            'option_values' => '[]',
            'unit_price' => 500,
            'quantity' => 1,
            'line_total' => 500,
        ]);
        $this->connection->table('commerce_order_events')->insert([
            'uuid' => Utils::generateNanoID(),
            'order_uuid' => $orderUuid,
            'type' => 'placed',
        ]);
        $this->connection->table('commerce_refund_lines')->insert([
            'refund_uuid' => $refundUuid,
            'order_line_uuid' => Utils::generateNanoID(),
            'quantity' => 1,
            'amount' => 100,
        ]);
        $this->connection->table('commerce_cart_lines')->insert([
            'uuid' => Utils::generateNanoID(),
            'cart_uuid' => $cartUuid,
            'variant_uuid' => Utils::generateNanoID(),
            'quantity' => 1,
        ]);
        $this->connection->table('commerce_product_categories')->insert([
            'product_uuid' => $productUuid,
            'category_uuid' => $categoryUuid,
        ]);
        $this->connection->table('commerce_product_tags')->insert([
            'product_uuid' => $productUuid,
            'tag_uuid' => $tagUuid,
        ]);
        $this->connection->table('commerce_product_attributes')->insert([
            'uuid' => Utils::generateNanoID(),
            'product_uuid' => $productUuid,
            'attribute_uuid' => $attributeUuid,
            'values' => '[]',
        ]);
        $this->connection->table('commerce_product_children')->insert([
            'product_uuid' => $productUuid,
            'child_uuid' => Utils::generateNanoID(),
        ]);
        $this->connection->table('commerce_attribute_values')->insert([
            'uuid' => Utils::generateNanoID(),
            'attribute_uuid' => $attributeUuid,
            'slug' => 'val-' . $tenant,
            'value' => 'Value',
        ]);
        $this->connection->table('commerce_shipping_zone_locations')->insert([
            'zone_uuid' => $zoneUuid,
            'kind' => 'country',
            'value' => 'US',
        ]);
        $this->connection->table('commerce_shipping_methods')->insert([
            'uuid' => Utils::generateNanoID(),
            'zone_uuid' => $zoneUuid,
            'kind' => 'flat',
            'label' => 'Standard',
            'config' => '{}',
        ]);

        return [
            'commerce_products' => $productUuid,
            'commerce_orders' => $orderUuid,
            'commerce_sellers' => $sellerUuid,
            'commerce_carts' => $cartUuid,
            'commerce_categories' => $categoryUuid,
            'commerce_tags' => $tagUuid,
            'commerce_attributes' => $attributeUuid,
            'commerce_shipping_zones' => $zoneUuid,
            'commerce_refunds' => $refundUuid,
        ];
    }

    /** @return list<string> */
    private function existingTenantTables(): array
    {
        $tables = [];
        foreach (DiagnosticsReport::tenantTables() as $table) {
            if ($this->connection->getSchemaBuilder()->hasTable($table)) {
                $tables[] = $table;
            }
        }

        return $tables;
    }
}
