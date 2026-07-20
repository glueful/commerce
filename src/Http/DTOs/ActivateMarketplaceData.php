<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * `POST /commerce/admin/marketplace/activate` request body (design spec
 * §2.2). `default_seller_uuid` is optional: when provided, every currently
 * -unassigned live product is bulk-adopted by that seller inside the
 * activation transaction; when omitted, activation 409s listing the
 * unassigned count unless every live product already has a seller.
 */
final class ActivateMarketplaceData implements RequestData
{
    public function __construct(
        #[Rule('string')]
        public readonly ?string $default_seller_uuid = null,
    ) {
    }
}
