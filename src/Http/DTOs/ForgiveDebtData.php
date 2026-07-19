<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * `POST /commerce/admin/marketplace/sellers/{uuid}/debt/forgive` request body (design
 * spec §2.8, MV5a Task 15/16): an explicit, audited, ALWAYS-POSITIVE debt-forgiveness
 * credit -- the target seller is the route `{uuid}`, never a body field (tenant/target
 * binding: the resolved tenant + this path segment are the only inputs that select
 * which account is credited). The caller idempotency key is the HTTP `Idempotency-Key`
 * HEADER (mirrors {@see ManualReserveHoldData}), required by the controller before this
 * DTO is even consulted. `amount > 0` is enforced by the controller itself (this is a
 * CREDIT, never a debit, unlike the general-purpose signed
 * {@see \Glueful\Extensions\Commerce\Marketplace\AdjustmentService::post()} this
 * delegates to); non-zero amount/non-empty reason/non-empty actor are otherwise
 * enforced by that service (a `422` `AdjustmentException`).
 */
final class ForgiveDebtData implements RequestData
{
    public function __construct(
        #[Rule('required|string|max:3')]
        public readonly string $currency = '',
        #[Rule('required|integer')]
        public readonly int $amount = 0,
        #[Rule('required|string|max:255')]
        public readonly string $reason = '',
    ) {
    }
}
