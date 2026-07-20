<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class FulfillSellerOrderData implements RequestData
{
    public function __construct(
        #[Rule('string|max:96')]
        public readonly ?string $carrier = null,
        #[Rule('string|max:191')]
        public readonly ?string $tracking_number = null,
        #[Rule('string|max:512')]
        public readonly ?string $tracking_url = null,
    ) {
    }
}
