<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

/**
 * Turns a resolved commission policy + a priced order line's money facts
 * into `commission_basis`/`commission_amount` (design spec §2.1, pinned),
 * and sums per-seller commission with the same hard-reconciliation
 * discipline as {@see SellerAllocationCalculator}. Pure -- no DB/context,
 * no side effects.
 *
 * **Per line.** `commission_basis = max(0, line_total - discount_amount)`
 * (shipping, shipping-discount, and tax are outside the basis).
 * `percentage`: `commission_amount = intdiv(basis * bps + 5000, 10000)`
 * (half-up, the house rounding idiom shared with `DiscountAllocation`/
 * `DbTaxCalculator`). `fixed`: `commission_amount = min(commission_fixed,
 * basis)`. A zero basis always yields a zero commission regardless of kind.
 *
 * **Per seller ({@see self::perSeller()}).** The seller-order
 * `commission_amount` is the exact sum of its lines' `commission_amount`.
 * `perSeller()` independently recomputes each seller's total straight from
 * the input and asserts it matches the accumulated sum -- unreachable by
 * construction (both sides sum the same integers), kept as a guardrail
 * against a future refactor silently breaking the invariant, mirroring
 * {@see SellerAllocationCalculator}'s "several invariants are unreachable
 * by construction, kept as guardrails" framing.
 */
final class CommissionCalculator
{
    /**
     * @param array{kind:string,bps:?int,fixed:?int} $policy a resolved
     *   policy ({@see CommissionPolicyResolver::resolve()}'s return, minus
     *   `source`)
     * @return array{commission_basis:int,commission_amount:int}
     */
    public static function lineCommission(int $lineTotal, int $discountAmount, array $policy): array
    {
        $basis = max(0, $lineTotal - $discountAmount);

        $amount = match ($policy['kind']) {
            'percentage' => intdiv($basis * (int) $policy['bps'] + 5000, 10000),
            'fixed' => min((int) $policy['fixed'], $basis),
            default => throw new CommissionPolicyException(
                "commission_kind: unrecognized kind '{$policy['kind']}'."
            ),
        };

        return [
            'commission_basis' => $basis,
            'commission_amount' => $amount,
        ];
    }

    /**
     * @param list<array{seller_uuid:string,commission_amount:int}> $lineResults
     * @return array<string,int> seller_uuid => summed commission_amount, in
     *   first-appearance order
     */
    public static function perSeller(array $lineResults): array
    {
        $sums = [];
        foreach ($lineResults as $line) {
            $sellerUuid = (string) $line['seller_uuid'];
            $sums[$sellerUuid] = ($sums[$sellerUuid] ?? 0) + (int) $line['commission_amount'];
        }

        foreach ($sums as $sellerUuid => $total) {
            $expected = 0;
            foreach ($lineResults as $line) {
                if ((string) $line['seller_uuid'] === $sellerUuid) {
                    $expected += (int) $line['commission_amount'];
                }
            }

            if ($expected !== $total) {
                throw new \RuntimeException(
                    "Commission integrity failure (seller {$sellerUuid}): expected {$expected}, got {$total}."
                );
            }
        }

        return $sums;
    }
}
