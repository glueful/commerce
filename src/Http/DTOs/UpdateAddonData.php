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
final class UpdateAddonData implements RequestData
{
    /** @param list<array<string,mixed>>|null $choices */
    public function __construct(
        #[Rule('string')]
        public readonly ?string $name = null,
        #[Rule('in:select,checkbox,text')]
        public readonly ?string $field_type = null,
        #[Rule('boolean')]
        public readonly ?bool $required = null,
        public readonly ?array $choices = null,
        #[Rule('integer')]
        public readonly ?int $price_delta = null,
        #[Rule('integer')]
        public readonly ?int $position = null,
        #[Rule('in:active,inactive')]
        public readonly ?string $status = null,
    ) {
    }
}
