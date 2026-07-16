<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\FromQuery;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class DiscountListQuery implements RequestData
{
    public function __construct(
        #[FromQuery(description: 'Filter by discount status.')]
        #[Rule('string')]
        public readonly ?string $status = null,
        #[FromQuery(description: 'Case-insensitive literal substring match on discount code.')]
        #[Rule('string')]
        public readonly ?string $q = null,
        #[FromQuery(description: 'Page number.')]
        #[Rule('numeric')]
        public readonly ?int $page = null,
        #[FromQuery(description: 'Items per page, clamped to 100.')]
        #[Rule('numeric')]
        public readonly ?int $per_page = null,
    ) {
    }
}
