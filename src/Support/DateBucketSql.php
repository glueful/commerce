<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Support;

/**
 * Driver-aware SQL fragment that formats a timestamp column down to its UTC
 * calendar day (`Y-m-d`), for `GROUP BY`/`SELECT` in the report repositories.
 *
 * Why this exists: cross-driver date functions diverge for anything beyond a
 * plain day truncation -- SQLite's `strftime('%W', ...)` is not an ISO week,
 * and MySQL/PostgreSQL diverge from each other too. Reports therefore only
 * ever bucket in SQL by UTC *day* via this helper; week/month rollups are
 * folded in pure PHP by `Glueful\Extensions\Commerce\Reports\ReportRollup`
 * (unit-tested, driver-independent). No database week/month function is used
 * anywhere in this layer.
 *
 * Mirrors `Glueful\Extensions\Commerce\Support\UtcNowSql`: every column this
 * codebase writes for these timestamps (`placed_at`, `created_at`,
 * `completed_at`) is already a naive UTC string, so no timezone conversion is
 * needed here -- only day-level formatting.
 */
final class DateBucketSql
{
    /**
     * @param string $driver one of the framework's `getDriverName()` values
     *     ('sqlite'|'mysql'|'pgsql'). Not user input -- always sourced from
     *     `db($context)->getDriverName()`, so safe to interpolate directly
     *     into SQL.
     * @param string $column the column (or expression) to format, e.g. a raw
     *     column name or a `report_at`-style derived-table alias. Not user
     *     input -- callers pass a fixed, code-authored identifier, so safe to
     *     interpolate directly into SQL.
     * @throws \InvalidArgumentException for any other driver name.
     */
    public static function dayExpression(string $driver, string $column): string
    {
        return match ($driver) {
            'sqlite' => "strftime('%Y-%m-%d', {$column})",
            'mysql' => "DATE_FORMAT({$column}, '%Y-%m-%d')",
            'pgsql' => "to_char({$column}, 'YYYY-MM-DD')",
            default => throw new \InvalidArgumentException(
                "DateBucketSql::dayExpression(): unsupported database driver '{$driver}'."
            ),
        };
    }

    private function __construct()
    {
    }
}
