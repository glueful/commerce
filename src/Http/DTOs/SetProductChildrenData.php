<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Contracts\RequestData;

final class SetProductChildrenData implements RequestData
{
    /**
     * No `required|array` rule: an empty list is a valid, meaningful request
     * ("detach every child from this grouped product"), and the framework's
     * `required` rule treats an empty array as absent. Shape and business rules
     * are validated in {@see CatalogService::setProductChildren()} instead,
     * mirroring SetProductCategoriesData.
     *
     * @param list<string>|null $child_uuids ordered
     */
    public function __construct(
        public readonly ?array $child_uuids = null,
    ) {
    }
}
