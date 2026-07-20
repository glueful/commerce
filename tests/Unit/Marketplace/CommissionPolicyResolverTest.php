<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Marketplace;

use Glueful\Extensions\Commerce\Marketplace\CommissionPolicyException;
use Glueful\Extensions\Commerce\Marketplace\CommissionPolicyResolver;
use PHPUnit\Framework\TestCase;

/**
 * `CommissionPolicyResolver` validation + precedence matrix (design spec
 * §2.2, pinned): `validate()`'s all-null-inherits / `percentage`|`fixed`
 * shape rules / rejected mixed states, and `resolve()`'s product -> seller
 * -> workspace -> config precedence with a total (never-all-null) config
 * tail. Pure logic, zero DB/context dependency -- mirrors
 * {@see \Glueful\Extensions\Commerce\Tests\Unit\Marketplace\SellerAllocationCalculatorTest}'s
 * plain-TestCase convention for this codebase's other side-effect-free units.
 */
final class CommissionPolicyResolverTest extends TestCase
{
    // -----------------------------------------------------------------
    // validate() -- accepted shapes
    // -----------------------------------------------------------------

    public function testValidateAcceptsAllNullAsInherit(): void
    {
        CommissionPolicyResolver::validate(null, null, null);

        $this->addToAssertionCount(1);
    }

    /** @dataProvider validPercentageBpsProvider */
    public function testValidateAcceptsPercentageWithBpsInRangeAndNullFixed(int $bps): void
    {
        CommissionPolicyResolver::validate('percentage', $bps, null);

        $this->addToAssertionCount(1);
    }

    /** @return list<array{int}> */
    public static function validPercentageBpsProvider(): array
    {
        return [
            'lower bound' => [0],
            'mid range' => [5000],
            'upper bound' => [10000],
        ];
    }

    /** @dataProvider validFixedAmountProvider */
    public function testValidateAcceptsFixedWithNonNegativeFixedAndNullBps(int $fixed): void
    {
        CommissionPolicyResolver::validate('fixed', null, $fixed);

        $this->addToAssertionCount(1);
    }

    /** @return list<array{int}> */
    public static function validFixedAmountProvider(): array
    {
        return [
            'zero' => [0],
            'positive' => [500],
        ];
    }

    // -----------------------------------------------------------------
    // validate() -- rejected shapes
    // -----------------------------------------------------------------

    /** @dataProvider invalidPolicyProvider */
    public function testValidateRejectsInvalidCombination(?string $kind, ?int $bps, ?int $fixed): void
    {
        $this->expectException(CommissionPolicyException::class);

        CommissionPolicyResolver::validate($kind, $bps, $fixed);
    }

    /** @return array<string, array{?string,?int,?int}> */
    public static function invalidPolicyProvider(): array
    {
        return [
            'percentage with fixed also set' => ['percentage', 5000, 100],
            'percentage bps above range' => ['percentage', 10001, null],
            'percentage bps below range' => ['percentage', -1, null],
            'percentage missing bps' => ['percentage', null, null],
            'fixed negative' => ['fixed', null, -1],
            'fixed with bps also set' => ['fixed', 100, 500],
            'fixed missing fixed' => ['fixed', null, null],
            'kind null but bps set' => [null, 100, null],
            'kind null but fixed set' => [null, null, 100],
            'unknown kind' => ['unknown', null, null],
        ];
    }

    // -----------------------------------------------------------------
    // resolve() -- precedence (product -> seller -> workspace -> config)
    // -----------------------------------------------------------------

    public function testResolvePrefersProductOverEveryOtherLevel(): void
    {
        $levels = [
            $this->level('percentage', 100, null),
            $this->level('percentage', 200, null),
            $this->level('fixed', null, 300),
            $this->level('percentage', 0, null),
        ];

        $result = CommissionPolicyResolver::resolve($levels);

        self::assertSame(
            ['source' => 'product', 'kind' => 'percentage', 'bps' => 100, 'fixed' => null],
            $result
        );
    }

    public function testResolvePrefersSellerOverWorkspaceAndConfigWhenProductInherits(): void
    {
        $levels = [
            $this->level(null, null, null),
            $this->level('fixed', null, 300),
            $this->level('percentage', 200, null),
            $this->level('percentage', 0, null),
        ];

        $result = CommissionPolicyResolver::resolve($levels);

        self::assertSame(
            ['source' => 'seller', 'kind' => 'fixed', 'bps' => null, 'fixed' => 300],
            $result
        );
    }

    public function testResolvePrefersWorkspaceOverConfigWhenProductAndSellerInherit(): void
    {
        $levels = [
            $this->level(null, null, null),
            $this->level(null, null, null),
            $this->level('percentage', 750, null),
            $this->level('percentage', 0, null),
        ];

        $result = CommissionPolicyResolver::resolve($levels);

        self::assertSame(
            ['source' => 'workspace', 'kind' => 'percentage', 'bps' => 750, 'fixed' => null],
            $result
        );
    }

    public function testResolveFallsBackToConfigWhenEveryDatabaseLevelInherits(): void
    {
        $levels = [
            $this->level(null, null, null),
            $this->level(null, null, null),
            $this->level(null, null, null),
            $this->level('percentage', 0, null),
        ];

        $result = CommissionPolicyResolver::resolve($levels);

        self::assertSame(
            ['source' => 'config', 'kind' => 'percentage', 'bps' => 0, 'fixed' => null],
            $result
        );
    }

    public function testResolveHonorsANonNullMiddleLevelEvenWithEarlierLevelsNull(): void
    {
        $levels = [
            $this->level(null, null, null),
            $this->level('fixed', null, 150),
            $this->level('percentage', 750, null),
            $this->level('percentage', 0, null),
        ];

        $result = CommissionPolicyResolver::resolve($levels);

        self::assertSame(
            ['source' => 'seller', 'kind' => 'fixed', 'bps' => null, 'fixed' => 150],
            $result
        );
    }

    public function testResolveThrowsWhenConfigTailIsAllNull(): void
    {
        $levels = [
            $this->level(null, null, null),
            $this->level(null, null, null),
            $this->level(null, null, null),
            $this->level(null, null, null),
        ];

        $this->expectException(CommissionPolicyException::class);

        CommissionPolicyResolver::resolve($levels);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /** @return array{kind:?string,bps:?int,fixed:?int} */
    private function level(?string $kind, ?int $bps, ?int $fixed): array
    {
        return ['kind' => $kind, 'bps' => $bps, 'fixed' => $fixed];
    }
}
