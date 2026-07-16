<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\ArrayOf;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\Contracts\ValidatesSelf;

/**
 * `POST /commerce/admin/variants/bulk-price` request body (design spec Layer
 * 6 §2/Task 2, plan Global Constraints "Bulk"): a WHOLE-REQUEST 422 boundary
 * before any write reaches {@see \Glueful\Extensions\Commerce\Catalog\CatalogService::setVariantPrice()}.
 * `#[ArrayOf(BulkPriceItemData::class)]` recursively hydrates and validates
 * every element (malformed uuid/price fields surface as `items.{i}.{field}`
 * errors); {@see self::validate()} adds the cross-item cap-100 and
 * duplicate-uuid checks nested hydration cannot express on its own.
 */
final class BulkPriceData implements RequestData, ValidatesSelf
{
    /** @param list<BulkPriceItemData> $items */
    public function __construct(
        #[ArrayOf(BulkPriceItemData::class)]
        #[Rule('required|array')]
        public readonly array $items = [],
    ) {
    }

    /** @return array<string,list<string>> */
    public function validate(): array
    {
        $errors = [];

        if (count($this->items) > 100) {
            $errors['items'][] = 'items must not contain more than 100 items.';
        }

        $uuids = array_map(static fn (BulkPriceItemData $item): string => $item->uuid, $this->items);
        if (count($uuids) !== count(array_unique($uuids))) {
            $errors['items'][] = 'items must not contain duplicate uuids.';
        }

        return $errors;
    }
}
