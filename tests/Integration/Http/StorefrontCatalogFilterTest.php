<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\AddonRepository;
use Glueful\Extensions\Commerce\Catalog\AttributeRepository;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\TagRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Http\DTOs\ProductListQuery;
use Glueful\Extensions\Commerce\Http\Storefront\CategoryController;
use Glueful\Extensions\Commerce\Http\Storefront\ProductController;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Commerce\Tests\Support\CountingPdoStatement;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Validation\RequestDataHydrator;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Layer 6 Task 5: the public category tree (strict recursive allowlist) and
 * the storefront product list's `category`/`tag`/`attributes` filters (batched
 * slug resolution + correlated `EXISTS` semijoins over the exact
 * `JsonStringArrayContainsSql` membership helper). See design spec Layer 6 §2
 * decision 6 and the plan's Global Constraints (storefront block).
 */
final class StorefrontCatalogFilterTest extends CommerceTestCase
{
    // === Category tree ==========================================================

    public function testCategoryTreeShapeIsExactAllowlistAtEveryLevel(): void
    {
        $this->seedCategory('catroot00001', 'root', 'Root', null, 'blobcatroot1');
        $this->seedCategory('catchild0001', 'child', 'Child', 'catroot00001');
        $this->seedCategory('catgrand0001', 'grand', 'Grand', 'catchild0001');

        $response = $this->categoryController()->index(Request::create('/x'));
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        $root = $body['data'][0];
        $this->assertExactAllowlist($root);
        self::assertSame('root', $root['slug']);
        self::assertSame('/blobs/blobcatroot1', $root['image_url']);

        $child = $root['children'][0];
        $this->assertExactAllowlist($child);
        self::assertSame('child', $child['slug']);

        $grand = $child['children'][0];
        $this->assertExactAllowlist($grand);
        self::assertSame('grand', $grand['slug']);
        self::assertSame([], $grand['children']);
    }

    public function testCategoryTreeImageUrlIsNullWhenNoBlobAttached(): void
    {
        $this->seedCategory('catnoblob001', 'no-image', 'No Image', null, null);

        $body = $this->json($this->categoryController()->index(Request::create('/x')));

        self::assertNull($body['data'][0]['image_url']);
    }

    public function testCategoryTreeIncludesAllTenantCategoriesIndependentOfProductAttachment(): void
    {
        // Categories are listed even with zero products attached -- they carry
        // no active/status column of their own.
        $this->seedCategory('catnoprod001', 'lonely', 'Lonely', null);

        $body = $this->json($this->categoryController()->index(Request::create('/x')));

        self::assertSame(['lonely'], array_column($body['data'], 'slug'));
    }

    public function testCategoryTreeExcludesOtherTenantsCategories(): void
    {
        $this->seedCategory('cattenanta01', 'mine', 'Mine', null, null, 'tenant-a');
        $this->seedCategory('cattenantb01', 'theirs', 'Theirs', null, null, 'tenant-b');

        $body = $this->json($this->categoryController('tenant-a')->index(Request::create('/x')));

        self::assertSame(['mine'], array_column($body['data'], 'slug'));
    }

    // === Product filters: category ==============================================

    public function testCategoryFilterReturnsOnlyProductsInThatCategory(): void
    {
        $this->seedCategory('catfilter001', 'shoes', 'Shoes');
        $inCategory = $this->seedProduct(['uuid' => 'prodcatinc01', 'slug' => 'in-cat', 'name' => 'In']);
        $outCategory = $this->seedProduct(['uuid' => 'prodcatout01', 'slug' => 'out-cat', 'name' => 'Out']);
        $this->attachCategory($inCategory, 'catfilter001');

        $response = $this->productController()->index($this->query(category: 'shoes'));
        $body = $this->json($response);

        self::assertSame(1, $body['total']);
        self::assertSame([$inCategory], array_column($body['data'], 'uuid'));
        self::assertNotContains($outCategory, array_column($body['data'], 'uuid'));
    }

