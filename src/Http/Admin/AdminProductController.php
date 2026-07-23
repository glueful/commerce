<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\StaleCatalogRevisionException;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Http\DTOs\AdminProductListQuery;
use Glueful\Extensions\Commerce\Http\DTOs\BulkPriceData;
use Glueful\Extensions\Commerce\Http\DTOs\BulkStatusData;
use Glueful\Extensions\Commerce\Http\DTOs\CreateProductData;
use Glueful\Extensions\Commerce\Http\DTOs\CreateVariantData;
use Glueful\Extensions\Commerce\Http\DTOs\ProductVariantData;
use Glueful\Extensions\Commerce\Http\DTOs\SetProductChildrenData;
use Glueful\Extensions\Commerce\Http\DTOs\UpdateProductData;
use Glueful\Extensions\Commerce\Http\DTOs\UpdateVariantData;
use Glueful\Extensions\Commerce\Marketplace\CommissionPolicyException;
use Glueful\Extensions\Commerce\Shipping\ShippingClassRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiRequestBody;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

final class AdminProductController
{
    use ReadsAdminInput;
    use ResolvesActor;

    public function __construct(
        private ApplicationContext $context,
        private ?CatalogService $catalog = null,
        private ?ProductRepository $products = null,
        private ?VariantRepository $variants = null,
        private ?CurrentTenantResolver $tenants = null,
        private ?ShippingClassRepository $shippingClasses = null,
    ) {
        $this->catalog ??= app($context, CatalogService::class);
        $this->products ??= app($context, ProductRepository::class);
        $this->variants ??= app($context, VariantRepository::class);
        $this->tenants ??= container($context)->has(CurrentTenantResolver::class)
            ? container($context)->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
        $this->shippingClasses ??= new ShippingClassRepository();
    }

    #[ApiOperation(summary: 'List products', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Products retrieved')]
    public function index(AdminProductListQuery $query, Request $request): Response
    {
        $page = max(1, $query->page ?? 1);
        $perPage = max(1, min(100, $query->per_page ?? 24));
        $tenant = $this->tenants->tenantUuid($this->context);
        $result = $this->products->paginatedForAdmin(
            $this->context,
            $tenant,
            array_filter(
                ['status' => $query->status, 'type' => $query->type, 'q' => $query->q],
                static fn (mixed $value): bool => $value !== null
            ),
            $page,
            $perPage
        );

        return Response::paginated($result['items'], $result['total'], $page, $perPage, null, 'Products retrieved');
    }

    #[ApiOperation(summary: 'Get a product', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Product retrieved')]
    #[ApiResponse(404, description: 'Product not found')]
    public function show(Request $request, string $uuid): Response
    {
        return Response::success($this->product($uuid), 'Product retrieved');
    }

