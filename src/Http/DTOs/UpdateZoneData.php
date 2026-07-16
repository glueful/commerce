<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Documentation-only schema (see {@see UpdateCategoryData}): the controller reads
 * the raw request body directly so only present keys (name, position) are applied.
 */
final class UpdateZoneData implements RequestData
{
    public function __construct(
        #[Rule('string')]
        public readonly ?string $name = null,
        #[Rule('integer')]
        public readonly ?int $position = null,
    ) {
    }
}
