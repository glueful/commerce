<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Documentation-only schema (see {@see UpdateDownloadData}): the controller
 * reads the raw request body directly so the service can distinguish an
 * absent field (leave unchanged) from an explicitly supplied one -- this
 * matters most for `is_default_shipping`/`is_default_billing`, where absent
 * must never be coerced into `false`.
 */
final class UpdateAddressData implements RequestData
{
    /** @param array<string,mixed>|null $address same loose shape checkout already accepts */
    public function __construct(
        #[Rule('string|max:64')]
        public readonly ?string $label = null,
        #[Rule('array')]
        public readonly ?array $address = null,
        #[Rule('boolean')]
        public readonly ?bool $is_default_shipping = null,
        #[Rule('boolean')]
        public readonly ?bool $is_default_billing = null,
    ) {
    }
}
