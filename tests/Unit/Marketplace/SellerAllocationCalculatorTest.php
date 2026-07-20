<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Marketplace;

use Glueful\Extensions\Commerce\Marketplace\SellerAllocationCalculator;
use Glueful\Extensions\Commerce\Marketplace\SellerAllocationException;
use Glueful\Extensions\Commerce\Pricing\TaxBreakdown;
use PHPUnit\Framework\TestCase;

/**
 * `SellerAllocationCalculator::allocate()` money-fact matrix (design spec
 * §2.1-§2.5, pinned): discount kind (`none`|`value`|`free_shipping`) x tax
 * attribution (`line_detailed` with a {@see TaxBreakdown} | `aggregate_allocated`
 * with none) x seller count (single | multi), every §2.5 invariant asserted
 * to the minor unit, deterministic ascending-seller-uuid ordering/tie-break,
 * the two documented zero-basis fallbacks, and integrity-exception coverage
 * for contradictory input. Pure logic, zero DB/context dependency -- mirrors
 * {@see \Glueful\Extensions\Commerce\Tests\Unit\Support\LargestRemainderTest}
 * and {@see \Glueful\Extensions\Commerce\Tests\Unit\Marketplace\FixedSellerRoleAuthorityTest}'s
 * plain-TestCase convention for this codebase's other side-effect-free units.
 */
final class SellerAllocationCalculatorTest extends TestCase
{
    // -----------------------------------------------------------------
    // 1. Single seller / no discount / aggregate_allocated tax
    // -----------------------------------------------------------------

    public function testSingleSellerNoDiscountAggregateAllocatedTax(): void
    {
        $lines = [
            $this->line('line-1', 'seller-a', 1000, 0, 0),
            $this->line('line-2', 'seller-a', 2000, 0, 0),
        ];
        $totals = $this->totals(3000, 0, 500, 280, 3780);

        $result = SellerAllocationCalculator::allocate($lines, $totals, 'none', null);

        self::assertSame([
            'seller-a' => [
                'subtotal' => 3000,
                'allocated_discount' => 0,
                'allocated_shipping_discount' => 0,
                'allocated_shipping' => 500,
                'allocated_tax' => 280,
                'attributed_total' => 3780,
                'tax_attribution_method' => 'aggregate_allocated',
            ],
        ], $result);
    }

    // -----------------------------------------------------------------
    // 2. Single seller / value discount / line_detailed tax
    // -----------------------------------------------------------------

    public function testSingleSellerValueDiscountLineDetailedTax(): void
    {
        $lines = [
            $this->line('line-1', 'seller-a', 1000, 100, 72),
            $this->line('line-2', 'seller-a', 2000, 200, 144),
        ];
        $totals = $this->totals(3000, 300, 500, 252, 3452);
        $breakdown = new TaxBreakdown(
            ['line-1' => 72, 'line-2' => 144],
            36,
            ['line-1', 'line-2']
        );

        $result = SellerAllocationCalculator::allocate($lines, $totals, 'value', $breakdown);

        self::assertSame([
            'seller-a' => [
                'subtotal' => 3000,
                'allocated_discount' => 300,
                'allocated_shipping_discount' => 0,
                'allocated_shipping' => 500,
                'allocated_tax' => 252,
                'attributed_total' => 3452,
                'tax_attribution_method' => 'line_detailed',
            ],
        ], $result);
    }

    // -----------------------------------------------------------------
    // 3. Multi (2) seller / value discount / line_detailed tax --
    //    non-trivial (non-tied) largest-remainder distributions across
    //    BOTH the shipping and shipping-tax allocations.
    // -----------------------------------------------------------------

