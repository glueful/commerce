<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Migrations;

use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * Freezes the migration-010/011 table shapes (marketplace settings, sellers,
 * seller memberships, and the folded `commerce_products.seller_uuid` column)
 * before any Marketplace repository/service code consumes them (design spec
 * §3). Exercises the database-level invariants directly against the schema:
 * the settings `tenant_uuid` GLOBAL unique (one activation row per tenant),
 * the seller `(tenant_uuid, slug)` per-tenant unique, the membership
 * `(seller_uuid, user_uuid)` unique, and the documented column defaults
 * (`revision` 0, seller/membership `status` `active`).
 */
final class MarketplaceShapeTest extends CommerceTestCase
{
    public function testAllThreeMarketplaceTablesExist(): void
    {
        $tables = [
            'commerce_marketplace_settings',
            'commerce_sellers',
            'commerce_seller_memberships',
        ];

        $schema = $this->connection->getSchemaBuilder();
        foreach ($tables as $table) {
            self::assertTrue($schema->hasTable($table), "missing table {$table}");
        }
    }

    public function testFoldedProductSellerUuidColumnIsNullableAndDefaultsToNullWhenOmitted(): void
    {
        $this->connection->table('commerce_products')->insert([
            'uuid' => 'prod00000950',
            'slug' => 'seller-fold-fixture',
            'name' => 'Seller Fold Fixture',
            'type' => 'physical',
            'status' => 'draft',
        ]);

        $row = $this->connection->table('commerce_products')->where('uuid', '=', 'prod00000950')->first();
        self::assertNotNull($row);
        self::assertNull($row['seller_uuid']);
    }

    public function testProductSellerUuidAcceptsAnAssignedValue(): void
    {
        $this->connection->table('commerce_products')->insert([
            'uuid' => 'prod00000951',
            'slug' => 'seller-assigned-fixture',
            'name' => 'Seller Assigned Fixture',
            'type' => 'physical',
            'status' => 'draft',
            'seller_uuid' => 'seller000001',
        ]);

        $row = $this->connection->table('commerce_products')->where('uuid', '=', 'prod00000951')->first();
        self::assertNotNull($row);
        self::assertSame('seller000001', $row['seller_uuid']);
    }

    // === commerce_marketplace_settings ==========================================

