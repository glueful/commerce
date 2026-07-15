<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Migrations;

use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * Freezes the three range indexes Layer 5 reports fold into the original
 * migrations (pre-launch fold posture -- no new migration file):
 *  - `commerce_orders` (tenant_uuid, placed_at)  -- report_at branch 1
 *  - `commerce_orders` (tenant_uuid, created_at) -- report_at branch 2 (legacy-null fallback)
 *  - `commerce_refunds` (tenant_uuid, completed_at)
 *
 * Windowed order queries never predicate on `WHERE COALESCE(placed_at, created_at)`
 * (which would make both of these indexes unusable); they use two `UNION ALL`
 * branches instead, each indexable by one of these composite indexes.
 */
final class ReportIndexShapeTest extends CommerceTestCase
{
    public function testCommerceOrdersHasTenantPlacedAtIndex(): void
    {
        self::assertIndexExists(
            'commerce_orders',
            'commerce_orders_tenant_uuid_placed_at_index',
            ['tenant_uuid', 'placed_at']
        );
    }

    public function testCommerceOrdersHasTenantCreatedAtIndex(): void
    {
        self::assertIndexExists(
            'commerce_orders',
            'commerce_orders_tenant_uuid_created_at_index',
            ['tenant_uuid', 'created_at']
        );
    }

    public function testCommerceRefundsHasTenantCompletedAtIndex(): void
    {
        self::assertIndexExists(
            'commerce_refunds',
            'commerce_refunds_tenant_uuid_completed_at_index',
            ['tenant_uuid', 'completed_at']
        );
    }

    /**
     * @param list<string> $expectedColumns ordered, leading column first
     */
    private function assertIndexExists(string $table, string $indexName, array $expectedColumns): void
    {
        $pdo = $this->connection->getPDO();

        $stmt = $pdo->query(sprintf("PRAGMA index_list('%s')", $table));
        self::assertNotFalse($stmt);
        $indexes = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $names = array_column($indexes, 'name');
        self::assertContains($indexName, $names, "missing index {$indexName} on {$table}");

        $infoStmt = $pdo->query(sprintf("PRAGMA index_info('%s')", $indexName));
        self::assertNotFalse($infoStmt);
        $columns = $infoStmt->fetchAll(\PDO::FETCH_ASSOC);
        $actualColumns = array_column($columns, 'name');

        self::assertSame($expectedColumns, $actualColumns, "unexpected column order for {$indexName}");
    }
}
