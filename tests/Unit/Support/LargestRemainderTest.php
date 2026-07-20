<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Support;

use Glueful\Extensions\Commerce\Support\LargestRemainder;
use PHPUnit\Framework\TestCase;

/**
 * Generic largest-remainder distribution unit matrix (design spec §5,
 * pinned): integer-exact sums despite rounding orphans, deterministic
 * ascending-key tie-break, all-zero-weight even split, single-bucket and
 * zero-total edge cases, and full key preservation.
 */
final class LargestRemainderTest extends TestCase
{
    public function testExactSumForNonTrivialRemainders(): void
    {
        // Three equal weights (sum 3), total 100: exact share is 33.33 each --
        // floor(100*1/3) = 33 for each (remainder = 1 for each, tied), leaving
        // one leftover unit (100 - 99 = 1) awarded to the ascending-key
        // winner among the tied remainders ('a').
        $result = LargestRemainder::distribute(['a' => 1, 'b' => 1, 'c' => 1], 100);

        self::assertSame(100, array_sum($result));
        self::assertSame(['a' => 34, 'b' => 33, 'c' => 33], $result);
    }

    public function testAscendingKeyTieBreakWhenRemaindersEqual(): void
    {
        // Weighted buckets 500 and 300 (sum 800), total 100. Exact shares are
        // 62.5 and 37.5 -- floors 62/37 (sum 99, remainder unit = 1) with both
        // remainders tied at .5 (400/800), so the ascending key wins.
        $result = LargestRemainder::distribute(['lineB' => 300, 'lineA' => 500], 100);

        self::assertSame(100, array_sum($result));
        self::assertSame(63, $result['lineA']);
        self::assertSame(37, $result['lineB']);
    }

    public function testTieBreakIsDeterministicRegardlessOfInputOrder(): void
    {
        $orderOne = LargestRemainder::distribute(['zzz' => 100, 'aaa' => 100], 1);
        $orderTwo = LargestRemainder::distribute(['aaa' => 100, 'zzz' => 100], 1);

        self::assertSame(['zzz' => 0, 'aaa' => 1], $orderOne);
        self::assertSame(['aaa' => 1, 'zzz' => 0], $orderTwo);
    }

    public function testAllZeroWeightsSplitEvenlyByAscendingKey(): void
    {
        // No weight information at all: fall back to an even split of the
        // total, leftover units awarded to the ascending keys first.
        $result = LargestRemainder::distribute(['c' => 0, 'a' => 0, 'b' => 0], 10);

        self::assertSame(10, array_sum($result));
        self::assertSame(['c' => 3, 'a' => 4, 'b' => 3], $result);
    }

    public function testEmptyWeightsReturnsEmptyResult(): void
    {
        self::assertSame([], LargestRemainder::distribute([], 10));
    }

    public function testSingleBucketGetsWholeTotal(): void
    {
        self::assertSame(['only' => 50], LargestRemainder::distribute(['only' => 7], 50));
    }

    public function testSingleZeroWeightBucketStillGetsWholeTotal(): void
    {
        self::assertSame(['only' => 50], LargestRemainder::distribute(['only' => 0], 50));
    }

    public function testTotalZeroYieldsAllZero(): void
    {
        self::assertSame(['a' => 0, 'b' => 0], LargestRemainder::distribute(['a' => 5, 'b' => 3], 0));
    }

    public function testTotalZeroWithAllZeroWeightsYieldsAllZero(): void
    {
        self::assertSame(['a' => 0, 'b' => 0], LargestRemainder::distribute(['a' => 0, 'b' => 0], 0));
    }

    public function testEveryInputKeyIsPresentInOutputEvenWhenZero(): void
    {
        $result = LargestRemainder::distribute(['a' => 0, 'b' => 1000], 1);

        self::assertSame(['a', 'b'], array_keys($result));
        self::assertArrayHasKey('a', $result);
        self::assertSame(0, $result['a']);
        self::assertSame(1, $result['b']);
    }
}
