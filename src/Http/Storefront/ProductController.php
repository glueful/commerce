<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Storefront;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\AddonRepository;
use Glueful\Extensions\Commerce\Catalog\AttributeRepository;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\ResolvedProductFilters;
use Glueful\Extensions\Commerce\Catalog\TagRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Http\DTOs\ProductListQuery;
use Glueful\Extensions\Commerce\Shipping\ShippingClassRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

final class ProductController
{
    /**
     * Public product allowlist: the base commerce_products row fields that may
     * leave this surface, plus the derived enrichments added below. Everything
     * else on the raw row is internal -- numeric id, tenant_uuid, status (this
     * surface is active-only, so it carries zero information), raw `metadata`
     * (the app-internal data channel; {@see self::externalPayload()} extracts
     * the public parts), the rating_sum/rating_count rollup internals (replaced
     * by the derived `rating`), catalog_revision, tax_class, and the
     * updated_at/deleted_at timestamps.
     */
    private const PUBLIC_PRODUCT_FIELDS = [
        'uuid',
        'slug',
        'name',
        'description',
        'type',
        'options',
        'created_at',
        // Derived enrichments (never raw row columns):
        'variants',
        'cover_url',
        'rating',
        'media',
        'categories',
        'tags',
        'attributes',
        'addons',
        'children',
        'external',
    ];

