<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Storefront;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\AttributeRepository;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\TagRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Http\DTOs\ProductListQuery;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

final class ProductController
{
    public function __construct(
        private ApplicationContext $context,
        private ?ProductRepository $products = null,
        private ?VariantRepository $variants = null,
        private ?CurrentTenantResolver $tenants = null,
        private ?ProductMediaRepository $media = null,
        private ?CategoryRepository $categories = null,
        private ?TagRepository $tags = null,
        private ?AttributeRepository $attributes = null,
        private ?ProductChildrenRepository $children = null,
    ) {
        $this->products ??= app($context, ProductRepository::class);
        $this->variants ??= app($context, VariantRepository::class);
        $this->tenants ??= container($context)->has(CurrentTenantResolver::class)
            ? container($context)->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
        $this->media ??= app($context, ProductMediaRepository::class);
        $this->categories ??= app($context, CategoryRepository::class);
        $this->tags ??= app($context, TagRepository::class);
        $this->attributes ??= app($context, AttributeRepository::class);
        $this->children ??= app($context, ProductChildrenRepository::class);
    }

    #[ApiOperation(summary: 'List active products', tags: ['Commerce Storefront'])]
    #[ApiResponse(200, description: 'Products retrieved')]
    public function index(ProductListQuery $query): Response
    {
        $page = max(1, $query->page ?? 1);
        $perPage = max(1, min(100, $query->per_page ?? 24));
        $tenant = $this->tenants->tenantUuid($this->context);
        $result = $this->products->listActive($this->context, $tenant, $page, $perPage);

        return Response::paginated(
            array_map(
                fn (array $product): array => $this->withCoverUrl($tenant, $this->withVariants($tenant, $product)),
                $result['items']
            ),
            $result['total'],
            $page,
            $perPage,
            null,
            'Products retrieved'
        );
    }

    #[ApiOperation(summary: 'Get an active product by slug', tags: ['Commerce Storefront'])]
    #[ApiResponse(200, description: 'Product retrieved')]
    #[ApiResponse(404, description: 'Product not found')]
    public function show(Request $request, string $slug): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $product = $this->products->findBySlug($this->context, $tenant, $slug);
        if ($product === null || ($product['status'] ?? '') !== 'active' || ($product['deleted_at'] ?? null) !== null) {
            throw new NotFoundException('Resource not found.');
        }

        $product = $this->withVariants($tenant, $product);
        $product['media'] = $this->mediaPayload($tenant, (string) $product['uuid']);
        $product['categories'] = $this->categoriesPayload($tenant, (string) $product['uuid']);
        $product['tags'] = $this->tagsPayload($tenant, (string) $product['uuid']);
        $product['attributes'] = $this->attributesPayload($tenant, (string) $product['uuid']);

        if (($product['type'] ?? null) === 'grouped') {
            $product['children'] = $this->childrenPayload($tenant, (string) $product['uuid']);
        }
        if (($product['type'] ?? null) === 'external') {
            $product['external'] = $this->externalPayload($product);
        }

