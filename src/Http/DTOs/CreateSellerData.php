<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class CreateSellerData implements RequestData
{
    /** @param array<string,mixed>|null $metadata */
    public function __construct(
        #[Rule('required|string')]
        public readonly string $slug = '',
        #[Rule('required|string')]
        public readonly string $name = '',
        #[Rule('array')]
        public readonly ?array $metadata = null,
        #[Rule('required|string')]
        public readonly string $owner_user_uuid = '',
    ) {
    }
}
