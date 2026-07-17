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
 *
 * `seller_uuid` is deliberately ABSENT here for the same reason: the raw-body
 * path is what actually enforces the rule (design spec §2.7). Both HTTP
 * controllers (see {@see \Glueful\Extensions\Commerce\Http\Admin\AdminProductController::update()})
 * silently drop a body-supplied `seller_uuid` before it ever reaches
 * {@see \Glueful\Extensions\Commerce\Catalog\CatalogService::updateProduct()}
 * -- a full-object read-modify-write PATCH echoing the column back unchanged
 * must succeed -- and that method's unconditional 422 on any `seller_uuid`
 * key remains the backstop for a caller that reaches it directly, mirroring
 * {@see \Glueful\Extensions\Commerce\Marketplace\SellerService::update()}'s
 * `slug`-immutability guard. Ordinary update never touches catalog
 * attribution.
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
