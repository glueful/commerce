<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\FromQuery;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class TaxRateListQuery implements RequestData
{
    public function __construct(
        #[FromQuery(description: 'Filter by ISO-3166 alpha-2 country code.')]
        #[Rule('string')]
        public readonly ?string $country = null,
        #[FromQuery(description: 'Filter by tax class slug.')]
        #[Rule('string')]
        public readonly ?string $class = null,
        #[FromQuery(description: 'Page number.')]
        #[Rule('numeric')]
        public readonly ?int $page = null,
        #[FromQuery(description: 'Items per page, clamped to 100.')]
        #[Rule('numeric')]
        public readonly ?int $per_page = null,
    ) {
    }
}
