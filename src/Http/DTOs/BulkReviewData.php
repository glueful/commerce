<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\ArrayOf;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\Contracts\ValidatesSelf;

/**
 * `POST /commerce/admin/reviews/bulk` request body (design spec Layer 6 §2/
 * Task 2, plan Global Constraints "Bulk"): a WHOLE-REQUEST 422 boundary before
 * any write reaches {@see \Glueful\Extensions\Commerce\Catalog\ReviewService::bulk()}.
 * `action` is validated against the closed `approve|spam|delete` vocabulary
 * inline (no shared vocabulary class exists for review actions, unlike
 * product status/type); `#[ArrayOf('string')]` rejects a non-string uuid
 * element; {@see self::validate()} closes the remaining gaps: cap-100,
 * duplicate uuids, and an empty-string uuid.
 */
final class BulkReviewData implements RequestData, ValidatesSelf
{
    public function __construct(
        #[Rule('required|string|in:approve,spam,delete')]
        public readonly string $action = '',
        #[ArrayOf('string')]
        #[Rule('required|array')]
        public readonly array $uuids = [],
    ) {
    }

    /** @return array<string,list<string>> */
    public function validate(): array
    {
        $errors = [];

        if (count($this->uuids) > 100) {
            $errors['uuids'][] = 'uuids must not contain more than 100 items.';
        }
        if (count($this->uuids) !== count(array_unique($this->uuids))) {
            $errors['uuids'][] = 'uuids must not contain duplicates.';
        }
        foreach ($this->uuids as $index => $uuid) {
            if (trim($uuid) === '') {
                $errors["uuids.{$index}"][] = "uuids.{$index} must be a non-empty string.";
            }
        }

        return $errors;
    }
}
