<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

/**
 * Closed vocabulary for `commerce_products.type` (design spec, Layer 6 §2): the
 * single declaration consumed by product create, ordinary update, list
 * filtering, and bulk status. Unknown values are rejected at the write/query
 * boundary and are never persisted. Absorbed from {@see CatalogService}'s
 * former private `TYPES` constant -- the PURCHASABLE subset (`physical`,
 * `digital`) is a CatalogService-internal business rule, not part of this
 * shared vocabulary, and stays there.
 */
final class ProductType
{
    private const VALUES = ['physical', 'digital', 'external', 'grouped'];

    /** @return list<string> */
    public static function all(): array
    {
        return self::VALUES;
    }

    public static function isValid(string $type): bool
    {
        return in_array($type, self::VALUES, true);
    }
}
