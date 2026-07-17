<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Extensions\Commerce\Pricing\TaxBreakdown;
use Glueful\Extensions\Commerce\Support\LargestRemainder;

/**
 * Turns priced order lines + persisted order totals into the exactly
 * reconciled per-seller money facts of design spec §2.1-§2.5 (pinned). Pure
 * function -- no DB/context, no side effects -- so `CheckoutService` can
 * call it inside its existing transaction and abort on the
 * {@see SellerAllocationException} guardrail before anything is persisted.
 *
 * **Grouping.** Sellers are the distinct `seller_uuid` values present in
 * `$lines`, always iterated/returned in ASCENDING seller-uuid order (spec
 * "determinism" constraint) regardless of `$lines`' own order --
 * {@see \Glueful\Extensions\Commerce\Support\LargestRemainder} is itself
 * keyed and tie-broken by ascending key, so every largest-remainder call
 * below is fed seller-uuid-keyed weights built from that same sorted list.
 *
 * **subtotal_s** = sum of the seller's lines' `line_total`.
 *
 * **Discount kind split (§2.2):**
 * - `value`: `allocated_discount_s` = sum of the seller's lines'
 *   `discount_amount` (already allocated by the caller, line-UUID
 *   largest-remainder, via {@see \Glueful\Extensions\Commerce\Tax\DiscountAllocation});
 *   `allocated_shipping_discount_s` = 0.
 * - `free_shipping`: `allocated_discount_s` = 0; `allocated_shipping_discount_s`
 *   is largest-remainder over `$totals['discount_total']` on the seller's
 *   MERCHANDISE-subtotal basis (`subtotal_s`).
 * - `none`: both 0.
 *
 * **Shipping (§2.3):** `allocated_shipping_s` is largest-remainder over
 * `$totals['shipping_total']` on the seller's POST-DISCOUNT merchandise
 * basis (`subtotal_s - allocated_discount_s`). Free-shipping orders always
 * carry `shipping_total = 0` (`PricingEngine`'s own invariant), so this
 * naturally yields 0 for every seller without a special case here.
 *
 * **Tax (§2.4), method chosen by BREAKDOWN PRESENCE, never `instanceof`:**
 * - `$breakdown !== null` -> `line_detailed`: `allocated_tax_s` = sum of the
 *   seller's lines' `tax_amount` (the caller's per-line merchandise tax)
 *   PLUS a largest-remainder share of `$breakdown->shippingTaxTotal()` on
 *   the seller's `allocated_shipping_s` basis -- falling back to the
 *   post-discount merchandise basis when EVERY seller's `allocated_shipping_s`
 *   is 0 (e.g. a $0 shipping method that is still taxable), so the shipping
 *   tax is never left stuck on an all-zero weight set.
 * - `$breakdown === null` -> `aggregate_allocated`: `allocated_tax_s` is a
 *   largest-remainder share of `$totals['tax_total']` on the seller's
 *   (post-discount merchandise + `allocated_shipping_s`) basis.
 *
 * Both `LargestRemainder::distribute()` itself falls back to an even,
 * ascending-key split whenever its ENTIRE weight set sums to zero -- so a
 * basis that is uniformly zero (e.g. a 100% discount zeroing every seller's
 * post-discount merchandise) resolves deterministically without any extra
 * code here.
 *
 * **attributed_total_s** = `subtotal_s - allocated_discount_s +
 * allocated_shipping_s + allocated_tax_s` (`allocated_shipping_discount_s`
 * is NEVER subtracted -- §2.1).
 *
 * **Hard reconciliation (§2.5).** Every sum below is asserted to EXACTLY
 * equal its corresponding order-level total before the result is returned;
 * a mismatch throws {@see SellerAllocationException} rather than being
 * silently accepted. Several of these are unreachable by construction given
 * this implementation (e.g. every largest-remainder call sums exactly to
 * the total it was fed) -- they remain as guardrails against a future
 * change breaking that invariant, matching the spec's own framing ("it must
 * be impossible by construction -- the assert is a guardrail, not control
 * flow"). The two that ARE independently falsifiable by a caller
 * contradiction are `subtotal` (grouped from `$lines`, independent of
 * `$totals['subtotal']`) and, on the `line_detailed` path, `allocated_tax`
 * (partly grouped from `$lines`' own `tax_amount`, independent of
 * `$totals['tax_total']`).
 */
