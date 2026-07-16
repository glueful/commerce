<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class CreateRefundData implements RequestData
{
    /**
     * `amount` uses `integer` only (not `integer|min:1`): the framework's `min`/`max`
     * rules are string-length rules, not numeric comparisons, so `> 0` is enforced by
     * `RefundInput`/`RefundService::validate()` instead. Line-element shape validation
     * happens in the controller (nested DTO support for arbitrary arrays is pending).
     *
     * @param list<array{order_line_uuid:string,quantity:int,amount:int}>|null $lines
     */
    public function __construct(
        #[Rule('integer')]
        public readonly ?int $amount = null,
        #[Rule('string|max:1000')]
        public readonly ?string $reason = null,
        public readonly ?array $lines = null,
        #[Rule('boolean')]
        public readonly bool $restock = false,
    ) {
    }
}
