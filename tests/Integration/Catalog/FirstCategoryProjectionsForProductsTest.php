<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Catalog;

use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Commerce\Tests\Support\CountingPdoStatement;

/**
 * Storefront-v1 Task 1: {@see CategoryRepository::firstCategoryProjectionsForProducts()}
 * -- ONE batched join read returning each product's FIRST directly-assigned
 * category as a `{name, slug}` projection. "First" is pinned as
 * `position ASC, name ASC, uuid ASC` over the tenant's category rows (the
 * per-product reduction happens in PHP off that ordered read). Input passes
 * through the shared pinned uuid normalizer; empty normalized set issues
 * zero queries; a product with no direct assignment is simply absent.
 */
final class FirstCategoryProjectionsForProductsTest extends CommerceTestCase
{
    private const TENANT = 'tenantFCP001';
    private const OTHER_TENANT = 'tenantFCP002';

    private CategoryRepository $categories;

    protected function setUp(): void
    {
        parent::setUp();
        $this->categories = new CategoryRepository();
    }

    // === Empty / malformed input: zero queries ==================================

    public function testEmptyAndFullyMalformedInputIssueNoQuery(): void
    {
        $pdo = $this->connection->getPDO();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [CountingPdoStatement::class]);
        CountingPdoStatement::$count = 0;

