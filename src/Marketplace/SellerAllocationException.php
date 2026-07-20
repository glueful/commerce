<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

/**
 * Thrown by {@see SellerAllocationCalculator::allocate()} when one of the
 * exact-reconciliation invariants (design spec §2.5) fails: the per-seller
 * allocation it just computed does not sum EXACTLY to the corresponding
 * order-level total. Every invariant is guaranteed to hold by construction
 * (each allocation either mirrors a per-line sum or runs through
 * {@see \Glueful\Extensions\Commerce\Support\LargestRemainder}, which is
 * itself exact-sum by contract) -- this exception is therefore a guardrail
 * against a caller-supplied contradiction in `$lines`/`$totals` (e.g. totals
 * that do not agree with the priced lines, or a tax breakdown that does not
 * agree with the lines' persisted `tax_amount`), never expected control
 * flow. Carries the failing invariant's name and both sides of the mismatch,
 * mirroring {@see \Glueful\Extensions\Commerce\Orders\Downloads\DownloadGrantOverflowException}'s
 * readonly-property-carries-the-detail convention.
 */
final class SellerAllocationException extends \RuntimeException
{
    public function __construct(
        public readonly string $invariant,
        public readonly int $expected,
        public readonly int $actual,
    ) {
        parent::__construct(
            "Seller allocation integrity failure ({$invariant}): expected {$expected}, got {$actual}."
        );
    }
}
