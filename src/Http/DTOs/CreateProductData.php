<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\ArrayOf;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class CreateProductData implements RequestData
{
    /**
     * @param array<string,mixed>|null $options
     * @param array<string,mixed>|null $metadata
     * @param list<ProductVariantData> $variants
     */
    public function __construct(
        #[Rule('required|string')]
        public readonly string $slug,
        #[Rule('required|string')]
        public readonly string $name,
        #[Rule('string')]
        public readonly ?string $description = null,
        #[Rule('string')]
        public readonly string $type = 'physical',
        #[Rule('string')]
        public readonly string $status = 'draft',
        #[Rule('array')]
        public readonly ?array $options = null,
        #[Rule('array')]
        public readonly ?array $metadata = null,
        #[Rule('string')]
        public readonly ?string $tax_class = null,
        #[ArrayOf(ProductVariantData::class)]
        #[Rule('required|array')]
        public readonly array $variants = [],
    ) {
    }
}
