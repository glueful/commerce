<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Support\DiagnosticsReport;

/**
 * Commerce-Slice-1 Task 3: hard-deletes every commerce row for a tenant --
 * both the tenant-scoped tables in {@see DiagnosticsReport::tenantTables()}
 * AND the child tables that carry no `tenant_uuid` column of their own and
 * are reachable only through a parent's uuid (order lines/events, refund
 * lines, cart lines, taxonomy joins, shipping-zone children -- see
 * {@see self::CHILD_TABLES}). For the tenant-column tables this mirrors
 * {@see TenantAdopter}'s style/transaction conventions and iterates
 * `tenantTables()` -- commerce keeps table-list ownership, so this stays in
 * lockstep with adoption's own list with no separate inventory to drift.
 *
 * Data-destruction code: `purgeTenant()` is a permanent, irreversible delete
 * with no soft-delete/undo. There is no DB-level cascade in this schema (no
 * foreign keys are declared anywhere in the commerce migrations), so deleting
 * a parent row alone would silently orphan its children forever. Every child
 * table in `CHILD_TABLES` is therefore deleted FIRST, scoped through its
 * parent's `tenant_uuid` via a parent-join subquery, inside the SAME
 * transaction as the parent deletes that follow.
 *
 * Scope boundary (documented on purpose, not a gap): a child row is only
 * "tenant-reachable" while its parent still exists to join through.
 * `countTenantRows()` certifies zero TENANT-REACHABLE rows across the full
 * table set above -- it cannot and does not attempt to detect a child row
 * whose parent was already removed by some path other than this service (an
 * unattributable orphan by definition, since nothing left in the database
 * says which tenant it belonged to). Because this service always deletes a
 * tenant's children before its parents in one transaction, purging through
 * `CommerceTenantPurge` alone can never create such an orphan.
 */
final class CommerceTenantPurge
{
    /**
     * Child tables with no `tenant_uuid` column of their own, keyed by table
     * name, each mapping to `[joinColumn, parentTable]`. Every `parentTable`
     * here carries `tenant_uuid` directly (verified against every commerce
     * migration -- no two-hop parent-of-a-parent chain exists in the current
     * schema, so a single join always resolves tenancy).
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

    /**
     * Delete every commerce row for this tenant -- every child table in
     * {@see self::CHILD_TABLES} first (via a parent-join subquery), then
     * every table in {@see DiagnosticsReport::tenantTables()} -- inside a
     * single transaction.
     *
     * @return array<string,int> rows deleted, keyed by table name
     */
    public function purgeTenant(ApplicationContext $context, string $tenantUuid): array
    {
        if ($tenantUuid === '') {
            throw new \InvalidArgumentException(
                'Refusing to purge the sentinel tenant (empty tenant_uuid); purging it is a programming error.'
            );
        }

        return db($context)->transaction(function () use ($context, $tenantUuid): array {
            $counts = [];

            foreach ($this->existingChildTables($context) as $table => $join) {
                [$joinColumn, $parentTable] = $join;
                $counts[$table] = db($context)->table($table)->executeModification(
                    "DELETE FROM {$table} WHERE {$joinColumn} IN "
                        . "(SELECT uuid FROM {$parentTable} WHERE tenant_uuid = ?)",
                    [$tenantUuid]
                );
            }

            foreach ($this->existingTenantTables($context) as $table) {
                $counts[$table] = (int) db($context)->table($table)
                    ->where('tenant_uuid', '=', $tenantUuid)
                    ->delete();
            }

            return $counts;
        });
    }

    /**
     * Remaining TENANT-REACHABLE commerce rows for this tenant, per table
     * (verify step, e.g. confirming a `purgeTenant()` call actually reached
     * zero everywhere). Child-table counts are computed via the same
     * parent-join `purgeTenant()` deletes through -- see the class
     * docblock's scope boundary for exactly what "reachable" certifies.
     *
     * @return array<string,int>
     */
    public function countTenantRows(ApplicationContext $context, string $tenantUuid): array
    {
        $counts = [];

        foreach ($this->existingChildTables($context) as $table => $join) {
            [$joinColumn, $parentTable] = $join;
            $counts[$table] = (int) db($context)->table($table)
                ->join($parentTable, "{$table}.{$joinColumn}", '=', "{$parentTable}.uuid")
                ->where("{$parentTable}.tenant_uuid", '=', $tenantUuid)
                ->count();
        }

        foreach ($this->existingTenantTables($context) as $table) {
            $counts[$table] = (int) db($context)->table($table)
                ->where('tenant_uuid', '=', $tenantUuid)
                ->count();
        }

        return $counts;
    }

    /** @return array<string,array{0:string,1:string}> */
    private function existingChildTables(ApplicationContext $context): array
    {
        $tables = [];
        foreach (self::CHILD_TABLES as $table => $join) {
            $parentTable = $join[1];
            if (
                db($context)->getSchemaBuilder()->hasTable($table)
                && db($context)->getSchemaBuilder()->hasTable($parentTable)
            ) {
                $tables[$table] = $join;
            }
        }

        return $tables;
    }

    /** @return list<string> */
    private function existingTenantTables(ApplicationContext $context): array
    {
        $tables = [];
        foreach (DiagnosticsReport::tenantTables() as $table) {
            if (db($context)->getSchemaBuilder()->hasTable($table)) {
                $tables[] = $table;
            }
        }

        return $tables;
    }
}
