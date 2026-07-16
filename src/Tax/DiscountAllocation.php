<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tax;

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
     * @return list<array{taxable_amount:int, tax_class:string, quantity:int}>
     */
    public static function taxableLines(array $lines, ?array $discount, int $discountTotal): array
    {
        $allocations = self::allocate($lines, $discount, $discountTotal);

        $taxableLines = [];
        foreach ($lines as $line) {
            $extended = (int) $line['unit_price'] * (int) $line['quantity'];
            $allocated = $allocations[(string) $line['line_uuid']] ?? 0;

            $taxableLines[] = [
                'taxable_amount' => max(0, $extended - $allocated),
                'tax_class' => (string) ($line['tax_class'] ?? 'standard'),
                'quantity' => (int) $line['quantity'],
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

        // Largest-remainder method: each eligible line's exact share is
        // discountTotal * extended / base; floor it, track the remainder, and
        // hand the leftover units (discountTotal - sum(floors)) one each to
        // the lines with the largest remainders, ties broken by ascending
        // line_uuid for determinism.
        $shares = [];
        $flooredTotal = 0;
        foreach ($eligible as $line) {
            $numerator = $discountTotal * $line['extended'];
            $floor = intdiv($numerator, $base);
            $shares[] = [
                'line_uuid' => $line['line_uuid'],
                'floor' => $floor,
                'remainder' => $numerator % $base,
            ];
            $flooredTotal += $floor;
        }

        $remainderUnits = $discountTotal - $flooredTotal;

        usort($shares, static function (array $a, array $b): int {
            return $a['remainder'] !== $b['remainder']
                ? $b['remainder'] <=> $a['remainder']
                : $a['line_uuid'] <=> $b['line_uuid'];
        });

        $result = [];
        foreach ($shares as $index => $share) {
            $amount = $share['floor'] + ($index < $remainderUnits ? 1 : 0);
            if ($amount > 0) {
                $result[$share['line_uuid']] = $amount;
            }
        }

        return $result;
    }
}
