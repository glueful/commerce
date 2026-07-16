<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Support;

use Glueful\Extensions\Commerce\Support\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testExponents(): void
    {
        self::assertSame(2, Money::exponentFor('USD'));
        self::assertSame(0, Money::exponentFor('JPY'));
        self::assertSame(3, Money::exponentFor('KWD'));
        self::assertNull(Money::exponentFor('XXX_NOPE'));
        self::assertNull(Money::exponentFor('usd'));
    }

    public function testFormatRespectsExponent(): void
    {
        self::assertSame('10.50', Money::format(1050, 'USD'));
        self::assertSame('1050', Money::format(1050, 'JPY'));
        self::assertSame('1.050', Money::format(1050, 'KWD'));
        self::assertSame('0.05', Money::format(5, 'USD'));
        self::assertSame('-10.50', Money::format(-1050, 'USD'));
    }

    public function testAssertValidCurrencyThrowsOnUnknown(): void
    {
        Money::assertValidCurrency('GHS');

        $this->expectException(\InvalidArgumentException::class);
        Money::assertValidCurrency('FAKE');
    }
}
