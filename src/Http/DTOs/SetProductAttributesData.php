<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

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
     * @param list<array<string,mixed>>|null $attributes
     */
    public function __construct(
        public readonly ?array $attributes = null,
    ) {
    }
}
