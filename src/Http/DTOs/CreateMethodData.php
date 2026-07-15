<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class CreateMethodData implements RequestData
{
    /**
     * `config` shape depends on `kind` (flat/free_over/per_class_table, spec §2) so
     * it carries no Rule attribute here -- per-kind shape and non-negative-integer
     * validation happens in
     * {@see \Glueful\Extensions\Commerce\Shipping\ShippingZoneService::createMethod()}.
     *
     * @param array<string,mixed>|null $config
     */
    public function __construct(
        #[Rule('required|string')]
        public readonly string $kind,
        #[Rule('required|string')]
        public readonly string $label,
        public readonly ?array $config = null,
        #[Rule('integer')]
        public readonly ?int $position = null,
        public readonly ?bool $enabled = null,
    ) {
    }
}
