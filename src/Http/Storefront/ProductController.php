<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Storefront;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

final class ProductController
{
    public function __construct(
        private ApplicationContext $context,
        private ?ProductRepository $products = null,
        private ?VariantRepository $variants = null,
        private ?CurrentTenantResolver $tenants = null,
    ) {
        $this->products ??= app($context, ProductRepository::class);
        $this->variants ??= app($context, VariantRepository::class);
        $this->tenants ??= container($context)->has(CurrentTenantResolver::class)
            ? container($context)->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
    }

    public function index(Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = max(1, min(100, (int) $request->query->get('per_page', 24)));
        $tenant = $this->tenants->tenantUuid($this->context);
        $result = $this->products->listActive($this->context, $tenant, $page, $perPage);

        return Response::paginated(
            array_map(fn (array $product): array => $this->withVariants($tenant, $product), $result['items']),
            $result['total'],
            $page,
            $perPage,
            null,
            'Products retrieved'
        );
    }

    public function show(Request $request, string $slug): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $product = $this->products->findBySlug($this->context, $tenant, $slug);
        if ($product === null || ($product['status'] ?? '') !== 'active' || ($product['deleted_at'] ?? null) !== null) {
            throw new NotFoundException('Resource not found.');
        }

        return Response::success($this->withVariants($tenant, $product), 'Product retrieved');
    }

    /** @param array<string,mixed> $product @return array<string,mixed> */
    private function withVariants(string $tenant, array $product): array
    {
        $product['variants'] = $this->variants->forProduct($this->context, $tenant, (string) $product['uuid']);

        return $product;
    }
}
