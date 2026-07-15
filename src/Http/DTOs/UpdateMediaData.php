<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class UpdateMediaData implements RequestData
{
    public function __construct(
        #[Rule('in:cover,gallery')]
        public readonly ?string $role = null,
        #[Rule('string|max:255')]
        public readonly ?string $alt = null,
        #[Rule('integer')]
        public readonly ?int $position = null,
    ) {
    }
}
