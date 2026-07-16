<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Support;

/**
 * Driver-aware `SELECT ... AS bucket, ... AS from_at, ... AS to_at` row
 * expression, one instance per bound bucket, joined together with
 * `UNION ALL` to build a small literal "boundary table" of PHP-generated
 * bucket boundaries (`ReportWindow::bucketBounds()`).
 *
 * Why this exists: the customer report's week/month bucket classification
 * needs a set of driver-portable boundary rows to `LEFT JOIN`/compare
 * timestamps against, without ever invoking a database week/month function.
 * SQLite compares bound string parameters against `timestamp`-shaped columns
 * lexicographically without any cast; MySQL and PostgreSQL both require an
 * explicit cast so the bound string parameter is treated as a temporal value
 * (not compared as text) when joined against real datetime/timestamp
 * columns.
 */
final class ReportBoundarySql
{
    /**
     * @param string $driver one of the framework's `getDriverName()` values
     *     ('sqlite'|'mysql'|'pgsql'). Not user input -- always sourced from
     *     `db($context)->getDriverName()`, so safe to interpolate directly
     *     into SQL.
     * @throws \InvalidArgumentException for any other driver name.
     */
    public static function rowExpression(string $driver): string
    {
        return match ($driver) {
            'sqlite' => 'SELECT ? AS bucket, ? AS from_at, ? AS to_at',
            'mysql' => 'SELECT ? AS bucket, CAST(? AS DATETIME) AS from_at, CAST(? AS DATETIME) AS to_at',
            'pgsql' => 'SELECT ? AS bucket, CAST(? AS timestamp) AS from_at, CAST(? AS timestamp) AS to_at',
            default => throw new \InvalidArgumentException(
                "ReportBoundarySql::rowExpression(): unsupported database driver '{$driver}'."
            ),
        };
    }

    private function __construct()
    {
    }
}
