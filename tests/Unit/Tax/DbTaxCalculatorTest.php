<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Tax;

use Glueful\Extensions\Commerce\Tax\DbTaxCalculator;
use Glueful\Extensions\Commerce\Tax\TaxRateOverflowException;
use Glueful\Extensions\Commerce\Tax\TaxRateRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * Rate-selection matrix (design spec §5, pinned): class match, priority
 * order, state/postcode narrowing (identical convention to
 * {@see \Glueful\Extensions\Commerce\Shipping\ZoneMatcher}), no-rate-for-class
 * taxes at 0 (open-vocabulary, never falls back to `standard`), the
 * shipping-tax rule, the sole-applied-rate label rule, and checked-multiply
 * overflow.
 */
final class DbTaxCalculatorTest extends CommerceTestCase
{
    // -----------------------------------------------------------------
    // Rate selection: class match / no match
    // -----------------------------------------------------------------

    public function testLineTaxedAtMatchingClassRate(): void
    {
        $this->insertRate(['country' => 'US', 'rate_bps' => 1000, 'label' => 'Standard', 'class' => 'standard']);

        $quote = $this->calculator()->quoteDetailed(
            $this->context,
            [$this->taxableLine(1000, 'standard')],
            0,
            ['country' => 'US']
        );

        self::assertSame(100, $quote->amount);
        self::assertSame('Standard', $quote->label);
    }

    public function testOpenVocabularyClassWithNoMatchingRateTaxesAtZero(): void
    {
        $this->insertRate(['country' => 'US', 'rate_bps' => 1000, 'label' => 'Standard', 'class' => 'standard']);

        $quote = $this->calculator()->quoteDetailed(
            $this->context,
            [$this->taxableLine(1000, 'giftcard')],
            0,
            ['country' => 'US']
        );

        self::assertSame(0, $quote->amount, 'a class with no matching rate must NOT fall back to standard');
    }

    public function testNoRateAtAllForCountryTaxesAtZero(): void
    {
        $quote = $this->calculator()->quoteDetailed(
            $this->context,
            [$this->taxableLine(1000, 'standard')],
            0,
            ['country' => 'US']
        );

        self::assertSame(0, $quote->amount);
    }

    public function testZeroTaxableAmountLineIsSkipped(): void
    {
        $this->insertRate(['country' => 'US', 'rate_bps' => 1000, 'label' => 'Standard', 'class' => 'standard']);

        $quote = $this->calculator()->quoteDetailed(
            $this->context,
            [$this->taxableLine(0, 'standard')],
            0,
            ['country' => 'US']
        );

        self::assertSame(0, $quote->amount);
    }

    // -----------------------------------------------------------------
    // Rate selection: priority ASC then uuid ASC, first wins
    // -----------------------------------------------------------------

    public function testLowerPriorityRateWinsOverHigherPriority(): void
    {
        $this->insertRate([
            'country' => 'US', 'rate_bps' => 500, 'label' => 'Low priority', 'priority' => 5,
        ]);
        $this->insertRate([
            'country' => 'US', 'rate_bps' => 1000, 'label' => 'High priority', 'priority' => 1,
        ]);

        $quote = $this->calculator()->quoteDetailed(
            $this->context,
            [$this->taxableLine(1000, 'standard')],
            0,
            ['country' => 'US']
        );

        self::assertSame(100, $quote->amount, 'priority=1 (bps 1000) must win over priority=5');
        self::assertSame('High priority', $quote->label);
    }

    public function testEqualPriorityBreaksTieByUuidAscending(): void
    {
        $this->insertRate([
            'country' => 'US', 'rate_bps' => 500, 'label' => 'From B', 'priority' => 0, 'uuid' => 'ratebb000002',
        ]);
        $this->insertRate([
            'country' => 'US', 'rate_bps' => 1000, 'label' => 'From A', 'priority' => 0, 'uuid' => 'rateaa000001',
        ]);

        $quote = $this->calculator()->quoteDetailed(
            $this->context,
            [$this->taxableLine(1000, 'standard')],
            0,
            ['country' => 'US']
        );

        self::assertSame('From A', $quote->label);
    }

    // -----------------------------------------------------------------
    // Rate selection: state narrowing
    // -----------------------------------------------------------------