    public function testMultiSellerValueDiscountLineDetailedNonTrivialRemainders(): void
    {
        $lines = [
            $this->line('line-x', 'seller-x', 700, 70, 50),
            $this->line('line-y', 'seller-y', 300, 30, 20),
        ];
        $totals = $this->totals(1000, 100, 91, 79, 1070);
        $breakdown = new TaxBreakdown(
            ['line-x' => 50, 'line-y' => 20],
            9,
            ['line-x', 'line-y']
        );

        $result = SellerAllocationCalculator::allocate($lines, $totals, 'value', $breakdown);

        // Shipping basis (post-discount merchandise 630/270 of 900): exact
        // shares 63.7/27.3 -> floors 63/27 (sum 90, 1 unit left over) --
        // seller-x's remainder (630/900) beats seller-y's (270/900).
        // Shipping-tax basis (allocated_shipping 64/27 of 91): exact shares
        // 6.33/2.67 -> floors 6/2 (sum 8, 1 unit left over) -- seller-y's
        // remainder (61/91) beats seller-x's (30/91) this time, so the extra
        // unit flips to the OTHER seller -- not a rubber-stamped "biggest
        // weight always wins".
        self::assertSame([
            'seller-x' => [
                'subtotal' => 700,
                'allocated_discount' => 70,
                'allocated_shipping_discount' => 0,
                'allocated_shipping' => 64,
                'allocated_tax' => 56,
                'attributed_total' => 750,
                'tax_attribution_method' => 'line_detailed',
            ],
            'seller-y' => [
                'subtotal' => 300,
                'allocated_discount' => 30,
                'allocated_shipping_discount' => 0,
                'allocated_shipping' => 27,
                'allocated_tax' => 23,
                'attributed_total' => 320,
                'tax_attribution_method' => 'line_detailed',
            ],
        ], $result);
    }

    // -----------------------------------------------------------------
    // 4. Multi (3) seller / free_shipping / aggregate_allocated tax --
    //    ascending-seller-uuid TIE-BREAK exercised on the shipping-discount
    //    largest-remainder call (three EQUAL weights).
    // -----------------------------------------------------------------

    public function testMultiSellerFreeShippingAggregateAllocatedTaxTieBreak(): void
    {
        $lines = [
            $this->line('line-a', 'seller-a', 1000, 0, 0),
            $this->line('line-b', 'seller-b', 1000, 0, 0),
            $this->line('line-c', 'seller-c', 1000, 0, 0),
        ];
        // discount_total (shipping waiver) = 100 over three EQUAL 1000
        // merchandise-subtotal weights: exact share 33.33 each -> floors
        // 33/33/33 (sum 99), 1 leftover unit -- all three remainders tied,
        // so the ascending key ('seller-a') wins it.
        $totals = $this->totals(3000, 100, 0, 90, 3090);

        $result = SellerAllocationCalculator::allocate($lines, $totals, 'free_shipping', null);

        self::assertSame(['seller-a', 'seller-b', 'seller-c'], array_keys($result));
        self::assertSame([
            'seller-a' => [
                'subtotal' => 1000,
                'allocated_discount' => 0,
                'allocated_shipping_discount' => 34,
                'allocated_shipping' => 0,
                'allocated_tax' => 30,
                'attributed_total' => 1030,
                'tax_attribution_method' => 'aggregate_allocated',
            ],
            'seller-b' => [
                'subtotal' => 1000,
                'allocated_discount' => 0,
                'allocated_shipping_discount' => 33,
                'allocated_shipping' => 0,
                'allocated_tax' => 30,
                'attributed_total' => 1030,
                'tax_attribution_method' => 'aggregate_allocated',
            ],
            'seller-c' => [
                'subtotal' => 1000,
                'allocated_discount' => 0,
                'allocated_shipping_discount' => 33,
                'allocated_shipping' => 0,
                'allocated_tax' => 30,
                'attributed_total' => 1030,
                'tax_attribution_method' => 'aggregate_allocated',
            ],
        ], $result);
    }

    // -----------------------------------------------------------------
    // 5. Zero-basis fallback #1: a 100% value discount zeroes EVERY
    //    seller's post-discount merchandise basis -> shipping falls back to
    //    an even split (largest-remainder over all-zero weights).
    // -----------------------------------------------------------------

