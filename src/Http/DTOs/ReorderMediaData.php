<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class ReorderMediaData implements RequestData
{
    /**
     * Shape-checks each raw `positions` element in the controller (nested-DTO support
     * for arbitrary request arrays is pending — same temporary substitute documented
     * on {@see CreateRefundData}).
     *
     * `expected_revision` (single-page product editor plan, Task A5): optional CAS
     * guard -- see {@see SetProductCategoriesData} for the full rationale (not
     * repeated here to avoid drift between two copies of the same reasoning),
     * including why this is `#[Rule('integer')]` rather than `'integer|min:0'`.
     *
     * @param list<array{uuid:string,position:int}>|null $positions
     */
    public function __construct(
        public readonly ?array $positions = null,
        #[Rule('integer')]
        public readonly ?int $expected_revision = null,
    ) {
    }
}
