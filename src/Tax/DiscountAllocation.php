<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tax;

use Glueful\Extensions\Commerce\Support\LargestRemainder;

/**
 * Pure discount-allocation logic feeding {@see \Glueful\Extensions\Commerce\Contracts\LineTaxCalculator}'s
 * per-line taxable base (design spec §5, pinned). For a `percentage`/`fixed`
 * discount, the order-level discount total is allocated proportionally ONLY
 * among lines eligible under the discount's `product_scope` (an absent scope
 * means every line is eligible); ineligible lines get allocation 0. Allocation
 * uses the largest-remainder method over each eligible line's extended total
 * (`unit_price * quantity`) and is integer-exact: allocations sum to exactly
 * the line-applicable discount total, with rounding orphans resolved by
 * stable `line_uuid` ascending. A `free_shipping` discount allocates nothing
 * to lines (it only reduces the shipping total, handled elsewhere by
 * {@see \Glueful\Extensions\Commerce\Pricing\PricingEngine}). Each line's
 * `taxable_amount = unit_price * quantity - allocation`, floored at 0.
 */
final class DiscountAllocation
{
    /**
     * @param list<array<string,mixed>> $lines pricedLines() rows (unit_price,
     *   quantity, product_uuid, line_uuid, tax_class)
     * @param array<string,mixed>|null $discount
     * @return list<array{taxable_amount:int, tax_class:string, quantity:int, line_uuid:string}>
     */
    public static function taxableLines(array $lines, ?array $discount, int $discountTotal): array
    {
        $allocations = self::allocate($lines, $discount, $discountTotal);

        $taxableLines = [];
        foreach ($lines as $line) {
            $lineUuid = (string) $line['line_uuid'];
            $extended = (int) $line['unit_price'] * (int) $line['quantity'];
            $allocated = $allocations[$lineUuid] ?? 0;

            $taxableLines[] = [
                'taxable_amount' => max(0, $extended - $allocated),
                'tax_class' => (string) ($line['tax_class'] ?? 'standard'),
                'quantity' => (int) $line['quantity'],
                'line_uuid' => $lineUuid,
            ];
        }

        return $taxableLines;
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @param array<string,mixed>|null $discount
     * @return array<string,int> line_uuid => allocated discount amount (only
     *   eligible lines with a non-zero allocation are present)
     */
    public static function allocate(array $lines, ?array $discount, int $discountTotal): array
    {
        if ($discount === null || $discountTotal <= 0) {
            return [];
        }

        if ((string) ($discount['type'] ?? '') === 'free_shipping') {
            return [];
        }

        $scope = $discount['product_scope'] ?? null;
        $allowed = is_array($scope) ? array_fill_keys(array_map('strval', $scope), true) : null;

        $eligible = [];
        foreach ($lines as $line) {
            if ($allowed !== null && !isset($allowed[(string) $line['product_uuid']])) {
                continue;
            }

            $eligible[] = [
                'line_uuid' => (string) $line['line_uuid'],
                'extended' => (int) $line['unit_price'] * (int) $line['quantity'],
            ];
        }

        $base = array_sum(array_column($eligible, 'extended'));
        if ($base <= 0) {
            return [];
        }

        // Largest-remainder method (extracted to the generic helper): each
        // eligible line's exact share is discountTotal * extended / base;
        // the leftover units are handed one each to the lines with the
        // largest remainders, ties broken by ascending line_uuid for
        // determinism. Only lines with a non-zero allocation are returned.
        $weights = array_column($eligible, 'extended', 'line_uuid');
        $allocations = LargestRemainder::distribute($weights, $discountTotal);

        return array_filter($allocations, static fn (int $amount): bool => $amount > 0);
    }
}