        self::assertSame(
            [],
            $this->categories->firstCategoryProjectionsForProducts($this->context, self::TENANT, [])
        );
        self::assertSame(
            [],
            $this->categories->firstCategoryProjectionsForProducts($this->context, self::TENANT, [
                'under_score1',
                'way-too-long-to-be-a-uuid',
                '',
                42,
            ])
        );
        self::assertSame(0, CountingPdoStatement::$count);
    }

    // === "First" ordering: position, then name, then uuid =======================

    public function testLowerPositionWinsRegardlessOfAttachmentOrder(): void
    {
        $this->seedCategory('catposb00001', 'cat-b', 'B', 2);
        $this->seedCategory('catposa00001', 'cat-a', 'A', 1);
        $this->attach('prodfcp00001', 'catposb00001');
        $this->attach('prodfcp00001', 'catposa00001');

        $result = $this->categories->firstCategoryProjectionsForProducts(
            $this->context,
            self::TENANT,
            ['prodfcp00001']
        );

        self::assertSame(['name' => 'A', 'slug' => 'cat-a'], $result['prodfcp00001']);
    }

    public function testPositionTieBreaksByNameAscThenUuidAsc(): void
    {
        // Same position, different names: name ASC decides.
        $this->seedCategory('cattienameb1', 'tie-b', 'B', 1);
        $this->seedCategory('cattienamea1', 'tie-a', 'A', 1);
        $this->attach('prodfcptie01', 'cattienameb1');
        $this->attach('prodfcptie01', 'cattienamea1');

        // Same position AND same name: uuid ASC decides (distinguished by slug).
        $this->seedCategory('cattieuuid02', 'tie-uuid-hi', 'Same', 1);
        $this->seedCategory('cattieuuid01', 'tie-uuid-lo', 'Same', 1);
        $this->attach('prodfcptie02', 'cattieuuid02');
        $this->attach('prodfcptie02', 'cattieuuid01');

        $result = $this->categories->firstCategoryProjectionsForProducts(
            $this->context,
            self::TENANT,
            ['prodfcptie01', 'prodfcptie02']
        );

        self::assertSame(['name' => 'A', 'slug' => 'tie-a'], $result['prodfcptie01']);
        self::assertSame(['name' => 'Same', 'slug' => 'tie-uuid-lo'], $result['prodfcptie02']);
    }

    // === Direct assignments only; absent keys ===================================

    public function testDirectAssignmentsOnlyNoAncestorExpansionAndUnassignedProductsAreAbsent(): void
    {
        // The assigned category has a parent with a LOWER position -- the
        // parent must never shadow the direct assignment (no ancestor walk).
        $this->seedCategory('catparent001', 'parent', 'Parent', 0);
        $this->seedCategory('catchild0001', 'child', 'Child', 5, 'catparent001');
        $this->attach('prodfcpdir01', 'catchild0001');

        $result = $this->categories->firstCategoryProjectionsForProducts(
            $this->context,
            self::TENANT,
            ['prodfcpdir01', 'prodfcpnone1']
        );

        self::assertSame(['name' => 'Child', 'slug' => 'child'], $result['prodfcpdir01']);
        self::assertArrayNotHasKey('prodfcpnone1', $result, 'a product with no direct assignment must be absent');
    }

    public function testProjectionShapeIsExactlyNameAndSlug(): void
    {
        $this->seedCategory('catshape0001', 'shape', 'Shape', 0);
        $this->attach('prodfcpshp01', 'catshape0001');

        $result = $this->categories->firstCategoryProjectionsForProducts(
            $this->context,
            self::TENANT,
            ['prodfcpshp01']
        );

        self::assertSame(['name', 'slug'], array_keys($result['prodfcpshp01']));
    }

    // === Tenant isolation ========================================================

    public function testAnotherTenantsCategoryNeverResolvesNorShadowsTheTenantsOwn(): void
    {
        // Product attached ONLY to another tenant's category: absent.
        $this->seedCategory('catother0001', 'other-only', 'Other', 0, null, self::OTHER_TENANT);
        $this->attach('prodfcpoth01', 'catother0001');

        // Product attached to another tenant's position-0 category AND this
        // tenant's position-9 category: the other tenant's row must not win.
        $this->seedCategory('catotherlo01', 'other-lo', 'AAA Other', 0, null, self::OTHER_TENANT);
        $this->seedCategory('catminehi001', 'mine-hi', 'ZZZ Mine', 9);
        $this->attach('prodfcpmix01', 'catotherlo01');
        $this->attach('prodfcpmix01', 'catminehi001');

        $result = $this->categories->firstCategoryProjectionsForProducts(
            $this->context,
            self::TENANT,
            ['prodfcpoth01', 'prodfcpmix01']
        );

        self::assertArrayNotHasKey('prodfcpoth01', $result);
        self::assertSame(['name' => 'ZZZ Mine', 'slug' => 'mine-hi'], $result['prodfcpmix01']);
    }

    // === Query-count guard =======================================================

    public function testNonEmptyInputIssuesExactlyOneQuery(): void
    {
        $this->seedCategory('catcount0001', 'count-a', 'Count A', 0);
        $this->seedCategory('catcount0002', 'count-b', 'Count B', 1);
        $this->attach('prodfcpcnt01', 'catcount0001');
        $this->attach('prodfcpcnt02', 'catcount0002');

        // Warm-up: the framework's SoftDeleteHandler runs a one-time,
        // process-cached schema probe the first time any query touches a
        // given table -- run once, unmeasured.
        $this->categories->firstCategoryProjectionsForProducts($this->context, self::TENANT, ['prodfcpcnt01']);

        $pdo = $this->connection->getPDO();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [CountingPdoStatement::class]);
        CountingPdoStatement::$count = 0;

        $result = $this->categories->firstCategoryProjectionsForProducts(
            $this->context,
            self::TENANT,
            ['prodfcpcnt01', 'prodfcpcnt02']
        );

        self::assertSame(1, CountingPdoStatement::$count, 'expected exactly one batched join query');
        self::assertCount(2, $result);
    }

    // === Fixtures ================================================================

    private function seedCategory(
        string $uuid,
        string $slug,
        string $name,
        int $position,
        ?string $parentUuid = null,
        string $tenant = self::TENANT
    ): void {
        $this->connection->table('commerce_categories')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'parent_uuid' => $parentUuid,
            'slug' => $slug,
            'name' => $name,
            'position' => $position,
        ]);
    }

    private function attach(string $productUuid, string $categoryUuid): void
    {
        $this->connection->table('commerce_product_categories')->insert([
            'product_uuid' => $productUuid,
            'category_uuid' => $categoryUuid,
        ]);
    }
}
