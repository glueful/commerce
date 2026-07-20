<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\ArrayOf;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\Contracts\ValidatesSelf;

/**
 * `seller_uuid` (design spec §2.7): NEVER accepted on the ordinary admin
 * create endpoint -- catalog attribution comes only via the marketplace
 * create policy (`CatalogService::createProduct()`'s own $sellerUuid
 * parameter, never derived from this body) or the dedicated adoption/
 * transfer operation. The property below exists ONLY so a client-supplied
 * `seller_uuid` is rejected with 422 (see {@see self::validate()}) instead
 * of being silently ignored.
 */
final class CreateProductData implements RequestData, ValidatesSelf
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
        public readonly ?string $seller_uuid = null,
    ) {
    }

    /** @return array<string,list<string>> */
    public function validate(): array
    {
        if ($this->seller_uuid !== null) {
            return [
                'seller_uuid' => [
                    'seller_uuid cannot be set directly; use the marketplace adoption/transfer operation instead.',
                ],
            ];
        }

        return [];
    }
}
