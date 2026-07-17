<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * `POST /commerce/admin/marketplace/products/{uuid}/assign` request body
 * (design spec §2.7): the ONE operator attribution operation covering both
 * adoption (the product currently has no seller) and transfer (it already
 * has one) -- {@see \Glueful\Extensions\Commerce\Marketplace\SellerAttributionService::assign()}
 * decides which it is from the product's current state, never from this
 * body.
 */
final class AssignSellerData implements RequestData
{
    public function __construct(
        #[Rule('required|string')]
        public readonly string $seller_uuid = '',
    ) {
    }
}
