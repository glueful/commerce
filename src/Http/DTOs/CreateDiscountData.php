<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class CreateDiscountData implements RequestData
{
    public function __construct(
        #[Rule('required|string')]
        public readonly string $code,
        #[Rule('required|string')]
        public readonly string $type,
        #[Rule('required|integer')]
        public readonly int $value,
        #[Rule('integer')]
        public readonly ?int $min_subtotal = null,
        #[Rule('integer')]
        public readonly ?int $usage_limit = null,
        #[Rule('boolean')]
        public readonly bool $once_per_buyer = false,
        #[Rule('string')]
        public readonly string $status = 'active',
        #[Rule('string')]
        public readonly ?string $starts_at = null,
        #[Rule('string')]
        public readonly ?string $ends_at = null,
    ) {
    }
}