    public function testStateSpecificRateAppliesOnlyToMatchingState(): void
    {
        $this->insertRate([
            'country' => 'US', 'state' => 'US:CA', 'rate_bps' => 900, 'label' => 'CA Tax',
        ]);

        $matching = $this->calculator()->quoteDetailed(
            $this->context,
            [$this->taxableLine(1000, 'standard')],
            0,
            ['country' => 'US', 'state' => 'CA']
        );
        $nonMatching = $this->calculator()->quoteDetailed(
            $this->context,
            [$this->taxableLine(1000, 'standard')],
            0,
            ['country' => 'US', 'state' => 'NY']
        );

        self::assertSame(90, $matching->amount);
        self::assertSame(0, $nonMatching->amount);
    }

    public function testCountryOnlyRateAppliesRegardlessOfState(): void
    {
        $this->insertRate(['country' => 'US', 'rate_bps' => 700, 'label' => 'National']);

        $quote = $this->calculator()->quoteDetailed(
            $this->context,
            [$this->taxableLine(1000, 'standard')],
            0,
            ['country' => 'US', 'state' => 'TX']
        );

        self::assertSame(70, $quote->amount);
    }

    // -----------------------------------------------------------------
    // Rate selection: postcode narrowing
    // -----------------------------------------------------------------

    public function testPostcodeExactMatch(): void
    {
        $this->insertRate([
            'country' => 'US', 'postcode_pattern' => '90210', 'rate_bps' => 950, 'label' => 'Beverly Hills',
        ]);

        $matching = $this->calculator()->quoteDetailed(
            $this->context,
            [$this->taxableLine(1000, 'standard')],
            0,
            ['country' => 'US', 'postcode' => '90210']
        );
        $nonMatching = $this->calculator()->quoteDetailed(
            $this->context,
            [$this->taxableLine(1000, 'standard')],
            0,
            ['country' => 'US', 'postcode' => '10001']
        );

        self::assertSame(95, $matching->amount);
        self::assertSame(0, $nonMatching->amount);
    }

    public function testPostcodeWildcardMatch(): void
    {
        $this->insertRate([
            'country' => 'US', 'postcode_pattern' => '90*', 'rate_bps' => 950, 'label' => 'LA Area',
        ]);

        $quote = $this->calculator()->quoteDetailed(
            $this->context,
            [$this->taxableLine(1000, 'standard')],
            0,
            ['country' => 'US', 'postcode' => '90210']
        );

        self::assertSame(95, $quote->amount);
    }

    // -----------------------------------------------------------------
    // Shipping-tax rule
    // -----------------------------------------------------------------

    public function testShippingTaxedByFirstMatchingStandardShippingTaxableRate(): void
    {
        $this->insertRate([
            'country' => 'US', 'rate_bps' => 800, 'label' => 'Standard', 'shipping_taxable' => true,
        ]);

        $quote = $this->calculator()->quoteDetailed($this->context, [], 1000, ['country' => 'US']);

        self::assertSame(80, $quote->amount);
        self::assertSame('Standard', $quote->label);
    }

    public function testShippingUntaxedWhenNoShippingTaxableRateMatches(): void
    {
        $this->insertRate([
            'country' => 'US', 'rate_bps' => 800, 'label' => 'Standard', 'shipping_taxable' => false,
        ]);

        $quote = $this->calculator()->quoteDetailed($this->context, [], 1000, ['country' => 'US']);

        self::assertSame(0, $quote->amount);
    }

    public function testShippingNeverTaxedByANonStandardClassRate(): void
    {
        $this->insertRate([
            'country' => 'US', 'rate_bps' => 800, 'label' => 'Reduced', 'class' => 'reduced',
            'shipping_taxable' => true,
        ]);

        $quote = $this->calculator()->quoteDetailed($this->context, [], 1000, ['country' => 'US']);

        self::assertSame(0, $quote->amount);
    }

    public function testZeroShippingAmountSkipsShippingTaxLookup(): void
    {
        $this->insertRate([
            'country' => 'US', 'rate_bps' => 800, 'label' => 'Standard', 'shipping_taxable' => true,
        ]);

        $quote = $this->calculator()->quoteDetailed($this->context, [], 0, ['country' => 'US']);

        self::assertSame(0, $quote->amount);
    }

    // -----------------------------------------------------------------
    // Label rule: sole applied rate vs multiple/zero
    // -----------------------------------------------------------------

    public function testLabelIsSoleAppliedRateAcrossMerchandiseAndShippingWhenSameRate(): void
    {
        $this->insertRate([
            'country' => 'US', 'rate_bps' => 1000, 'label' => 'Sales Tax', 'shipping_taxable' => true,
        ]);

        $quote = $this->calculator()->quoteDetailed(
            $this->context,
            [$this->taxableLine(1000, 'standard')],
            500,
            ['country' => 'US']
        );

        self::assertSame('Sales Tax', $quote->label);
    }

