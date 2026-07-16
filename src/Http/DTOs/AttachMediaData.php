<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class AttachMediaData implements RequestData
{
    public function __construct(
        #[Rule('required|string')]
        public readonly string $blob_uuid,
        #[Rule('in:cover,gallery')]
        public readonly string $role = 'gallery',
        #[Rule('string|max:255')]
        public readonly ?string $alt = null,
        #[Rule('string')]
        public readonly ?string $variant_uuid = null,
    ) {
    }
}
