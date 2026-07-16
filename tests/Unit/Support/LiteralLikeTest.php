<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Support;

use Glueful\Extensions\Commerce\Support\LiteralLike;
use PHPUnit\Framework\TestCase;

/**
 * {@see LiteralLike} is the single normalizer every admin-list `q` filter consumes
 * (Layer 6 Global Constraints): case-insensitive, and `%`/`_` in user input are
 * LITERAL characters, never SQL LIKE wildcards. `!` is the escape character and
 * MUST be escaped first -- escaping `%`/`_` before `!` would double-escape any `!`
 * that step just introduced.
 */
final class LiteralLikeTest extends TestCase
{
    public function testWrapsPlainValueInWildcardsForSubstringMatch(): void
    {
        self::assertSame('%hello%', LiteralLike::pattern('hello'));
    }

    public function testLowercasesInput(): void
    {
        self::assertSame('%hello%', LiteralLike::pattern('HELLO'));
        self::assertSame('%mixedcase%', LiteralLike::pattern('MixedCase'));
    }

    public function testEscapesPercentAsALiteralCharacter(): void
    {
        self::assertSame('%50!%%', LiteralLike::pattern('50%'));
    }

    public function testEscapesUnderscoreAsALiteralCharacter(): void
    {
        self::assertSame('%a!_b%', LiteralLike::pattern('a_b'));
    }

    public function testEscapesALiteralEscapeCharacterInInput(): void
    {
        // A literal '!' in input must become '!!' so it is never misread as the
        // start of an escape sequence for a later '%'/'_' in the same pattern.
        self::assertSame('%a!!b%', LiteralLike::pattern('a!b'));
    }

    public function testEscapesTheEscapeCharacterBeforePercentToAvoidDoubleEscaping(): void
    {
        // Input '!%' must become '!!!%' (an escaped '!' followed by an escaped
        // '%'), never '!!%', which would misparse as an escaped '!' immediately
        // followed by an UNESCAPED wildcard '%' -- exactly the double-escaping
        // bug the escape-'!'-first ordering exists to prevent.
        self::assertSame('%!!!%%', LiteralLike::pattern('!%'));
    }

    public function testMixedPercentUnderscoreAndEscapeCharacterTogether(): void
    {
        self::assertSame('%a!%b!_c!!d%', LiteralLike::pattern('a%b_c!d'));
    }

    public function testEmptyStringProducesAWildcardOnlyPattern(): void
    {
        self::assertSame('%%', LiteralLike::pattern(''));
    }

    public function testRepeatedMetacharactersAreEachIndependentlyEscaped(): void
    {
        self::assertSame('%!%!%!_!_%', LiteralLike::pattern('%%__'));
    }
}
