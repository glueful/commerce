<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\FromQuery;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * `GET /commerce/admin/refunds` (Layer 6 Global Constraints, cross-order refund
 * list): `status` and `order` (order uuid) are exact matches; `from`/`to` are
 * plain optional half-open bounds on `completed_at` (the L5 `date:Y-m-d`
 * convention, but deliberately NOT `ReportWindow` -- no defaulting, no 366-day
 * cap). Pending/failed rows carry no `completed_at` and therefore never match a
 * request that supplies either date bound.
 */
final class RefundListQuery implements RequestData
{
    public function __construct(
        #[FromQuery(description: 'Filter by refund status.')]
        #[Rule('string')]
        public readonly ?string $status = null,
        #[FromQuery(description: 'Filter by order uuid.')]
        #[Rule('string')]
        public readonly ?string $order = null,
        #[FromQuery(description: 'Only refunds completed on/after this date (Y-m-d), inclusive.')]
        #[Rule('date:Y-m-d')]
        public readonly ?string $from = null,
        #[FromQuery(description: 'Only refunds completed on/before this date (Y-m-d), inclusive.')]
        #[Rule('date:Y-m-d')]
        public readonly ?string $to = null,
        #[FromQuery(description: 'Page number.')]
        #[Rule('numeric')]
        public readonly ?int $page = null,
        #[FromQuery(description: 'Items per page, clamped to 100.')]
        #[Rule('numeric')]
        public readonly ?int $per_page = null,
    ) {
    }
}
