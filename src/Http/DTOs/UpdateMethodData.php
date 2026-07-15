<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Documentation-only schema (see {@see UpdateCategoryData}): the controller reads
 * the raw request body directly so only present keys are applied. `kind` is
 * immutable after creation and is therefore not settable here.
 */
final class UpdateMethodData implements RequestData
{
    /** @param array<string,mixed>|null $config */
    public function __construct(
        #[Rule('string')]
        public readonly ?string $label = null,
        public readonly ?array $config = null,
        #[Rule('integer')]
        public readonly ?int $position = null,
        public readonly ?bool $enabled = null,
    ) {
    }
}