final class SellerAllocationCalculator
{
    private const DISCOUNT_KINDS = ['none', 'value', 'free_shipping'];

    /**
     * @param list<array{
     *     line_uuid:string,
     *     seller_uuid:string,
     *     line_total:int,
     *     discount_amount:int,
     *     tax_amount:int
     * }> $lines
     * @param array{subtotal:int, discount_total:int, shipping_total:int, tax_total:int, grand_total:int} $totals
     * @param 'none'|'value'|'free_shipping' $discountKind
     * @return array<string, array{
     *     subtotal:int,
     *     allocated_discount:int,
     *     allocated_shipping_discount:int,
     *     allocated_shipping:int,
     *     allocated_tax:int,
     *     attributed_total:int,
     *     tax_attribution_method:string
     * }> keyed by seller_uuid, ascending
     */
    public static function allocate(array $lines, array $totals, string $discountKind, ?TaxBreakdown $breakdown): array
    {
        if (!in_array($discountKind, self::DISCOUNT_KINDS, true)) {
            throw new \InvalidArgumentException("SellerAllocationCalculator: unknown discount kind '{$discountKind}'.");
        }

        $subtotalTotal = (int) $totals['subtotal'];
        $discountTotal = (int) $totals['discount_total'];
        $shippingTotal = (int) $totals['shipping_total'];
        $taxTotal = (int) $totals['tax_total'];
        $grandTotal = (int) $totals['grand_total'];

        [$sellerUuids, $subtotalBySeller, $lineDiscountBySeller, $lineTaxBySeller, $lineDiscountSum] =
            self::groupLines($lines);

        // ---- discount kind split (§2.2) ----
        $allocatedDiscount = array_fill_keys($sellerUuids, 0);
        $allocatedShippingDiscount = array_fill_keys($sellerUuids, 0);

        if ($discountKind === 'value') {
            foreach ($sellerUuids as $sellerUuid) {
                $allocatedDiscount[$sellerUuid] = $lineDiscountBySeller[$sellerUuid];
            }
        } elseif ($discountKind === 'free_shipping') {
            $allocatedShippingDiscount = LargestRemainder::distribute($subtotalBySeller, $discountTotal);
        }

        // ---- shipping (§2.3): post-discount merchandise basis ----
        $postDiscountMerchandise = [];
        foreach ($sellerUuids as $sellerUuid) {
            $postDiscountMerchandise[$sellerUuid] = $subtotalBySeller[$sellerUuid] - $allocatedDiscount[$sellerUuid];
        }
        $allocatedShipping = LargestRemainder::distribute($postDiscountMerchandise, $shippingTotal);

        // ---- tax (§2.4) ----
        if ($breakdown !== null) {
            $taxAttributionMethod = 'line_detailed';

            $shippingTaxBasis = $allocatedShipping;
            if (array_sum($shippingTaxBasis) === 0) {
                $shippingTaxBasis = $postDiscountMerchandise;
            }
            $shippingTaxShare = LargestRemainder::distribute($shippingTaxBasis, $breakdown->shippingTaxTotal());

            $allocatedTax = [];
            foreach ($sellerUuids as $sellerUuid) {
                $allocatedTax[$sellerUuid] = $lineTaxBySeller[$sellerUuid] + $shippingTaxShare[$sellerUuid];
            }
        } else {
            $taxAttributionMethod = 'aggregate_allocated';

            $taxBasis = [];
            foreach ($sellerUuids as $sellerUuid) {
                $taxBasis[$sellerUuid] = $postDiscountMerchandise[$sellerUuid] + $allocatedShipping[$sellerUuid];
            }
            $allocatedTax = LargestRemainder::distribute($taxBasis, $taxTotal);
        }

        // ---- attributed total (§2.1) ----
        $attributedTotal = [];
        foreach ($sellerUuids as $sellerUuid) {
            $attributedTotal[$sellerUuid] = $subtotalBySeller[$sellerUuid]
                - $allocatedDiscount[$sellerUuid]
                + $allocatedShipping[$sellerUuid]
                + $allocatedTax[$sellerUuid];
        }

        // ---- hard reconciliation (§2.5) ----
        self::assertInvariant('subtotal', $subtotalTotal, array_sum($subtotalBySeller));

        if ($discountKind === 'value') {
            self::assertInvariant('allocated_discount', $discountTotal, array_sum($allocatedDiscount));
            self::assertInvariant('line_discount_amount', $discountTotal, $lineDiscountSum);
        } elseif ($discountKind === 'free_shipping') {
            self::assertInvariant('allocated_shipping_discount', $discountTotal, array_sum($allocatedShippingDiscount));
        } else {
            self::assertInvariant('allocated_discount_none', 0, array_sum($allocatedDiscount));
            self::assertInvariant('allocated_shipping_discount_none', 0, array_sum($allocatedShippingDiscount));
        }

        self::assertInvariant('allocated_shipping', $shippingTotal, array_sum($allocatedShipping));
        self::assertInvariant('allocated_tax', $taxTotal, array_sum($allocatedTax));
        self::assertInvariant('attributed_total', $grandTotal, array_sum($attributedTotal));

        // ---- assemble (ascending seller_uuid) ----
        $result = [];
        foreach ($sellerUuids as $sellerUuid) {
            $result[$sellerUuid] = [
                'subtotal' => $subtotalBySeller[$sellerUuid],
                'allocated_discount' => $allocatedDiscount[$sellerUuid],
                'allocated_shipping_discount' => $allocatedShippingDiscount[$sellerUuid],
                'allocated_shipping' => $allocatedShipping[$sellerUuid],
                'allocated_tax' => $allocatedTax[$sellerUuid],
                'attributed_total' => $attributedTotal[$sellerUuid],
                'tax_attribution_method' => $taxAttributionMethod,
            ];
        }

        return $result;
    }

