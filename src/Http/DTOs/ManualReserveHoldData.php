<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * `POST /commerce/admin/marketplace/reserves/holds` request body (design spec §2.8, MV5a
 * Task 15/16): an emergency operator reserve hold. The caller idempotency key is the HTTP
 * `Idempotency-Key` HEADER (mirrors {@see \Glueful\Extensions\Commerce\Http\Admin\AdminRefundController::store()}'s
 * convention, NOT a body field like {@see RecordPayoutData::$idempotency_key}) --
 * required by the controller before this DTO is even consulted. `amount > 0` and the
 * idempotency-key replay-vs-conflict arbiter are enforced by
 * {@see \Glueful\Extensions\Commerce\Marketplace\ReserveService::manualHold()} itself,
 * not here -- `integer`/`string` shape checks only.
 */
final class ManualReserveHoldData implements RequestData
{
    public function __construct(
        #[Rule('required|string')]
        public readonly string $seller_uuid = '',
        #[Rule('required|string|max:3')]
        public readonly string $currency = '',
        #[Rule('required|integer')]
        public readonly int $amount = 0,
        #[Rule('required|string|max:255')]
        public readonly string $reason = '',
    ) {
    }
}
