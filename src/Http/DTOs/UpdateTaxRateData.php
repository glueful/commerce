<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Documentation-only schema (see {@see UpdateCategoryData}): the controller reads
 * the raw request body directly so only present keys are applied.
 */
final class UpdateTaxRateData implements RequestData
{
    public function __construct(
        #[Rule('string')]
        public readonly ?string $country = null,
        #[Rule('string')]
        public readonly ?string $state = null,
        #[Rule('string')]
        public readonly ?string $postcode_pattern = null,
        #[Rule('integer')]
        public readonly ?int $rate_bps = null,
        #[Rule('string')]
        public readonly ?string $label = null,
        #[Rule('integer')]
        public readonly ?int $priority = null,
        public readonly ?bool $shipping_taxable = null,
        #[Rule('string')]
        public readonly ?string $class = null,
    ) {
    }
}
