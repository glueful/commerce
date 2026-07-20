<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Tenancy;

use Glueful\Extensions\Commerce\Support\DiagnosticsReport;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantPurge;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * Task 3: the `CommerceTenantPurge` service. Data-destruction code -- every
 * assertion here is a proof that purge touches ONLY the target tenant's rows,
 * across EVERY table `DiagnosticsReport::tenantTables()` lists, and nothing
 * else.
 */
final class CommerceTenantPurgeTest extends CommerceTestCase
{
    private const TENANT_A = 'tenantAAAA01';
    private const TENANT_B = 'tenantBBBB02';

    public function testPurgeRemovesOnlyTargetTenantRowsAcrossEveryTenantTable(): void
    {
        $this->seedTenant(self::TENANT_A);
        $this->seedTenant(self::TENANT_B);

        $purge = new CommerceTenantPurge();
        $result = $purge->purgeTenant($this->context, self::TENANT_A);

        // The returned per-table map must match what was actually deleted:
        // exactly 1 row per seeded table for tenant A, 0 for every other
        // table in the list.
        foreach ($this->existingTenantTables() as $table) {
            $expected = in_array($table, ['commerce_products', 'commerce_orders', 'commerce_sellers'], true)
                ? 1
                : 0;
            self::assertSame($expected, $result[$table], "unexpected delete count for {$table}");
        }

        // Tenant A is gone everywhere.
        $counts = $purge->countTenantRows($this->context, self::TENANT_A);
        foreach ($this->existingTenantTables() as $table) {
            self::assertSame(0, $counts[$table], "{$table} must have zero rows left for tenant A");
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
    }

    public function testPurgeRefusesTheSentinelTenant(): void
    {
        $purge = new CommerceTenantPurge();

        $this->expectException(\InvalidArgumentException::class);
        $purge->purgeTenant($this->context, '');
    }

    private function seedTenant(string $tenant): void
    {
        $this->connection->table('commerce_products')->insert([
            'uuid' => 'prod' . substr($tenant, -8),
            'tenant_uuid' => $tenant,
            'slug' => 'tee-' . $tenant,
            'name' => 'Tee',
        ]);
        $this->connection->table('commerce_orders')->insert([
            'uuid' => 'ordr' . substr($tenant, -8),
            'tenant_uuid' => $tenant,
            'order_number' => 'ORD-' . $tenant,
            'email' => 'buyer@example.test',
            'guest_token_hash' => 'hash-' . $tenant,
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
        ]);
        $this->connection->table('commerce_sellers')->insert([
            'uuid' => 'sell' . substr($tenant, -8),
            'tenant_uuid' => $tenant,
            'slug' => 'seller-' . $tenant,
            'name' => 'Seller',
        ]);
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
