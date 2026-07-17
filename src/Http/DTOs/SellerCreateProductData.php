<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\DTOs;

use Glueful\Validation\Attributes\ArrayOf;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * `POST /commerce/seller/{sellerUuid}/products` request body (design spec
 * §2.7/§2.8, MV1 Task 4): the SAME shape as {@see CreateProductData} minus
 * `seller_uuid` -- there is deliberately no such property here at all.
 * Attribution is derived entirely from the ROUTE resource
 * (`SellerCatalogController::store()` passes the route's `sellerUuid` to
 * {@see \Glueful\Extensions\Commerce\Catalog\CatalogService::createProduct()}
 * directly); the request-data hydrator only ever reads constructor-declared
 * parameter names (see {@see \Glueful\Validation\RequestDataHydrator}), so a
 * client that smuggles a `seller_uuid` key in the body has it silently
 * IGNORED rather than rejected -- the product always lands on the route's
 * seller, never a body-supplied one.
 */
final class SellerCreateProductData implements RequestData
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
