<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Documentation-only schema (see {@see UpdateCategoryData}): the controller reads
 * the raw request body directly so the service can distinguish an absent field
 * (leave unchanged) from an explicit value.
 */
final class UpdateAttributeValueData implements RequestData
{
    public function __construct(
        #[Rule('string')]
        public readonly ?string $slug = null,
        #[Rule('string')]
        public readonly ?string $value = null,
        #[Rule('integer')]
        public readonly ?int $position = null,
    ) {
    }
}
