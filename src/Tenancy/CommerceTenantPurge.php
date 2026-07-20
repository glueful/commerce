<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Support\DiagnosticsReport;

/**
 * Commerce-Slice-1 Task 3: hard-deletes every commerce row for a tenant.
 * Mirrors {@see TenantAdopter}'s style/transaction conventions and, like it,
 * iterates {@see DiagnosticsReport::tenantTables()} -- commerce keeps
 * table-list ownership, so this stays in lockstep with adoption's own list
 * with no separate table inventory to drift.
 *
 * Data-destruction code: `purgeTenant()` is a permanent, irreversible delete
 * with no soft-delete/undo. There is no DB-level cascade in this schema (no
 * foreign keys are declared anywhere in the commerce migrations), so this
 * intentionally only reaches the tenant-scoped tables in `tenantTables()` --
 * the same set adoption rekeys. Non-tenant-scoped child tables excluded from
 * that list (e.g. `commerce_order_lines`, `commerce_cart_lines`,
 * `commerce_order_events`) carry no `tenant_uuid` column to filter on and are
 * NOT reached by this purge; see the CHANGELOG entry for this release.
 */
final class CommerceTenantPurge
{
    /**
     * Delete every commerce row for this tenant, across every table in
     * {@see DiagnosticsReport::tenantTables()}, inside a single transaction.
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
            foreach ($this->existingTenantTables($context) as $table) {
                $counts[$table] = (int) db($context)->table($table)
                    ->where('tenant_uuid', '=', $tenantUuid)
                    ->delete();
            }

            return $counts;
        });
    }

    /**
     * Remaining commerce rows for this tenant, per table (verify step, e.g.
     * confirming a `purgeTenant()` call actually reached zero everywhere).
     *
     * @return array<string,int>
     */
    public function countTenantRows(ApplicationContext $context, string $tenantUuid): array
    {
        $counts = [];
        foreach ($this->existingTenantTables($context) as $table) {
            $counts[$table] = (int) db($context)->table($table)
                ->where('tenant_uuid', '=', $tenantUuid)
                ->count();
        }

        return $counts;
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
