<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Tax;

use Glueful\Extensions\Commerce\Tax\FlatRateTaxCalculator;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

final class FlatRateTaxCalculatorTest extends CommerceTestCase
{
    public function testFlatRateTaxRoundsHalfUp(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['tax' => ['flat_rate_bps' => 825]]);

        $quote = (new FlatRateTaxCalculator())->quote($this->context, 999, []);

        self::assertSame(82, $quote->amount);
    }

    public function testZeroRateReturnsZeroQuote(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['tax' => ['flat_rate_bps' => 0]]);

        $quote = (new FlatRateTaxCalculator())->quote($this->context, 999, []);

        self::assertSame(0, $quote->amount);
    }
}
