<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Support;

/**
 * Driver-aware SQL fragment for "the database server's current time, pinned to UTC" --
 * meant to be embedded directly into raw SQL (a `SET`/`WHERE` clause built via
 * `executeModification()`), not bound as a parameter.
 *
 * Why this exists: the literal `CURRENT_TIMESTAMP` keyword is UTC by definition on
 * SQLite and MySQL, but on PostgreSQL it is a `timestamptz` value. Comparing or
 * assigning a `timestamptz` to this codebase's naive `timestamp` columns (every
 * `expires_at`/`last_minted_at`/etc. -- see migration 008) triggers an implicit cast to
 * the SESSION's local timezone, not UTC. Under any non-UTC PostgreSQL session that
 * silently shifts the effective comparison/assignment by the session's UTC offset --
 * e.g. an expiry check can fail OPEN (grant a few extra hours of access) purely because
 * an operator's session timezone isn't UTC. Every value this codebase writes into those
 * columns is UTC (`gmdate()`), so the DB-time expression used against them must be too,
 * independent of session timezone.
 *
 * Precedent: `QueryBuilder::explain()` already branches on `getDriverName()` to build a
 * driver-specific SQL prefix string directly (not a bound parameter) -- this follows the
 * same shape for a driver-specific SQL keyword/expression.
 */
final class UtcNowSql
{
    /**
     * @param string $driver one of the framework's `getDriverName()` values
     *     ('sqlite'|'mysql'|'pgsql'). Not user input -- always sourced from
     *     `db($context)->getDriverName()`, so safe to interpolate directly into SQL.
     * @throws \InvalidArgumentException for any other driver name.
     */
    public static function expression(string $driver): string
    {
        return match ($driver) {
            'sqlite' => 'CURRENT_TIMESTAMP',
            'mysql' => 'UTC_TIMESTAMP()',
            'pgsql' => "(NOW() AT TIME ZONE 'UTC')",
            default => throw new \InvalidArgumentException(
                "UtcNowSql::expression(): unsupported database driver '{$driver}'."
            ),
        };
    }
}
