<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Support;

/**
 * Bearer-token hygiene: callers receive raw values once; only hashes are stored.
 */
final class TokenHasher
{
    /** @return array{raw: string, hash: string} */
    public static function generate(): array
    {
        $raw = bin2hex(random_bytes(20));

        return [
            'raw' => $raw,
            'hash' => self::hash($raw),
        ];
    }

    public static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }
}
