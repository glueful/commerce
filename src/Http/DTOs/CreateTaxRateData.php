<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class CreateTaxRateData implements RequestData
{
    public function __construct(
        #[Rule('required|string')]
        public readonly string $country = '',
        #[Rule('string')]
        public readonly ?string $state = null,
        #[Rule('string')]
        public readonly ?string $postcode_pattern = null,
        #[Rule('required|integer')]
        public readonly int $rate_bps = 0,
        #[Rule('required|string')]
        public readonly string $label = '',
        #[Rule('integer')]
        public readonly ?int $priority = null,
        public readonly ?bool $shipping_taxable = null,
        #[Rule('string')]
        public readonly ?string $class = null,
    ) {
    }
}
