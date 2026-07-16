<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class UpdateDiscountData implements RequestData
{
    public function __construct(
        #[Rule('string')]
        public readonly ?string $code = null,
        #[Rule('string')]
        public readonly ?string $type = null,
        #[Rule('integer')]
        public readonly ?int $value = null,
        #[Rule('integer')]
        public readonly ?int $min_subtotal = null,
        #[Rule('integer')]
        public readonly ?int $usage_limit = null,
        #[Rule('boolean')]
        public readonly ?bool $once_per_buyer = null,
        #[Rule('string')]
        public readonly ?string $status = null,
        #[Rule('string')]
        public readonly ?string $starts_at = null,
        #[Rule('string')]
        public readonly ?string $ends_at = null,
    ) {
    }
}