    /**
     * Public variant allowlist. Variant `status` stays -- unlike the parent
     * product's constant-'active' status, variant status is functional for
     * option pickers. `shipping_class` is the derived slug added at projection.
     */
    private const PUBLIC_VARIANT_FIELDS = [
        'uuid',
        'sku',
        'option_values',
        'price',
        'compare_at_price',
        'currency',
        'position',
        'status',
        'shipping_class_uuid',
        'shipping_class',
    ];

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
        private ?AddonRepository $addons = null,
        private ?ShippingClassRepository $shippingClasses = null,
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
        $this->addons ??= app($context, AddonRepository::class);
        $this->shippingClasses ??= new ShippingClassRepository();
    }

    #[ApiOperation(summary: 'List active products', tags: ['Commerce Storefront'])]
    #[ApiResponse(200, description: 'Products retrieved')]
    public function index(ProductListQuery $query): Response
    {
        $page = max(1, $query->page ?? 1);
        $perPage = max(1, min(100, $query->per_page ?? 24));
        $tenant = $this->tenants->tenantUuid($this->context);

        $filters = $this->resolveFilters($tenant, $query);
        if ($filters === null) {
            // A requested category/tag/attribute slug (or pair) does not exist in
            // this tenant -- enumeration-neutral empty page, never a 404/422, and
            // never a product query at all (Layer 6 Global Constraints).
            return Response::paginated([], 0, $page, $perPage, null, 'Products retrieved');
        }

        $result = $this->products->listActive($this->context, $tenant, $page, $perPage, $filters);

        // Batched per-page: one variants-for-products query, one covers-for-products
        // query, and one shipping-class slug lookup across every variant on the page
        // -- instead of one of each per item (avoids an N+1 per page of results).
        $productUuids = array_map(static fn (array $product): string => (string) $product['uuid'], $result['items']);
        $variantsByProduct = $this->variants->forProducts($this->context, $tenant, $productUuids);
        $covers = $this->media->coversForProducts($this->context, $tenant, $productUuids);
        $classSlugsByUuid = $this->shippingClasses->slugsByUuids(
            $this->context,
            $tenant,
            $this->distinctShippingClassUuids($variantsByProduct)
        );

        return Response::paginated(
            array_map(
                function (array $product) use ($variantsByProduct, $covers, $classSlugsByUuid): array {
                    $uuid = (string) $product['uuid'];
                    $product['variants'] = array_map(
                        fn (array $variant): array => $this->withShippingClassSlug($variant, $classSlugsByUuid),
                        $variantsByProduct[$uuid] ?? []
                    );
                    $cover = $covers[$uuid] ?? null;
                    $product['cover_url'] = $cover === null ? null : '/blobs/' . $cover['blob_uuid'];

                    return $this->publicProduct($this->withRating($product));
                },
                $result['items']
            ),
            $result['total'],
            $page,
            $perPage,
            null,
            'Products retrieved'
        );
    }

    /**
     * Resolves `category`/`tag`/`attributes` to a {@see ResolvedProductFilters}
     * value (Layer 6 Global Constraints): category and tag are each ONE
     * tenant-scoped slug lookup; every requested attribute pair is resolved in
     * ONE batched query via {@see AttributeRepository::findPairsBySlugs()} --
     * never one query per pair. Returns `null` the moment ANY requested
     * category/tag/attribute-pair slug fails to resolve in this tenant, so
     * `index()` can short-circuit to an empty page BEFORE the product query
     * ever runs -- an unknown slug is enumeration-neutral, identical in shape
     * to a slug that simply matches nothing.
     */
    private function resolveFilters(string $tenant, ProductListQuery $query): ?ResolvedProductFilters
    {
        $categoryUuid = null;
        $category = $query->category !== null ? trim($query->category) : '';
        if ($category !== '') {
            $categoryUuid = $this->categories->findUuidBySlug($this->context, $tenant, $category);
            if ($categoryUuid === null) {
                return null;
            }
        }

        $tagUuid = null;
        $tag = $query->tag !== null ? trim($query->tag) : '';
        if ($tag !== '') {
            $tagUuid = $this->tags->findUuidBySlug($this->context, $tenant, $tag);
            if ($tagUuid === null) {
                return null;
            }
        }

        $requestedPairs = $query->attributePairs();
        $attributePairs = [];
        if ($requestedPairs !== []) {
            $resolved = $this->attributes->findPairsBySlugs($this->context, $tenant, $requestedPairs);
            foreach ($requestedPairs as $pair) {
                $key = $pair['attribute_slug'] . ':' . $pair['value_slug'];
                if (!isset($resolved[$key])) {
                    return null;
                }
                $attributePairs[] = $resolved[$key];
            }
        }

        return new ResolvedProductFilters($categoryUuid, $tagUuid, $attributePairs);
    }

    #[ApiOperation(summary: 'Get an active product by slug', tags: ['Commerce Storefront'])]
    #[ApiResponse(200, description: 'Product retrieved')]
    #[ApiResponse(404, description: 'Product not found')]
    public function show(Request $request, string $slug): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        // Buyer-context direct read (design spec §2.3, MV5b): a suspended/
        // onboarding/closed seller's product is unavailable here exactly
        // like a delisted/tombstoned one, via the centralized buyer
        // predicate -- never `findLiveBySlug()` (admin/internal only).
        $product = $this->products->findBuyerAvailableBySlug($this->context, $tenant, $slug);
        if ($product === null || ($product['status'] ?? '') !== 'active') {
            throw new NotFoundException('Resource not found.');
        }

        $product = $this->withRating($this->withVariants($tenant, $product));
        $product['media'] = $this->mediaPayload($tenant, (string) $product['uuid']);
        $product['categories'] = $this->categoriesPayload($tenant, (string) $product['uuid']);
        $product['tags'] = $this->tagsPayload($tenant, (string) $product['uuid']);
        $product['attributes'] = $this->attributesPayload($tenant, (string) $product['uuid']);
        $product['addons'] = $this->addonsPayload($tenant, (string) $product['uuid']);

        if (($product['type'] ?? null) === 'grouped') {
            $product['children'] = $this->childrenPayload($tenant, (string) $product['uuid']);
        }
        if (($product['type'] ?? null) === 'external') {
            $product['external'] = $this->externalPayload($product);
        }

        return Response::success($this->publicProduct($product), 'Product retrieved');
    }

    /**
     * Applies {@see self::PUBLIC_PRODUCT_FIELDS} / {@see self::PUBLIC_VARIANT_FIELDS}
     * as the LAST step before a product leaves this controller -- enrichment
     * happens on the raw row first, then everything not allowlisted is dropped.
     *
     * @param array<string,mixed> $product
     * @return array<string,mixed>
     */
    private function publicProduct(array $product): array
    {
        $public = array_intersect_key($product, array_flip(self::PUBLIC_PRODUCT_FIELDS));

        if (isset($public['variants']) && is_array($public['variants'])) {
            $public['variants'] = array_map(
                static fn (array $variant): array => array_intersect_key(
                    $variant,
                    array_flip(self::PUBLIC_VARIANT_FIELDS)
                ),
                $public['variants']
            );
        }

        return $public;
    }

    /** @param array<string,mixed> $product @return array<string,mixed> */
    private function withVariants(string $tenant, array $product): array
    {
        $variants = $this->variants->forProduct($this->context, $tenant, (string) $product['uuid']);
        $product['variants'] = $this->shippingClasses->attachResolvedSlugs($this->context, $tenant, $variants);

        return $product;
    }

    /**
     * @param array<string,list<array<string,mixed>>> $variantsByProduct
     * @return list<string>
     */
    private function distinctShippingClassUuids(array $variantsByProduct): array
    {
        $uuids = [];
        foreach ($variantsByProduct as $variants) {
            foreach ($variants as $variant) {
                if (($variant['shipping_class_uuid'] ?? null) !== null) {
                    $uuids[] = (string) $variant['shipping_class_uuid'];
                }
            }
        }

        return array_values(array_unique($uuids));
    }

    /**
     * @param array<string,mixed> $variant
     * @param array<string,string> $slugsByUuid
     * @return array<string,mixed>
     */
    private function withShippingClassSlug(array $variant, array $slugsByUuid): array
    {
        $classUuid = $variant['shipping_class_uuid'] ?? null;
        $variant['shipping_class'] = $classUuid !== null ? ($slugsByUuid[$classUuid] ?? null) : null;

        return $variant;
    }

    /**
     * `rating {average, count}` (design spec §5/§6) -- only added when count > 0;
     * `average` is rounded to 1 decimal AT PROJECTION TIME from
     * `rating_sum`/`rating_count`, never stored. Never sourced from
     * `commerce_reviews` directly -- the storefront product payload carries no
     * review-table field (author_email or otherwise), only this two-field
     * derived summary of the moderated rollup.
     *
     * @param array<string,mixed> $product
     * @return array<string,mixed>
     */
    private function withRating(array $product): array
    {
        $rating = $this->ratingPayload($product);
        if ($rating !== null) {
            $product['rating'] = $rating;
        }

        return $product;
    }

    /**
     * @param array<string,mixed> $product
     * @return array{average:float,count:int}|null
     */
    private function ratingPayload(array $product): ?array
    {
        $count = (int) ($product['rating_count'] ?? 0);
        if ($count <= 0) {
            return null;
        }

        return [
            'average' => round(((int) ($product['rating_sum'] ?? 0)) / $count, 1),
            'count' => $count,
        ];
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
     * is silently skipped rather than surfaced as a broken reference. Global
     * attribute rows are resolved via ONE batched `findManyByUuid()` IN query
     * (rather than one `findByUuid()` call per row) -- avoids an N+1 when a
     * product carries several global attribute assignments.
     *
     * @return list<array{slug?:string,name:string,values:list<string>}>
     */
    private function attributesPayload(string $tenant, string $productUuid): array
    {
        $rows = $this->attributes->productAttributeRows($this->context, $productUuid);

        $attributeUuids = array_values(array_unique(array_filter(array_map(
            static fn (array $row): ?string => $row['attribute_uuid'] !== null ? (string) $row['attribute_uuid'] : null,
            $rows
        ))));
        $attributes = $this->attributes->findManyByUuid($this->context, $tenant, $attributeUuids);

        $result = [];

        foreach ($rows as $row) {
            if (!(bool) $row['visible']) {
                continue;
            }

            if ($row['attribute_uuid'] !== null) {
                $attribute = $attributes[(string) $row['attribute_uuid']] ?? null;
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
     * ACTIVE-only, full public definition (design spec §6) -- the cart picker needs
     * the complete pricing surface, INCLUDING `uuid`, since the cart's `addons`
     * selection references a definition by `addon_uuid`. This is the opposite
     * convention from {@see \Glueful\Extensions\Commerce\Cart\AddonSnapshot::sanitize()}'s
     * order-line echo, which is deliberately uuid-free. `status` and every other
     * admin-only/internal field (tenant_uuid, product_uuid, id) are excluded.
     *
     * @return list<array{
     *     uuid:string,name:string,field_type:string,required:bool,
     *     choices:?list<array<string,mixed>>,price_delta:int,position:int
     * }>
     */
    private function addonsPayload(string $tenant, string $productUuid): array
    {
        return array_map(static fn (array $row): array => [
            'uuid' => (string) $row['uuid'],
            'name' => (string) $row['name'],
            'field_type' => (string) $row['field_type'],
            'required' => (bool) $row['required'],
            'choices' => $row['choices'] ?? null,
            'price_delta' => (int) $row['price_delta'],
            'position' => (int) $row['position'],
        ], $this->addons->activeForProduct($this->context, $tenant, $productUuid));
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
