<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Extensions\Commerce\Catalog\ProductStatus;
use Glueful\Extensions\Commerce\Catalog\ProductType;
use Glueful\Validation\Attributes\FromQuery;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\Contracts\ValidatesSelf;

/**
 * `GET /commerce/admin/products` (Layer 6 Global Constraints): `status`/`type`
 * are validated against the SAME shared {@see ProductStatus}/{@see ProductType}
 * vocabularies create/update/bulk consume -- an unknown value is rejected at
 * this boundary (422) rather than silently matching nothing. `q` is a
 * case-insensitive literal substring match on product name via
 * {@see \Glueful\Extensions\Commerce\Support\LiteralLike}.
 */
final class AdminProductListQuery implements RequestData, ValidatesSelf
{
    public function __construct(
        #[FromQuery(description: 'Filter by product status.')]
        #[Rule('string')]
        public readonly ?string $status = null,
        #[FromQuery(description: 'Filter by product type.')]
        #[Rule('string')]
        public readonly ?string $type = null,
        #[FromQuery(description: 'Case-insensitive literal substring match on product name.')]
        #[Rule('string')]
        public readonly ?string $q = null,
        #[FromQuery(description: 'Page number.')]
        #[Rule('numeric')]
        public readonly ?int $page = null,
        #[FromQuery(description: 'Items per page, clamped to 100.')]
        #[Rule('numeric')]
        public readonly ?int $per_page = null,
    ) {
    }

    /** @return array<string,list<string>> */
    public function validate(): array
    {
        $errors = [];

        if ($this->status !== null && !ProductStatus::isValid($this->status)) {
            $errors['status'][] = 'status must be one of: ' . implode(', ', ProductStatus::all()) . '.';
        }
        if ($this->type !== null && !ProductType::isValid($this->type)) {
            $errors['type'][] = 'type must be one of: ' . implode(', ', ProductType::all()) . '.';
        }

        return $errors;
    }
}
