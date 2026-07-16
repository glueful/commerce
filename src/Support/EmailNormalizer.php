<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Support;

/**
 * The single PHP-side implementation of "normalized email" used everywhere this
 * codebase groups or matches guest orders by email (design spec §7: customer
 * aggregation grouping key, `commerce:customers:link-guests` exact-match guard).
 * MUST byte-for-byte match the SQL-side `LOWER(TRIM(email))` expression
 * ({@see \Glueful\Extensions\Commerce\Customers\CustomerAggregationRepository})
 * so a value normalized here always lands in the same group/row a grouped query
 * computed independently.
 *
 * Two deliberate departures from the "obvious" PHP idiom, both verified against
 * a real sqlite:memory: connection and a live PostgreSQL instance:
 * - `trim($email, ' ')` (NOT bare `trim()`): SQL `TRIM(x)` with no explicit
 *   characters strips only the space character (0x20) on every supported
 *   driver (sqlite/mysql/pgsql). Bare PHP `trim()` also strips tabs/newlines/
 *   null/vertical-tab, which SQL's `TRIM()` does not -- using bare `trim()`
 *   here would silently diverge from the SQL-side expression for an email
 *   with leading/trailing whitespace other than a plain space.
 * - `strtolower()` (NOT `mb_strtolower()`): SQLite's `LOWER()` is ASCII-only by
 *   default. Matching that (rather than doing full Unicode case-folding here)
 *   keeps this helper's output identical to what SQLite computes; PostgreSQL's
 *   locale-aware `LOWER()` agrees with plain ASCII lowercasing for the ASCII
 *   email addresses this codebase otherwise assumes throughout. Non-ASCII
 *   case-folding is out of scope.
 */
final class EmailNormalizer
{
    public static function normalize(string $email): string
    {
        return strtolower(trim($email, ' '));
    }
}
