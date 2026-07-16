<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Contracts\RequestData;

final class SetProductTagsData implements RequestData
{
    /**
     * No `required|array` rule: an empty list is a valid, meaningful request
     * ("remove every tag from this product"), and the framework's `required` rule
     * treats an empty array as absent. Shape is validated in
     * {@see TagService::setProductTags()} instead.
     *
     * @param list<string>|null $tag_uuids
     */
    public function __construct(
        public readonly ?array $tag_uuids = null,
    ) {
    }
}
