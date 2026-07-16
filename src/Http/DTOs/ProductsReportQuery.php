<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\FromQuery;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * The products report has no `group` (it is a ranked list, not a bucketed
 * series) -- the controller constructs its window via
 * `ReportWindow::fromDates($query->from, $query->to, 'day')` directly.
 */
final class ProductsReportQuery implements RequestData
{
    public function __construct(
        #[FromQuery(description: 'Window start date (inclusive), Y-m-d. Defaults to 29 days before today.')]
        #[Rule('date:Y-m-d')]
        public readonly ?string $from = null,
        #[FromQuery(description: 'Window end date (inclusive), Y-m-d. Defaults to today.')]
        #[Rule('date:Y-m-d')]
        public readonly ?string $to = null,
        #[FromQuery(description: 'Sort field: quantity or revenue. Defaults to revenue.')]
        #[Rule('in:quantity,revenue')]
        public readonly ?string $sort = null,
        #[FromQuery(description: 'Page number.')]
        #[Rule('numeric')]
        public readonly ?int $page = null,
        #[FromQuery(description: 'Items per page, clamped to 100.')]
        #[Rule('numeric')]
        public readonly ?int $per_page = null,
    ) {
    }
}
