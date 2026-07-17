<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Tax;

use Glueful\Extensions\Commerce\Pricing\TaxBreakdown;
use Glueful\Extensions\Commerce\Tax\DbTaxCalculator;
use Glueful\Extensions\Commerce\Tax\DelegatingTaxCalculator;
use Glueful\Extensions\Commerce\Tax\FlatRateTaxCalculator;
use Glueful\Extensions\Commerce\Tax\TaxRateRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * Delegation precedence (design spec §4): Db when the tenant has rate rows,
 * else flat-rate config -- for BOTH the aggregate and detailed contracts.
 * The no-rows `quoteDetailed()` path must reconstruct the exact legacy
 * aggregate base and produce a byte-identical result to going straight
 * through {@see FlatRateTaxCalculator}.
 */
final class DelegatingTaxCalculatorTest extends CommerceTestCase
{
    // -----------------------------------------------------------------
    // No rate rows: byte-parity with FlatRateTaxCalculator
    // -----------------------------------------------------------------

    public function testNoRateRowsAggregateQuoteDelegatesToFlatRateByteIdentical(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['tax' => ['flat_rate_bps' => 825]]);

        $expected = (new FlatRateTaxCalculator())->quote($this->context, 999, ['country' => 'US']);
        $actual = $this->delegator()->quote($this->context, 999, ['country' => 'US']);

        self::assertEquals($expected, $actual);
    }

    public function testNoRateRowsQuoteDetailedReconstructsLegacyBaseByteIdentical(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['tax' => ['flat_rate_bps' => 825]]);

        $taxableLines = [
            ['taxable_amount' => 1000, 'tax_class' => 'standard', 'quantity' => 1],
            ['taxable_amount' => 2000, 'tax_class' => 'reduced', 'quantity' => 2],
        ];
        $shippingAmount = 500;
        $legacyBase = 1000 + 2000 + 500;

        $expected = (new FlatRateTaxCalculator())->quote($this->context, $legacyBase, ['country' => 'US']);
        $actual = $this->delegator()->quoteDetailed(
            $this->context,
            $taxableLines,
            $shippingAmount,
            ['country' => 'US']
        );

        self::assertEquals($expected, $actual);
    }

    public function testNoRateRowsQuoteDetailedWithNoTaxableLinesUsesShippingOnlyBase(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['tax' => ['flat_rate_bps' => 1000]]);

        $expected = (new FlatRateTaxCalculator())->quote($this->context, 500, ['country' => 'US']);
        $actual = $this->delegator()->quoteDetailed($this->context, [], 500, ['country' => 'US']);

        self::assertEquals($expected, $actual);
    }

    /**
     * Attribution-method detection (design spec §2.4) is by breakdown
     * PRESENCE -- the flat-rate fallback never attaches one, even though
     * `DelegatingTaxCalculator` implements `LineTaxCalculator` throughout.
     */
    public function testNoRateRowsQuoteDetailedReturnsNullBreakdownOnFlatFallback(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['tax' => ['flat_rate_bps' => 825]]);

        $taxableLines = [
            ['taxable_amount' => 1000, 'tax_class' => 'standard', 'quantity' => 1, 'line_uuid' => 'line-a'],
            ['taxable_amount' => 2000, 'tax_class' => 'reduced', 'quantity' => 2, 'line_uuid' => 'line-b'],
        ];

        $actual = $this->delegator()->quoteDetailed($this->context, $taxableLines, 500, ['country' => 'US']);

        self::assertNull($actual->breakdown);
    }

    // -----------------------------------------------------------------
    // Rate rows present: delegates to Db even when flat config would also
    // produce a result
    // -----------------------------------------------------------------

    public function testRateRowsPresentAggregateQuoteDelegatesToDb(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['tax' => ['flat_rate_bps' => 999]]);
        $this->insertRate(['country' => 'US', 'rate_bps' => 1000, 'label' => 'DB Standard']);

        $quote = $this->delegator()->quote($this->context, 1000, ['country' => 'US']);

        self::assertSame(100, $quote->amount);
        self::assertSame('DB Standard', $quote->label);
    }

    public function testRateRowsPresentQuoteDetailedDelegatesToDb(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['tax' => ['flat_rate_bps' => 999]]);
        $this->insertRate(['country' => 'US', 'rate_bps' => 1000, 'label' => 'DB Standard']);

        $taxableLines = [
            ['taxable_amount' => 1000, 'tax_class' => 'standard', 'quantity' => 1, 'line_uuid' => 'line-a'],
        ];
        $quote = $this->delegator()->quoteDetailed($this->context, $taxableLines, 0, ['country' => 'US']);

        self::assertSame(100, $quote->amount);
        self::assertSame('DB Standard', $quote->label);
    }

    /**
     * The inverse of the flat-fallback case: once the tenant has rate rows,
     * the delegator's DB path attaches a real breakdown.
     */
    public function testRateRowsPresentQuoteDetailedReturnsNonNullBreakdown(): void
    {
        $this->insertRate(['country' => 'US', 'rate_bps' => 1000, 'label' => 'DB Standard']);

        $taxableLines = [
            ['taxable_amount' => 1000, 'tax_class' => 'standard', 'quantity' => 1, 'line_uuid' => 'line-a'],
        ];
        $quote = $this->delegator()->quoteDetailed($this->context, $taxableLines, 0, ['country' => 'US']);

        self::assertInstanceOf(TaxBreakdown::class, $quote->breakdown);
        self::assertSame(['line-a' => 100], $quote->breakdown->taxByLine());
        self::assertSame($quote->amount, $quote->breakdown->total());
    }

    /**
     * A tenant with rate rows is wholly on the data-driven path -- even a
     * miss (no rate matches this address) must NOT fall through to flat
     * config.
     */
    public function testRateRowsPresentButNoMatchDoesNotFallThroughToFlat(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['tax' => ['flat_rate_bps' => 999]]);
        $this->insertRate(['country' => 'CA', 'rate_bps' => 1000, 'label' => 'CA Only']);

        $quote = $this->delegator()->quote($this->context, 1000, ['country' => 'US']);

        self::assertSame(0, $quote->amount);
    }

    private function delegator(): DelegatingTaxCalculator
    {
        return new DelegatingTaxCalculator(
            new DbTaxCalculator(new TaxRateRepository(), new SentinelTenantResolver()),
            new FlatRateTaxCalculator()
        );
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
