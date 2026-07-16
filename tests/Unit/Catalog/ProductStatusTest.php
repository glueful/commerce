<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Catalog;

use Glueful\Extensions\Commerce\Catalog\ProductStatus;
use PHPUnit\Framework\TestCase;

final class ProductStatusTest extends TestCase
{
    public function testAllReturnsTheClosedVocabularyInOrder(): void
    {
        self::assertSame(['draft', 'active', 'archived'], ProductStatus::all());
    }

    /** @dataProvider validStatusProvider */
    public function testIsValidAcceptsEveryDeclaredStatus(string $status): void
    {
        self::assertTrue(ProductStatus::isValid($status));
    }

    /** @return list<array{string}> */
    public static function validStatusProvider(): array
    {
        return [['draft'], ['active'], ['archived']];
    }

    /** @dataProvider invalidStatusProvider */
    public function testIsValidRejectsUnknownOrMalformedValues(string $status): void
    {
        self::assertFalse(ProductStatus::isValid($status));
    }

    /** @return list<array{string}> */
    public static function invalidStatusProvider(): array
    {
        return [
            ['published'],
            ['Draft'],
            [''],
            ['draft '],
            ['deleted'],
        ];
    }
}
