<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\FromQuery;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class ProductOrdersQuery implements RequestData
{
    public function __construct(
        #[FromQuery(description: 'Summary window in days, clamped to 1-365. Default 30.')]
        #[Rule('numeric')]
        public readonly ?int $days = null,
        #[FromQuery(description: 'Recent orders returned, clamped to 1-20. Default 5.')]
        #[Rule('numeric')]
        public readonly ?int $per_page = null,
    ) {
    }
}
