<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Documentation-only schema (see {@see UpdateMediaData}): the controller reads the
 * raw request body directly so it can distinguish an absent `parent_uuid` (leave
 * unchanged) from an explicit `null` (move the category to the root).
 */
final class UpdateCategoryData implements RequestData
{
    public function __construct(
        #[Rule('string')]
        public readonly ?string $slug = null,
        #[Rule('string')]
        public readonly ?string $name = null,
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
