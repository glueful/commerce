<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class CreateAddonData implements RequestData
{
    /**
     * `choices` carries no `#[Rule]`: it is select-only, shape- and
     * business-validated in {@see \Glueful\Extensions\Commerce\Catalog\AddonService}
     * (nested-DTO support for arbitrary request arrays is pending -- same temporary
     * substitute documented on {@see ReorderMediaData}).
     *
     * @param list<array<string,mixed>>|null $choices
     */
    public function __construct(
        #[Rule('required|string')]
        public readonly string $name,
        #[Rule('required|in:select,checkbox,text')]
        public readonly string $field_type,
        #[Rule('boolean')]
        public readonly bool $required = false,
        public readonly ?array $choices = null,
        #[Rule('integer')]
        public readonly int $price_delta = 0,
        #[Rule('integer')]
        public readonly ?int $position = null,
        #[Rule('in:active,inactive')]
        public readonly string $status = 'active',
    ) {
    }
}
