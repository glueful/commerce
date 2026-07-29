<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Wishlist;

use Glueful\Extensions\Commerce\Support\DiagnosticsReport;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

final class WishlistSchemaTest extends CommerceTestCase
{
    public function testOneProductMayBeSavedOncePerUser(): void
    {
        $db = $this->connection->getPDO();
        $db->exec(
            "INSERT INTO commerce_wishlist_items (uuid, tenant_uuid, user_uuid, product_uuid, position, created_at)
             VALUES ('wish00000001', '', 'user00000001', 'prod00000001', 0, '2026-07-29 10:00:00')"
        );

        $this->expectException(\PDOException::class);
        $db->exec(
            "INSERT INTO commerce_wishlist_items (uuid, tenant_uuid, user_uuid, product_uuid, position, created_at)
             VALUES ('wish00000002', '', 'user00000001', 'prod00000001', 1, '2026-07-29 10:00:01')"
        );
    }

    public function testTheSameProductMaySitInTwoDifferentUsersLists(): void
    {
        $db = $this->connection->getPDO();
        $db->exec(
            "INSERT INTO commerce_wishlist_items (uuid, tenant_uuid, user_uuid, product_uuid, position, created_at)
             VALUES ('wish00000003', '', 'user00000001', 'prod00000002', 0, '2026-07-29 10:00:00')"
        );
        $db->exec(
            "INSERT INTO commerce_wishlist_items (uuid, tenant_uuid, user_uuid, product_uuid, position, created_at)
             VALUES ('wish00000004', '', 'user00000002', 'prod00000002', 0, '2026-07-29 10:00:00')"
        );

        $count = (int) $db->query(
            "SELECT COUNT(*) FROM commerce_wishlist_items WHERE product_uuid = 'prod00000002'"
        )->fetchColumn();

        self::assertSame(2, $count);
    }

    public function testOneParentListPerUser(): void
    {
        $db = $this->connection->getPDO();
        $db->exec(
            "INSERT INTO commerce_wishlists (uuid, tenant_uuid, user_uuid, revision)
             VALUES ('wlst00000001', '', 'user00000001', 0)"
        );

        $this->expectException(\PDOException::class);
        $db->exec(
            "INSERT INTO commerce_wishlists (uuid, tenant_uuid, user_uuid, revision)
             VALUES ('wlst00000002', '', 'user00000001', 0)"
        );
    }

    public function testBothTablesAreInTheTenancyInventory(): void
    {
        // The inventory drives TenantTableRegistry registration, adoption, diagnostics and
        // purge. A tenant table missing here survives a tenant teardown unnoticed.
        $tables = DiagnosticsReport::commerceTables();

        self::assertContains('commerce_wishlists', $tables);
        self::assertContains('commerce_wishlist_items', $tables);
    }
}
