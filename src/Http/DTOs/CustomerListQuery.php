<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\FromQuery;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class CustomerListQuery implements RequestData
{
    public function __construct(
        #[FromQuery(description: 'Filter by email substring.')]
        #[Rule('string')]
        public readonly ?string $email = null,
        #[FromQuery(description: 'Sort field: last_order_at or total_spent.')]
        #[Rule('in:last_order_at,total_spent')]
        public readonly ?string $sort = null,
        #[FromQuery(description: 'Sort direction: asc or desc.')]
        #[Rule('in:asc,desc')]
        public readonly ?string $direction = null,
        #[FromQuery(description: 'Page number.')]
        #[Rule('numeric')]
        public readonly ?int $page = null,
        #[FromQuery(description: 'Items per page, clamped to 100.')]
        #[Rule('numeric')]
        public readonly ?int $per_page = null,
    ) {
    }
}
