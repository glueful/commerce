<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * `POST /commerce/admin/marketplace/chargebacks` request body (design spec §2.4, MV5a
 * Task 16): an operator/system-supplied NORMALIZED chargeback event -- the same shape
 * {@see \Glueful\Extensions\Contracts\Payments\ProviderChargebackEvent} carries, minus
 * `tenantUuid` (the controller ALWAYS resolves that from the authenticated admin
 * profile, never this body -- design spec §6/Task 16 tenant-binding requirement).
 * `payable_currency` defaults to `currency` when omitted (the ordinary single-currency
 * case). Deeper invariants (provider/event/reference non-empty, `amount > 0`, event
 * currency == payable currency, `occurred_at` parseable, a `reversal` requires
 * `related_event_id`) are enforced by the VO's own constructor
 * ({@see \Glueful\Extensions\Contracts\Payments\ProviderChargebackEvent}), which the
 * controller catches (`\InvalidArgumentException`) and maps to `422` -- not here.
 */
final class IngestChargebackData implements RequestData
{
    public function __construct(
        #[Rule('required|string|max:32')]
        public readonly string $provider = '',
        #[Rule('required|string|max:191')]
        public readonly string $provider_event_id = '',
        #[Rule('required|string|max:191')]
        public readonly string $payment_reference = '',
        #[Rule('required|string')]
        public readonly string $payable_type = '',
        #[Rule('required|string')]
        public readonly string $payable_id = '',
        #[Rule('required|integer')]
        public readonly int $payable_amount = 0,
        #[Rule('string|max:3')]
        public readonly ?string $payable_currency = null,
        #[Rule('required|integer')]
        public readonly int $amount = 0,
        #[Rule('required|string|max:3')]
        public readonly string $currency = '',
        #[Rule('string|max:64')]
        public readonly ?string $reason_code = null,
        #[Rule('required|string')]
        public readonly string $occurred_at = '',
        #[Rule('in:chargeback,reversal')]
        public readonly string $kind = 'chargeback',
        #[Rule('string|max:191')]
        public readonly ?string $related_event_id = null,
    ) {
    }
}
