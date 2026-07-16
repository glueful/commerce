<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\FromQuery;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * The shared date-window query params for sales/customers reports. Defaults
 * (`from` = today-29d, `to` = today) and window semantics (`from <= to`,
 * span <= 366 days, `Glueful\Extensions\Commerce\Reports\ReportWindow`) are
 * resolved by `ReportWindow::fromQuery()`, not here -- this DTO only shapes
 * and format-validates the raw request input.
 */
final class ReportWindowQuery implements RequestData
{
    public function __construct(
        #[FromQuery(description: 'Window start date (inclusive), Y-m-d. Defaults to 29 days before today.')]
        #[Rule('date:Y-m-d')]
        public readonly ?string $from = null,
        #[FromQuery(description: 'Window end date (inclusive), Y-m-d. Defaults to today.')]
        #[Rule('date:Y-m-d')]
        public readonly ?string $to = null,
        #[FromQuery(description: 'Rollup granularity: day, week, or month. Defaults to day.')]
        #[Rule('in:day,week,month')]
        public readonly ?string $group = null,
    ) {
    }
}
