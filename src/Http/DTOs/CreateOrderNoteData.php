<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class CreateOrderNoteData implements RequestData
{
    public function __construct(
        #[Rule('required|string|min:1|max:4000')]
        public readonly string $body,
        #[Rule('required|in:internal,customer')]
        public readonly string $visibility,
        #[Rule('boolean')]
        public readonly bool $notify = false,
    ) {
    }
}
