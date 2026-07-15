<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Contracts\RequestData;

final class SetProductCategoriesData implements RequestData
{
    /**
     * No `required|array` rule: an empty list is a valid, meaningful request
     * ("remove every category from this product"), and the framework's `required`
     * rule treats an empty array as absent. Shape is validated in
     * {@see CategoryService::setProductCategories()} instead.
     *
     * @param list<string>|null $category_uuids
     */
    public function __construct(
        public readonly ?array $category_uuids = null,
    ) {
    }
}
