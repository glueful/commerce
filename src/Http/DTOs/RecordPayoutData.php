<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * `POST /commerce/admin/marketplace/payouts` request body (design spec
 * §2.10): `amount > 0`/non-empty `external_ref`/non-empty resolved actor are
 * enforced by {@see \Glueful\Extensions\Commerce\Marketplace\PayoutService::record()}
 * itself (a `422` `PayoutException`), not here -- `integer`/`string` shape
 * checks only. `note` is the one optional field.
 */
final class RecordPayoutData implements RequestData
{
    public function __construct(
        #[Rule('required|string')]
        public readonly string $seller_uuid = '',
        #[Rule('required|string|max:3')]
        public readonly string $currency = '',
        #[Rule('required|integer')]
        public readonly int $amount = 0,
        #[Rule('required|string|max:191')]
        public readonly string $external_ref = '',
        #[Rule('string|max:255')]
        public readonly ?string $note = null,
        #[Rule('required|string|max:191')]
        public readonly string $idempotency_key = '',
    ) {
    }
}
