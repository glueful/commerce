<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Reports;

use Glueful\Extensions\Commerce\Reports\ReportConfigurationException;
use Glueful\Extensions\Commerce\Reports\StockThreshold;
use PHPUnit\Framework\TestCase;

final class StockThresholdTest extends TestCase
{
    // -- override wins when present ------------------------------------------

    public function testOverrideWinsOverConfiguredValue(): void
    {
        self::assertSame(10, StockThreshold::resolve(10, 2));
    }

    public function testOverrideZeroIsAllowed(): void
    {
        self::assertSame(0, StockThreshold::resolve(0, 2));
    }

    public function testOverrideAtUpperBoundIsAllowed(): void
    {
        self::assertSame(100000, StockThreshold::resolve(100000, 2));
    }

    public function testOverrideNegativeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StockThreshold::resolve(-1, 2);
    }

    public function testOverrideAboveUpperBoundIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StockThreshold::resolve(100001, 2);
    }

    // -- falls back to configured value when override is null ----------------

    public function testNullOverrideFallsBackToConfiguredValue(): void
    {
        self::assertSame(2, StockThreshold::resolve(null, 2));
    }

    public function testConfiguredZeroIsAllowed(): void
    {
        self::assertSame(0, StockThreshold::resolve(null, 0));
    }

    public function testConfiguredAtUpperBoundIsAllowed(): void
    {
        self::assertSame(100000, StockThreshold::resolve(null, 100000));
    }

    public function testConfiguredNumericStringIsCoerced(): void
    {
        self::assertSame(5, StockThreshold::resolve(null, '5'));
    }

    // -- invalid configured value: named configuration error, never clamp ----

    public function testConfiguredNegativeThrowsReportConfigurationException(): void
    {
        $this->expectException(ReportConfigurationException::class);
        $this->expectExceptionMessageMatches('/low_stock_threshold/');

        StockThreshold::resolve(null, -1);
    }

    public function testConfiguredAboveUpperBoundThrowsReportConfigurationException(): void
    {
        $this->expectException(ReportConfigurationException::class);
        $this->expectExceptionMessageMatches('/low_stock_threshold/');

        StockThreshold::resolve(null, 100001);
    }

    public function testConfiguredNonNumericThrowsReportConfigurationException(): void
    {
        $this->expectException(ReportConfigurationException::class);

        StockThreshold::resolve(null, 'not-a-number');
    }

    public function testConfiguredNullThrowsReportConfigurationException(): void
    {
        $this->expectException(ReportConfigurationException::class);

        StockThreshold::resolve(null, null);
    }

    public function testInvalidConfigIsNeverClampedToBounds(): void
    {
        try {
            StockThreshold::resolve(null, 999999);
            self::fail('Expected ReportConfigurationException.');
        } catch (ReportConfigurationException $e) {
            // Must fail loudly, not silently clamp to 100000.
            self::assertStringContainsString('100000', $e->getMessage());
        }
    }
}