    #[ApiOperation(summary: 'Create a product', tags: ['Commerce Admin'])]
    #[ApiResponse(201, description: 'Product created')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function store(CreateProductData $input, Request $request): Response
    {
        try {
            return Response::created(
                $this->catalog->createProduct($this->context, $this->createProductPayload($input)),
                'Product created'
            );
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Update a product', tags: ['Commerce Admin'])]
    #[ApiRequestBody(schema: UpdateProductData::class)]
    #[ApiResponse(200, description: 'Product updated')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function update(Request $request, string $uuid): Response
    {
        try {
            // A body-supplied `seller_uuid` (e.g. echoed back from a prior GET --
            // every admin product payload carries the column since migration 011,
            // marketplace switch or not) is silently dropped, never rejected,
            // mirroring {@see \Glueful\Extensions\Commerce\Http\Seller\SellerCatalogController::update()}.
            // Attribution only ever moves via the dedicated platform
            // adoption/transfer operation; CatalogService::updateProduct()'s
            // unconditional 422 guard stays intact as the backstop for any
            // caller that reaches it with the key still present.
            //
            // A commission_kind/bps/fixed field (design spec §2.3, MV3 Task 4) is
            // operator-only and IS allowed here -- CatalogService::updateProduct()
            // routes it through CommissionPolicyService for validation + a
            // durable audit row, using the resolved actor below.
            $changes = $this->input($request);
            unset($changes['seller_uuid']);

            $this->catalog->updateProduct($this->context, $uuid, $changes, $this->actorUuid($request));

            return Response::success($this->product($uuid), 'Product updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        } catch (CommissionPolicyException $e) {
            return Response::validation(['commission' => $e->getMessage()]);
        }
    }

    #[ApiOperation(summary: 'Delete a product', tags: ['Commerce Admin'])]
    #[ApiResponse(204, description: 'Product deleted')]
    #[ApiResponse(404, description: 'Product not found')]
    public function destroy(Request $request, string $uuid): Response
    {
        $this->catalog->deleteProduct($this->context, $uuid);

        return Response::noContent();
    }

    #[ApiOperation(summary: 'Create a product variant', tags: ['Commerce Admin'])]
    #[ApiResponse(201, description: 'Variant created')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function storeVariant(CreateVariantData $input, Request $request, string $uuid): Response
    {
        try {
            return Response::created(
                $this->catalog->createVariant($this->context, $uuid, $this->variantPayload($input)),
                'Variant created'
            );
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Update a product variant', tags: ['Commerce Admin'])]
    #[ApiRequestBody(schema: UpdateVariantData::class)]
    #[ApiResponse(200, description: 'Variant updated')]
    #[ApiResponse(404, description: 'Variant not found')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function updateVariant(Request $request, string $uuid): Response
    {
        try {
            $this->catalog->updateVariant($this->context, $uuid, $this->input($request));
            $tenant = $this->tenants->tenantUuid($this->context);
            $variant = $this->variants->findByUuid($this->context, $tenant, $uuid);
            if ($variant === null) {
                throw new NotFoundException('Resource not found.');
            }
            $variant = $this->shippingClasses->attachResolvedSlug($this->context, $tenant, $variant);

            return Response::success($variant, 'Variant updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Bulk update product status', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Bulk product status update processed')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function bulkStatus(BulkStatusData $input, Request $request): Response
    {
        $applied = [];
        $failed = [];

        foreach ($input->uuids as $uuid) {
            try {
                $this->catalog->setProductStatus($this->context, $uuid, $input->status);
                $applied[] = $uuid;
            } catch (NotFoundException) {
                $failed[] = ['uuid' => $uuid, 'reason' => 'not_found'];
            }
        }

        return Response::success(
            ['applied' => $applied, 'failed' => $failed],
            'Bulk product status update processed'
        );
    }

    #[ApiOperation(summary: 'Bulk update variant prices', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Bulk variant price update processed')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function bulkPrice(BulkPriceData $input, Request $request): Response
    {
        $applied = [];
        $failed = [];

        foreach ($input->items as $item) {
            try {
                $this->catalog->setVariantPrice($this->context, $item->uuid, $item->price);
                $applied[] = $item->uuid;
            } catch (NotFoundException) {
                $failed[] = ['uuid' => $item->uuid, 'reason' => 'not_found'];
            }
        }

        return Response::success(
            ['applied' => $applied, 'failed' => $failed],
            'Bulk variant price update processed'
        );
    }

    #[ApiOperation(summary: 'Set the children attached to a grouped product', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Product children updated')]
    #[ApiResponse(404, description: 'Product not found')]
    #[ApiResponse(409, description: 'Product was modified by another request')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function setChildren(SetProductChildrenData $input, Request $request, string $uuid): Response
    {
        try {
            $children = $this->catalog->setProductChildren(
                $this->context,
                $uuid,
                $input->child_uuids ?? [],
                $input->expected_revision
            );

            return Response::success($children, 'Product children updated');
        } catch (StaleCatalogRevisionException $e) {
            return Response::error($e->getMessage(), 409);
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'List the children attached to a grouped product', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Product children retrieved')]
    #[ApiResponse(404, description: 'Product not found')]
    public function childrenForProductIndex(Request $request, string $uuid): Response
    {
        return Response::success(
            $this->catalog->childrenForProduct($this->context, $uuid),
            'Product children retrieved'
        );
    }

    /**
     * Deliberately uncaught: a {@see \Glueful\Extensions\Commerce\Inventory\StockIntegrityException}
     * from {@see CatalogService::stockForProduct()} must bubble to the
     * framework's default 500-class handler, never be mapped to a 4xx (Global
     * Constraints: "the read fails loudly").
     */
    #[ApiOperation(summary: 'List the stock levels for a product\'s variants', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Product stock retrieved')]
    #[ApiResponse(404, description: 'Product not found')]
    public function stockForProductIndex(Request $request, string $uuid): Response
    {
        return Response::success(
            $this->catalog->stockForProduct($this->context, $uuid),
            'Product stock retrieved'
        );
    }

    /** @return array<string,mixed> */
    private function product(string $uuid): array
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $product = $this->products->findLiveByUuid($this->context, $tenant, $uuid);
        if ($product === null) {
            throw new NotFoundException('Resource not found.');
        }
        $product['variants'] = $this->shippingClasses->attachResolvedSlugs(
            $this->context,
            $tenant,
            $this->variants->forProduct($this->context, $tenant, $uuid)
        );

        return $product;
    }

    /** @return array<string,mixed> */
    private function createProductPayload(CreateProductData $input): array
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
                fn (ProductVariantData $variant): array => $this->productVariantPayload($variant),
                $input->variants
            ),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string,mixed> */
    private function variantPayload(CreateVariantData $input): array
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

    /** @return array<string,mixed> */
    private function productVariantPayload(ProductVariantData $input): array
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
