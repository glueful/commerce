<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\AttributeRepository;
use Glueful\Extensions\Commerce\Catalog\AttributeService;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\CategoryService;
use Glueful\Extensions\Commerce\Catalog\ProductMediaRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\TagRepository;
use Glueful\Extensions\Commerce\Catalog\TagService;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Http\Admin\AdminAttributeController;
use Glueful\Extensions\Commerce\Http\Admin\AdminCategoryController;
use Glueful\Extensions\Commerce\Http\Admin\AdminMediaController;
use Glueful\Extensions\Commerce\Http\Admin\AdminTagController;
use Glueful\Extensions\Commerce\Http\DTOs\SetProductAttributesData;
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
 *
 * Task A3 adds `GET .../products/{uuid}/attributes` and `/media` below,
 * following the identical envelope/guard convention, plus (carried Minor
 * from A2's review) two tombstoned-product 404 tests -- one on the
 * categories read (A2's endpoint) and one on the attributes read (this
 * task's) -- so the plain "unknown vs. tombstoned product" 404 stays visible
 * in this file even though Task A4's children read deliberately returns
 * attached tombstones instead of 404ing on them.
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

    /**
     * Carried Minor from A2's review: a tombstoned product (as opposed to an
     * unknown or cross-tenant one) 404s exactly like the other two cases on
     * this plain product-attached read -- `catalogRevision()`'s
     * `findLiveByUuid()` predicate excludes `deleted_at IS NOT NULL` rows the
     * same way. Task A4's children read deliberately differs (an attached
     * tombstoned CHILD stays visible with `deleted: true`); this test and its
     * attributes-section twin below exist so that contrast stays visible in
     * one file rather than only in Task A4's own tests.
     */
    public function testCategoriesForProductTombstonedProductReturns404(): void
    {
        $product = $this->seedProduct('prodreadtmb1');
        self::assertTrue((new ProductRepository())->markDeleted($this->context, '', $product['uuid']));

        $this->expectException(NotFoundException::class);
        $this->categoryController()->forProductIndex(Request::create('/x'), $product['uuid']);
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
    // GET .../products/{uuid}/attributes
    // -----------------------------------------------------------------

    public function testAttributesForProductProjectsExactWhitelistedColumns(): void
    {
        $product = $this->seedProduct('prodreada001');
        $attribute = $this->seedAttribute('attrread0001', 'color', 'Color');
        $this->assignProductAttribute(
            'pattr0000001',
            $product['uuid'],
            $attribute['uuid'],
            null,
            ['red', 'blue'],
            true,
            false,
            0
        );

        $response = $this->attributeController()->forProductIndex(Request::create('/x'), $product['uuid']);
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $body['data']['items']);
        $item = $body['data']['items'][0];
        self::assertEqualsCanonicalizing(
            ['attribute_uuid', 'name', 'values', 'used_for_variants', 'visible', 'position'],
            array_keys($item)
        );
        self::assertSame($attribute['uuid'], $item['attribute_uuid']);
        self::assertNull($item['name']);
        self::assertSame(['red', 'blue'], $item['values']);
        self::assertTrue($item['used_for_variants']);
        self::assertFalse($item['visible']);
        self::assertSame(0, $item['position']);
    }

    /** `values` must decode to a real array over the wire, never a JSON string. */
    public function testAttributesForProductValuesIsDecodedArrayNeverJsonString(): void
    {
        $product = $this->seedProduct('prodreada002');
        $this->assignProductAttribute(
            'pattr0000002',
            $product['uuid'],
            null,
            'Care Note',
            ['Hand wash only', 'Line dry'],
            false,
            true,
            0
        );

        $response = $this->attributeController()->forProductIndex(Request::create('/x'), $product['uuid']);
        $values = $this->json($response)['data']['items'][0]['values'];

        self::assertIsArray($values);
        self::assertSame(['Hand wash only', 'Line dry'], $values);
    }

    /**
     * Boolean columns must come back as real booleans on the wire, not ints
     * (SQLite returns 0/1) -- `assertTrue`/`assertFalse` are strict (`===`),
     * so an unconverted `1`/`0` int fails this test.
     */
    public function testAttributesForProductBooleansAreRealBooleansNotInts(): void
    {
        $product = $this->seedProduct('prodreada003');
        $this->assignProductAttribute('pattr0000003', $product['uuid'], null, 'Flag row', [], true, false, 0);

        $item = $this->json(
            $this->attributeController()->forProductIndex(Request::create('/x'), $product['uuid'])
        )['data']['items'][0];

        self::assertTrue($item['used_for_variants']);
        self::assertFalse($item['visible']);
    }

    /**
     * Attributes are ordered editable data -- `position` ASC, NOT alphabetical
     * by name. Seeded deliberately out of alphabetical order relative to
     * position (Zebra=0, Apple=1, Mango=2) so an accidental name-sort would
     * be caught.
     */
    public function testAttributesForProductOrdersItemsByPositionAscendingNotAlphabetical(): void
    {
        $product = $this->seedProduct('prodreada004');
        $this->assignProductAttribute('pattrz000004', $product['uuid'], null, 'Zebra', [], false, true, 0);
        $this->assignProductAttribute('pattra000004', $product['uuid'], null, 'Apple', [], false, true, 1);
        $this->assignProductAttribute('pattrm000004', $product['uuid'], null, 'Mango', [], false, true, 2);

        $response = $this->attributeController()->forProductIndex(Request::create('/x'), $product['uuid']);

        self::assertSame(
            ['Zebra', 'Apple', 'Mango'],
            array_column($this->json($response)['data']['items'], 'name')
        );
    }

    public function testAttributesForProductEmptyAssignmentReturnsEmptyItemsWithRevisionPresent(): void
    {
        $product = $this->seedProduct('prodreada005');

        $response = $this->attributeController()->forProductIndex(Request::create('/x'), $product['uuid']);
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $body['data']['items']);
        self::assertSame(0, $body['data']['revision']);
    }

    public function testAttributesForProductEnvelopeHasExactlyRevisionAndItemsKeys(): void
    {
        $product = $this->seedProduct('prodreada006');

        $response = $this->attributeController()->forProductIndex(Request::create('/x'), $product['uuid']);

        self::assertEqualsCanonicalizing(['revision', 'items'], array_keys($this->json($response)['data']));
    }

    public function testAttributesForProductUnknownProductReturns404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->attributeController()->forProductIndex(Request::create('/x'), 'no-such-product');
    }

    public function testAttributesForProductCrossTenantProductReturns404NonRevealing(): void
    {
        $product = $this->seedProduct('prodreadxa01', 'tenantAAAA01');

        $this->expectException(NotFoundException::class);
        $this->attributeController('tenantBBBB02')->forProductIndex(Request::create('/x'), $product['uuid']);
    }

    /** Carried Minor from A2's review -- see the categories-section twin above for the full rationale. */
    public function testAttributesForProductTombstonedProductReturns404(): void
    {
        $product = $this->seedProduct('prodreadtmb2');
        self::assertTrue((new ProductRepository())->markDeleted($this->context, '', $product['uuid']));

        $this->expectException(NotFoundException::class);
        $this->attributeController()->forProductIndex(Request::create('/x'), $product['uuid']);
    }

    /**
     * Round-trip pin (brief's Step 1): feeding the GET's `items` verbatim back
     * into `setProductAttributes()`'s `attributes` argument must be a no-op --
     * same rows, same content, same position order -- proving the read's shape
     * really is the write's accepted input shape, not just a lookalike.
     */
    public function testAttributesForProductRoundTripsThroughSetProductAttributes(): void
    {
        $product = $this->seedProduct('prodreada007');
        $attribute = $this->seedAttribute('attrread0007', 'size', 'Size');
        $this->seedAttributeValue($attribute['uuid'], 'sm', 'Small', 0);
        $this->seedAttributeValue($attribute['uuid'], 'lg', 'Large', 1);

        $initial = $this->attributeController()->setForProduct(
            new SetProductAttributesData(attributes: [
                [
                    'attribute_uuid' => $attribute['uuid'],
                    'values' => ['sm', 'lg'],
                    'used_for_variants' => true,
                    'visible' => true,
                    'position' => 0,
                ],
                [
                    'name' => 'Care Note',
                    'values' => ['Hand wash only'],
                    'used_for_variants' => false,
                    'visible' => false,
                    'position' => 1,
                ],
            ]),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );
        self::assertSame(200, $initial->getStatusCode());

        $firstRead = $this->json(
            $this->attributeController()->forProductIndex(Request::create('/x'), $product['uuid'])
        )['data']['items'];
        self::assertCount(2, $firstRead);

        $replayed = $this->attributeController()->setForProduct(
            new SetProductAttributesData(attributes: $firstRead),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );
        self::assertSame(200, $replayed->getStatusCode(), (string) $replayed->getContent());

        $secondRead = $this->json(
            $this->attributeController()->forProductIndex(Request::create('/x'), $product['uuid'])
        )['data']['items'];

        self::assertSame($firstRead, $secondRead, 'Replaying the GET items verbatim must leave state unchanged.');
    }

    // -----------------------------------------------------------------
    // GET .../products/{uuid}/media
    // -----------------------------------------------------------------

    public function testMediaForProductProjectsExactWhitelistedColumns(): void
    {
        $product = $this->seedProduct('prodreadm001');
        $this->assignMedia('med00000001', $product['uuid'], 'blob0000001', 'cover', 0, 'Front view', null);

        $response = $this->mediaController()->forProductIndex(Request::create('/x'), $product['uuid']);
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $body['data']['items']);
        $item = $body['data']['items'][0];
        self::assertEqualsCanonicalizing(
            ['uuid', 'blob_uuid', 'role', 'position', 'alt', 'variant_uuid'],
            array_keys($item)
        );
        self::assertSame('med00000001', $item['uuid']);
        self::assertSame('blob0000001', $item['blob_uuid']);
        self::assertSame('cover', $item['role']);
        self::assertSame(0, $item['position']);
        self::assertSame('Front view', $item['alt']);
        self::assertNull($item['variant_uuid']);
    }

    public function testMediaForProductEchoesVariantUuidWhenSet(): void
    {
        $product = $this->seedProduct('prodreadm002');
        $this->assignMedia('med00000002', $product['uuid'], 'blob0000002', 'gallery', 0, null, 'variantread01');

        $item = $this->json(
            $this->mediaController()->forProductIndex(Request::create('/x'), $product['uuid'])
        )['data']['items'][0];

        self::assertSame('variantread01', $item['variant_uuid']);
    }

    /** Media items are ordered by `position` ASC, pinned with an out-of-insertion-order seed. */
    public function testMediaForProductOrdersItemsByPositionAscending(): void
    {
        $product = $this->seedProduct('prodreadm003');
        $this->assignMedia('medz0000003', $product['uuid'], 'blobz000003', 'gallery', 2, null, null);
        $this->assignMedia('meda0000003', $product['uuid'], 'bloba000003', 'gallery', 0, null, null);
        $this->assignMedia('medm0000003', $product['uuid'], 'blobm000003', 'gallery', 1, null, null);

        $response = $this->mediaController()->forProductIndex(Request::create('/x'), $product['uuid']);

        self::assertSame(
            ['meda0000003', 'medm0000003', 'medz0000003'],
            array_column($this->json($response)['data']['items'], 'uuid')
        );
    }

    public function testMediaForProductEmptyAssignmentReturnsEmptyItemsWithRevisionPresent(): void
    {
        $product = $this->seedProduct('prodreadm004');

        $response = $this->mediaController()->forProductIndex(Request::create('/x'), $product['uuid']);
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $body['data']['items']);
        self::assertSame(0, $body['data']['revision']);
    }

    public function testMediaForProductEnvelopeHasExactlyRevisionAndItemsKeys(): void
    {
        $product = $this->seedProduct('prodreadm005');

        $response = $this->mediaController()->forProductIndex(Request::create('/x'), $product['uuid']);

        self::assertEqualsCanonicalizing(['revision', 'items'], array_keys($this->json($response)['data']));
    }

    public function testMediaForProductUnknownProductReturns404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->mediaController()->forProductIndex(Request::create('/x'), 'no-such-product');
    }

    public function testMediaForProductCrossTenantProductReturns404NonRevealing(): void
    {
        $product = $this->seedProduct('prodreadxm01', 'tenantAAAA01');

        $this->expectException(NotFoundException::class);
        $this->mediaController('tenantBBBB02')->forProductIndex(Request::create('/x'), $product['uuid']);
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

    private function attributeController(string $tenant = ''): AdminAttributeController
    {
        return new AdminAttributeController($this->context, $this->attributeService($tenant));
    }

    private function attributeService(string $tenant = ''): AttributeService
    {
        return new AttributeService(
            new AttributeRepository(),
            new ProductRepository(),
            $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant)
        );
    }

    private function mediaController(string $tenant = ''): AdminMediaController
    {
        return new AdminMediaController($this->context, $this->mediaService($tenant));
    }

    private function mediaService(string $tenant = ''): ProductMediaService
    {
        return new ProductMediaService(
            new ProductRepository(),
            new VariantRepository(),
            new ProductMediaRepository(),
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
    private function seedAttribute(string $uuid, string $slug, string $name, string $tenant = ''): array
    {
        $this->connection->table('commerce_attributes')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => $slug,
            'name' => $name,
        ]);

        $attribute = (new AttributeRepository())->findByUuid($this->context, $tenant, $uuid);
        self::assertNotNull($attribute);

        return $attribute;
    }

    private function seedAttributeValue(string $attributeUuid, string $slug, string $value, int $position): void
    {
        $this->connection->table('commerce_attribute_values')->insert([
            'uuid' => 'av' . substr(md5($attributeUuid . $slug), 0, 10),
            'attribute_uuid' => $attributeUuid,
            'slug' => $slug,
            'value' => $value,
            'position' => $position,
        ]);
    }

    /** @param list<string> $values */
    private function assignProductAttribute(
        string $uuid,
        string $productUuid,
        ?string $attributeUuid,
        ?string $name,
        array $values,
        bool $usedForVariants,
        bool $visible,
        int $position
    ): void {
        $this->connection->table('commerce_product_attributes')->insert([
            'uuid' => $uuid,
            'product_uuid' => $productUuid,
            'attribute_uuid' => $attributeUuid,
            'name' => $name,
            'values' => json_encode($values, JSON_THROW_ON_ERROR),
            'used_for_variants' => $usedForVariants,
            'visible' => $visible,
            'position' => $position,
        ]);
    }

    private function assignMedia(
        string $uuid,
        string $productUuid,
        string $blobUuid,
        string $role,
        int $position,
        ?string $alt,
        ?string $variantUuid,
        string $tenant = ''
    ): void {
        $this->connection->table('commerce_product_media')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'product_uuid' => $productUuid,
            'variant_uuid' => $variantUuid,
            'blob_uuid' => $blobUuid,
            'role' => $role,
            'position' => $position,
            'alt' => $alt,
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
