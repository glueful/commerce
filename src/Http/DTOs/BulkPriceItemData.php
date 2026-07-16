<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\Contracts\ValidatesSelf;

/**
 * One `{uuid, price}` element of {@see BulkPriceData::$items} (design spec
 * Layer 6 §2/Task 2). Nested hydration via `#[ArrayOf(self::class)]` on the
 * parent already rejects a non-object element, a missing/non-string `uuid`,
 * and a missing/non-integer `price` as a per-item field error merged into the
 * whole-request 422 BEFORE any write; {@see self::validate()} closes the one
 * gap plain `#[Rule('integer')]` cannot express -- a negative price.
 */
final class BulkPriceItemData implements RequestData, ValidatesSelf
{
    public function __construct(
        #[Rule('required|string')]
        public readonly string $uuid = '',
        #[Rule('required|integer')]
        public readonly int $price = 0,
    ) {
    }

    /** @return array<string,list<string>> */
    public function validate(): array
    {
        if ($this->price < 0) {
            return ['price' => ['price must be a non-negative integer (minor units).']];
        }

        return [];
    }
}