    public function testUnknownCategorySlugReturnsEmptyPageNotError(): void
    {
        $this->seedProduct(['uuid' => 'prodcatunk01', 'slug' => 'unk-cat', 'name' => 'Unk']);

        $response = $this->productController()->index($this->query(category: 'does-not-exist'));
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $body['total']);
        self::assertSame([], $body['data']);
    }

    public function testUnknownCategorySlugNeverReachesTheProductQuery(): void
    {
        $this->seedProduct(['uuid' => 'prodcatskip1', 'slug' => 'skip-cat', 'name' => 'Skip']);

        // Warm-up: the framework's SoftDeleteHandler runs a one-time,
        // process-cached schema probe (PRAGMA table_info) the first time any
        // query touches `commerce_categories` -- run once, unmeasured.
        $this->productController()->index($this->query(category: 'warm-up'));

        $pdo = $this->connection->getPDO();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [CountingPdoStatement::class]);
        CountingPdoStatement::$count = 0;

        $this->productController()->index($this->query(category: 'nope'));

        // Exactly one statement: the category slug lookup. Zero product
        // queries (no count query, no row query) -- the short-circuit happens
        // BEFORE the product query, not after an empty result comes back.
        self::assertSame(1, CountingPdoStatement::$count);
    }

    // === Product filters: tag ====================================================

    public function testTagFilterReturnsOnlyProductsWithThatTag(): void
    {
        $this->seedTag('tagfilter001', 'sale', 'Sale');
        $tagged = $this->seedProduct(['uuid' => 'prodtagyes01', 'slug' => 'tag-yes', 'name' => 'Yes']);
        $untagged = $this->seedProduct(['uuid' => 'prodtagno001', 'slug' => 'tag-no', 'name' => 'No']);
        $this->attachTag($tagged, 'tagfilter001');

        $body = $this->json($this->productController()->index($this->query(tag: 'sale')));

        self::assertSame(1, $body['total']);
        self::assertSame([$tagged], array_column($body['data'], 'uuid'));
        self::assertNotContains($untagged, array_column($body['data'], 'uuid'));
    }

    public function testUnknownTagSlugReturnsEmptyPageNotError(): void
    {
        $this->seedProduct(['uuid' => 'prodtagunk01', 'slug' => 'unk-tag', 'name' => 'Unk']);

        $body = $this->json($this->productController()->index($this->query(tag: 'does-not-exist')));

        self::assertSame(0, $body['total']);
        self::assertSame([], $body['data']);
    }

    // === Product filters: attributes =============================================

    public function testAttributeFilterReturnsOnlyProductsWithThatValue(): void
    {
        $attr = $this->seedAttribute('attrcolor001', 'color', 'Color');
        $this->seedAttributeValue($attr, 'valred000001', 'red', 'Red');
        $this->seedAttributeValue($attr, 'valblue00001', 'blue', 'Blue');

        $red = $this->seedProduct(['uuid' => 'prodattred01', 'slug' => 'attr-red', 'name' => 'Red']);
        $blue = $this->seedProduct(['uuid' => 'prodattblu01', 'slug' => 'attr-blue', 'name' => 'Blue']);
        $this->attachProductAttribute('pa-red-0001', $red, $attr, ['red']);
        $this->attachProductAttribute('pa-blue-001', $blue, $attr, ['blue']);

        $body = $this->json($this->productController()->index($this->query(attributes: 'color:red')));

        self::assertSame(1, $body['total']);
        self::assertSame([$red], array_column($body['data'], 'uuid'));
        self::assertNotContains($blue, array_column($body['data'], 'uuid'));
    }

    public function testAttributeValueMembershipIsExactRedDoesNotMatchBred(): void
    {
        $attr = $this->seedAttribute('attrcolor002', 'shade', 'Shade');
        $this->seedAttributeValue($attr, 'valred000002', 'red', 'Red');
        $this->seedAttributeValue($attr, 'valbred00001', 'bred', 'Bred');

        $bredProduct = $this->seedProduct(['uuid' => 'prodattbred1', 'slug' => 'attr-bred', 'name' => 'Bred']);
        $this->attachProductAttribute('pa-bred-0001', $bredProduct, $attr, ['bred']);

        $body = $this->json($this->productController()->index($this->query(attributes: 'shade:red')));

        self::assertSame(0, $body['total'], 'a stored "bred" value must never match a "red" filter');
        self::assertSame([], $body['data']);
    }

    public function testUnknownAttributePairReturnsEmptyPageNotError(): void
    {
        $this->seedProduct(['uuid' => 'prodattunk01', 'slug' => 'unk-attr', 'name' => 'Unk']);

        $body = $this->json(
            $this->productController()->index($this->query(attributes: 'no-such-attr:no-such-value'))
        );

        self::assertSame(0, $body['total']);
        self::assertSame([], $body['data']);
    }

    public function testUnknownValueSlugForAKnownAttributeReturnsEmptyPageNotError(): void
    {
        $attr = $this->seedAttribute('attrcolor003', 'finish', 'Finish');
        $this->seedAttributeValue($attr, 'valmatte0001', 'matte', 'Matte');
        $product = $this->seedProduct(['uuid' => 'prodattmat01', 'slug' => 'attr-matte', 'name' => 'Matte']);
        $this->attachProductAttribute('pa-matte-001', $product, $attr, ['matte']);

        $body = $this->json(
            $this->productController()->index($this->query(attributes: 'finish:glossy'))
        );

        self::assertSame(0, $body['total']);
    }

    public function testAttributesOverCapRejectsAtTheHydrationBoundary(): void
    {
        $pairs = 'a:1,b:2,c:3,d:4,e:5,f:6';

        try {
            (new RequestDataHydrator())->hydrate(ProductListQuery::class, [], [], ['attributes' => $pairs]);
            self::fail('expected ValidationException for more than 5 attribute pairs');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('attributes', $e->errors());
        }
    }

    public function testExactlyFiveAttributePairsIsAcceptedAtTheHydrationBoundary(): void
    {
        $query = (new RequestDataHydrator())->hydrate(
            ProductListQuery::class,
            [],
            [],
            ['attributes' => 'a:1,b:2,c:3,d:4,e:5']
        );

        self::assertInstanceOf(ProductListQuery::class, $query);
    }

    // === Combined AND semantics + duplicate-producing fixtures ===================

    public function testCombinedFiltersUseAndSemantics(): void
    {
        $this->seedCategory('catcombo0001', 'combo-cat', 'Combo Cat');
        $this->seedTag('tagcombo0001', 'combo-tag', 'Combo Tag');
        $attr = $this->seedAttribute('attrcombo001', 'combo-attr', 'Combo Attr');
        $this->seedAttributeValue($attr, 'valcombo0001', 'combo-val', 'Combo Val');

        $matchesAll = $this->seedProduct(['uuid' => 'prodcomboall', 'slug' => 'combo-all', 'name' => 'All']);
        $this->attachCategory($matchesAll, 'catcombo0001');
        $this->attachTag($matchesAll, 'tagcombo0001');
        $this->attachProductAttribute('pa-combo-all', $matchesAll, $attr, ['combo-val']);

        // Matches category + tag but NOT the attribute -- must be excluded.
        $partial = $this->seedProduct(['uuid' => 'prodcombopar', 'slug' => 'combo-partial', 'name' => 'Partial']);
        $this->attachCategory($partial, 'catcombo0001');
        $this->attachTag($partial, 'tagcombo0001');

        $body = $this->json($this->productController()->index($this->query(
            category: 'combo-cat',
            tag: 'combo-tag',
            attributes: 'combo-attr:combo-val'
        )));

        self::assertSame(1, $body['total']);
        self::assertSame([$matchesAll], array_column($body['data'], 'uuid'));
    }

    public function testDuplicateProducingFixturesKeepExactTotalsAndPages(): void
    {
        $this->seedCategory('catdupe00001', 'dupe-cat', 'Dupe Cat');
        $this->seedCategory('catdupe00002', 'dupe-extra', 'Dupe Extra');
        $this->seedTag('tagdupe00001', 'dupe-tag', 'Dupe Tag');
        $this->seedTag('tagdupe00002', 'dupe-extra-tag', 'Dupe Extra Tag');
        $attr = $this->seedAttribute('attrdupe0001', 'dupe-attr', 'Dupe Attr');
        $this->seedAttributeValue($attr, 'valdupe00001', 'dupe-val', 'Dupe Val');
        $this->seedAttributeValue($attr, 'valdupe00002', 'dupe-extra-val', 'Dupe Extra Val');

        $product = $this->seedProduct(['uuid' => 'proddupe0001', 'slug' => 'dupe-product', 'name' => 'Dupe']);
        // Attached to TWO categories, TWO tags, and an attribute row carrying
        // TWO value slugs -- a naive JOIN-based filter would multiply this
        // single product into several result rows; the correlated EXISTS
        // semijoin must still count it exactly once.
        $this->attachCategory($product, 'catdupe00001');
        $this->attachCategory($product, 'catdupe00002');
        $this->attachTag($product, 'tagdupe00001');
        $this->attachTag($product, 'tagdupe00002');
        $this->attachProductAttribute('pa-dupe-0001', $product, $attr, ['dupe-val', 'dupe-extra-val']);

        $body = $this->json($this->productController()->index($this->query(
            category: 'dupe-cat',
            tag: 'dupe-tag',
            attributes: 'dupe-attr:dupe-val'
        )));

        self::assertSame(1, $body['total']);
        self::assertCount(1, $body['data']);
        self::assertSame(1, $body['total_pages']);
    }

    // === Ordering + lifecycle ====================================================

    public function testOrderIsCreatedAtDescendingWithUuidAscendingTieBreak(): void
    {
        $this->seedProduct([
            'uuid' => 'prodtieb0002', 'slug' => 'tie-b', 'name' => 'Tie B', 'created_at' => '2026-01-01 00:00:00',
        ]);
        $this->seedProduct([
            'uuid' => 'prodtiea0001', 'slug' => 'tie-a', 'name' => 'Tie A', 'created_at' => '2026-01-01 00:00:00',
        ]);
        $this->seedProduct([
            'uuid' => 'prodtiec0003', 'slug' => 'tie-c', 'name' => 'Tie C', 'created_at' => '2026-02-01 00:00:00',
        ]);

        $body = $this->json($this->productController()->index($this->query()));

        self::assertSame(
            ['prodtiec0003', 'prodtiea0001', 'prodtieb0002'],
            array_column($body['data'], 'uuid')
        );
    }

    public function testDraftAndTombstonedProductsAreExcludedEvenWhenMatchingFilters(): void
    {
        $this->seedCategory('catlivefil01', 'live-only', 'Live Only');

        $active = $this->seedProduct(['uuid' => 'prodliveact1', 'slug' => 'live-active', 'name' => 'Active']);
        $draft = $this->seedProduct([
            'uuid' => 'prodlivedra1', 'slug' => 'live-draft', 'name' => 'Draft', 'status' => 'draft',
        ]);
        $tombstoned = $this->seedProduct(['uuid' => 'prodlivetom1', 'slug' => 'live-tomb', 'name' => 'Tomb']);
        $this->connection->table('commerce_products')
            ->where('uuid', '=', $tombstoned)
            ->update(['deleted_at' => '2026-01-01 00:00:00']);

        foreach ([$active, $draft, $tombstoned] as $uuid) {
            $this->attachCategory($uuid, 'catlivefil01');
        }

        $body = $this->json($this->productController()->index($this->query(category: 'live-only')));

        self::assertSame([$active], array_column($body['data'], 'uuid'));
    }

    // === Query-count guard (batched resolution) ==================================

    public function testAttributePairResolutionIsOneQueryRegardlessOfPairCount(): void
    {
        $pairs = [];
        for ($i = 1; $i <= 5; $i++) {
            $attrUuid = 'attrqc00000' . $i;
            $this->seedAttribute($attrUuid, 'qcattr' . $i, 'QC Attr ' . $i);
            $this->seedAttributeValue($attrUuid, 'valqc0000' . $i, 'qcval' . $i, 'QC Val ' . $i);
            $pairs[] = ['attribute_slug' => 'qcattr' . $i, 'value_slug' => 'qcval' . $i];
        }

        $repository = new AttributeRepository();

        // Warm-up: the framework's SoftDeleteHandler runs a one-time,
        // process-cached schema probe (PRAGMA table_info) the first time any
        // query touches a given table -- run once, unmeasured, so it never
        // contaminates the measured count below.
        $repository->findPairsBySlugs($this->context, '', $pairs);

        $pdo = $this->connection->getPDO();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [CountingPdoStatement::class]);
        CountingPdoStatement::$count = 0;

        $resolved = $repository->findPairsBySlugs($this->context, '', $pairs);

        self::assertSame(1, CountingPdoStatement::$count, 'expected exactly one batched query for 5 pairs');
        self::assertCount(5, $resolved);
    }

    public function testControllerFilterResolutionQueryCountDoesNotGrowWithAttributePairCount(): void
    {
        $this->seedCategory('catqc0000001', 'qc-cat', 'QC Cat');
        $this->seedTag('tagqc0000001', 'qc-tag', 'QC Tag');

        $pairs = [];
        for ($i = 1; $i <= 5; $i++) {
            $attrUuid = 'attrqcc0000' . $i;
            $this->seedAttribute($attrUuid, 'qccattr' . $i, 'QCC Attr ' . $i);
            $this->seedAttributeValue($attrUuid, 'valqcc000' . $i, 'qccval' . $i, 'QCC Val ' . $i);
            $pairs[] = 'qccattr' . $i . ':qccval' . $i;
        }
        $fivePairs = implode(',', $pairs);
        $onePair = $pairs[0];

        // Warm-up call against every table this query touches (category, tag,
        // attribute/value, product) so the SoftDeleteHandler's one-time schema
        // probes never leak into either measured count below.
        $this->productController()->index($this->query(category: 'qc-cat', tag: 'qc-tag', attributes: $onePair));

        $pdo = $this->connection->getPDO();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [CountingPdoStatement::class]);

        CountingPdoStatement::$count = 0;
        $this->productController()->index($this->query(category: 'qc-cat', tag: 'qc-tag', attributes: $onePair));
        $withOnePair = CountingPdoStatement::$count;

        CountingPdoStatement::$count = 0;
        $this->productController()->index($this->query(category: 'qc-cat', tag: 'qc-tag', attributes: $fivePairs));
        $withFivePairs = CountingPdoStatement::$count;

        self::assertSame(
            $withOnePair,
            $withFivePairs,
            'resolving 5 attribute pairs must not issue more queries than resolving 1'
        );
    }

    // === Fixtures + helpers ======================================================

    /** @param array<string,mixed> $keys */
    private function assertExactAllowlist(array $node): void
    {
        $keys = array_keys($node);
        sort($keys);
        self::assertSame(['children', 'description', 'image_url', 'name', 'position', 'slug'], $keys);
        self::assertArrayNotHasKey('uuid', $node);
        self::assertArrayNotHasKey('tenant_uuid', $node);
        self::assertArrayNotHasKey('parent_uuid', $node);
        self::assertArrayNotHasKey('blob_uuid', $node);
        self::assertArrayNotHasKey('revision', $node);
    }

    private function query(
        ?string $category = null,
        ?string $tag = null,
        ?string $attributes = null
    ): ProductListQuery {
        return new ProductListQuery(category: $category, tag: $tag, attributes: $attributes);
    }

    /** @param array<string,mixed> $overrides */
    private function seedProduct(array $overrides, string $tenant = ''): string
    {
        $uuid = (string) $overrides['uuid'];
        $this->connection->table('commerce_products')->insert(array_merge([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => 'slug-' . $uuid,
            'name' => 'Product',
            'type' => 'physical',
            'status' => 'active',
        ], $overrides, ['uuid' => $uuid, 'tenant_uuid' => $tenant]));

        return $uuid;
    }

    private function seedCategory(
        string $uuid,
        string $slug,
        string $name,
        ?string $parentUuid = null,
        ?string $blobUuid = null,
        string $tenant = ''
    ): string {
        $this->connection->table('commerce_categories')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'parent_uuid' => $parentUuid,
            'slug' => $slug,
            'name' => $name,
            'position' => 0,
            'blob_uuid' => $blobUuid,
        ]);

        return $uuid;
    }

    private function seedTag(string $uuid, string $slug, string $name, string $tenant = ''): string
    {
        $this->connection->table('commerce_tags')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => $slug,
            'name' => $name,
        ]);

        return $uuid;
    }

    private function seedAttribute(string $uuid, string $slug, string $name, string $tenant = ''): string
    {
        $this->connection->table('commerce_attributes')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => $slug,
            'name' => $name,
        ]);

        return $uuid;
    }

    private function seedAttributeValue(string $attributeUuid, string $uuid, string $slug, string $value): void
    {
        $this->connection->table('commerce_attribute_values')->insert([
            'uuid' => $uuid,
            'attribute_uuid' => $attributeUuid,
            'slug' => $slug,
            'value' => $value,
        ]);
    }

    private function attachCategory(string $productUuid, string $categoryUuid): void
    {
        $this->connection->table('commerce_product_categories')->insert([
            'product_uuid' => $productUuid,
            'category_uuid' => $categoryUuid,
        ]);
    }

    private function attachTag(string $productUuid, string $tagUuid): void
    {
        $this->connection->table('commerce_product_tags')->insert([
            'product_uuid' => $productUuid,
            'tag_uuid' => $tagUuid,
        ]);
    }

    /** @param list<string> $valueSlugs */
    private function attachProductAttribute(
        string $uuid,
        string $productUuid,
        ?string $attributeUuid,
        array $valueSlugs
    ): void {
        $this->connection->table('commerce_product_attributes')->insert([
            'uuid' => $uuid,
            'product_uuid' => $productUuid,
            'attribute_uuid' => $attributeUuid,
            'name' => $attributeUuid === null ? 'Custom' : null,
            'values' => json_encode($valueSlugs, JSON_THROW_ON_ERROR),
            'used_for_variants' => false,
            'visible' => true,
        ]);
    }

    private function productController(string $tenant = ''): ProductController
    {
        $tenants = $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant);

        return new ProductController(
            $this->context,
            new ProductRepository(),
            new VariantRepository(),
            $tenants,
            new ProductMediaRepository(),
            new CategoryRepository(),
            new TagRepository(),
            new AttributeRepository(),
            new ProductChildrenRepository(),
            new AddonRepository()
        );
    }

    private function categoryController(string $tenant = ''): CategoryController
    {
        $tenants = $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant);

        return new CategoryController($this->context, new CategoryRepository(), $tenants);
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
