<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class SetProductAttributesData implements RequestData
{
    /**
     * No `required|array` rule: an empty list is a valid, meaningful request
     * ("remove every attribute from this product"), and the framework's `required`
     * rule treats an empty array as absent. Each row is
     * `{attribute_uuid?: string|null, name?: string|null, values?: list<string>,
     * used_for_variants?: bool, visible?: bool, position?: int}` -- shape and
     * business validation both happen in {@see AttributeService::setProductAttributes()}
     * (nested-DTO support for arbitrary request arrays is pending -- same temporary
     * substitute documented on {@see ReorderMediaData}).
     *
     * `expected_revision` (single-page product editor plan, Task A5): optional CAS
     * guard -- see {@see SetProductCategoriesData} for the full rationale (not
     * repeated here to avoid drift between two copies of the same reasoning),
     * including why this is `#[Rule('integer')]` rather than `'integer|min:0'`.
     *
     * @param list<array<string,mixed>>|null $attributes
     */
    public function __construct(
        public readonly ?array $attributes = null,
        #[Rule('integer')]
        public readonly ?int $expected_revision = null,
    ) {
    }
}