    public function testAllZeroPostDiscountMerchandiseFallsBackToEvenShippingSplit(): void
    {
        $lines = [
            $this->line('line-a', 'seller-a', 1000, 1000, 0),
            $this->line('line-b', 'seller-b', 1000, 1000, 0),
            $this->line('line-c', 'seller-c', 1000, 1000, 0),
        ];
        // shipping_total = 10 over three all-zero post-discount-merchandise
        // weights -> even split 4/3/3, ascending key first.
        $totals = $this->totals(3000, 3000, 10, 100, 110);

        $result = SellerAllocationCalculator::allocate($lines, $totals, 'value', null);

        self::assertSame([
            'seller-a' => [
                'subtotal' => 1000,
                'allocated_discount' => 1000,
                'allocated_shipping_discount' => 0,
                'allocated_shipping' => 4,
                'allocated_tax' => 40,
                'attributed_total' => 44,
                'tax_attribution_method' => 'aggregate_allocated',
            ],
            'seller-b' => [
                'subtotal' => 1000,
                'allocated_discount' => 1000,
                'allocated_shipping_discount' => 0,
                'allocated_shipping' => 3,
                'allocated_tax' => 30,
                'attributed_total' => 33,
                'tax_attribution_method' => 'aggregate_allocated',
            ],
            'seller-c' => [
                'subtotal' => 1000,
                'allocated_discount' => 1000,
                'allocated_shipping_discount' => 0,
                'allocated_shipping' => 3,
                'allocated_tax' => 30,
                'attributed_total' => 33,
                'tax_attribution_method' => 'aggregate_allocated',
            ],
        ], $result);
    }

    // -----------------------------------------------------------------
    // 6. Zero-basis fallback #2: shipping_total = 0 forces EVERY seller's
    //    allocated_shipping to 0 regardless of weight -> the line_detailed
    //    shipping-tax allocation falls back to the post-discount
    //    merchandise basis instead of riding a uniformly-zero shipping
    //    weight.
    // -----------------------------------------------------------------

    public function testZeroAllocatedShippingFallsBackToMerchandiseBasisForShippingTax(): void
    {
        $lines = [
            $this->line('line-a', 'seller-a', 1000, 0, 80),
            $this->line('line-b', 'seller-b', 3000, 0, 240),
        ];
        $totals = $this->totals(4000, 0, 0, 420, 4420);
        $breakdown = new TaxBreakdown(
            ['line-a' => 80, 'line-b' => 240],
            100,
            ['line-a', 'line-b']
        );

        $result = SellerAllocationCalculator::allocate($lines, $totals, 'none', $breakdown);

        self::assertSame([
            'seller-a' => [
                'subtotal' => 1000,
                'allocated_discount' => 0,
                'allocated_shipping_discount' => 0,
                'allocated_shipping' => 0,
                'allocated_tax' => 105,
                'attributed_total' => 1105,
                'tax_attribution_method' => 'line_detailed',
            ],
            'seller-b' => [
                'subtotal' => 3000,
                'allocated_discount' => 0,
                'allocated_shipping_discount' => 0,
                'allocated_shipping' => 0,
                'allocated_tax' => 315,
                'attributed_total' => 3315,
                'tax_attribution_method' => 'line_detailed',
            ],
        ], $result);
    }

    // -----------------------------------------------------------------
    // 7. Determinism: result key order is ascending seller_uuid regardless
    //    of the input lines' order.
    // -----------------------------------------------------------------

    public function testResultIsOrderedByAscendingSellerUuidRegardlessOfInputOrder(): void
    {
        $lines = [
            $this->line('line-c', 'seller-c', 1000, 0, 0),
            $this->line('line-a', 'seller-a', 1000, 0, 0),
            $this->line('line-b', 'seller-b', 1000, 0, 0),
        ];
        $totals = $this->totals(3000, 0, 0, 0, 3000);

        $result = SellerAllocationCalculator::allocate($lines, $totals, 'none', null);

        self::assertSame(['seller-a', 'seller-b', 'seller-c'], array_keys($result));
    }

