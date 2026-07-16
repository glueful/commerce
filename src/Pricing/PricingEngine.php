<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Pricing;

final class PricingEngine
{
    /**
     * @param list<array{product_uuid: string, unit_price: int, quantity: int}> $lines
     * @param array<string,mixed>|null $discount
     */
    public function price(
        array $lines,
        ?array $discount,
        ?ShippingQuote $shipping,
        ?TaxQuote $tax,
    ): Totals {
        $subtotal = 0;
        foreach ($lines as $line) {
            $subtotal += $line['unit_price'] * $line['quantity'];
        }

        $shippingTotal = $shipping?->amount ?? 0;
        $discountTotal = 0;
        $discountType = is_array($discount) ? (string) ($discount['type'] ?? '') : '';

        if ($discount !== null) {
            $base = $this->discountableBase($lines, $discount['product_scope'] ?? null);
            $discountTotal = match ($discountType) {
                'percentage' => intdiv($base * (int) $discount['value'] + 5000, 10000),
                'fixed' => min((int) $discount['value'], $base),
                'free_shipping' => $shippingTotal,
                default => 0,
            };

            if ($discountType === 'free_shipping') {
                $shippingTotal = 0;
            }

            $discountTotal = min($discountTotal, $subtotal + ($shipping?->amount ?? 0));
        }

        $taxTotal = $tax?->amount ?? 0;
        $discountedSubtotal = $discountType === 'free_shipping'
            ? $subtotal
            : max(0, $subtotal - $discountTotal);

        return new Totals(
            $subtotal,
            $discountTotal,
            $shippingTotal,
            $taxTotal,
            $discountedSubtotal + $shippingTotal + $taxTotal
        );
    }

    /**
     * @param list<array{product_uuid: string, unit_price: int, quantity: int}> $lines
     * @param mixed $scope
     */
    public function discountableBase(array $lines, mixed $scope): int
    {
        $allowed = null;
        if (is_array($scope)) {
            $allowed = array_fill_keys(array_map('strval', $scope), true);
        }

        $base = 0;
        foreach ($lines as $line) {
            if ($allowed !== null && !isset($allowed[$line['product_uuid']])) {
                continue;
            }

            $base += $line['unit_price'] * $line['quantity'];
        }

        return $base;
    }
}
