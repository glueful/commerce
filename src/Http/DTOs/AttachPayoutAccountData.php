<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * `POST /commerce/admin/marketplace/payouts/accounts` request body (design
 * spec §2.7, MV4 Task 10): attach (insert or replace) the opaque destination
 * reference for one (seller, provider). Blank-provider/blank-ref refusals are
 * enforced by {@see \Glueful\Extensions\Commerce\Marketplace\PayoutAccountService::attach()}
 * itself (a `422` {@see \Glueful\Extensions\Commerce\Marketplace\PayoutException}),
 * not here -- `string` shape checks only. `account_ref` is opaque and
 * provider-owned -- Commerce never validates its internal shape.
 */
final class AttachPayoutAccountData implements RequestData
{
    public function __construct(
        #[Rule('required|string')]
        public readonly string $seller_uuid = '',
        #[Rule('required|string|max:32')]
        public readonly string $provider = '',
        #[Rule('required|string|max:191')]
        public readonly string $account_ref = '',
    ) {
    }
}
