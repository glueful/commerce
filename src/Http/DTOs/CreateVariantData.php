<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class CreateVariantData implements RequestData
{
    /** @param array<string,mixed> $option_values */
    public function __construct(
        #[Rule('required|string')]
        public readonly string $sku,
        #[Rule('array')]
        public readonly array $option_values = [],
        #[Rule('required|integer')]
        public readonly int $price = 0,
        #[Rule('integer')]
        public readonly ?int $compare_at_price = null,
        #[Rule('required|string')]
        public readonly string $currency = '',
        #[Rule('string')]
        public readonly ?string $status = null,
    ) {
    }
}
