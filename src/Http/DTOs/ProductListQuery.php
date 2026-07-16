<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\FromQuery;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\Contracts\ValidatesSelf;

/**
 * `GET /commerce/products` (Layer 6 Global Constraints): `category`/`tag` are
 * exact-slug filters; `attributes` is a comma-separated list of
 * `attribute-slug:value-slug` pairs with AND semantics (e.g.
 * `?attributes=color:red,size:m`), capped at 5 pairs (422 above via
 * {@see self::validate()}). Slug/pair RESOLUTION (and the enumeration-neutral
 * "unknown slug matches nothing" behavior) happens downstream in
 * `Http\Storefront\ProductController::index()` -- this DTO only parses syntax
 * and enforces the cap; it never rejects an unknown or malformed slug/pair
 * itself (a pair that can't parse simply resolves to nothing later).
 */
final class ProductListQuery implements RequestData, ValidatesSelf
{
    private const MAX_ATTRIBUTE_PAIRS = 5;

    public function __construct(
        #[FromQuery(description: 'Page number.')]
        #[Rule('numeric')]
        public readonly ?int $page = null,
        #[FromQuery(description: 'Items per page, clamped to 100.')]
        #[Rule('numeric')]
        public readonly ?int $per_page = null,
        #[FromQuery(description: 'Filter by exact category slug.')]
        #[Rule('string')]
        public readonly ?string $category = null,
        #[FromQuery(description: 'Filter by exact tag slug.')]
        #[Rule('string')]
        public readonly ?string $tag = null,
        #[FromQuery(
            description: 'Comma-separated attribute-slug:value-slug pairs, AND semantics, max 5.'
        )]
        #[Rule('string')]
        public readonly ?string $attributes = null,
    ) {
    }

    /** @return array<string,list<string>> */
    public function validate(): array
    {
        $errors = [];

        if (count($this->rawAttributePairs()) > self::MAX_ATTRIBUTE_PAIRS) {
            $errors['attributes'][] = 'attributes must not include more than '
                . self::MAX_ATTRIBUTE_PAIRS . ' pairs.';
        }

        return $errors;
    }

    /**
     * Parsed `attribute_slug`/`value_slug` pairs, in request order. A pair with
     * no `:` (or an empty slug on either side) parses to an empty-string slug
     * that can never resolve to a real attribute/value -- deliberately NOT a
     * validation error, so a malformed pair behaves exactly like an unknown one
     * (enumeration-neutral).
     *
     * @return list<array{attribute_slug:string, value_slug:string}>
     */
    public function attributePairs(): array
    {
        $pairs = [];
        foreach ($this->rawAttributePairs() as $raw) {
            $parts = explode(':', $raw, 2);
            $pairs[] = [
                'attribute_slug' => trim($parts[0] ?? ''),
                'value_slug' => trim($parts[1] ?? ''),
            ];
        }

        return $pairs;
    }

    /** @return list<string> non-empty, trimmed raw pair strings, comma-split */
    private function rawAttributePairs(): array
    {
        if ($this->attributes === null || trim($this->attributes) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $this->attributes)),
            static fn (string $pair): bool => $pair !== ''
        ));
    }
}