    public function testSettingsTenantUuidIsAGlobalUniqueOnePerWorkspace(): void
    {
        $this->connection->table('commerce_marketplace_settings')->insert([
            'uuid' => 'mktsetting01',
            'tenant_uuid' => 'tenantAAAA01',
            'status' => 'disabled',
        ]);

        // A DIFFERENT tenant may have its own row.
        $this->connection->table('commerce_marketplace_settings')->insert([
            'uuid' => 'mktsetting02',
            'tenant_uuid' => 'tenantBBBB02',
            'status' => 'disabled',
        ]);
        self::assertSame(2, $this->connection->table('commerce_marketplace_settings')->count());

        // The SAME tenant again -- must be rejected (one settings row per workspace).
        try {
            $this->connection->table('commerce_marketplace_settings')->insert([
                'uuid' => 'mktsetting03',
                'tenant_uuid' => 'tenantAAAA01',
                'status' => 'disabled',
            ]);
            self::fail('duplicate tenant_uuid settings row must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    public function testSettingsRevisionDefaultsToZeroAndOptionalColumnsDefaultToNullWhenOmitted(): void
    {
        $this->connection->table('commerce_marketplace_settings')->insert([
            'uuid' => 'mktsetting10',
            'tenant_uuid' => 'tenantCCCC03',
            'status' => 'disabled',
        ]);

        $row = $this->connection->table('commerce_marketplace_settings')
            ->where('uuid', '=', 'mktsetting10')
            ->first();
        self::assertNotNull($row);
        self::assertSame(0, (int) $row['revision']);
        self::assertNull($row['default_seller_uuid']);
        self::assertNull($row['activated_by']);
        self::assertNull($row['activated_at']);
    }

    public function testSettingsUuidIsUniqueAcrossTenants(): void
    {
        $this->connection->table('commerce_marketplace_settings')->insert([
            'uuid' => 'mktsettingdup',
            'tenant_uuid' => 'tenantDDDD04',
            'status' => 'disabled',
        ]);

        try {
            $this->connection->table('commerce_marketplace_settings')->insert([
                'uuid' => 'mktsettingdup',
                'tenant_uuid' => 'tenantEEEE05',
                'status' => 'disabled',
            ]);
            self::fail('duplicate settings uuid must be rejected even across different tenants');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    // === commerce_sellers =========================================================

    public function testSellerSlugUniquePerTenantButReusableAcrossTenants(): void
    {
        $this->connection->table('commerce_sellers')->insert([
            'uuid' => 'sellerslugA1',
            'tenant_uuid' => 'tenantAAAA01',
            'slug' => 'acme',
            'name' => 'Acme',
        ]);

        // Same slug, different tenant -- must succeed.
        $this->connection->table('commerce_sellers')->insert([
            'uuid' => 'sellerslugB1',
            'tenant_uuid' => 'tenantBBBB02',
            'slug' => 'acme',
            'name' => 'Acme',
        ]);
        self::assertSame(2, $this->connection->table('commerce_sellers')->where('slug', '=', 'acme')->count());

        // Same slug, same tenant again -- must be rejected.
        try {
            $this->connection->table('commerce_sellers')->insert([
                'uuid' => 'sellerslugA2',
                'tenant_uuid' => 'tenantAAAA01',
                'slug' => 'acme',
                'name' => 'Acme Duplicate',
            ]);
            self::fail('duplicate (tenant_uuid, slug) seller insert must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    public function testSellerStatusDefaultsToActiveAndRevisionDefaultsToZeroWhenOmitted(): void
    {
        $this->connection->table('commerce_sellers')->insert([
            'uuid' => 'sellerdflt01',
            'tenant_uuid' => '',
            'slug' => 'default-seller',
            'name' => 'Default Seller',
        ]);

        $row = $this->connection->table('commerce_sellers')->where('uuid', '=', 'sellerdflt01')->first();
        self::assertNotNull($row);
        self::assertSame('active', $row['status']);
        self::assertSame(0, (int) $row['revision']);
        self::assertNull($row['metadata']);
    }

    // === commerce_seller_memberships ==============================================

    public function testMembershipSellerAndUserUniqueButAllowsSameUserAcrossDifferentSellers(): void
    {
        $this->connection->table('commerce_seller_memberships')->insert([
            'uuid' => 'memb00000001',
            'tenant_uuid' => 'tenantAAAA01',
            'seller_uuid' => 'sellerslugA1',
            'user_uuid' => 'user000000001',
            'role' => 'seller_owner',
        ]);

        // Same user, a DIFFERENT seller -- must succeed.
        $this->connection->table('commerce_seller_memberships')->insert([
            'uuid' => 'memb00000002',
            'tenant_uuid' => 'tenantAAAA01',
            'seller_uuid' => 'sellerslugB1',
            'user_uuid' => 'user000000001',
            'role' => 'seller_staff',
        ]);
        self::assertSame(
            2,
            $this->connection->table('commerce_seller_memberships')
                ->where('user_uuid', '=', 'user000000001')
                ->count()
        );

        // Same (seller, user) pair again -- must be rejected.
        try {
            $this->connection->table('commerce_seller_memberships')->insert([
                'uuid' => 'memb00000003',
                'tenant_uuid' => 'tenantAAAA01',
                'seller_uuid' => 'sellerslugA1',
                'user_uuid' => 'user000000001',
                'role' => 'seller_admin',
            ]);
            self::fail('duplicate (seller_uuid, user_uuid) membership insert must be rejected');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    public function testMembershipStatusDefaultsToActiveAndCreatedByDefaultsToNullWhenOmitted(): void
    {
        $this->connection->table('commerce_seller_memberships')->insert([
            'uuid' => 'membdflt0001',
            'tenant_uuid' => '',
            'seller_uuid' => 'sellerdflt01',
            'user_uuid' => 'user000000099',
            'role' => 'seller_analyst',
        ]);

        $row = $this->connection->table('commerce_seller_memberships')
            ->where('uuid', '=', 'membdflt0001')
            ->first();
        self::assertNotNull($row);
        self::assertSame('active', $row['status']);
        self::assertNull($row['created_by']);
    }

    /**
     * The `(tenant_uuid, user_uuid)` "my sellers" index and the
     * `(seller_uuid, status, role)` membership-list/last-owner index (design
     * spec §3) are exercised behaviorally here rather than by introspecting
     * index metadata (this codebase's established shape-test convention,
     * see {@see \Glueful\Extensions\Commerce\Tests\Integration\Migrations\ShippingTaxShapeTest}):
     * both queries must return correct, tenant/seller-scoped results.
     */
    public function testMembershipListableByTenantAndUserAndBySellerStatusAndRole(): void
    {
        $this->connection->table('commerce_seller_memberships')->insert([
            'uuid' => 'membidx00001',
            'tenant_uuid' => 'tenantFFFF06',
            'seller_uuid' => 'selleridx001',
            'user_uuid' => 'useridx00001',
            'role' => 'seller_owner',
            'status' => 'active',
        ]);
        $this->connection->table('commerce_seller_memberships')->insert([
            'uuid' => 'membidx00002',
            'tenant_uuid' => 'tenantFFFF06',
            'seller_uuid' => 'selleridx002',
            'user_uuid' => 'useridx00099',
            'role' => 'seller_staff',
            'status' => 'active',
        ]);

        $mine = $this->connection->table('commerce_seller_memberships')
            ->where('tenant_uuid', '=', 'tenantFFFF06')
            ->where('user_uuid', '=', 'useridx00001')
            ->get();
        self::assertCount(1, $mine);
        self::assertSame('membidx00001', $mine[0]['uuid']);

        $owners = $this->connection->table('commerce_seller_memberships')
            ->where('seller_uuid', '=', 'selleridx001')
            ->where('status', '=', 'active')
            ->where('role', '=', 'seller_owner')
            ->get();
        self::assertCount(1, $owners);
        self::assertSame('membidx00001', $owners[0]['uuid']);
    }
}
