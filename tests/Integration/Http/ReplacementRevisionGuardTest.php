<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Extensions\Commerce\Catalog\AttributeRepository;
use Glueful\Extensions\Commerce\Catalog\AttributeService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\CategoryService;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\TagRepository;
use Glueful\Extensions\Commerce\Catalog\TagService;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Http\Admin\AdminAttributeController;
use Glueful\Extensions\Commerce\Http\Admin\AdminCategoryController;
use Glueful\Extensions\Commerce\Http\Admin\AdminMediaController;
use Glueful\Extensions\Commerce\Http\Admin\AdminProductController;
use Glueful\Extensions\Commerce\Http\Admin\AdminTagController;
use Glueful\Extensions\Commerce\Http\DTOs\ReorderMediaData;
use Glueful\Extensions\Commerce\Http\DTOs\SetProductAttributesData;
use Glueful\Extensions\Commerce\Http\DTOs\SetProductCategoriesData;
use Glueful\Extensions\Commerce\Http\DTOs\SetProductChildrenData;
use Glueful\Extensions\Commerce\Http\DTOs\SetProductTagsData;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Task A5 (single-page product editor plan): `expected_revision` CAS guard on
 * the five replacement mutations -- CategoryService::setProductCategories,
 * TagService::setProductTags, AttributeService::setProductAttributes,
 * ProductMediaService::reorder, and CatalogService::setProductChildren.
 *
 * Every mutation gets the SAME six-case matrix (stale/matching/absent/
 * negative/unknown/tombstoned); `setChildren` additionally gets the
 * retention-only tombstone exception's two directions. Harness mirrors
 * {@see AdminProductReadEndpointsTest}: direct controller construction over
 * an in-memory sqlite `CommerceTestCase`, no router/HTTP dispatch -- so
 * `#[Rule]` DTO-hydration attributes are never exercised here; the
 * `expected_revision` contract under test lives entirely in the service
 * layer each controller delegates to.
 */
final class ReplacementRevisionGuardTest extends CommerceTestCase
{
    // -----------------------------------------------------------------
    // CategoryService::setProductCategories via AdminCategoryController
    // -----------------------------------------------------------------

    public function testCategoriesStaleRevisionReturns409AndLeavesStateUnchanged(): void
    {
        $product = $this->seedProduct('prodgcat00001');
        $category = $this->seedCategory('catgrd0000001', 'guard-cat-1', 'Guard Cat 1');

        // A concurrent write bumps the product's revision to 1 behind the
        // caller's back; the caller's snapshot (0) is now stale.
        self::assertTrue((new ProductRepository())->claimCatalogRevision($this->context, '', $product['uuid']));

        $response = $this->categoryController()->setForProduct(
            new SetProductCategoriesData(category_uuids: [$category['uuid']], expected_revision: 0),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            [],
            $this->connection->table('commerce_product_categories')
                ->where('product_uuid', '=', $product['uuid'])
                ->get()
        );
        $reloaded = (new ProductRepository())->findLiveByUuid($this->context, '', $product['uuid']);
        self::assertSame(1, (int) $reloaded['catalog_revision']);
    }

