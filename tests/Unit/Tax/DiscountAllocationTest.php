<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Tax;

use Glueful\Extensions\Commerce\Tax\DiscountAllocation;
use PHPUnit\Framework\TestCase;

/**
 * Discount-allocation unit matrix (design spec §5, pinned): largest-remainder
 * exactness including rounding orphans, deterministic `line_uuid` ties,
 * product_scope eligibility, `free_shipping` allocating nothing, the
 * extended-amount/no-double-quantity contract, and floor-at-0.
 */
final class DiscountAllocationTest extends TestCase
{
    // -----------------------------------------------------------------
    // No discount / zero discount total
    // -----------------------------------------------------------------

    public function testNullDiscountLeavesLinesFullyTaxable(): void
    {
        $lines = [$this->line('a', 'p1', 1000, 1), $this->line('b', 'p2', 500, 2)];

        $taxable = DiscountAllocation::taxableLines($lines, null, 0);

        self::assertSame(1000, $taxable[0]['taxable_amount']);
        self::assertSame(1000, $taxable[1]['taxable_amount']);
    }

    public function testZeroDiscountTotalAllocatesNothing(): void
    {
        $lines = [$this->line('a', 'p1', 1000, 1)];
        $discount = ['type' => 'percentage', 'value' => 0, 'product_scope' => null];

        self::assertSame([], DiscountAllocation::allocate($lines, $discount, 0));
    }

    // -----------------------------------------------------------------
    // Largest remainder exactness
    // -----------------------------------------------------------------

    public function testAllocationSumsExactlyToDiscountTotalDespiteRoundingOrphans(): void
    {
        // Three equal lines of 100 each (base 300), discount total 100 -- an
        // even split would be 33.33 each; the remainder unit must go to
        // exactly one line so the allocations sum to exactly 100.
        $lines = [
            $this->line('l1', 'p1', 100, 1),
            $this->line('l2', 'p1', 100, 1),
            $this->line('l3', 'p1', 100, 1),
        ];
        $discount = ['type' => 'fixed', 'value' => 100, 'product_scope' => null];

        $allocations = DiscountAllocation::allocate($lines, $discount, 100);

        self::assertSame(100, array_sum($allocations));
        // floor(100*100/300) = 33 for each (remainder=100 each, tied) --
        // remainder units = 100 - 99 = 1, awarded to the ascending-uuid
        // winner among the tied remainders ('l1').
        self::assertSame(34, $allocations['l1']);
        self::assertSame(33, $allocations['l2']);
        self::assertSame(33, $allocations['l3']);
    }

    public function testRemainderUnitsGoToLinesWithLargestRemainderFirst(): void
    {
        // Weighted lines: 500 and 300 (base 800), discount 100.
        // Exact shares: 62.5 and 37.5 -- floors 62/37 (sum 99), remainder=1
        // unit goes to whichever remainder is larger (both .5 here so this
        // exercises the tie-break path via line_uuid instead).
        $lines = [
            $this->line('lineB', 'p1', 300, 1),
            $this->line('lineA', 'p1', 500, 1),
        ];
        $discount = ['type' => 'fixed', 'value' => 100, 'product_scope' => null];

        $allocations = DiscountAllocation::allocate($lines, $discount, 100);

        self::assertSame(100, array_sum($allocations));
        // Equal .5 remainders -- ascending line_uuid ("lineA" < "lineB") wins
        // the extra unit.
        self::assertSame(63, $allocations['lineA']);
        self::assertSame(37, $allocations['lineB']);
    }

    public function testLineUuidTieBreakIsDeterministicRegardlessOfInputOrder(): void
    {
        $linesInOrderOne = [
            $this->line('zzz', 'p1', 100, 1),
            $this->line('aaa', 'p1', 100, 1),
        ];
        $linesInOrderTwo = [
            $this->line('aaa', 'p1', 100, 1),
            $this->line('zzz', 'p1', 100, 1),
        ];
        $discount = ['type' => 'fixed', 'value' => 1, 'product_scope' => null];

        $allocationsOne = DiscountAllocation::allocate($linesInOrderOne, $discount, 1);
        $allocationsTwo = DiscountAllocation::allocate($linesInOrderTwo, $discount, 1);

        self::assertSame(['aaa' => 1], $allocationsOne);
        self::assertSame(['aaa' => 1], $allocationsTwo);
    }

    // -----------------------------------------------------------------
    // product_scope eligibility
    // -----------------------------------------------------------------

    public function testProductScopedDiscountLeavesIneligibleLinesUntouched(): void
    {
        $lines = [
            $this->line('l1', 'p1', 1000, 1),
            $this->line('l2', 'p2', 1000, 1),
        ];
        $discount = ['type' => 'fixed', 'value' => 500, 'product_scope' => ['p1']];

        $taxable = DiscountAllocation::taxableLines($lines, $discount, 500);

        self::assertSame(500, $taxable[0]['taxable_amount'], 'p1 line: 1000 - 500 allocation');
        self::assertSame(1000, $taxable[1]['taxable_amount'], 'p2 line ineligible: untouched');
    }

