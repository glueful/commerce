<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Catalog;

use Glueful\Extensions\Commerce\Catalog\ProductType;
use PHPUnit\Framework\TestCase;

final class ProductTypeTest extends TestCase
{
    public function testAllReturnsTheClosedVocabularyInOrder(): void
    {
        self::assertSame(['physical', 'digital', 'external', 'grouped'], ProductType::all());
    }

    /** @dataProvider validTypeProvider */
    public function testIsValidAcceptsEveryDeclaredType(string $type): void
    {
        self::assertTrue(ProductType::isValid($type));
    }

    /** @return list<array{string}> */
    public static function validTypeProvider(): array
    {
        return [['physical'], ['digital'], ['external'], ['grouped']];
    }

    /** @dataProvider invalidTypeProvider */
    public function testIsValidRejectsUnknownOrMalformedValues(string $type): void
    {
        self::assertFalse(ProductType::isValid($type));
    }

    /** @return list<array{string}> */
    public static function invalidTypeProvider(): array
    {
        return [
            ['subscription'],
            ['Physical'],
            [''],
            ['physical '],
            ['bundle'],
        ];
    }
}
