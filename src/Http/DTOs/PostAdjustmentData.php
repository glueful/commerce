<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * `POST /commerce/admin/marketplace/adjustments` request body (design spec
 * §2.10): targets EITHER a seller account (`seller_uuid`) OR the marketplace
 * account (`account: "marketplace"`) -- exactly one must be provided; the
 * controller resolves whichever is present into the canonical `account_key`
 * {@see \Glueful\Extensions\Commerce\Marketplace\AdjustmentService::post()}
 * expects. Non-zero `amount`/non-empty `reason`/non-empty resolved actor are
 * enforced by that service itself (a `422` `AdjustmentException`), not here.
 */
final class PostAdjustmentData implements RequestData
{
    public function __construct(
        #[Rule('string')]
        public readonly ?string $seller_uuid = null,
        #[Rule('string')]
        public readonly ?string $account = null,
        #[Rule('required|string|max:3')]
        public readonly string $currency = '',
        #[Rule('required|integer')]
        public readonly int $amount = 0,
        #[Rule('required|string|max:255')]
        public readonly string $reason = '',
        #[Rule('required|string|max:191')]
        public readonly string $idempotency_key = '',
    ) {
    }
}
