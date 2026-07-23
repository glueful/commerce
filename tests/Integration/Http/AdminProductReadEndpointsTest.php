<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\CategoryService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\TagRepository;
use Glueful\Extensions\Commerce\Catalog\TagService;
use Glueful\Extensions\Commerce\Http\Admin\AdminCategoryController;
use Glueful\Extensions\Commerce\Http\Admin\AdminTagController;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Task A2 (single-page product editor plan): read-only product-children
 * endpoints -- `GET .../products/{uuid}/categories` and `/tags`. Both share
 * the SAME envelope convention `{revision: int, items: [...]}` (Global
 * Constraints) that every subsequent read this plan adds (variants, media,
 * add-ons -- Tasks A3/A4) reuses, so this file is structured with one section
 * per endpoint plus shared seed helpers at the bottom, meant to be extended
 * in place rather than duplicated.
 */
final class AdminProductReadEndpointsTest extends CommerceTestCase
{
    // -----------------------------------------------------------------
    // GET .../products/{uuid}/categories
    // -----------------------------------------------------------------

    public function testCategoriesForProductProjectsExactlyUuidNameSlug(): void
    {
        $product = $this->seedProduct('prodread00001');
        $category = $this->seedCategory('catread0001', 'shoes', 'Shoes');
        $this->assignCategory($product['uuid'], $category['uuid']);

        $response = $this->categoryController()->forProductIndex(Request::create('/x'), $product['uuid']);
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $body['data']['items']);
        self::assertEqualsCanonicalizing(['uuid', 'name', 'slug'], array_keys($body['data']['items'][0]));
        self::assertSame($category['uuid'], $body['data']['items'][0]['uuid']);
        self::assertSame('Shoes', $body['data']['items'][0]['name']);
        self::assertSame('shoes', $body['data']['items'][0]['slug']);
    }

    public function testCategoriesForProductOrdersItemsAlphabeticallyByName(): void
    {
        $product = $this->seedProduct('prodread00002');
        $z = $this->seedCategory('catread0002', 'zebra', 'Zebra');
        $a = $this->seedCategory('catread0003', 'apple', 'Apple');
        $m = $this->seedCategory('catread0004', 'mango', 'Mango');
        $this->assignCategory($product['uuid'], $z['uuid']);
        $this->assignCategory($product['uuid'], $a['uuid']);
        $this->assignCategory($product['uuid'], $m['uuid']);

        $response = $this->categoryController()->forProductIndex(Request::create('/x'), $product['uuid']);
        $body = $this->json($response);

        self::assertSame(['Apple', 'Mango', 'Zebra'], array_column($body['data']['items'], 'name'));
    }

    public function testCategoriesForProductEmptyAssignmentReturnsEmptyItemsWithRevisionPresent(): void
    {
        $product = $this->seedProduct('prodread00005');

        $response = $this->categoryController()->forProductIndex(Request::create('/x'), $product['uuid']);
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $body['data']['items']);
        self::assertSame(0, $body['data']['revision']);
    }

    public function testCategoriesForProductEnvelopeHasExactlyRevisionAndItemsKeys(): void
    {
        $product = $this->seedProduct('prodread00006');

        $response = $this->categoryController()->forProductIndex(Request::create('/x'), $product['uuid']);
        $body = $this->json($response);

        self::assertEqualsCanonicalizing(['revision', 'items'], array_keys($body['data']));
    }

    public function testCategoriesForProductUnknownProductReturns404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->categoryController()->forProductIndex(Request::create('/x'), 'no-such-product');
    }

    public function testCategoriesForProductCrossTenantProductReturns404NonRevealing(): void
    {
        $product = $this->seedProduct('prodreadx001', 'tenantAAAA01');

        $this->expectException(NotFoundException::class);
        $this->categoryController('tenantBBBB02')->forProductIndex(Request::create('/x'), $product['uuid']);
    }

    // -----------------------------------------------------------------
    // GET .../products/{uuid}/tags
    // -----------------------------------------------------------------

    public function testTagsForProductProjectsExactlyUuidNameSlug(): void
    {
        $product = $this->seedProduct('prodreadt001');
        $tag = $this->seedTag('tagread00001', 'summer', 'Summer');
        $this->assignTag($product['uuid'], $tag['uuid']);

        $response = $this->tagController()->forProductIndex(Request::create('/x'), $product['uuid']);
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $body['data']['items']);
        self::assertEqualsCanonicalizing(['uuid', 'name', 'slug'], array_keys($body['data']['items'][0]));
        self::assertSame($tag['uuid'], $body['data']['items'][0]['uuid']);
        self::assertSame('Summer', $body['data']['items'][0]['name']);
        self::assertSame('summer', $body['data']['items'][0]['slug']);
    }

    public function testTagsForProductOrdersItemsAlphabeticallyByName(): void
    {
        $product = $this->seedProduct('prodreadt002');
        $z = $this->seedTag('tagread00002', 'zebra', 'Zebra');
        $a = $this->seedTag('tagread00003', 'apple', 'Apple');
        $m = $this->seedTag('tagread00004', 'mango', 'Mango');
        $this->assignTag($product['uuid'], $z['uuid']);
        $this->assignTag($product['uuid'], $a['uuid']);
        $this->assignTag($product['uuid'], $m['uuid']);

        $response = $this->tagController()->forProductIndex(Request::create('/x'), $product['uuid']);
        $body = $this->json($response);

        self::assertSame(['Apple', 'Mango', 'Zebra'], array_column($body['data']['items'], 'name'));
    }

    public function testTagsForProductEmptyAssignmentReturnsEmptyItemsWithRevisionPresent(): void
    {
        $product = $this->seedProduct('prodreadt005');

        $response = $this->tagController()->forProductIndex(Request::create('/x'), $product['uuid']);
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $body['data']['items']);
        self::assertSame(0, $body['data']['revision']);
    }

    public function testTagsForProductEnvelopeHasExactlyRevisionAndItemsKeys(): void
    {
        $product = $this->seedProduct('prodreadt006');

        $response = $this->tagController()->forProductIndex(Request::create('/x'), $product['uuid']);
        $body = $this->json($response);

        self::assertEqualsCanonicalizing(['revision', 'items'], array_keys($body['data']));
    }

    public function testTagsForProductUnknownProductReturns404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->tagController()->forProductIndex(Request::create('/x'), 'no-such-product');
    }

    public function testTagsForProductCrossTenantProductReturns404NonRevealing(): void
    {
        $product = $this->seedProduct('prodreadtx01', 'tenantAAAA01');

        $this->expectException(NotFoundException::class);
        $this->tagController('tenantBBBB02')->forProductIndex(Request::create('/x'), $product['uuid']);
    }

    // -----------------------------------------------------------------
    // Fixtures / controller wiring (reused and extended by Tasks A3/A4)
    // -----------------------------------------------------------------

    private function categoryController(string $tenant = ''): AdminCategoryController
    {
        return new AdminCategoryController($this->context, $this->categoryService($tenant));
    }

    private function tagController(string $tenant = ''): AdminTagController
    {
        return new AdminTagController($this->context, $this->tagService($tenant));
    }

    private function categoryService(string $tenant = ''): CategoryService
    {
        return new CategoryService(
            new CategoryRepository(),
            new ProductRepository(),
            $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant)
        );
    }

    private function tagService(string $tenant = ''): TagService
    {
        return new TagService(
            new TagRepository(),
            new ProductRepository(),
            $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant)
        );
    }

    private function fixedTenant(string $tenant): CurrentTenantResolver
    {
        return new class ($tenant) implements CurrentTenantResolver {
            public function __construct(private string $tenant)
            {
            }

            public function tenantUuid(ApplicationContext $context): string
            {
                return $this->tenant;
            }
        };
    }

    /** @return array<string,mixed> */
    private function seedProduct(string $uuid, string $tenant = ''): array
    {
        $this->connection->table('commerce_products')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => strtolower($uuid),
            'name' => $uuid,
            'type' => 'physical',
            'status' => 'active',
        ]);

        $product = (new ProductRepository())->findLiveByUuid($this->context, $tenant, $uuid);
        self::assertNotNull($product);

        return $product;
    }

    /** @return array<string,mixed> */
    private function seedCategory(string $uuid, string $slug, string $name, string $tenant = ''): array
    {
        $this->connection->table('commerce_categories')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => $slug,
            'name' => $name,
        ]);

        $category = (new CategoryRepository())->findByUuid($this->context, $tenant, $uuid);
        self::assertNotNull($category);

        return $category;
    }

    /** @return array<string,mixed> */
    private function seedTag(string $uuid, string $slug, string $name, string $tenant = ''): array
    {
        $this->connection->table('commerce_tags')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => $slug,
            'name' => $name,
        ]);

        $tag = (new TagRepository())->findByUuid($this->context, $tenant, $uuid);
        self::assertNotNull($tag);

        return $tag;
    }

    private function assignCategory(string $productUuid, string $categoryUuid): void
    {
        $this->connection->table('commerce_product_categories')->insert([
            'product_uuid' => $productUuid,
            'category_uuid' => $categoryUuid,
        ]);
    }

    private function assignTag(string $productUuid, string $tagUuid): void
    {
        $this->connection->table('commerce_product_tags')->insert([
            'product_uuid' => $productUuid,
            'tag_uuid' => $tagUuid,
        ]);
    }

    /** @return array<string,mixed> */
    private function json(HttpResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
