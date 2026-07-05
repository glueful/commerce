<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class UpdateCartLineData implements RequestData
{
    public function __construct(
        #[Rule('required|integer')]
        public readonly int $quantity,
    ) {
    }
}
