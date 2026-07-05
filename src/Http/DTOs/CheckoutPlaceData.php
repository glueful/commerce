<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class CheckoutPlaceData implements RequestData
{
    /**
     * @param array<string,mixed> $buyer
     * @param array<string,mixed> $addresses
     */
    public function __construct(
        #[Rule('required|array')]
        public readonly array $buyer = [],
        #[Rule('required|array')]
        public readonly array $addresses = [],
        #[Rule('string')]
        public readonly ?string $shipping_method = null,
    ) {
    }
}