        return Response::success($product, 'Product retrieved');
    }

    /** @param array<string,mixed> $product @return array<string,mixed> */
    private function withVariants(string $tenant, array $product): array
    {
        $product['variants'] = $this->variants->forProduct($this->context, $tenant, (string) $product['uuid']);

        return $product;
    }

    /** @param array<string,mixed> $product @return array<string,mixed> */
    private function withCoverUrl(string $tenant, array $product): array
    {
        $product['cover_url'] = $this->coverUrl($tenant, (string) $product['uuid']);

        return $product;
    }

    private function coverUrl(string $tenant, string $productUuid): ?string
    {
        $cover = $this->media->coverFor($this->context, $tenant, $productUuid);

        return $cover === null ? null : '/blobs/' . $cover['blob_uuid'];
    }

    /**
     * Cover first, then gallery ordered by position — URLs are paths, origin
     * resolution stays with the host (framework's `BlobPublicUrlProvider`).
     *
     * @return list<array<string,mixed>>
     */
    private function mediaPayload(string $tenant, string $productUuid): array
    {
        $rows = $this->media->forProduct($this->context, $tenant, $productUuid);
        usort($rows, static function (array $a, array $b): int {
            if ($a['role'] !== $b['role']) {
                return $a['role'] === 'cover' ? -1 : 1;
            }

            return ((int) $a['position']) <=> ((int) $b['position']);
        });

        return array_map(static fn (array $row): array => [
            'blob_uuid' => (string) $row['blob_uuid'],
            'url' => '/blobs/' . $row['blob_uuid'],
            'role' => (string) $row['role'],
            'alt' => $row['alt'] ?? null,
            'variant_uuid' => $row['variant_uuid'] ?? null,
        ], $rows);
    }

    /** @return list<array{slug:string,name:string}> */
    private function categoriesPayload(string $tenant, string $productUuid): array
    {
        return array_map(static fn (array $row): array => [
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
        ], $this->categories->categoriesForProduct($this->context, $tenant, $productUuid));
    }

    /** @return list<array{slug:string,name:string}> */
    private function tagsPayload(string $tenant, string $productUuid): array
    {
        return array_map(static fn (array $row): array => [
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
        ], $this->tags->tagsForProduct($this->context, $tenant, $productUuid));
    }

    /**
     * VISIBLE-only echo: global rows (`attribute_uuid` set) as
     * `{slug, name, values}` -- the ATTRIBUTE's slug/display name plus the chosen
     * value slugs; custom rows (`attribute_uuid` null) as `{name, values}`. An
     * attribute deleted concurrently after its assignment was read (a cascade
     * delete would have detached the join row too, but a race is still possible)
     * is silently skipped rather than surfaced as a broken reference.
     *
     * @return list<array{slug?:string,name:string,values:list<string>}>
     */
    private function attributesPayload(string $tenant, string $productUuid): array
    {
        $rows = $this->attributes->productAttributeRows($this->context, $productUuid);
        $result = [];

        foreach ($rows as $row) {
            if (!(bool) $row['visible']) {
                continue;
            }

            if ($row['attribute_uuid'] !== null) {
                $attribute = $this->attributes->findByUuid($this->context, $tenant, (string) $row['attribute_uuid']);
                if ($attribute === null) {
                    continue;
                }
                $result[] = [
                    'slug' => (string) $attribute['slug'],
                    'name' => (string) $attribute['name'],
                    'values' => $row['values'],
                ];
                continue;
            }

            $result[] = [
                'name' => (string) $row['name'],
                'values' => $row['values'],
            ];
        }

        return $result;
    }

    /**
     * Grouped-only: children ordered by position, each with the same cover-url
     * resolution as the parent -- batched into a single IN query via
     * `coversForProducts()` rather than one `coverFor()` call per child (avoids
     * an N+1 query per grouped-product show()).
     *
     * @return list<array{slug:string,name:string,cover_url:?string}>
     */
    private function childrenPayload(string $tenant, string $productUuid): array
    {
        $rows = $this->children->visibleChildProductsForProduct($this->context, $tenant, $productUuid);
        $covers = $this->media->coversForProducts(
            $this->context,
            $tenant,
            array_map(static fn (array $row): string => (string) $row['uuid'], $rows)
        );

        return array_map(static function (array $row) use ($covers): array {
            $cover = $covers[(string) $row['uuid']] ?? null;

            return [
                'slug' => (string) $row['slug'],
                'name' => (string) $row['name'],
                'cover_url' => $cover === null ? null : '/blobs/' . $cover['blob_uuid'],
            ];
        }, $rows);
    }

    /**
     * External-only echo of the validated metadata CatalogService required at
     * save time — url is never null in practice (creation/type-change to
     * external both require it), but the projection stays defensive rather than
     * assuming metadata shape.
     *
     * @param array<string,mixed> $product
     * @return array{url:?string,button_label:?string}
     */
    private function externalPayload(array $product): array
    {
        $metadata = $product['metadata'] ?? null;
        $metadata = is_array($metadata) ? $metadata : [];

        return [
            'url' => isset($metadata['external_url']) ? (string) $metadata['external_url'] : null,
            'button_label' => isset($metadata['button_label']) ? (string) $metadata['button_label'] : null,
        ];
    }
}
