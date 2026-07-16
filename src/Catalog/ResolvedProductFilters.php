<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Catalog;

/**
 * The already-resolved, already-tenant-scoped shape of the storefront product
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
    /** @param list<array{attribute_uuid:string, value_slug:string}> $attributePairs */
    public function __construct(
        public readonly ?string $categoryUuid = null,
        public readonly ?string $tagUuid = null,
        public readonly array $attributePairs = [],
    ) {
    }

    /** True when no filter was requested at all -- `listActive()` skips every EXISTS predicate. */
    public function isEmpty(): bool
    {
        return $this->categoryUuid === null && $this->tagUuid === null && $this->attributePairs === [];
    }
}
