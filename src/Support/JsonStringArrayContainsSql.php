<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Support;

/**
 * Exact cross-driver "is this string a member of this JSON string array?" SQL
 * fragment (Layer 6 Global Constraints), used by the storefront product
 * attribute-value filter's correlated `EXISTS` semijoins.
 *
 * Why this exists: the framework's generic `WhereClause::whereJsonContains()`
 * falls back to `CAST(column AS TEXT) LIKE '%value%'` on SQLite and to the same
 * text-`LIKE` shape on PostgreSQL when no JSON path is given -- a search for
 * `red` would therefore also match a stored value of `bred`. Attribute-value
 * membership must be exact, so this helper never delegates to that fallback and
 * instead builds a real membership test per driver:
 * - sqlite: `json_each()` table-valued function, comparing `value` for exact
 *   string equality.
 * - pgsql: the JSON containment operator `@>` against an encoded one-element
 *   array, so containment (not substring) is what's tested.
 * - mysql: `JSON_CONTAINS()` against an encoded JSON scalar -- per MySQL's own
 *   documentation, a scalar candidate against an array target matches an exact
 *   element, not a substring.
 *
 * `$trustedColumn` is interpolated directly into the returned SQL (never bound
 * as a parameter, since column identifiers cannot be bound) -- this method
 * therefore validates it looks like a plain `column` or `table.column`
 * identifier before use, rejecting anything else outright, and then quotes
 * each segment before interpolating it (driver-appropriate: double quotes on
 * sqlite/pgsql, backticks on mysql). Quoting is not optional here: this
 * helper's real caller passes `commerce_product_attributes.values`, and
 * `values` is a reserved word in SQLite's grammar even when qualified with a
 * table name -- `json_each(commerce_product_attributes.values)` is a syntax
 * error, `json_each(commerce_product_attributes."values")` is not. `$value` is
 * always returned as a bound parameter, never interpolated.
 */
final class JsonStringArrayContainsSql
{
    private const IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/';

    /**
     * @param string $driver one of the framework's `getDriverName()` values
     *     ('sqlite'|'mysql'|'pgsql'). Not user input -- always sourced from
     *     `db($context)->getDriverName()`, so safe to interpolate directly
     *     into SQL.
     * @param string $trustedColumn a code-authored `column` or `table.column`
     *     identifier -- NEVER user input. Validated (not merely trusted) and
     *     quoted before being interpolated into the returned SQL.
     * @param string $value the string to test for array membership; always
     *     returned bound, never interpolated.
     * @return array{sql: string, bindings: list<mixed>}
     * @throws \InvalidArgumentException for an invalid column identifier or an
     *     unsupported driver.
     */
    public static function condition(string $driver, string $trustedColumn, string $value): array
    {
        if (preg_match(self::IDENTIFIER_PATTERN, $trustedColumn) !== 1) {
            throw new \InvalidArgumentException(
                "JsonStringArrayContainsSql::condition(): invalid column identifier '{$trustedColumn}'."
            );
        }

        $column = self::quoteIdentifier($driver, $trustedColumn);

        return match ($driver) {
            'sqlite' => [
                'sql' => "EXISTS (SELECT 1 FROM json_each({$column}) WHERE json_each.value = ?)",
                'bindings' => [$value],
            ],
            'pgsql' => [
                'sql' => "{$column}::jsonb @> ?::jsonb",
                'bindings' => [json_encode([$value], JSON_THROW_ON_ERROR)],
            ],
            'mysql' => [
                'sql' => "JSON_CONTAINS({$column}, ?)",
                'bindings' => [json_encode($value, JSON_THROW_ON_ERROR)],
            ],
            default => throw new \InvalidArgumentException(
                "JsonStringArrayContainsSql::condition(): unsupported database driver '{$driver}'."
            ),
        };
    }

    private static function quoteIdentifier(string $driver, string $identifier): string
    {
        $quote = $driver === 'mysql' ? '`' : '"';

        return implode('.', array_map(
            static fn (string $part): string => $quote . $part . $quote,
            explode('.', $identifier)
        ));
    }

    private function __construct()
    {
    }
}
