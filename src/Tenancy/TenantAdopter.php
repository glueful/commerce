<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Support\DiagnosticsReport;

final class TenantAdopter
{
    /**
     * @return array{tenant_uuid: string, tables: array<string,int>}
     */
    public function adopt(ApplicationContext $context, string $tenantUuid): array
    {
        $tenantUuid = trim($tenantUuid);
        if ($tenantUuid === '') {
            throw new \InvalidArgumentException('Tenant uuid is required.');
        }

        return db($context)->transaction(function () use ($context, $tenantUuid): array {
            $tables = $this->existingTenantTables($context);

            foreach ($tables as $table) {
                $mixed = (int) db($context)->table($table)
                    ->whereNotIn('tenant_uuid', ['', $tenantUuid])
                    ->count();
                if ($mixed > 0) {
                    throw new \RuntimeException(
                        "Commerce tenancy adoption refused: {$table} already contains non-sentinel rows."
                    );
                }
            }

            $counts = [];
            foreach ($tables as $table) {
                $count = (int) db($context)->table($table)
                    ->where('tenant_uuid', '=', '')
                    ->count();

                if ($count > 0) {
                    db($context)->table($table)
                        ->where('tenant_uuid', '=', '')
                        ->update(['tenant_uuid' => $tenantUuid]);
                }

                $counts[$table] = $count;
            }

            return ['tenant_uuid' => $tenantUuid, 'tables' => $counts];
        });
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
