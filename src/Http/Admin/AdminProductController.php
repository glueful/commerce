<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Http\DTOs\CreateProductData;
use Glueful\Extensions\Commerce\Http\DTOs\CreateVariantData;
use Glueful\Extensions\Commerce\Http\DTOs\ProductVariantData;
use Glueful\Extensions\Commerce\Http\DTOs\SetProductChildrenData;
use Glueful\Extensions\Commerce\Http\DTOs\UpdateProductData;
use Glueful\Extensions\Commerce\Http\DTOs\UpdateVariantData;
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

    public function __construct(
        private ApplicationContext $context,
        private ?CatalogService $catalog = null,
        private ?ProductRepository $products = null,
        private ?VariantRepository $variants = null,
        private ?CurrentTenantResolver $tenants = null,
    ) {
        $this->catalog ??= app($context, CatalogService::class);
        $this->products ??= app($context, ProductRepository::class);
        $this->variants ??= app($context, VariantRepository::class);
        $this->tenants ??= container($context)->has(CurrentTenantResolver::class)
            ? container($context)->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
    }

    #[ApiOperation(summary: 'List products', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Products retrieved')]
    public function index(Request $request): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $rows = db($this->context)->table('commerce_products')
            ->where('tenant_uuid', '=', $tenant)
            ->orderBy('created_at', 'DESC')
            ->get();

        return Response::success($rows, 'Products retrieved');
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
            $this->catalog->updateProduct($this->context, $uuid, $this->input($request));

            return Response::success($this->product($uuid), 'Product updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
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

            return Response::success($variant, 'Variant updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    #[ApiOperation(summary: 'Set the children attached to a grouped product', tags: ['Commerce Admin'])]
    #[ApiResponse(200, description: 'Product children updated')]
    #[ApiResponse(404, description: 'Product not found')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function setChildren(SetProductChildrenData $input, Request $request, string $uuid): Response
    {
        try {
            $children = $this->catalog->setProductChildren($this->context, $uuid, $input->child_uuids ?? []);

            return Response::success($children, 'Product children updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    /** @return array<string,mixed> */
    private function product(string $uuid): array
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $product = $this->products->findByUuid($this->context, $tenant, $uuid);
        if ($product === null) {
            throw new NotFoundException('Resource not found.');
        }
        $product['variants'] = $this->variants->forProduct($this->context, $tenant, $uuid);

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
        ], static fn (mixed $value): bool => $value !== null);
    }
}
