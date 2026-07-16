<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Documentation-only schema (see {@see UpdateCategoryData}): the controller reads
 * the raw request body directly. `slug` is deliberately absent here -- it is
 * immutable after creation -- and
 * {@see \Glueful\Extensions\Commerce\Shipping\ShippingClassService::update()}
 * rejects a `slug` key present anywhere in that raw payload with 422 rather than
 * silently ignoring it (spec §6).
 */
final class UpdateShippingClassData implements RequestData
{
    public function __construct(
        #[Rule('string')]
        public readonly ?string $name = null,
    ) {
    }
}
