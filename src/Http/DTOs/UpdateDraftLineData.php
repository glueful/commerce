<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * OpenAPI request-body schema for
 * `PATCH /commerce/admin/orders/drafts/{uuid}/lines/{lineUuid}`
 * (admin-order-creation cycle 2, Task 9). DOCUMENTATION ONLY -- see
 * {@see CreateDraftOrderData}.
 *
 * The line's `variant_uuid` is immutable: a different purchasable is a
 * different line. Both `quantity` and `addons` re-resolve through the shared
 * resolver, and the line's UUID is stable across every such edit.
 */
final class UpdateDraftLineData implements RequestData
{
    /** @param list<array<string,mixed>>|null $addons */
    public function __construct(
        #[Rule('numeric')]
        public readonly ?int $quantity = null,
        public readonly ?array $addons = null,
        #[Rule('numeric')]
        public readonly ?int $expected_revision = null,
    ) {
    }
}
