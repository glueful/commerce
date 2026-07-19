<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * `PATCH /commerce/admin/marketplace/sellers/{uuid}/reserve-policy` request body (design
 * spec §2.1, MV5a Task 6/16): the per-seller reserve override. Both fields are OPTIONAL
 * and independent -- an omitted (`null`) field INHERITS the workspace default; an
 * explicit `0` DISABLES that field for this seller without inheriting. Bounds are
 * enforced by {@see \Glueful\Extensions\Commerce\Marketplace\ReservePolicyService}
 * itself (a `422` framework `ValidationException`), not here.
 */
final class SetSellerReservePolicyData implements RequestData
{
    public function __construct(
        #[Rule('integer')]
        public readonly ?int $reserve_bps = null,
        #[Rule('integer')]
        public readonly ?int $reserve_days = null,
    ) {
    }
}
