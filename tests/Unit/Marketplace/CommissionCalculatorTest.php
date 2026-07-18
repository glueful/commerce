<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Marketplace;

use Glueful\Extensions\Commerce\Marketplace\CommissionCalculator;
use PHPUnit\Framework\TestCase;

/**
 * `CommissionCalculator` per-line + per-seller commission matrix (design
 * spec §2.1, pinned): `commission_basis = max(0, line_total - discount_amount)`
 * (shipping/shipping-discount/tax excluded); `percentage` half-up rounding
 * (`intdiv(basis * bps + 5000, 10000)`); `fixed` capped at basis; and
 * `perSeller()`'s hard-reconciled per-seller sum, mirroring
 * {@see \Glueful\Extensions\Commerce\Marketplace\SellerAllocationCalculator}'s
 * hard-reconciliation discipline. Pure logic, zero DB/context dependency --
 * mirrors {@see \Glueful\Extensions\Commerce\Tests\Unit\Marketplace\SellerAllocationCalculatorTest}'s
 * plain-TestCase convention for this codebase's other side-effect-free units.
 */
final class CommissionCalculatorTest extends TestCase
{
    // -----------------------------------------------------------------
    // commission_basis = max(0, line_total - discount_amount)
    // -----------------------------------------------------------------

    public function testBasisIsLineTotalMinusDiscountAmount(): void
    {
        $result = CommissionCalculator::lineCommission(1000, 300, $this->fixedPolicy(1000));

        self::assertSame(700, $result['commission_basis']);
    }

    public function testDiscountGreaterThanLineTotalFloorsBasisAtZero(): void
    {
        $result = CommissionCalculator::lineCommission(500, 800, $this->percentagePolicy(500));

        self::assertSame(0, $result['commission_basis']);
        self::assertSame(0, $result['commission_amount']);
    }

    // -----------------------------------------------------------------
    // percentage: intdiv(basis * bps + 5000, 10000), half-up
    // -----------------------------------------------------------------

    public function testPercentageAppliesHalfUpRounding(): void
    {
        // basis 999, bps 250 -> intdiv(999*250+5000, 10000) = intdiv(254750, 10000) = 25.
        $result = CommissionCalculator::lineCommission(999, 0, $this->percentagePolicy(250));

        self::assertSame(999, $result['commission_basis']);
        self::assertSame(25, $result['commission_amount']);
    }

    public function testPercentageWithZeroBpsYieldsZeroCommission(): void
    {
        $result = CommissionCalculator::lineCommission(1000, 0, $this->percentagePolicy(0));

        self::assertSame(1000, $result['commission_basis']);
        self::assertSame(0, $result['commission_amount']);
    }

    // -----------------------------------------------------------------
    // fixed: min(commission_fixed, basis)
    // -----------------------------------------------------------------

    public function testFixedCommissionCapsAtBasisWhenFixedExceedsBasis(): void
    {
        $result = CommissionCalculator::lineCommission(300, 0, $this->fixedPolicy(500));

        self::assertSame(300, $result['commission_basis']);
        self::assertSame(300, $result['commission_amount']);
    }

    public function testFixedCommissionUsesFixedAmountWhenBelowBasis(): void
    {
        $result = CommissionCalculator::lineCommission(300, 0, $this->fixedPolicy(200));

        self::assertSame(300, $result['commission_basis']);
        self::assertSame(200, $result['commission_amount']);
    }

    // -----------------------------------------------------------------
    // basis 0 -> amount 0, regardless of policy kind
    // -----------------------------------------------------------------

    /** @dataProvider zeroBasisPolicyProvider */
    public function testZeroBasisYieldsZeroCommissionRegardlessOfPolicyKind(array $policy): void
    {
        $result = CommissionCalculator::lineCommission(500, 500, $policy);

        self::assertSame(0, $result['commission_basis']);
        self::assertSame(0, $result['commission_amount']);
    }

    /** @return array<string, array{array{kind:string,bps:?int,fixed:?int}}> */
    public static function zeroBasisPolicyProvider(): array
    {
        return [
            'percentage' => [['kind' => 'percentage', 'bps' => 500, 'fixed' => null]],
            'fixed' => [['kind' => 'fixed', 'bps' => null, 'fixed' => 200]],
        ];
    }

    // -----------------------------------------------------------------
    // perSeller() -- hard-reconciled per-seller sum
    // -----------------------------------------------------------------

    public function testPerSellerSumsPerSellerAndReconciles(): void
    {
        $lineResults = [
            ['seller_uuid' => 'seller-a', 'commission_amount' => 25],
            ['seller_uuid' => 'seller-b', 'commission_amount' => 40],
            ['seller_uuid' => 'seller-a', 'commission_amount' => 15],
        ];

        $result = CommissionCalculator::perSeller($lineResults);

        self::assertSame(['seller-a' => 40, 'seller-b' => 40], $result);
    }

    public function testPerSellerReturnsEmptyArrayForNoLines(): void
    {
        self::assertSame([], CommissionCalculator::perSeller([]));
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /** @return array{kind:string,bps:?int,fixed:?int} */
    private function percentagePolicy(int $bps): array
    {
        return ['kind' => 'percentage', 'bps' => $bps, 'fixed' => null];
    }

    /** @return array{kind:string,bps:?int,fixed:?int} */
    private function fixedPolicy(int $fixed): array
    {
        return ['kind' => 'fixed', 'bps' => null, 'fixed' => $fixed];
    }
}
