<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Support;

/**
 * The single input normalizer for every batched uuid-list catalog read
 * (storefront-v1 Task 1): keeps only values matching the pinned
 * `/\A[A-Za-z0-9]{12}\z/` shape (the schema pins `uuid` as `string(12)` and
 * the framework's NanoID charset is alphanumeric ONLY -- no `_`/`-`), dedupes
 * preserving FIRST occurrence, and caps to the FIRST {@see self::LIMIT}
 * values AFTER dedupe. Malformed values are DROPPED, never rejected -- these
 * are defensive repository reads; strictness (422s) lives at HTTP
 * boundaries. An empty result means the caller must issue NO query.
 */
final class UuidBatch
{
    public const LIMIT = 100;

    private const UUID_PATTERN = '/\A[A-Za-z0-9]{12}\z/';

    /**
     * @param array<mixed> $values
     * @return list<string>
     */
    public static function normalize(array $values): array
    {
        $seen = [];
        $kept = [];
        foreach ($values as $value) {
            if (!is_string($value) || preg_match(self::UUID_PATTERN, $value) !== 1) {
                continue;
            }
            if (isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $kept[] = $value;
            if (count($kept) === self::LIMIT) {
                break;
            }
        }

        return $kept;
    }
}
