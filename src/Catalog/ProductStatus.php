<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

/**
 * Closed vocabulary for `commerce_products.status` (design spec, Layer 6 §2):
 * the single declaration consumed by product create, ordinary update, list
 * filtering, and bulk status. Unknown values are rejected at the write/query
 * boundary and are never persisted -- this class has no relationship to
 * `deleted_at`/soft delete, which is an orthogonal tombstone concept covered by
 * {@see ProductRepository}'s live-vs-history reads.
 */
final class ProductStatus
{
    private const VALUES = ['draft', 'active', 'archived'];

    /** @return list<string> */
    public static function all(): array
    {
        return self::VALUES;
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::VALUES, true);
    }
}
