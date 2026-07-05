<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class UpdateVariantData implements RequestData
{
    /** @param array<string,mixed>|null $option_values */
    public function __construct(
        #[Rule('string')]
        public readonly ?string $sku = null,
        #[Rule('array')]
        public readonly ?array $option_values = null,
        #[Rule('integer')]
        public readonly ?int $price = null,
        #[Rule('integer')]
        public readonly ?int $compare_at_price = null,
        #[Rule('string')]
        public readonly ?string $currency = null,
        #[Rule('string')]
        public readonly ?string $status = null,
    ) {
    }
}
