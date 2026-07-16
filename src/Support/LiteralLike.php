<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Support;

/**
 * The single normalizer for every admin-list `q` literal-substring filter
 * (Layer 6 Global Constraints): matching is case-insensitive, and `%`/`_` in
 * user input are LITERAL characters, never SQL LIKE wildcards -- a search for
 * `50%` must not match every code, and a search for `_` must not match every
 * single character.
 *
 * `!` is the escape character (never collides with `%`/`_`, unlike the more
 * conventional `\`, which some drivers already treat specially inside string
 * literals). It MUST be escaped first: escaping `%`/`_` before `!` would
 * double-escape any `!` that step just introduced, corrupting the pattern.
 *
 * Consumers query `LOWER(<allowlisted-column>) LIKE ? ESCAPE '!'` with the
 * pattern this method returns bound as the sole placeholder. The `ESCAPE '!'`
 * clause is standard SQL, written identically across sqlite/mysql/pgsql, so it
 * belongs directly in each caller's SQL text -- never parameterized.
 */
final class LiteralLike
{
    public static function pattern(string $value): string
    {
        $escaped = str_replace('!', '!!', strtolower($value));
        $escaped = str_replace(['%', '_'], ['!%', '!_'], $escaped);

        return '%' . $escaped . '%';
    }
}
