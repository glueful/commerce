<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * `POST /commerce/admin/marketplace/payouts/execute` request body (design
 * spec §2.3/§2.6, MV4 Task 10): a single-seller provider payout. Unlike
 * {@see RecordPayoutData}, there is no caller-supplied idempotency key or
 * external reference -- {@see \Glueful\Extensions\Commerce\Marketplace\PayoutService::execute()}
 * reserves a genuinely new payout each call (subject to the readiness gate +
 * available-balance check), never a caller-controlled replay. `amount > 0`
 * and the readiness/balance refusals are enforced by `execute()` itself (a
 * `422` {@see \Glueful\Extensions\Commerce\Marketplace\PayoutException}), not
 * here -- `integer`/`string` shape checks only.
 */
final class ExecutePayoutData implements RequestData
{
    public function __construct(
        #[Rule('required|string')]
        public readonly string $seller_uuid = '',
        #[Rule('required|string|max:3')]
        public readonly string $currency = '',
        #[Rule('required|integer')]
        public readonly int $amount = 0,
    ) {
    }
}
