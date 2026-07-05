<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class CheckoutQuoteData implements RequestData
{
    /** @param array<string,mixed> $shipping_address */
    public function __construct(
        #[Rule('array')]
        public readonly array $shipping_address = [],
        #[Rule('string')]
        public readonly ?string $shipping_method = null,
    ) {
    }
}