    /**
     * @param list<array{
     *     line_uuid:string,
     *     seller_uuid:string,
     *     line_total:int,
     *     discount_amount:int,
     *     tax_amount:int
     * }> $lines
     * @return array{0:list<string>, 1:array<string,int>, 2:array<string,int>, 3:array<string,int>, 4:int}
     *   [sellerUuids (ascending), subtotalBySeller, lineDiscountBySeller,
     *   lineTaxBySeller, sum of every line's discount_amount]
     */
    private static function groupLines(array $lines): array
    {
        $subtotalBySeller = [];
        $lineDiscountBySeller = [];
        $lineTaxBySeller = [];
        $lineDiscountSum = 0;

        foreach ($lines as $line) {
            $sellerUuid = (string) $line['seller_uuid'];

            $subtotalBySeller[$sellerUuid] = ($subtotalBySeller[$sellerUuid] ?? 0) + (int) $line['line_total'];
            $lineDiscountBySeller[$sellerUuid] =
                ($lineDiscountBySeller[$sellerUuid] ?? 0) + (int) $line['discount_amount'];
            $lineTaxBySeller[$sellerUuid] = ($lineTaxBySeller[$sellerUuid] ?? 0) + (int) $line['tax_amount'];
            $lineDiscountSum += (int) $line['discount_amount'];
        }

        $sellerUuids = array_keys($subtotalBySeller);
        sort($sellerUuids, SORT_STRING);

        $orderedSubtotal = [];
        $orderedDiscount = [];
        $orderedTax = [];
        foreach ($sellerUuids as $sellerUuid) {
            $orderedSubtotal[$sellerUuid] = $subtotalBySeller[$sellerUuid];
            $orderedDiscount[$sellerUuid] = $lineDiscountBySeller[$sellerUuid];
            $orderedTax[$sellerUuid] = $lineTaxBySeller[$sellerUuid];
        }

        return [$sellerUuids, $orderedSubtotal, $orderedDiscount, $orderedTax, $lineDiscountSum];
    }

    private static function assertInvariant(string $invariant, int $expected, int $actual): void
    {
        if ($expected !== $actual) {
            throw new SellerAllocationException($invariant, $expected, $actual);
        }
    }
}
