<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Extensions\Commerce\Catalog\ProductStatus;
use Glueful\Validation\Attributes\ArrayOf;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\Contracts\ValidatesSelf;

/**
 * `POST /commerce/admin/products/bulk-status` request body (design spec Layer
 * 6 §2/Task 2, plan Global Constraints "Bulk"): a WHOLE-REQUEST 422 boundary
 * before any write reaches {@see \Glueful\Extensions\Commerce\Catalog\CatalogService::setProductStatus()}.
 * `#[ArrayOf('string')]` rejects a non-string element; {@see self::validate()}
 * closes the remaining gaps `#[ArrayOf]` cannot express on its own: the
 * cap-100 batch size, duplicate uuids, an empty-string uuid, and the closed
 * `status` vocabulary (the SAME shared {@see ProductStatus} declaration
 * create/update/list-filter consume, never a duplicated magic-string list).
 */
final class BulkStatusData implements RequestData, ValidatesSelf
{
    /** @param list<string> $uuids */
    public function __construct(
        #[ArrayOf('string')]
        #[Rule('required|array')]
        public readonly array $uuids = [],
        #[Rule('required|string')]
        public readonly string $status = '',
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

        if (!ProductStatus::isValid($this->status)) {
            $errors['status'][] = 'status must be one of: ' . implode(', ', ProductStatus::all()) . '.';
        }

        return $errors;
    }
}
