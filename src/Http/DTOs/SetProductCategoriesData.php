<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class SetProductCategoriesData implements RequestData
{
    /**
     * No `required|array` rule: an empty list is a valid, meaningful request
     * ("remove every category from this product"), and the framework's `required`
     * rule treats an empty array as absent. Shape is validated in
     * {@see CategoryService::setProductCategories()} instead.
     *
     * `expected_revision` (single-page product editor plan, Task A5): optional CAS
     * guard against {@see CategoryService::setProductCategories()}'s
     * `catalog_revision` snapshot -- absent preserves today's unguarded
     * serialize-only behavior byte-for-byte. `#[Rule('integer')]` only proves
     * SHAPE (never a TypeError at construction on a malformed value); the
     * non-negative range check happens in the service, the same place every
     * other field on this DTO is validated -- not `#[Rule('integer|min:0')]`,
     * whose `min` keyword maps to the framework's string-length rule and would
     * reject every integer value including valid ones.
     *
     * @param list<string>|null $category_uuids
     */
    public function __construct(
        public readonly ?array $category_uuids = null,
        #[Rule('integer')]
        public readonly ?int $expected_revision = null,
    ) {
    }
}
