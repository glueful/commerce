<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class AddCartLineData implements RequestData
{
    /**
     * `addons` carries no `#[Rule]`: shape and business validation both happen in
     * {@see \Glueful\Extensions\Commerce\Cart\AddonSnapshot::build()} (nested-DTO
     * support for arbitrary request arrays is pending -- same temporary substitute
     * documented on {@see ReorderMediaData}).
     *
     * @param list<array<string,mixed>>|null $addons
     */
    public function __construct(
        #[Rule('required|string')]
        public readonly string $variant_uuid,
        #[Rule('required|integer')]
        public readonly int $quantity,
        public readonly ?array $addons = null,
    ) {
    }
}
