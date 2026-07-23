<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
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
     * `expected_revision` (single-page product editor plan, Task A5): optional CAS
     * guard -- see {@see SetProductCategoriesData} for the full rationale (not
     * repeated here to avoid drift between two copies of the same reasoning),
     * including why this is `#[Rule('integer')]` rather than `'integer|min:0'`.
     *
     * @param list<string>|null $child_uuids ordered
     */
    public function __construct(
        public readonly ?array $child_uuids = null,
        #[Rule('integer')]
        public readonly ?int $expected_revision = null,
    ) {
    }
}
