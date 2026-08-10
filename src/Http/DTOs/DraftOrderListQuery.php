<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\FromQuery;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * `GET /commerce/admin/orders/drafts` (admin-order-creation cycle 2, Task 9).
 *
 * Deliberately carries NO `status` filter, unlike {@see OrderListQuery}: this
 * listing is draft-only by construction (design spec §2.2 -- it is the ONE
 * draft-inclusive listing), so a status parameter could only ever be a
 * confusing no-op or a back door into the finalized-order surface.
 */
final class DraftOrderListQuery implements RequestData
{
    public function __construct(
        #[FromQuery(description: 'Page number.')]
        #[Rule('numeric')]
        public readonly ?int $page = null,
        #[FromQuery(description: 'Items per page, clamped to 100.')]
        #[Rule('numeric')]
        public readonly ?int $per_page = null,
    ) {
    }
}