    public function testCategoriesMatchingRevisionSucceedsAndBumpsRevision(): void
    {
        $product = $this->seedProduct('prodgcat00002');
        $category = $this->seedCategory('catgrd0000002', 'guard-cat-2', 'Guard Cat 2');

        $response = $this->categoryController()->setForProduct(
            new SetProductCategoriesData(category_uuids: [$category['uuid']], expected_revision: 0),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertCount(1, $this->json($response)['data']);
        $reloaded = (new ProductRepository())->findLiveByUuid($this->context, '', $product['uuid']);
        self::assertSame(1, (int) $reloaded['catalog_revision']);
    }

    public function testCategoriesAbsentExpectedRevisionSucceedsAgainstAnyRevision(): void
    {
        $product = $this->seedProduct('prodgcat00003');
        $category = $this->seedCategory('catgrd0000003', 'guard-cat-3', 'Guard Cat 3');
        self::assertTrue((new ProductRepository())->claimCatalogRevision($this->context, '', $product['uuid']));

        $response = $this->categoryController()->setForProduct(
            new SetProductCategoriesData(category_uuids: [$category['uuid']]),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    public function testCategoriesNegativeExpectedRevisionReturns422(): void
    {
        $product = $this->seedProduct('prodgcat00004');

        $response = $this->categoryController()->setForProduct(
            new SetProductCategoriesData(category_uuids: [], expected_revision: -1),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('expected_revision', $this->json($response)['error']['details']);
    }

    public function testCategoriesUnknownProductWithExpectedRevisionReturns404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->categoryController()->setForProduct(
            new SetProductCategoriesData(category_uuids: [], expected_revision: 0),
            Request::create('/x', 'PUT'),
            'no-such-product'
        );
    }

    public function testCategoriesTombstonedProductWithExpectedRevisionReturns404NotConflict(): void
    {
        $product = $this->seedProduct('prodgcat00005');
        self::assertTrue((new ProductRepository())->markDeleted($this->context, '', $product['uuid']));

        $this->expectException(NotFoundException::class);
        $this->categoryController()->setForProduct(
            new SetProductCategoriesData(category_uuids: [], expected_revision: 0),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );
    }

    // -----------------------------------------------------------------
    // TagService::setProductTags via AdminTagController
    // -----------------------------------------------------------------

    public function testTagsStaleRevisionReturns409AndLeavesStateUnchanged(): void
    {
        $product = $this->seedProduct('prodgtag00001');
        $tag = $this->seedTag('taggrd00000001', 'guard-tag-1', 'Guard Tag 1');
        self::assertTrue((new ProductRepository())->claimCatalogRevision($this->context, '', $product['uuid']));

        $response = $this->tagController()->setForProduct(
            new SetProductTagsData(tag_uuids: [$tag['uuid']], expected_revision: 0),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            [],
            $this->connection->table('commerce_product_tags')
                ->where('product_uuid', '=', $product['uuid'])
                ->get()
        );
        $reloaded = (new ProductRepository())->findLiveByUuid($this->context, '', $product['uuid']);
        self::assertSame(1, (int) $reloaded['catalog_revision']);
    }

    public function testTagsMatchingRevisionSucceedsAndBumpsRevision(): void
    {
        $product = $this->seedProduct('prodgtag00002');
        $tag = $this->seedTag('taggrd00000002', 'guard-tag-2', 'Guard Tag 2');

        $response = $this->tagController()->setForProduct(
            new SetProductTagsData(tag_uuids: [$tag['uuid']], expected_revision: 0),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertCount(1, $this->json($response)['data']);
        $reloaded = (new ProductRepository())->findLiveByUuid($this->context, '', $product['uuid']);
        self::assertSame(1, (int) $reloaded['catalog_revision']);
    }

    public function testTagsAbsentExpectedRevisionSucceedsAgainstAnyRevision(): void
    {
        $product = $this->seedProduct('prodgtag00003');
        $tag = $this->seedTag('taggrd00000003', 'guard-tag-3', 'Guard Tag 3');
        self::assertTrue((new ProductRepository())->claimCatalogRevision($this->context, '', $product['uuid']));

        $response = $this->tagController()->setForProduct(
            new SetProductTagsData(tag_uuids: [$tag['uuid']]),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    public function testTagsNegativeExpectedRevisionReturns422(): void
    {
        $product = $this->seedProduct('prodgtag00004');

        $response = $this->tagController()->setForProduct(
            new SetProductTagsData(tag_uuids: [], expected_revision: -1),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('expected_revision', $this->json($response)['error']['details']);
    }

    public function testTagsUnknownProductWithExpectedRevisionReturns404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->tagController()->setForProduct(
            new SetProductTagsData(tag_uuids: [], expected_revision: 0),
            Request::create('/x', 'PUT'),
            'no-such-product'
        );
    }

    public function testTagsTombstonedProductWithExpectedRevisionReturns404NotConflict(): void
    {
        $product = $this->seedProduct('prodgtag00005');
        self::assertTrue((new ProductRepository())->markDeleted($this->context, '', $product['uuid']));

        $this->expectException(NotFoundException::class);
        $this->tagController()->setForProduct(
            new SetProductTagsData(tag_uuids: [], expected_revision: 0),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );
    }

    // -----------------------------------------------------------------
    // AttributeService::setProductAttributes via AdminAttributeController
    // -----------------------------------------------------------------

    /** @return array<string,mixed> */
    private function customAttributeRow(): array
    {
        return [
            'name' => 'Care Note',
            'values' => ['Hand wash only'],
            'used_for_variants' => false,
            'visible' => false,
            'position' => 0,
        ];
    }

    public function testAttributesStaleRevisionReturns409AndLeavesStateUnchanged(): void
    {
        $product = $this->seedProduct('prodgatt00001');
        self::assertTrue((new ProductRepository())->claimCatalogRevision($this->context, '', $product['uuid']));

        $response = $this->attributeController()->setForProduct(
            new SetProductAttributesData(attributes: [$this->customAttributeRow()], expected_revision: 0),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            [],
            $this->connection->table('commerce_product_attributes')
                ->where('product_uuid', '=', $product['uuid'])
                ->get()
        );
        $reloaded = (new ProductRepository())->findLiveByUuid($this->context, '', $product['uuid']);
        self::assertSame(1, (int) $reloaded['catalog_revision']);
    }

    public function testAttributesMatchingRevisionSucceedsAndBumpsRevision(): void
    {
        $product = $this->seedProduct('prodgatt00002');

        $response = $this->attributeController()->setForProduct(
            new SetProductAttributesData(attributes: [$this->customAttributeRow()], expected_revision: 0),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertCount(1, $this->json($response)['data']);
        $reloaded = (new ProductRepository())->findLiveByUuid($this->context, '', $product['uuid']);
        self::assertSame(1, (int) $reloaded['catalog_revision']);
    }

    public function testAttributesAbsentExpectedRevisionSucceedsAgainstAnyRevision(): void
    {
        $product = $this->seedProduct('prodgatt00003');
        self::assertTrue((new ProductRepository())->claimCatalogRevision($this->context, '', $product['uuid']));

        $response = $this->attributeController()->setForProduct(
            new SetProductAttributesData(attributes: [$this->customAttributeRow()]),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    public function testAttributesNegativeExpectedRevisionReturns422(): void
    {
        $product = $this->seedProduct('prodgatt00004');

        $response = $this->attributeController()->setForProduct(
            new SetProductAttributesData(attributes: [], expected_revision: -1),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('expected_revision', $this->json($response)['error']['details']);
    }

    public function testAttributesUnknownProductWithExpectedRevisionReturns404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->attributeController()->setForProduct(
            new SetProductAttributesData(attributes: [], expected_revision: 0),
            Request::create('/x', 'PUT'),
            'no-such-product'
        );
    }

    public function testAttributesTombstonedProductWithExpectedRevisionReturns404NotConflict(): void
    {
        $product = $this->seedProduct('prodgatt00005');
        self::assertTrue((new ProductRepository())->markDeleted($this->context, '', $product['uuid']));

        $this->expectException(NotFoundException::class);
        $this->attributeController()->setForProduct(
            new SetProductAttributesData(attributes: [], expected_revision: 0),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );
    }

    // -----------------------------------------------------------------
    // ProductMediaService::reorder via AdminMediaController
    // -----------------------------------------------------------------

    public function testMediaReorderStaleRevisionReturns409AndLeavesStateUnchanged(): void
    {
        $product = $this->seedProduct('prodgmed00001');
        $this->assignMedia('mediaguard0001', $product['uuid'], 'blobguard001', 'gallery', 0, null, null);
        self::assertTrue((new ProductRepository())->claimCatalogRevision($this->context, '', $product['uuid']));

        $response = $this->mediaController()->reorder(
            new ReorderMediaData(positions: [['uuid' => 'mediaguard0001', 'position' => 3]], expected_revision: 0),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        self::assertSame(409, $response->getStatusCode());
        $row = $this->connection->table('commerce_product_media')
            ->where('uuid', '=', 'mediaguard0001')
            ->first();
        self::assertSame(0, (int) $row['position']);
        $reloaded = (new ProductRepository())->findLiveByUuid($this->context, '', $product['uuid']);
        self::assertSame(1, (int) $reloaded['catalog_revision']);
    }

    public function testMediaReorderMatchingRevisionSucceedsAndBumpsRevision(): void
    {
        $product = $this->seedProduct('prodgmed00002');
        $this->assignMedia('mediaguard0002', $product['uuid'], 'blobguard002', 'gallery', 0, null, null);

        $response = $this->mediaController()->reorder(
            new ReorderMediaData(positions: [['uuid' => 'mediaguard0002', 'position' => 3]], expected_revision: 0),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $row = $this->connection->table('commerce_product_media')
            ->where('uuid', '=', 'mediaguard0002')
            ->first();
        self::assertSame(3, (int) $row['position']);
        $reloaded = (new ProductRepository())->findLiveByUuid($this->context, '', $product['uuid']);
        self::assertSame(1, (int) $reloaded['catalog_revision']);
    }

    public function testMediaReorderAbsentExpectedRevisionSucceedsAgainstAnyRevision(): void
    {
        $product = $this->seedProduct('prodgmed00003');
        $this->assignMedia('mediaguard0003', $product['uuid'], 'blobguard003', 'gallery', 0, null, null);
        self::assertTrue((new ProductRepository())->claimCatalogRevision($this->context, '', $product['uuid']));

        $response = $this->mediaController()->reorder(
            new ReorderMediaData(positions: [['uuid' => 'mediaguard0003', 'position' => 3]]),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    public function testMediaReorderNegativeExpectedRevisionReturns422(): void
    {
        $product = $this->seedProduct('prodgmed00004');
        $this->assignMedia('mediaguard0004', $product['uuid'], 'blobguard004', 'gallery', 0, null, null);

        $response = $this->mediaController()->reorder(
            new ReorderMediaData(positions: [['uuid' => 'mediaguard0004', 'position' => 0]], expected_revision: -1),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('expected_revision', $this->json($response)['error']['details']);
    }

    public function testMediaReorderUnknownProductWithExpectedRevisionReturns404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->mediaController()->reorder(
            new ReorderMediaData(positions: [['uuid' => 'x', 'position' => 0]], expected_revision: 0),
            Request::create('/x', 'PUT'),
            'no-such-product'
        );
    }

    public function testMediaReorderTombstonedProductWithExpectedRevisionReturns404NotConflict(): void
    {
        $product = $this->seedProduct('prodgmed00005');
        $this->assignMedia('mediaguard0005', $product['uuid'], 'blobguard005', 'gallery', 0, null, null);
        self::assertTrue((new ProductRepository())->markDeleted($this->context, '', $product['uuid']));

        $this->expectException(NotFoundException::class);
        $this->mediaController()->reorder(
            new ReorderMediaData(positions: [['uuid' => 'mediaguard0005', 'position' => 0]], expected_revision: 0),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );
    }

    // -----------------------------------------------------------------
    // CatalogService::setProductChildren via AdminProductController
    // -----------------------------------------------------------------

    public function testChildrenStaleRevisionReturns409AndLeavesStateUnchanged(): void
    {
        $parent = $this->seedGroupedProduct('prodgchd00001');
        $child = $this->seedProduct('prodgchdc0001');
        self::assertTrue((new ProductRepository())->claimCatalogRevision($this->context, '', $parent['uuid']));

        $response = $this->productController()->setChildren(
            new SetProductChildrenData(child_uuids: [$child['uuid']], expected_revision: 0),
            Request::create('/x', 'PUT'),
            $parent['uuid']
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            [],
            $this->connection->table('commerce_product_children')
                ->where('product_uuid', '=', $parent['uuid'])
                ->get()
        );
        $reloaded = (new ProductRepository())->findLiveByUuid($this->context, '', $parent['uuid']);
        self::assertSame(1, (int) $reloaded['catalog_revision']);
    }

    public function testChildrenMatchingRevisionSucceedsAndBumpsRevision(): void
    {
        $parent = $this->seedGroupedProduct('prodgchd00002');
        $child = $this->seedProduct('prodgchdc0002');

        $response = $this->productController()->setChildren(
            new SetProductChildrenData(child_uuids: [$child['uuid']], expected_revision: 0),
            Request::create('/x', 'PUT'),
            $parent['uuid']
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertCount(1, $this->json($response)['data']);
        $reloaded = (new ProductRepository())->findLiveByUuid($this->context, '', $parent['uuid']);
        self::assertSame(1, (int) $reloaded['catalog_revision']);
    }

    public function testChildrenAbsentExpectedRevisionSucceedsAgainstAnyRevision(): void
    {
        $parent = $this->seedGroupedProduct('prodgchd00003');
        $child = $this->seedProduct('prodgchdc0003');
        self::assertTrue((new ProductRepository())->claimCatalogRevision($this->context, '', $parent['uuid']));

        $response = $this->productController()->setChildren(
            new SetProductChildrenData(child_uuids: [$child['uuid']]),
            Request::create('/x', 'PUT'),
            $parent['uuid']
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    public function testChildrenNegativeExpectedRevisionReturns422(): void
    {
        $parent = $this->seedGroupedProduct('prodgchd00004');

        $response = $this->productController()->setChildren(
            new SetProductChildrenData(child_uuids: [], expected_revision: -1),
            Request::create('/x', 'PUT'),
            $parent['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('expected_revision', $this->json($response)['error']['details']);
    }

    public function testChildrenUnknownProductWithExpectedRevisionReturns404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->productController()->setChildren(
            new SetProductChildrenData(child_uuids: [], expected_revision: 0),
            Request::create('/x', 'PUT'),
            'no-such-product'
        );
    }

    public function testChildrenTombstonedProductWithExpectedRevisionReturns404NotConflict(): void
    {
        $parent = $this->seedGroupedProduct('prodgchd00005');
        self::assertTrue((new ProductRepository())->markDeleted($this->context, '', $parent['uuid']));

        $this->expectException(NotFoundException::class);
        $this->productController()->setChildren(
            new SetProductChildrenData(child_uuids: [], expected_revision: 0),
            Request::create('/x', 'PUT'),
            $parent['uuid']
        );
    }

    /**
     * Retention-only tombstone exception (brief step 2): an already-attached
     * child that was tombstoned AFTER attachment survives a no-op replacement
     * that still proposes it, and may then be removed by a later call that
     * drops it from the proposed list.
     */
    public function testChildrenRetainsAlreadyAttachedTombstonedChildOnNoOpReplaceThenAllowsRemoval(): void
    {
        $parent = $this->seedGroupedProduct('prodgchdret01');
        $child = $this->seedProduct('prodgchdretc1');
        $this->assignChild($parent['uuid'], $child['uuid'], 0);
        self::assertTrue((new ProductRepository())->markDeleted($this->context, '', $child['uuid']));

        $noOp = $this->productController()->setChildren(
            new SetProductChildrenData(child_uuids: [$child['uuid']]),
            Request::create('/x', 'PUT'),
            $parent['uuid']
        );
        self::assertSame(200, $noOp->getStatusCode(), (string) $noOp->getContent());
        $afterNoOp = $this->connection->table('commerce_product_children')
            ->where('product_uuid', '=', $parent['uuid'])
            ->get();
        self::assertCount(1, $afterNoOp);
        self::assertSame($child['uuid'], $afterNoOp[0]['child_uuid']);

        $removal = $this->productController()->setChildren(
            new SetProductChildrenData(child_uuids: []),
            Request::create('/x', 'PUT'),
            $parent['uuid']
        );
        self::assertSame(200, $removal->getStatusCode(), (string) $removal->getContent());
        self::assertSame(
            [],
            $this->connection->table('commerce_product_children')
                ->where('product_uuid', '=', $parent['uuid'])
                ->get()
        );
    }

    /**
     * The other direction of the same exception: a tombstoned product NOT
     * already attached to this parent may never be newly attached, even
     * though an already-attached tombstone may be retained.
     */
    public function testChildrenRejectsNewlyProposedTombstonedChild(): void
    {
        $parent = $this->seedGroupedProduct('prodgchdret02');
        $child = $this->seedProduct('prodgchdretc2');
        self::assertTrue((new ProductRepository())->markDeleted($this->context, '', $child['uuid']));

        $response = $this->productController()->setChildren(
            new SetProductChildrenData(child_uuids: [$child['uuid']]),
            Request::create('/x', 'PUT'),
            $parent['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('child_uuids.0', $this->json($response)['error']['details']);
        self::assertSame(
            [],
            $this->connection->table('commerce_product_children')
                ->where('product_uuid', '=', $parent['uuid'])
                ->get()
        );
    }

    // -----------------------------------------------------------------
    // Fixtures / controller wiring
    // -----------------------------------------------------------------

    private function categoryController(): AdminCategoryController
    {
        return new AdminCategoryController(
            $this->context,
            new CategoryService(new CategoryRepository(), new ProductRepository(), new SentinelTenantResolver())
        );
    }

    private function tagController(): AdminTagController
    {
        return new AdminTagController(
            $this->context,
            new TagService(new TagRepository(), new ProductRepository(), new SentinelTenantResolver())
        );
    }

    private function attributeController(): AdminAttributeController
    {
        return new AdminAttributeController(
            $this->context,
            new AttributeService(new AttributeRepository(), new ProductRepository(), new SentinelTenantResolver())
        );
    }

    private function mediaController(): AdminMediaController
    {
        return new AdminMediaController(
            $this->context,
            new ProductMediaService(
                new ProductRepository(),
                new VariantRepository(),
                new ProductMediaRepository(),
                new SentinelTenantResolver()
            )
        );
    }

    private function productController(): AdminProductController
    {
        return new AdminProductController(
            $this->context,
            $this->catalogService(),
            new ProductRepository(),
            new VariantRepository(),
            new SentinelTenantResolver()
        );
    }

    private function catalogService(): CatalogService
    {
        return new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            new SentinelTenantResolver(),
            new StockRepository(),
            new ProductChildrenRepository()
        );
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
    private function seedGroupedProduct(string $uuid, string $tenant = ''): array
    {
        $this->connection->table('commerce_products')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => strtolower($uuid),
            'name' => $uuid,
            'type' => 'grouped',
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

    private function assignChild(string $productUuid, string $childUuid, int $position): void
    {
        $this->connection->table('commerce_product_children')->insert([
            'product_uuid' => $productUuid,
            'child_uuid' => $childUuid,
            'position' => $position,
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
