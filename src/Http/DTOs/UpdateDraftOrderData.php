<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * OpenAPI request-body schema for `PATCH /commerce/admin/orders/drafts/{uuid}`
 * (admin-order-creation cycle 2, Task 9). DOCUMENTATION ONLY -- see
 * {@see CreateDraftOrderData} for why the controller reads raw input.
 *
 * `shipping_method` is a METHOD ID that must appear in a live server quote --
 * an amount is never accepted (design Ruling 5). `discount_code` is validated
 * here but consumed only at finalize. `expected_revision` is the optional
 * optimistic-concurrency assertion; omitting it still compare-and-sets against
 * the revision read at the start of the request.
 */
final class UpdateDraftOrderData implements RequestData
{
    /** @param array<string,mixed>|null $addresses */
    public function __construct(
        #[Rule('string')]
        public readonly ?string $fulfillment_mode = null,
        #[Rule('string')]
        public readonly ?string $customer_name = null,
        #[Rule('string')]
        public readonly ?string $email = null,
        #[Rule('string')]
        public readonly ?string $phone = null,
        #[Rule('string')]
        public readonly ?string $user_uuid = null,
        public readonly ?array $addresses = null,
        #[Rule('string')]
        public readonly ?string $shipping_method = null,
        #[Rule('string')]
        public readonly ?string $discount_code = null,
        #[Rule('numeric')]
        public readonly ?int $expected_revision = null,
    ) {
    }
}
