<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\AttributeRepository;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\CategoryService;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\TagRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Http\Admin\AdminCategoryController;
use Glueful\Extensions\Commerce\Http\DTOs\CreateCategoryData;
use Glueful\Extensions\Commerce\Http\DTOs\SetProductCategoriesData;
use Glueful\Extensions\Commerce\Http\Storefront\ProductController;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Repository\BlobRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class CategoryEndpointTest extends CommerceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Category images reuse the framework's blobs table, exactly like media.
        require_once __DIR__ . '/../../../vendor/glueful/framework/migrations/uploads/001_CreateBlobsTable.php';
        (new \Glueful\Migrations\Uploads\CreateBlobsTable())->up($this->connection->getSchemaBuilder());
    }

    public function testCreateRootCategoryHappyPath(): void
    {
        $response = $this->controller()->store(
            new CreateCategoryData(slug: 'shoes', name: 'Shoes'),
            Request::create('/x', 'POST')
        );

        self::assertSame(201, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame('shoes', $data['slug']);
        self::assertSame('Shoes', $data['name']);
        self::assertNull($data['parent_uuid']);
        self::assertSame(0, (int) $data['revision']);
    }

    public function testCreateChildCategoryComputesParentRelationship(): void
    {
        $parent = $this->createCategory('apparel', 'Apparel');

        $response = $this->controller()->store(
            new CreateCategoryData(slug: 'shoes', name: 'Shoes', parent_uuid: $parent['uuid']),
            Request::create('/x', 'POST')
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertSame($parent['uuid'], $this->json($response)['data']['parent_uuid']);

        // create-with-parent claims the parent (a child-attach event) -- its
        // revision moved.
        $reloaded = (new CategoryRepository())->findByUuid($this->context, '', $parent['uuid']);
        self::assertSame(1, (int) $reloaded['revision']);
    }

    /**
     * The depth-<=6 invariant depends on the parent's FULL ancestor chain, not
     * just the parent row -- so create-with-parent must claim (revision-bump)
     * every one of the parent's ancestors, not only the parent itself. This is
     * the observable proof that the claim set includes the whole chain.
     */
    public function testCreateWithParentBumpsRevisionOfEveryAncestorOfParent(): void
    {
        $grandparent = $this->createCategory('electronics', 'Electronics');
        $parent = $this->createCategory('phones', 'Phones', '', $grandparent['uuid']);

        // Parent's own create-with-parent claimed grandparent once already.
        $categories = new CategoryRepository();
        self::assertSame(1, (int) $categories->findByUuid($this->context, '', $grandparent['uuid'])['revision']);

        $response = $this->controller()->store(
            new CreateCategoryData(slug: 'cases', name: 'Cases', parent_uuid: $parent['uuid']),
            Request::create('/x', 'POST')
        );
        self::assertSame(201, $response->getStatusCode());

        // Creating under "parent" claims parent AND its ancestor (grandparent).
        $reloadedParent = $categories->findByUuid($this->context, '', $parent['uuid']);
        $reloadedGrandparent = $categories->findByUuid($this->context, '', $grandparent['uuid']);
        self::assertSame(1, (int) $reloadedParent['revision']);
        self::assertSame(2, (int) $reloadedGrandparent['revision']);
    }

    public function testCreateWithUnknownParentReturns422(): void
    {
        $response = $this->controller()->store(
            new CreateCategoryData(slug: 'shoes', name: 'Shoes', parent_uuid: 'no-such-parent'),
            Request::create('/x', 'POST')
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('parent_uuid', $this->json($response)['error']['details']);
    }

    public function testCreateWithCrossTenantParentReturns422NonRevealing(): void
    {
        $otherTenantParent = $this->createCategory('apparel', 'Apparel', 'tenant-b');

        $response = $this->controller()->store(
            new CreateCategoryData(slug: 'shoes', name: 'Shoes', parent_uuid: $otherTenantParent['uuid']),
            Request::create('/x', 'POST')
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('parent_uuid', $this->json($response)['error']['details']);
    }

    public function testCreateDuplicateSlugSameTenantReturns422(): void
    {
        $this->createCategory('shoes', 'Shoes');

        $response = $this->controller()->store(
            new CreateCategoryData(slug: 'shoes', name: 'Shoes Duplicate'),
            Request::create('/x', 'POST')
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('slug', $this->json($response)['error']['details']);
    }

    public function testCreateSameSlugDifferentTenantSucceeds(): void
    {
        $this->createCategory('shoes', 'Shoes', 'tenant-b');

        $response = $this->controller()->store(
            new CreateCategoryData(slug: 'shoes', name: 'Shoes'),
            Request::create('/x', 'POST')
        );

        self::assertSame(201, $response->getStatusCode());
    }

    public function testCreateAtMaxDepthSucceedsButExceedingIsRejected(): void
    {
        // Build a chain of depth 1..6 (six categories deep).
        $parentUuid = null;
        for ($level = 1; $level <= 6; $level++) {
            $category = $this->createCategory("level{$level}", "Level {$level}", '', $parentUuid);
            $parentUuid = $category['uuid'];
        }

        // A 7th level would be depth 7 -- must be rejected.
        $response = $this->controller()->store(
            new CreateCategoryData(slug: 'level7', name: 'Level 7', parent_uuid: $parentUuid),
            Request::create('/x', 'POST')
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('parent_uuid', $this->json($response)['error']['details']);
    }

    public function testUpdateRenamesFields(): void
    {
        $category = $this->createCategory('shoes', 'Shoes');

        $request = Request::create(
            '/x',
            'PATCH',
            [],
            [],
            [],
            [],
            json_encode(['name' => 'Footwear', 'description' => 'All shoes'], JSON_THROW_ON_ERROR)
        );
        $request->headers->set('Content-Type', 'application/json');

        $response = $this->controller()->update($request, $category['uuid']);

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame('Footwear', $data['name']);
        self::assertSame('All shoes', $data['description']);
        self::assertSame(1, (int) $data['revision']);
    }

    public function testUpdateSlugConflictReturns422(): void
    {
        $this->createCategory('shoes', 'Shoes');
        $other = $this->createCategory('boots', 'Boots');

        $response = $this->controller()->update(
            $this->patchRequest(['slug' => 'shoes']),
            $other['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('slug', $this->json($response)['error']['details']);
    }

    public function testUpdateUnknownCategoryThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->update($this->patchRequest(['name' => 'x']), 'no-such-category');
    }

    public function testUpdateCrossTenantCategoryThrowsNotFound(): void
    {
        $category = $this->createCategory('shoes', 'Shoes', 'tenant-b');

        $this->expectException(NotFoundException::class);
        $this->controller()->update($this->patchRequest(['name' => 'x']), $category['uuid']);
    }

    public function testUpdateReparentToRootSucceeds(): void
    {
        $parent = $this->createCategory('apparel', 'Apparel');
        $child = $this->createCategory('shoes', 'Shoes', '', $parent['uuid']);

        $response = $this->controller()->update(
            $this->patchRequest(['parent_uuid' => null]),
            $child['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($this->json($response)['data']['parent_uuid']);
    }

    public function testUpdateReparentSelfParentReturns422Cycle(): void
    {
        $category = $this->createCategory('shoes', 'Shoes');

        $response = $this->controller()->update(
            $this->patchRequest(['parent_uuid' => $category['uuid']]),
            $category['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('parent_uuid', $this->json($response)['error']['details']);
    }

    /**
     * Same proof as testCreateWithParentBumpsRevisionOfEveryAncestorOfParent, for
     * reparenting: the claim set must include every ancestor of the NEW parent,
     * not just the new parent row.
     */
    public function testUpdateReparentBumpsRevisionOfEveryAncestorOfNewParent(): void
    {
        $grandparent = $this->createCategory('electronics', 'Electronics');
        $parent = $this->createCategory('phones', 'Phones', '', $grandparent['uuid']);
        $leaf = $this->createCategory('accessories', 'Accessories');

        $categories = new CategoryRepository();
        self::assertSame(1, (int) $categories->findByUuid($this->context, '', $grandparent['uuid'])['revision']);

        $response = $this->controller()->update(
            $this->patchRequest(['parent_uuid' => $parent['uuid']]),
            $leaf['uuid']
        );
        self::assertSame(200, $response->getStatusCode());

        $reloadedLeaf = $categories->findByUuid($this->context, '', $leaf['uuid']);
        $reloadedParent = $categories->findByUuid($this->context, '', $parent['uuid']);
        $reloadedGrandparent = $categories->findByUuid($this->context, '', $grandparent['uuid']);

        self::assertSame($parent['uuid'], $reloadedLeaf['parent_uuid']);
        self::assertSame(1, (int) $reloadedLeaf['revision']);
        // parent claimed as the new-parent itself.
        self::assertSame(1, (int) $reloadedParent['revision']);
        // grandparent claimed as an ancestor of the new parent -- this is the bug fix.
        self::assertSame(2, (int) $reloadedGrandparent['revision']);
    }

    public function testUpdateReparentToOwnDescendantReturns422Cycle(): void
    {
        $root = $this->createCategory('apparel', 'Apparel');
        $child = $this->createCategory('shoes', 'Shoes', '', $root['uuid']);
        $grandchild = $this->createCategory('boots', 'Boots', '', $child['uuid']);

        // Moving root under its own grandchild must be rejected as a cycle.
        $response = $this->controller()->update(
            $this->patchRequest(['parent_uuid' => $grandchild['uuid']]),
            $root['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('parent_uuid', $this->json($response)['error']['details']);
    }

    public function testUpdateReparentToUnknownParentReturns422(): void
    {
        $category = $this->createCategory('shoes', 'Shoes');

        $response = $this->controller()->update(
            $this->patchRequest(['parent_uuid' => 'no-such-parent']),
            $category['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('parent_uuid', $this->json($response)['error']['details']);
    }

    public function testUpdateReparentRejectsWhenSubtreeWouldExceedMaxDepth(): void
    {
        // A 6-level-deep chain, chain[0] is the root and chain[5] is at depth 6.
        $chain = [];
        $parentUuid = null;
        for ($level = 1; $level <= 6; $level++) {
            $category = $this->createCategory("chain{$level}", "Chain {$level}", '', $parentUuid);
            $chain[] = $category;
            $parentUuid = $category['uuid'];
        }

        // A separate root with one child (subtree height 1).
        $otherRoot = $this->createCategory('other-root', 'Other Root');
        $this->createCategory('other-child', 'Other Child', '', $otherRoot['uuid']);

        // Reparenting other-root under chain5 (depth 5) would push other-child to
        // depth 7 -- must be rejected.
        $response = $this->controller()->update(
            $this->patchRequest(['parent_uuid' => $chain[4]['uuid']]),
            $otherRoot['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('parent_uuid', $this->json($response)['error']['details']);

        // But reparenting under chain4 (depth 4) keeps other-child at depth 6 -- allowed.
        $ok = $this->controller()->update(
            $this->patchRequest(['parent_uuid' => $chain[3]['uuid']]),
            $otherRoot['uuid']
        );
        self::assertSame(200, $ok->getStatusCode());
    }

    public function testDeleteReparentsChildrenAndDetachesProductsThenDeletesCategory(): void
    {
        $grandparent = $this->createCategory('apparel', 'Apparel');
        $target = $this->createCategory('shoes', 'Shoes', '', $grandparent['uuid']);
        $child = $this->createCategory('boots', 'Boots', '', $target['uuid']);

        $product = $this->seedProduct('prodcat00001');
        $this->assignCategory($product['uuid'], $target['uuid']);

        $response = $this->controller()->destroy(Request::create('/x', 'DELETE'), $target['uuid']);

        self::assertSame(HttpResponse::HTTP_NO_CONTENT, $response->getStatusCode());

        $categories = new CategoryRepository();
        self::assertNull($categories->findByUuid($this->context, '', $target['uuid']));

        $reloadedChild = $categories->findByUuid($this->context, '', $child['uuid']);
        self::assertSame($grandparent['uuid'], $reloadedChild['parent_uuid']);

        self::assertSame([], $categories->categoryUuidsForProduct($this->context, $product['uuid']));
    }

    public function testDeleteReparentsChildrenToRootWhenTargetHasNoParent(): void
    {
        $target = $this->createCategory('shoes', 'Shoes');
        $child = $this->createCategory('boots', 'Boots', '', $target['uuid']);

        $this->controller()->destroy(Request::create('/x', 'DELETE'), $target['uuid']);

        $reloadedChild = (new CategoryRepository())->findByUuid($this->context, '', $child['uuid']);
        self::assertNull($reloadedChild['parent_uuid']);
    }

    public function testDeleteUnknownCategoryThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->destroy(Request::create('/x', 'DELETE'), 'no-such-category');
    }

    public function testDeleteCrossTenantCategoryThrowsNotFound(): void
    {
        $category = $this->createCategory('shoes', 'Shoes', 'tenant-b');

        $this->expectException(NotFoundException::class);
        $this->controller()->destroy(Request::create('/x', 'DELETE'), $category['uuid']);
    }

    public function testSetProductCategoriesAttachesAndIsIdempotent(): void
    {
        $product = $this->seedProduct('prodcat00002');
        $a = $this->createCategory('shoes', 'Shoes');
        $b = $this->createCategory('boots', 'Boots');

        $first = $this->controller()->setForProduct(
            new SetProductCategoriesData(category_uuids: [$a['uuid'], $b['uuid']]),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );
        self::assertSame(200, $first->getStatusCode());
        self::assertCount(2, $this->json($first)['data']);

        $before = $this->connection->table('commerce_product_categories')
            ->where('product_uuid', '=', $product['uuid'])
            ->get();

        // Same list again: idempotent, no churn (row set is exactly the same).
        $second = $this->controller()->setForProduct(
            new SetProductCategoriesData(category_uuids: [$a['uuid'], $b['uuid']]),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );
        self::assertSame(200, $second->getStatusCode());

        $after = $this->connection->table('commerce_product_categories')
            ->where('product_uuid', '=', $product['uuid'])
            ->get();
        self::assertSame($before, $after);
    }

    public function testSetProductCategoriesRemovesUnlistedCategories(): void
    {
        $product = $this->seedProduct('prodcat00003');
        $a = $this->createCategory('shoes', 'Shoes');
        $b = $this->createCategory('boots', 'Boots');

        $this->controller()->setForProduct(
            new SetProductCategoriesData(category_uuids: [$a['uuid'], $b['uuid']]),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        $response = $this->controller()->setForProduct(
            new SetProductCategoriesData(category_uuids: [$a['uuid']]),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        $uuids = array_column($this->json($response)['data'], 'uuid');
        self::assertSame([$a['uuid']], $uuids);
    }

    public function testSetProductCategoriesEmptyListClearsAll(): void
    {
        $product = $this->seedProduct('prodcat00004');
        $a = $this->createCategory('shoes', 'Shoes');

        $this->controller()->setForProduct(
            new SetProductCategoriesData(category_uuids: [$a['uuid']]),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        $response = $this->controller()->setForProduct(
            new SetProductCategoriesData(category_uuids: []),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->json($response)['data']);
    }

    public function testSetProductCategoriesUnknownCategoryReturns422(): void
    {
        $product = $this->seedProduct('prodcat00005');

        $response = $this->controller()->setForProduct(
            new SetProductCategoriesData(category_uuids: ['no-such-category']),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('category_uuids', $this->json($response)['error']['details']);
    }

    public function testSetProductCategoriesUnknownProductThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->setForProduct(
            new SetProductCategoriesData(category_uuids: []),
            Request::create('/x', 'PUT'),
            'no-such-product'
        );
    }

    public function testIndexListsAllCategoriesForTenant(): void
    {
        $this->createCategory('shoes', 'Shoes');
        $this->createCategory('boots', 'Boots');
        $this->createCategory('other-tenant-cat', 'Other', 'tenant-b');

        $response = $this->controller()->index(Request::create('/x'));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $this->json($response)['data']);
    }

    public function testStorefrontShowEchoesCategoriesAsSlugAndName(): void
    {
        $product = $this->seedProduct('prodcatstore1');
        $a = $this->createCategory('shoes', 'Shoes');
        $b = $this->createCategory('boots', 'Boots');
        $this->assignCategory($product['uuid'], $a['uuid']);
        $this->assignCategory($product['uuid'], $b['uuid']);

        $response = $this->productController()->show(Request::create('/x'), $product['uuid']);

        self::assertSame(200, $response->getStatusCode());
        $categories = $this->json($response)['data']['categories'];
        self::assertCount(2, $categories);
        $slugs = array_column($categories, 'slug');
        sort($slugs);
        self::assertSame(['boots', 'shoes'], $slugs);
        self::assertArrayHasKey('name', $categories[0]);
        self::assertArrayNotHasKey('uuid', $categories[0]);
    }

    /** @return array<string,mixed> */
    private function createCategory(
        string $slug,
        string $name,
        string $tenant = '',
        ?string $parentUuid = null
    ): array {
        $response = $this->controller($tenant)->store(
            new CreateCategoryData(slug: $slug, name: $name, parent_uuid: $parentUuid),
            Request::create('/x', 'POST')
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        return $this->json($response)['data'];
    }

    private function assignCategory(string $productUuid, string $categoryUuid): void
    {
        $this->connection->table('commerce_product_categories')->insert([
            'product_uuid' => $productUuid,
            'category_uuid' => $categoryUuid,
        ]);
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

        $product = (new ProductRepository())->findByUuid($this->context, $tenant, $uuid);
        self::assertNotNull($product);

        return $product;
    }

    /** @param array<string,mixed> $body */
    private function patchRequest(array $body): Request
    {
        $request = Request::create('/x', 'PATCH', [], [], [], [], json_encode($body, JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');

        return $request;
    }

    private function controller(string $tenant = ''): AdminCategoryController
    {
        return new AdminCategoryController($this->context, $this->categoryService($tenant));
    }

    private function categoryService(string $tenant = '', bool $withBlobs = true): CategoryService
    {
        return new CategoryService(
            new CategoryRepository(),
            new ProductRepository(),
            $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant),
            $withBlobs ? new BlobRepository($this->connection) : null
        );
    }

    private function productController(): ProductController
    {
        return new ProductController(
            $this->context,
            new ProductRepository(),
            new VariantRepository(),
            new SentinelTenantResolver(),
            new ProductMediaRepository(),
            new CategoryRepository(),
            new TagRepository(),
            new AttributeRepository(),
            new ProductChildrenRepository()
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
    private function json(HttpResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
