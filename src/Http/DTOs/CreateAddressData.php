<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class CreateAddressData implements RequestData
{
    /** @param array<string,mixed> $address same loose shape checkout already accepts */
    public function __construct(
        #[Rule('string|max:64')]
        public readonly ?string $label = null,
        #[Rule('required|array')]
        public readonly array $address = [],
        #[Rule('boolean')]
        public readonly ?bool $is_default_shipping = null,
        #[Rule('boolean')]
        public readonly ?bool $is_default_billing = null,
    ) {
    }
}
