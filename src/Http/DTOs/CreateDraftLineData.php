<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * OpenAPI request-body schema for
 * `POST /commerce/admin/orders/drafts/{uuid}/lines` (admin-order-creation
 * cycle 2, Task 9). DOCUMENTATION ONLY -- see {@see CreateDraftOrderData}.
 *
 * `addons` are RAW selections resolved against the product's CURRENT active
 * addon definitions by
 * {@see \Glueful\Extensions\Commerce\Orders\PurchasableLineResolver::resolveSelections()};
 * option values are never caller-supplied (they are always derived from the
 * resolved variant).
 */
final class CreateDraftLineData implements RequestData
{
    /** @param list<array<string,mixed>>|null $addons */
    public function __construct(
        #[Rule('required|string')]
        public readonly string $variant_uuid = '',
        #[Rule('numeric')]
        public readonly int $quantity = 1,
        public readonly ?array $addons = null,
        #[Rule('numeric')]
        public readonly ?int $expected_revision = null,
    ) {
    }
}
