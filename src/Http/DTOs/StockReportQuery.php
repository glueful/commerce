<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\FromQuery;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Stock is point-in-time (no `from`/`to`). `threshold`'s `0..100000` range
 * is resolved and defended by `Glueful\Extensions\Commerce\Reports\StockThreshold::resolve()`,
 * not this DTO -- the framework's string-based `#[Rule(...)]` syntax has no
 * numeric-range rule wired up (`min`/`max` validate STRING length, not a
 * numeric bound; see `CreateRefundData`'s `amount` docblock for the same
 * finding elsewhere in this codebase).
 */
final class StockReportQuery implements RequestData
{
    public function __construct(
        #[FromQuery(description: 'Filter by stock status: out_of_stock or low_stock. Defaults to both.')]
        #[Rule('in:out_of_stock,low_stock')]
        public readonly ?string $status = null,
        #[FromQuery(description: 'Low-stock threshold override, 0-100000. Defaults to the configured value.')]
        #[Rule('numeric')]
        public readonly ?int $threshold = null,
        #[FromQuery(description: 'Page number.')]
        #[Rule('numeric')]
        public readonly ?int $page = null,
        #[FromQuery(description: 'Items per page, clamped to 100.')]
        #[Rule('numeric')]
        public readonly ?int $per_page = null,
    ) {
    }
}
