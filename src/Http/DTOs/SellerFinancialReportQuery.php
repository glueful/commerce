<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\FromQuery;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * The date-window + currency query params shared by the seller and operator
 * financial-report endpoints (design spec §6.1/§6.2, MV3 Task 11). Window
 * defaults/semantics (`from` = today-29d, `to` = today, `from <= to`, span
 * <= 366 days) are resolved by `ReportWindow::fromDates()`, not here --
 * mirrors {@see ReportWindowQuery}'s own division of labor. `currency` is
 * optional: an omitted value falls back to the account's own first ledger
 * currency (or the configured store default when the account has none yet)
 * -- balances/ledger entries are currency-separated (design spec §2.9), so
 * this endpoint is always scoped to exactly one currency per request.
 */
final class SellerFinancialReportQuery implements RequestData
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
        #[FromQuery(description: 'ISO-4217 currency code. Defaults to the account\'s own ledger currency.')]
        #[Rule('string|max:3')]
        public readonly ?string $currency = null,
    ) {
    }
}