    public function testLabelIsGenericTaxWhenTwoDistinctRatesApply(): void
    {
        $this->insertRate([
            'country' => 'US', 'rate_bps' => 500, 'label' => 'Merch Tax', 'class' => 'standard',
        ]);
        $this->insertRate([
            'country' => 'US', 'rate_bps' => 800, 'label' => 'Reduced Tax', 'class' => 'reduced',
        ]);

        $quote = $this->calculator()->quoteDetailed(
            $this->context,
            [$this->taxableLine(1000, 'standard'), $this->taxableLine(1000, 'reduced')],
            0,
            ['country' => 'US']
        );

        self::assertSame('Tax', $quote->label);
    }

    public function testLabelIsGenericTaxWhenNoRateApplies(): void
    {
        $quote = $this->calculator()->quoteDetailed(
            $this->context,
            [$this->taxableLine(1000, 'standard')],
            0,
            ['country' => 'US']
        );

        self::assertSame('Tax', $quote->label);
    }

    // -----------------------------------------------------------------
    // Multi-line / multi-class summation
    // -----------------------------------------------------------------

    public function testMultipleLinesWithDifferentClassesSumIndependently(): void
    {
        $this->insertRate(['country' => 'US', 'rate_bps' => 1000, 'label' => 'Standard', 'class' => 'standard']);
        $this->insertRate(['country' => 'US', 'rate_bps' => 500, 'label' => 'Reduced', 'class' => 'reduced']);

        $quote = $this->calculator()->quoteDetailed(
            $this->context,
            [$this->taxableLine(1000, 'standard'), $this->taxableLine(1000, 'reduced')],
            0,
            ['country' => 'US']
        );

        self::assertSame(150, $quote->amount, '100 (standard) + 50 (reduced)');
    }

    // -----------------------------------------------------------------
    // Aggregate quote(): opaque standard base
    // -----------------------------------------------------------------

    public function testAggregateQuoteTreatsAmountAsOpaqueStandardBase(): void
    {
        $this->insertRate(['country' => 'US', 'rate_bps' => 1000, 'label' => 'Standard', 'class' => 'standard']);

        $quote = $this->calculator()->quote($this->context, 1000, ['country' => 'US']);

        self::assertSame(100, $quote->amount);
        self::assertSame('Standard', $quote->label);
    }

    public function testAggregateQuoteZeroForNonPositiveAmount(): void
    {
        $this->insertRate(['country' => 'US', 'rate_bps' => 1000, 'label' => 'Standard']);

        self::assertSame(0, $this->calculator()->quote($this->context, 0, ['country' => 'US'])->amount);
    }

    // -----------------------------------------------------------------
    // Overflow
    // -----------------------------------------------------------------

    public function testOverflowingMultiplyThrowsDomainException(): void
    {
        $this->insertRate(['country' => 'US', 'rate_bps' => 10000, 'label' => 'Max']);

        $this->expectException(TaxRateOverflowException::class);

        $this->calculator()->quoteDetailed(
            $this->context,
            [$this->taxableLine(PHP_INT_MAX, 'standard')],
            0,
            ['country' => 'US']
        );
    }

    public function testOverflowingAggregateQuoteThrowsDomainException(): void
    {
        $this->insertRate(['country' => 'US', 'rate_bps' => 10000, 'label' => 'Max']);

        $this->expectException(TaxRateOverflowException::class);

        $this->calculator()->quote($this->context, PHP_INT_MAX, ['country' => 'US']);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function calculator(): DbTaxCalculator
    {
        return new DbTaxCalculator(new TaxRateRepository(), new SentinelTenantResolver());
    }

    /** @return array{taxable_amount:int, tax_class:string, quantity:int} */
    private function taxableLine(int $taxableAmount, string $taxClass): array
    {
        return ['taxable_amount' => $taxableAmount, 'tax_class' => $taxClass, 'quantity' => 1];
    }

    /** @param array<string,mixed> $overrides */
    private function insertRate(array $overrides): void
    {
        $uuid = $overrides['uuid'] ?? ('rate' . substr(md5((string) random_int(0, PHP_INT_MAX)), 0, 8));
        $this->connection->table('commerce_tax_rates')->insert(array_merge([
            'uuid' => $uuid,
            'tenant_uuid' => '',
            'country' => 'US',
            'state' => null,
            'postcode_pattern' => null,
            'rate_bps' => 0,
            'label' => 'Tax',
            'priority' => 0,
            'shipping_taxable' => false,
            'class' => 'standard',
        ], $overrides));
    }
}
