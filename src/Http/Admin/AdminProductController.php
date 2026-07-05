<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Response;
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

    public function index(Request $request): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $rows = db($this->context)->table('commerce_products')
            ->where('tenant_uuid', '=', $tenant)
            ->orderBy('created_at', 'DESC')
            ->get();

        return Response::success($rows, 'Products retrieved');
    }

    public function show(Request $request, string $uuid): Response
    {
        return Response::success($this->product($uuid), 'Product retrieved');
    }

    public function store(Request $request): Response
    {
        try {
            return Response::created(
                $this->catalog->createProduct($this->context, $this->input($request)),
                'Product created'
            );
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    public function update(Request $request, string $uuid): Response
    {
        try {
            $this->catalog->updateProduct($this->context, $uuid, $this->input($request));

            return Response::success($this->product($uuid), 'Product updated');
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

    public function storeVariant(Request $request, string $uuid): Response
    {
        try {
            return Response::created(
                $this->catalog->createVariant($this->context, $uuid, $this->input($request)),
                'Variant created'
            );
        } catch (ValidationException $e) {
            return Response::validation($e->firstErrors());
        }
    }

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
}
