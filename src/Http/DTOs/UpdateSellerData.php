<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Documentation-only schema (see {@see UpdateShippingClassData}): the
 * controller reads the raw request body directly. `slug` is deliberately
 * absent here -- it is immutable after creation -- and
 * {@see \Glueful\Extensions\Commerce\Marketplace\SellerService::update()}
 * rejects a `slug` key present anywhere in that raw payload with 422 rather
 * than silently ignoring it.
 */
final class UpdateSellerData implements RequestData
{
    /** @param array<string,mixed>|null $metadata */
    public function __construct(
        #[Rule('string')]
        public readonly ?string $name = null,
        #[Rule('array')]
        public readonly ?array $metadata = null,
    ) {
    }
}
