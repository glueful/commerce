<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class AddCartLineData implements RequestData
{
    public function __construct(
        #[Rule('required|string')]
        public readonly string $variant_uuid,
        #[Rule('required|integer')]
        public readonly int $quantity,
    ) {
    }
}
