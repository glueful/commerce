<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * OpenAPI request-body schema for `POST /commerce/admin/orders/drafts`
 * (admin-order-creation cycle 2, Task 9).
 *
 * DOCUMENTATION ONLY -- the controller reads raw input instead of hydrating
 * this, because every field is presence-sensitive (an explicit `null` clears,
 * an absent key leaves the stored value alone) and a constructor-hydrated DTO
 * cannot distinguish the two. Validation is
 * {@see \Glueful\Extensions\Commerce\Orders\DraftOrderService}'s, which owns the
 * phone contract and the user-attachment rules; same convention as
 * {@see UpdateProductData}.
 *
 * Every field is optional: the default is a fully ANONYMOUS `in_store` draft
 * (design Ruling 4). No placeholder email is ever invented.
 */
final class CreateDraftOrderData implements RequestData
{
    public function __construct(
        #[Rule('string')]
        public readonly ?string $fulfillment_mode = null,
        #[Rule('string')]
        public readonly ?string $customer_name = null,
        #[Rule('string')]
        public readonly ?string $email = null,
        /** International form, e.g. `+15550109999`; never an identity lookup. */
        #[Rule('string')]
        public readonly ?string $phone = null,
        #[Rule('string')]
        public readonly ?string $user_uuid = null,
    ) {
    }
}
