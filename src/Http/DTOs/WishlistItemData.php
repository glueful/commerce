<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Extensions\Commerce\Support\UuidBatch;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\Contracts\ValidatesSelf;

/**
 * Catalog identifiers are exactly 12 alphanumeric characters. `max:12` would accept "x" or a
 * traversal-shaped string, so the exact shape is asserted here and surfaces as a 422 instead of
 * reaching the service as a uuid that can never match anything.
 *
 * The pattern is {@see UuidBatch}'s, not a local copy: `\A...\z` anchors, because PCRE's `$`
 * also matches immediately before a trailing newline -- so "prod00000001\n" would satisfy
 * `/^[A-Za-z0-9]{12}$/` and then fail every lookup.
 */
final class WishlistItemData implements RequestData, ValidatesSelf
{
    public function __construct(
        #[Rule('required|string')]
        public readonly string $product_uuid,
    ) {
    }

    /** @return array<string, list<string>> */
    public function validate(): array
    {
        return preg_match(UuidBatch::UUID_PATTERN, $this->product_uuid) === 1
            ? []
            : ['product_uuid' => ['Must be a 12-character product identifier.']];
    }
}
