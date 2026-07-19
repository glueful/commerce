<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * `PATCH /commerce/admin/marketplace/settings/reserves` request body (design spec §2.1,
 * MV5a Task 6/16): the workspace-default rolling-reserve policy. Both fields are
 * REQUIRED (unlike the per-seller override, this level has no "inherit" concept --
 * {@see SetSellerReservePolicyData}) -- `0`/`0` explicitly disables the reserve for
 * every seller with no per-seller override. Bounds (`0..10000` bps, non-negative days)
 * are enforced by {@see \Glueful\Extensions\Commerce\Marketplace\ReservePolicyService}
 * itself (a `422` framework `ValidationException`), not here.
 */
final class SetWorkspaceReservePolicyData implements RequestData
{
    public function __construct(
        #[Rule('required|integer')]
        public readonly int $reserve_bps = 0,
        #[Rule('required|integer')]
        public readonly int $reserve_days = 0,
    ) {
    }
}