    // -----------------------------------------------------------------
    // Integrity exceptions: contradictory input aborts (design spec §2.5)
    // -----------------------------------------------------------------

    public function testThrowsWhenLineSubtotalsDoNotReconcileWithOrderSubtotal(): void
    {
        $lines = [$this->line('line-1', 'seller-a', 1000, 0, 0)];
        // orders.subtotal (2000) contradicts the actual line total (1000).
        $totals = $this->totals(2000, 0, 0, 0, 2000);

        $this->expectException(SellerAllocationException::class);

        SellerAllocationCalculator::allocate($lines, $totals, 'none', null);
    }

    public function testThrowsWhenValueDiscountTotalDoesNotReconcileWithLineDiscountAmounts(): void
    {
        $lines = [$this->line('line-1', 'seller-a', 1000, 100, 0)];
        // orders.discount_total (300) contradicts the per-line discount_amount
        // sum (100) -- a caller bug, not a computable state.
        $totals = $this->totals(1000, 300, 0, 0, 900);

        $this->expectException(SellerAllocationException::class);

        SellerAllocationCalculator::allocate($lines, $totals, 'value', null);
    }

    public function testThrowsWhenLineDetailedTaxSumDoesNotReconcileWithOrderTaxTotal(): void
    {
        $lines = [$this->line('line-1', 'seller-a', 1000, 0, 80)];
        $breakdown = new TaxBreakdown(['line-1' => 80], 20, ['line-1']);
        // breakdown total (80 + 20 = 100) contradicts orders.tax_total (150)
        // -- e.g. the caller mismatched which quote produced which totals row.
        $totals = $this->totals(1000, 0, 0, 150, 1150);

        $this->expectException(SellerAllocationException::class);

        SellerAllocationCalculator::allocate($lines, $totals, 'none', $breakdown);
    }

    public function testThrowsWhenGrandTotalDoesNotReconcileWithAttributedTotals(): void
    {
        $lines = [$this->line('line-1', 'seller-a', 1000, 0, 0)];
        // Every other total is internally consistent (subtotal 1000, no
        // discount/shipping/tax) but grand_total (1000) was overridden to
        // something that does not match attributed_total's own formula.
        $totals = $this->totals(1000, 0, 0, 0, 1500);

        $this->expectException(SellerAllocationException::class);

        SellerAllocationCalculator::allocate($lines, $totals, 'none', null);
    }

    public function testIntegrityExceptionCarriesTheFailingInvariantAndBothValues(): void
    {
        $lines = [$this->line('line-1', 'seller-a', 1000, 0, 0)];
        $totals = $this->totals(2000, 0, 0, 0, 2000);

        try {
            SellerAllocationCalculator::allocate($lines, $totals, 'none', null);
            self::fail('Expected SellerAllocationException.');
        } catch (SellerAllocationException $e) {
            self::assertSame('subtotal', $e->invariant);
            self::assertSame(2000, $e->expected);
            self::assertSame(1000, $e->actual);
        }
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /** @return array{line_uuid:string, seller_uuid:string, line_total:int, discount_amount:int, tax_amount:int} */
    private function line(
        string $lineUuid,
        string $sellerUuid,
        int $lineTotal,
        int $discountAmount,
        int $taxAmount
    ): array {
        return [
            'line_uuid' => $lineUuid,
            'seller_uuid' => $sellerUuid,
            'line_total' => $lineTotal,
            'discount_amount' => $discountAmount,
            'tax_amount' => $taxAmount,
        ];
    }

    /** @return array{subtotal:int, discount_total:int, shipping_total:int, tax_total:int, grand_total:int} */
    private function totals(
        int $subtotal,
        int $discountTotal,
        int $shippingTotal,
        int $taxTotal,
        int $grandTotal
    ): array {
        return [
            'subtotal' => $subtotal,
            'discount_total' => $discountTotal,
            'shipping_total' => $shippingTotal,
            'tax_total' => $taxTotal,
            'grand_total' => $grandTotal,
        ];
    }
}
