<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class CreateCategoryData implements RequestData
{
    public function __construct(
        #[Rule('required|string')]
        public readonly string $slug,
        #[Rule('required|string')]
        public readonly string $name,
        #[Rule('string')]
        public readonly ?string $description = null,
        #[Rule('string')]
        public readonly ?string $parent_uuid = null,
        #[Rule('integer')]
        public readonly ?int $position = null,
        #[Rule('string')]
        public readonly ?string $blob_uuid = null,
    ) {
    }
}
