<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Pricing;

use Glueful\Extensions\Commerce\Pricing\TaxBreakdown;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * `TaxBreakdown` validation rules (design spec §2.4, pinned): duplicate
 * values in the known-line UUID LIST are rejected before the canonical map
 * is even built (duplicate associative keys can't be detected after PHP has
 * collapsed them); unknown `taxByLine` keys are rejected; omitted known
 * lines canonicalize to 0; `total()` sums the canonical map plus shipping.
 * `TaxQuote` cross-checks a non-null breakdown's `total()` against its own
 * amount.
 */
final class TaxBreakdownTest extends TestCase
{
    // -----------------------------------------------------------------
    // Construction order: duplicate knownLineUuids rejected FIRST
    // -----------------------------------------------------------------

    public function testDuplicateValuesInKnownLineUuidsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TaxBreakdown([], 0, ['line-a', 'line-b', 'line-a']);
    }

    public function testDuplicateKnownLineUuidsRejectedEvenWithEmptyTaxByLine(): void
    {
        // Regression guard: the duplicate check must not be skipped just
        // because taxByLine is empty -- it is validated against the RAW
        // list, before any map is built.
        $this->expectException(InvalidArgumentException::class);

        new TaxBreakdown([], 5, ['same', 'same']);
    }

    // -----------------------------------------------------------------
    // Unknown taxByLine key rejected
    // -----------------------------------------------------------------

    public function testUnknownTaxByLineKeyIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TaxBreakdown(['foreign-line' => 100], 0, ['line-a', 'line-b']);
    }

    // -----------------------------------------------------------------
    // Omitted known line canonicalized to 0
    // -----------------------------------------------------------------

    public function testOmittedKnownLineIsCanonicalizedToZero(): void
    {
        $breakdown = new TaxBreakdown(['line-a' => 150], 0, ['line-a', 'line-b']);

        self::assertSame(['line-a' => 150, 'line-b' => 0], $breakdown->taxByLine());
    }

    public function testEmptyTaxByLineCanonicalizesEveryKnownLineToZero(): void
    {
        $breakdown = new TaxBreakdown([], 0, ['line-a', 'line-b', 'line-c']);

        self::assertSame(['line-a' => 0, 'line-b' => 0, 'line-c' => 0], $breakdown->taxByLine());
    }

    public function testEmptyKnownLineUuidsProducesEmptyCanonicalMap(): void
    {
        $breakdown = new TaxBreakdown([], 250, []);

        self::assertSame([], $breakdown->taxByLine());
        self::assertSame(250, $breakdown->shippingTaxTotal());
        self::assertSame(250, $breakdown->total());
    }

    // -----------------------------------------------------------------
    // total() = sum(taxByLine) + shippingTaxTotal
    // -----------------------------------------------------------------

    public function testTotalSumsCanonicalizedTaxByLineAndShippingTaxTotal(): void
    {
        $breakdown = new TaxBreakdown(
            ['line-a' => 150, 'line-b' => 75],
            25,
            ['line-a', 'line-b', 'line-c']
        );

        self::assertSame(250, $breakdown->total(), '150 + 75 + 0 (canonicalized line-c) + 25 shipping');
    }

    public function testShippingTaxTotalAccessor(): void
    {
        $breakdown = new TaxBreakdown([], 42, []);

        self::assertSame(42, $breakdown->shippingTaxTotal());
    }

    // -----------------------------------------------------------------
    // TaxQuote <-> TaxBreakdown cross-check
    // -----------------------------------------------------------------

    public function testTaxQuoteAcceptsABreakdownWhoseTotalMatchesTheAmount(): void
    {
        $breakdown = new TaxBreakdown(['line-a' => 100], 20, ['line-a']);

        $quote = new TaxQuote(120, 'Sales Tax', $breakdown);

        self::assertSame(120, $quote->amount);
        self::assertSame($breakdown, $quote->breakdown);
    }

    public function testTaxQuoteRejectsABreakdownWhoseTotalDiffersFromTheAmount(): void
    {
        $breakdown = new TaxBreakdown(['line-a' => 100], 20, ['line-a']);

        $this->expectException(InvalidArgumentException::class);

        new TaxQuote(121, 'Sales Tax', $breakdown);
    }

    public function testTwoArgTaxQuoteConstructionRemainsValidWithNullBreakdown(): void
    {
        $quote = new TaxQuote(360);

        self::assertSame(360, $quote->amount);
        self::assertSame('Tax', $quote->label);
        self::assertNull($quote->breakdown);
    }

    public function testThreeArgTaxQuoteConstructionAcceptsExplicitNullBreakdown(): void
    {
        $quote = new TaxQuote(50, 'Custom', null);

        self::assertNull($quote->breakdown);
    }
}
