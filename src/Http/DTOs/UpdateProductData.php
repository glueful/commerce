<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Documentation-only schema for `tax_class` (see {@see UpdateCategoryData}): the
 * controller reads the raw request body directly, so an explicit `null` clears
 * the product's tax_class while an omitted key preserves it -- a distinction this
 * typed DTO cannot express, only the raw-body path
 * {@see \Glueful\Extensions\Commerce\Catalog\CatalogService::updateProduct()}
 * actually implements.
 */
final class UpdateProductData implements RequestData
{
    /**
     * @param array<string,mixed>|null $options
     * @param array<string,mixed>|null $metadata
     */
    public function __construct(
        #[Rule('string')]
        public readonly ?string $slug = null,
        #[Rule('string')]
        public readonly ?string $name = null,
        #[Rule('string')]
        public readonly ?string $description = null,
        #[Rule('string')]
        public readonly ?string $type = null,
        #[Rule('string')]
        public readonly ?string $status = null,
        #[Rule('array')]
        public readonly ?array $options = null,
        #[Rule('array')]
        public readonly ?array $metadata = null,
        #[Rule('string')]
        public readonly ?string $tax_class = null,
    ) {
    }
}
