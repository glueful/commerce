<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Extensions\Commerce\Support\UuidBatch;
use Glueful\Validation\Attributes\ArrayOf;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\Contracts\ValidatesSelf;

final class WishlistImportData implements RequestData, ValidatesSelf
{
    /** @param list<string> $product_uuids device order -- first entry is the device's newest */
    public function __construct(
        #[Rule('required|array')]
        #[ArrayOf('string')]
        public readonly array $product_uuids,
    ) {
    }

    /** @return array<string,string> */
    public function validate(): array
    {
        // Over-limit lists are REFUSED, not truncated. `UuidBatch::normalize()` keeps the first
        // 100 and drops the rest -- appropriate for a defensive repository read, wrong here: a
        // dropped uuid would be reported as neither imported nor overflow, so the caller would
        // clear it locally believing it had landed.
        if (count($this->product_uuids) > UuidBatch::LIMIT) {
            return ['product_uuids' => sprintf('Send at most %d products per import.', UuidBatch::LIMIT)];
        }

        foreach ($this->product_uuids as $index => $uuid) {
            if (!is_string($uuid) || preg_match(UuidBatch::UUID_PATTERN, $uuid) !== 1) {
                return ['product_uuids.' . $index => 'Must be a 12-character product identifier.'];
            }
        }

        return [];
    }
}
