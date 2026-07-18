<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Seller;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Http\DTOs\AdminProductListQuery;
use Glueful\Extensions\Commerce\Http\DTOs\CreateVariantData;
use Glueful\Extensions\Commerce\Http\DTOs\ProductVariantData;
use Glueful\Extensions\Commerce\Http\DTOs\SellerCreateProductData;
use Glueful\Extensions\Commerce\Http\DTOs\UpdateProductData;
use Glueful\Extensions\Commerce\Marketplace\CommissionPolicyResolver;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiRequestBody;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Seller-scoped catalog surface (design spec §2.8, MV1 Task 4):
 * list/show/create/update products scoped to the `{sellerUuid}` route
 * resource -- `commerce_seller:commerce.seller.catalog.{read,write}`
 * middleware has already resolved and authorized the caller against that
 * exact seller before any handler here runs. Every read/write is ADDITIONALLY
 * predicated by seller at the {@see CatalogService} layer (never a raw
 * repository call), so a wrong-seller product uuid is a non-revealing 404 --
 * defense in depth alongside the middleware's own gate. Variants (and any
 * other product child resource) are reached ONLY through the product root,
 * mirroring {@see \Glueful\Extensions\Commerce\Http\Admin\AdminProductController}'s
 * `/products/{uuid}/variants` nesting -- there is no standalone seller-scoped
 * variant route.
 */
final class SellerCatalogController
{
    use ReadsSellerRequest;

    public function __construct(
        private ApplicationContext $context,
        private ?CatalogService $catalog = null,
    ) {
        $this->catalog ??= app($context, CatalogService::class);
    }

    #[ApiOperation(summary: "List a seller's products", tags: ['Commerce Seller'])]
    #[ApiResponse(200, description: 'Products retrieved')]
    #[ApiResponse(404, description: 'Unknown seller, no active membership, or workspace not activated')]
    public function index(AdminProductListQuery $query, Request $request, string $sellerUuid): Response
    {
        $page = max(1, $query->page ?? 1);
        $perPage = max(1, min(100, $query->per_page ?? 24));

        $result = $this->catalog->listSellerProducts(
            $this->context,
            $sellerUuid,
            array_filter(
                ['status' => $query->status, 'type' => $query->type, 'q' => $query->q],
                static fn (mixed $value): bool => $value !== null
            ),
            $page,
            $perPage
        );

        return Response::paginated($result['items'], $result['total'], $page, $perPage, null, 'Products retrieved');
    }

    #[ApiOperation(summary: 'Get a product owned by this seller', tags: ['Commerce Seller'])]
    #[ApiResponse(200, description: 'Product retrieved')]
    #[ApiResponse(404, description: 'Product not found for this seller')]
    public function show(Request $request, string $sellerUuid, string $uuid): Response
    {
        $product = $this->catalog->sellerProduct($this->context, $sellerUuid, $uuid);

        return Response::success($product, 'Product retrieved');
    }

    #[ApiOperation(summary: 'Create a product for this seller', tags: ['Commerce Seller'])]
    #[ApiResponse(201, description: 'Product created and attributed to the route seller')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function store(SellerCreateProductData $input, Request $request, string $sellerUuid): Response
    {
        try {
            // Commission policy is operator-only (design spec §2.3, MV3 Task 4):
            // SellerCreateProductData's hydrator silently drops unknown keys, so
            // a commission field would otherwise vanish unremarked -- the RAW
            // body is inspected here instead, to reject it loudly with a
            // field-specific 422 rather than silently ignoring it.
            $this->rejectCommissionFields($this->input($request));

            $payload = $this->createProductPayload($input);
            $product = $this->catalog->createProduct($this->context, $payload, $sellerUuid);

            return Response::created($product, 'Product created');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: "Update a product owned by this seller", tags: ['Commerce Seller'])]
    #[ApiRequestBody(schema: UpdateProductData::class)]
    #[ApiResponse(200, description: 'Product updated')]
    #[ApiResponse(404, description: 'Product not found for this seller')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function update(Request $request, string $sellerUuid, string $uuid): Response
    {
        try {
            // A body-supplied `seller_uuid` (e.g. echoed back from a prior GET) is
            // silently dropped, never rejected -- attribution only ever moves via
            // the dedicated platform adoption/transfer operation.
            $changes = $this->input($request);
            $this->rejectCommissionFields($changes);
            unset($changes['seller_uuid']);

            $this->catalog->updateSellerProduct($this->context, $sellerUuid, $uuid, $changes);
            $product = $this->catalog->sellerProduct($this->context, $sellerUuid, $uuid);

            return Response::success($product, 'Product updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: "Create a variant for a product owned by this seller", tags: ['Commerce Seller'])]
    #[ApiResponse(201, description: 'Variant created')]
    #[ApiResponse(404, description: 'Product not found for this seller')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function storeVariant(CreateVariantData $input, Request $request, string $sellerUuid, string $uuid): Response
    {
        try {
            $variant = $this->catalog->createSellerVariant(
                $this->context,
                $sellerUuid,
                $uuid,
                $this->variantPayload($input)
            );

            return Response::created($variant, 'Variant created');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    /**
     * Commission-field rejection (design spec §2.3, MV3 Task 4): a seller can
     * never set their own commission policy, on create OR update -- checked
     * against the RAW request body, before any DTO/patch handling, so a
     * commission field can never be silently dropped. `403` stays reserved
     * for callers lacking the route capability (the `commerce_seller`
     * middleware, which has already run by the time this method body
     * executes) -- this is a separate, field-specific `422`.
     *
     * @param array<string,mixed> $changes
     */
    private function rejectCommissionFields(array $changes): void
    {
        foreach (CommissionPolicyResolver::FIELDS as $field) {
            if (array_key_exists($field, $changes)) {
                throw ValidationException::forField(
                    $field,
                    'Sellers cannot set commission policy; only platform operators may change it.'
                );
            }
        }
    }

    /** @return array<string,mixed> */
    private function createProductPayload(SellerCreateProductData $input): array
    {
        return array_filter([
            'slug' => $input->slug,
            'name' => $input->name,
            'description' => $input->description,
            'type' => $input->type,
            'status' => $input->status,
            'options' => $input->options,
            'metadata' => $input->metadata,
            'tax_class' => $input->tax_class,
            'variants' => array_map(
                fn (ProductVariantData $variant): array => $this->variantPayload($variant),
                $input->variants
            ),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string,mixed> */
    private function variantPayload(CreateVariantData|ProductVariantData $input): array
    {
        return array_filter([
            'sku' => $input->sku,
            'option_values' => $input->option_values,
            'price' => $input->price,
            'compare_at_price' => $input->compare_at_price,
            'currency' => $input->currency,
            'status' => $input->status,
            'shipping_class_uuid' => $input->shipping_class_uuid,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
