<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

/**
 * The already-resolved, already-tenant-scoped shape of a product list's filters: category and tag
 * LISTS matched any-of (1.14.0; the storefront list passes one-item lists), attribute pairs, and
 * the on-sale / in-stock conditions. Originally the storefront product
 * list's `category`/`tag`/`attributes` query filters (Layer 6 Global
 * Constraints): a slug is only ever meaningful to a human/client, so every
 * slug is resolved to its uuid exactly once, up front, in
 * `Http\Storefront\ProductController::index()` -- via one batched
 * tenant-scoped lookup per vocabulary -- before this value is built. Passed
 * ONCE to {@see ProductRepository::listActive()}, which applies the identical
 * predicate builder to both its count and row queries.
 *
 * `attributePairs` entries are already canonical `{attribute_uuid, value_slug}`
 * pairs (see {@see AttributeRepository::findPairsBySlugs()}) -- the repository
 * never re-resolves a slug, it only correlates `attribute_uuid` and tests
 * `value_slug` membership via {@see \Glueful\Extensions\Commerce\Support\JsonStringArrayContainsSql}.
 */
final class ResolvedProductFilters
{
    /**
     * @param list<string> $categoryUuids products in ANY of these categories
     * @param list<string> $tagUuids products with ANY of these tags (ANDed with the categories)
     * @param list<array{attribute_uuid:string, value_slug:string}> $attributePairs
     * @param bool $onSale at least one active variant priced below its compare-at price
     * @param bool $inStock at least one active variant untracked, or tracked above zero
     */
    public function __construct(
        public readonly array $categoryUuids = [],
        public readonly array $tagUuids = [],
        public readonly array $attributePairs = [],
        public readonly bool $onSale = false,
        public readonly bool $inStock = false,
    ) {
    }

    /** True when no filter was requested at all -- `listActive()` skips every EXISTS predicate. */
    public function isEmpty(): bool
    {
        return $this->categoryUuids === [] && $this->tagUuids === [] && $this->attributePairs === []
            && !$this->onSale && !$this->inStock;
    }
}
