<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tax;

/**
 * `amount * rate_bps` (design spec §2/§5) would overflow PHP's native int
 * range before the house half-up rounding division ever runs. Both operands
 * come from trusted, already-validated state (a priced line/shipping total
 * and a write-validated `0..10000` rate_bps), so this signals corrupt/
 * adversarial data rather than a normal business condition -- the quote
 * aborts rather than silently wrapping or truncating tax owed.
 */
final class TaxRateOverflowException extends \OverflowException
{
    public function __construct(
        public readonly int $amount,
        public readonly int $rateBps,
    ) {
        parent::__construct(
            "Tax calculation overflow: amount ({$amount}) x rate_bps ({$rateBps}) exceeds safe integer range."
        );
    }
}