    public function testAbsentScopeMakesEveryLineEligible(): void
    {
        $lines = [
            $this->line('l1', 'p1', 1000, 1),
            $this->line('l2', 'p2', 1000, 1),
        ];
        $discount = ['type' => 'percentage', 'value' => 1000, 'product_scope' => null];

        $allocations = DiscountAllocation::allocate($lines, $discount, 200);

        self::assertCount(2, $allocations);
        self::assertSame(200, array_sum($allocations));
    }

    // -----------------------------------------------------------------
    // free_shipping
    // -----------------------------------------------------------------

    public function testFreeShippingDiscountAllocatesNothingToLines(): void
    {
        $lines = [$this->line('l1', 'p1', 1000, 1)];
        $discount = ['type' => 'free_shipping', 'value' => 0, 'product_scope' => null];

        $allocations = DiscountAllocation::allocate($lines, $discount, 500);

        self::assertSame([], $allocations);
    }

    public function testFreeShippingLeavesLineTaxableAmountsUnreduced(): void
    {
        $lines = [$this->line('l1', 'p1', 1000, 1)];
        $discount = ['type' => 'free_shipping', 'value' => 0, 'product_scope' => null];

        $taxable = DiscountAllocation::taxableLines($lines, $discount, 500);

        self::assertSame(1000, $taxable[0]['taxable_amount']);
    }

    // -----------------------------------------------------------------
    // Extended-amount / no-double-quantity contract
    // -----------------------------------------------------------------

    public function testTaxableAmountIsExtendedNotUnitPrice(): void
    {
        $lines = [$this->line('l1', 'p1', 250, 4)];

        $taxable = DiscountAllocation::taxableLines($lines, null, 0);

        self::assertSame(1000, $taxable[0]['taxable_amount'], 'unit_price(250) * quantity(4)');
        self::assertSame(4, $taxable[0]['quantity']);
    }

    // -----------------------------------------------------------------
    // Floor at 0
    // -----------------------------------------------------------------

    public function testTaxableAmountFlooredAtZeroWhenAllocationExceedsLineExtended(): void
    {
        // Defensive floor: DiscountAllocation is a pure function that doesn't
        // itself assume the caller capped discountTotal at the eligible base
        // (PricingEngine already does this in normal operation) --
        // taxable_amount must still floor at 0 rather than go negative if it
        // is ever handed an allocation exceeding a line's own extended total.
        $lines = [$this->line('l1', 'p1', 100, 1)];
        $discount = ['type' => 'fixed', 'value' => 150, 'product_scope' => null];

        $taxable = DiscountAllocation::taxableLines($lines, $discount, 150);

        self::assertSame(0, $taxable[0]['taxable_amount']);
    }

    // -----------------------------------------------------------------
    // tax_class carried through
    // -----------------------------------------------------------------

    public function testTaxClassDefaultsToStandardWhenMissing(): void
    {
        $lines = [['line_uuid' => 'l1', 'product_uuid' => 'p1', 'unit_price' => 100, 'quantity' => 1]];

        $taxable = DiscountAllocation::taxableLines($lines, null, 0);

        self::assertSame('standard', $taxable[0]['tax_class']);
    }

    public function testTaxClassCarriedThroughFromLine(): void
    {
        $lines = [$this->line('l1', 'p1', 100, 1, 'reduced')];

        $taxable = DiscountAllocation::taxableLines($lines, null, 0);

        self::assertSame('reduced', $taxable[0]['tax_class']);
    }

    // -----------------------------------------------------------------
    // line_uuid preserved in taxableLines() rows (design spec §2.4/§5)
    // -----------------------------------------------------------------

    public function testTaxableLinesPreservesLineUuid(): void
    {
        $lines = [$this->line('l1', 'p1', 1000, 1), $this->line('l2', 'p2', 500, 2)];

        $taxable = DiscountAllocation::taxableLines($lines, null, 0);

        self::assertSame('l1', $taxable[0]['line_uuid']);
        self::assertSame('l2', $taxable[1]['line_uuid']);
    }

    public function testTaxableLinesPreservesLineUuidAlongsideDiscountAllocation(): void
    {
        $lines = [
            $this->line('l1', 'p1', 1000, 1),
            $this->line('l2', 'p2', 1000, 1),
        ];
        $discount = ['type' => 'fixed', 'value' => 500, 'product_scope' => ['p1']];

        $taxable = DiscountAllocation::taxableLines($lines, $discount, 500);

        self::assertSame('l1', $taxable[0]['line_uuid']);
        self::assertSame('l2', $taxable[1]['line_uuid']);
    }

    /** @return array<string,mixed> */
    private function line(string $lineUuid, string $productUuid, int $unitPrice, int $quantity, string $taxClass = 'standard'): array
    {
        return [
            'line_uuid' => $lineUuid,
            'product_uuid' => $productUuid,
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'tax_class' => $taxClass,
        ];
    }
}
