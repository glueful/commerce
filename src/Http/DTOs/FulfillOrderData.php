<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class FulfillOrderData implements RequestData
{
    public function __construct(
        #[Rule('string')]
        public readonly ?string $tracking_ref = null,
    ) {
    }
}
