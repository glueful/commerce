<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Support;

use Glueful\Extensions\Commerce\Support\TokenHasher;
use PHPUnit\Framework\TestCase;

final class TokenHasherTest extends TestCase
{
    public function testGenerateReturnsRawAndMatchingHash(): void
    {
        $token = TokenHasher::generate();

        self::assertSame(40, strlen($token['raw']));
        self::assertSame(hash('sha256', $token['raw']), $token['hash']);
        self::assertSame($token['hash'], TokenHasher::hash($token['raw']));
        self::assertNotSame(TokenHasher::generate()['raw'], $token['raw']);
    }
}
