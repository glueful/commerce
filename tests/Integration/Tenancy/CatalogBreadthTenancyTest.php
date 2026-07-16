<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\AddonRepository;
use Glueful\Extensions\Commerce\Catalog\AddonService;
use Glueful\Extensions\Commerce\Catalog\AttributeRepository;
use Glueful\Extensions\Commerce\Catalog\AttributeService;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\CategoryService;
use Glueful\Extensions\Commerce\Catalog\ProductMediaRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\ReviewRepository;
use Glueful\Extensions\Commerce\Catalog\ReviewService;
use Glueful\Extensions\Commerce\Catalog\TagRepository;
use Glueful\Extensions\Commerce\Catalog\TagService;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Support\DiagnosticsReport;
use Glueful\Extensions\Commerce\Tenancy\TenantAdopter;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Repository\BlobRepository;
use Glueful\Validation\ValidationException;

/**
 * Layer-level tenancy sweep for the six catalog-breadth tables that carry a
 * `tenant_uuid` (`commerce_product_media`, `commerce_categories`, `commerce_tags`,
 * `commerce_attributes`, `commerce_product_addons`, `commerce_reviews`). T2-T7
 * already carry deep per-surface tenancy coverage (see e.g.
 * `MediaEndpointTest::testAttachCrossTenantProductThrowsNotFound()`,
 * `MediaTenancyConcurrencyTest`) -- this file does not repeat those cases. It
 * asserts BREADTH: every one of the six surfaces gets a non-revealing 404
 * spot-check (one read-adjacent lookup + one mutation, not full CRUD), slug/name
 * reuse across tenants is allowed where the schema scopes uniqueness per-tenant,
 * the product<->category/tag/attribute join tables reject a cross-tenant
 * reference instead of silently admitting it, and the tenancy registry/adopter
 * both know about all six tables. Follows the `tenantAAAA01`/`tenantBBBB02`
 * fixed-resolver convention established by `TenantScopingTest` and reused by
 * `RefundTenancyTest`, and exercises the Catalog `*Service` classes directly
 * (the admin controllers are thin DTO-to-service adapters with no additional
 * tenancy logic of their own -- same layer `TenantScopingTest` tests at).
 */
final class CatalogBreadthTenancyTest extends CommerceTestCase
{
    private const TENANT_A = 'tenantAAAA01';
    private const TENANT_B = 'tenantBBBB02';

    protected function setUp(): void
    {
        parent::setUp();

        // Product media attach needs the framework's core blobs table, which is
        // not part of the commerce migration set (see MediaEndpointTest::setUp()).
        require_once __DIR__ . '/../../../vendor/glueful/framework/migrations/uploads/001_CreateBlobsTable.php';
        (new \Glueful\Migrations\Uploads\CreateBlobsTable())->up($this->connection->getSchemaBuilder());
    }

    // -----------------------------------------------------------------
    // Cross-tenant admin access -> non-revealing 404s (one read-adjacent
    // lookup + one mutation per surface).
    // -----------------------------------------------------------------

    public function testProductMediaCrossTenantAdminAccessIsNonRevealing(): void
    {
        $productA = $this->seedProduct(self::TENANT_A);
        $blobUuid = $this->seedBlob();

        $media = $this->media(self::TENANT_A)->attach($this->context, $productA['uuid'], ['blob_uuid' => $blobUuid]);

        // Read-adjacent: attach() must resolve its parent product in-tenant before
        // it does anything else -- tenant B naming tenant A's product is the
        // lookup surface for this domain (there is no standalone admin GET).
        try {
            $this->media(self::TENANT_B)->attach($this->context, $productA['uuid'], ['blob_uuid' => $blobUuid]);
            self::fail('expected NotFoundException');
        } catch (NotFoundException $e) {
        }

        // Mutation.
        try {
            $this->media(self::TENANT_B)->detach($this->context, $media['uuid']);
            self::fail('expected NotFoundException');
        } catch (NotFoundException $e) {
        }
        self::assertNotNull(
            $this->connection->table('commerce_product_media')->where('uuid', '=', $media['uuid'])->first(),
            'Media row must survive a cross-tenant detach attempt.'
        );
    }

    public function testCategoriesCrossTenantAdminAccessIsNonRevealing(): void
    {
        $categoryA = $this->categories(self::TENANT_A)->create($this->context, [
            'slug' => 'breadth-cat',
            'name' => 'Breadth Category',
        ]);

        // Read: tenant B's own list never contains tenant A's category.
        $namesForB = array_column($this->categories(self::TENANT_B)->list($this->context), 'uuid');
        self::assertNotContains($categoryA['uuid'], $namesForB);

        // Mutation.
        try {
            $this->categories(self::TENANT_B)->update($this->context, $categoryA['uuid'], ['name' => 'Hijacked']);
            self::fail('expected NotFoundException');
        } catch (NotFoundException $e) {
        }
        self::assertSame(
            'Breadth Category',
            $this->connection->table('commerce_categories')->where('uuid', '=', $categoryA['uuid'])->first()['name']
        );
    }

    public function testTagsCrossTenantAdminAccessIsNonRevealing(): void
    {
        $tagA = $this->tags(self::TENANT_A)->create($this->context, ['slug' => 'breadth-tag', 'name' => 'Breadth Tag']);

        $uuidsForB = array_column($this->tags(self::TENANT_B)->list($this->context, [], 1, 100)['items'], 'uuid');
        self::assertNotContains($tagA['uuid'], $uuidsForB);

        try {
            $this->tags(self::TENANT_B)->delete($this->context, $tagA['uuid']);
            self::fail('expected NotFoundException');
        } catch (NotFoundException $e) {
        }
        self::assertNotNull(
            $this->connection->table('commerce_tags')->where('uuid', '=', $tagA['uuid'])->first(),
            'Tag row must survive a cross-tenant delete attempt.'
        );
    }

    public function testAttributesCrossTenantAdminAccessIsNonRevealing(): void
    {
        $attributeA = $this->attributes(self::TENANT_A)->create($this->context, [
            'slug' => 'breadth-attr',
            'name' => 'Breadth Attribute',
        ]);

        $uuidsForB = array_column(
            $this->attributes(self::TENANT_B)->list($this->context, [], 1, 100)['items'],
            'uuid'
        );
        self::assertNotContains($attributeA['uuid'], $uuidsForB);

        try {
            $this->attributes(self::TENANT_B)->update($this->context, $attributeA['uuid'], ['name' => 'Hijacked']);
            self::fail('expected NotFoundException');
        } catch (NotFoundException $e) {
        }
        self::assertSame(
            'Breadth Attribute',
            $this->connection->table('commerce_attributes')
                ->where('uuid', '=', $attributeA['uuid'])->first()['name']
        );
    }

    public function testProductAddonsCrossTenantAdminAccessIsNonRevealing(): void
    {
        $productA = $this->seedProduct(self::TENANT_A);
        $addonA = $this->addons(self::TENANT_A)->create($this->context, $productA['uuid'], [
            'name' => 'Gift wrap',
            'field_type' => 'checkbox',
            'price_delta' => 500,
        ]);

        // Read: the product-scoped list has no parent-ownership check of its own
        // (see AddonRepository::forProduct()), so cross-tenant access degrades to
        // an empty result rather than a 404 -- still a non-leak, just not an
        // exception. Assert that explicitly rather than assuming.
        self::assertSame([], $this->addons(self::TENANT_B)->list($this->context, $productA['uuid']));

        // Mutation.
        try {
            $this->addons(self::TENANT_B)->update($this->context, $addonA['uuid'], ['name' => 'Hijacked']);
            self::fail('expected NotFoundException');
        } catch (NotFoundException $e) {
        }
        self::assertSame(
            'Gift wrap',
            $this->connection->table('commerce_product_addons')->where('uuid', '=', $addonA['uuid'])->first()['name']
        );
    }

    public function testReviewsCrossTenantAdminAccessIsNonRevealing(): void
    {
        $productA = $this->seedProduct(self::TENANT_A);
        $reviewA = $this->reviews(self::TENANT_A)->create($this->context, [
            'product_uuid' => $productA['uuid'],
            'rating' => 5,
            'body' => 'Great product.',
            'author_name' => 'Ann',
            'author_email' => 'ann@example.com',
        ]);

        // Read: product-filtered list is tenant-scoped, so it degrades to an empty
        // result for tenant B rather than a 404 (same shape as add-ons above).
        $listForB = $this->reviews(self::TENANT_B)->list($this->context, ['product' => $productA['uuid']], 1, 24);
        self::assertSame([], $listForB['items']);

        // Mutation.
        try {
            $this->reviews(self::TENANT_B)->approve($this->context, $reviewA['uuid']);
            self::fail('expected NotFoundException');
        } catch (NotFoundException $e) {
        }
        self::assertSame(
            'pending',
            $this->connection->table('commerce_reviews')->where('uuid', '=', $reviewA['uuid'])->first()['status']
        );
    }

    // -----------------------------------------------------------------
    // Slug/name reuse across tenants (categories, tags, attributes).
    // -----------------------------------------------------------------

    public function testSlugReuseAcrossTenantsIsAllowed(): void
    {
        $categoryA = $this->categories(self::TENANT_A)->create($this->context, ['slug' => 'shared', 'name' => 'A']);
        $categoryB = $this->categories(self::TENANT_B)->create($this->context, ['slug' => 'shared', 'name' => 'B']);
        self::assertNotSame($categoryA['uuid'], $categoryB['uuid']);
        self::assertSame('A', (new CategoryRepository())->findBySlug($this->context, self::TENANT_A, 'shared')['name']);
        self::assertSame('B', (new CategoryRepository())->findBySlug($this->context, self::TENANT_B, 'shared')['name']);

        $tagA = $this->tags(self::TENANT_A)->create($this->context, ['slug' => 'shared', 'name' => 'A']);
        $tagB = $this->tags(self::TENANT_B)->create($this->context, ['slug' => 'shared', 'name' => 'B']);
        self::assertNotSame($tagA['uuid'], $tagB['uuid']);
        self::assertSame('A', (new TagRepository())->findBySlug($this->context, self::TENANT_A, 'shared')['name']);
        self::assertSame('B', (new TagRepository())->findBySlug($this->context, self::TENANT_B, 'shared')['name']);

        $attributeA = $this->attributes(self::TENANT_A)->create($this->context, ['slug' => 'shared', 'name' => 'A']);
        $attributeB = $this->attributes(self::TENANT_B)->create($this->context, ['slug' => 'shared', 'name' => 'B']);
        self::assertNotSame($attributeA['uuid'], $attributeB['uuid']);
        self::assertSame(
            'A',
            (new AttributeRepository())->findBySlug($this->context, self::TENANT_A, 'shared')['name']
        );
        self::assertSame(
            'B',
            (new AttributeRepository())->findBySlug($this->context, self::TENANT_B, 'shared')['name']
        );
    }

    // -----------------------------------------------------------------
    // Join tables unreachable except through in-tenant parents (one probe
    // per join family: product<->category, product<->tag, product<->attribute).
    // -----------------------------------------------------------------

    public function testProductJoinTablesRejectCrossTenantReferences(): void
    {
        $categoryA = $this->categories(self::TENANT_A)->create($this->context, ['slug' => 'joincat', 'name' => 'Join Cat']);
        $tagA = $this->tags(self::TENANT_A)->create($this->context, ['slug' => 'jointag', 'name' => 'Join Tag']);
        $attributeA = $this->attributes(self::TENANT_A)->create($this->context, [
            'slug' => 'joinattr',
            'name' => 'Join Attr',
        ]);
        $productB = $this->seedProduct(self::TENANT_B);

        try {
            $this->categories(self::TENANT_B)->setProductCategories($this->context, $productB['uuid'], [$categoryA['uuid']]);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('category_uuids', $e->firstErrors());
        }
        self::assertSame(
            0,
            $this->connection->table('commerce_product_categories')
                ->where('product_uuid', '=', $productB['uuid'])->count()
        );

        try {
            $this->tags(self::TENANT_B)->setProductTags($this->context, $productB['uuid'], [$tagA['uuid']]);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('tag_uuids', $e->firstErrors());
        }
        self::assertSame(
            0,
            $this->connection->table('commerce_product_tags')
                ->where('product_uuid', '=', $productB['uuid'])->count()
        );

        try {
            $this->attributes(self::TENANT_B)->setProductAttributes($this->context, $productB['uuid'], [
                ['attribute_uuid' => $attributeA['uuid']],
            ]);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('attributes', $e->firstErrors());
        }
        self::assertSame(
            0,
            $this->connection->table('commerce_product_attributes')
                ->where('product_uuid', '=', $productB['uuid'])->count()
        );
    }

    // -----------------------------------------------------------------
    // Registry + adopter coverage.
    // -----------------------------------------------------------------

    public function testDiagnosticsReportAndAdopterCoverAllSixCatalogBreadthTables(): void
    {
        // Exact list, not a subset check -- pins every tenant table this layer
        // knows about (the pre-existing ten plus the six added in Layer 2), so a
        // future accidental removal/addition is caught here as well as locally.
        self::assertSame([
            'commerce_products',
            'commerce_variants',
            'commerce_stock',
            'commerce_stock_movements',
            'commerce_carts',
            'commerce_orders',
            'commerce_refunds',
            'commerce_sequences',
            'commerce_discounts',
            'commerce_discount_redemptions',
            'commerce_product_media',
            'commerce_categories',
            'commerce_tags',
            'commerce_attributes',
            'commerce_product_addons',
            'commerce_reviews',
            'commerce_customer_address_books',
            'commerce_customer_addresses',
            'commerce_downloads',
            'commerce_download_grants',
            'commerce_shipping_zones',
            'commerce_shipping_classes',
            'commerce_tax_rates',
        ], DiagnosticsReport::tenantTables());

        // The join/child tables added alongside the six must never be treated as
        // tenant tables (they carry no tenant_uuid column of their own).
        foreach ([
            'commerce_product_categories',
            'commerce_product_tags',
            'commerce_attribute_values',
            'commerce_product_attributes',
            'commerce_product_children',
            'commerce_shipping_zone_locations',
            'commerce_shipping_methods',
        ] as $joinTable) {
            self::assertNotContains($joinTable, DiagnosticsReport::tenantTables());
        }

        $sentinels = [
            'commerce_product_media' => [
                'uuid' => Utils::generateNanoID(),
                'tenant_uuid' => '',
                'product_uuid' => Utils::generateNanoID(),
                'blob_uuid' => Utils::generateNanoID(),
                'role' => 'gallery',
            ],
            'commerce_categories' => [
                'uuid' => Utils::generateNanoID(),
                'tenant_uuid' => '',
                'slug' => 'sentinel-category',
                'name' => 'Sentinel Category',
            ],
            'commerce_tags' => [
                'uuid' => Utils::generateNanoID(),
                'tenant_uuid' => '',
                'slug' => 'sentinel-tag',
                'name' => 'Sentinel Tag',
            ],
            'commerce_attributes' => [
                'uuid' => Utils::generateNanoID(),
                'tenant_uuid' => '',
                'slug' => 'sentinel-attribute',
                'name' => 'Sentinel Attribute',
            ],
            'commerce_product_addons' => [
                'uuid' => Utils::generateNanoID(),
                'tenant_uuid' => '',
                'product_uuid' => Utils::generateNanoID(),
                'name' => 'Sentinel Addon',
                'field_type' => 'checkbox',
            ],
            'commerce_reviews' => [
                'uuid' => Utils::generateNanoID(),
                'tenant_uuid' => '',
                'product_uuid' => Utils::generateNanoID(),
                'author_name' => 'Sentinel Author',
                'author_email' => 'sentinel@example.com',
                'rating' => 5,
                'body' => 'Sentinel review body.',
            ],
        ];

        foreach ($sentinels as $table => $row) {
            $this->connection->table($table)->insert($row);
        }

        $result = (new TenantAdopter())->adopt($this->context, self::TENANT_A);

        foreach ($sentinels as $table => $row) {
            self::assertArrayHasKey($table, $result['tables']);
            self::assertSame(1, $result['tables'][$table], "Adopter should have found exactly 1 sentinel row in {$table}.");

            $adopted = $this->connection->table($table)->where('uuid', '=', $row['uuid'])->first();
            self::assertNotNull($adopted);
            self::assertSame(self::TENANT_A, $adopted['tenant_uuid']);
        }
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /** @return array<string,mixed> */
    private function seedProduct(string $tenant): array
    {
        $uuid = Utils::generateNanoID();
        $this->connection->table('commerce_products')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => 'product-' . $uuid,
            'name' => 'Product ' . $uuid,
            'type' => 'physical',
            'status' => 'active',
        ]);

        $product = (new ProductRepository())->findLiveByUuid($this->context, $tenant, $uuid);
        self::assertNotNull($product);

        return $product;
    }

    private function seedBlob(): string
    {
        $uuid = Utils::generateNanoID();
        $this->connection->table('blobs')->insert([
            'uuid' => $uuid,
            'name' => $uuid,
            'mime_type' => 'image/png',
            'size' => 100,
            'url' => '/storage/' . $uuid,
            'storage_type' => 'local',
            'visibility' => 'public',
            'status' => 'active',
            'created_by' => 'uploader00001',
        ]);

        return $uuid;
    }

    private function media(string $tenant): ProductMediaService
    {
        return new ProductMediaService(
            new ProductRepository(),
            new VariantRepository(),
            new ProductMediaRepository(),
            $this->fixedTenant($tenant),
            new BlobRepository($this->connection)
        );
    }

    private function categories(string $tenant): CategoryService
    {
        return new CategoryService(new CategoryRepository(), new ProductRepository(), $this->fixedTenant($tenant));
    }

    private function tags(string $tenant): TagService
    {
        return new TagService(new TagRepository(), new ProductRepository(), $this->fixedTenant($tenant));
    }

    private function attributes(string $tenant): AttributeService
    {
        return new AttributeService(new AttributeRepository(), new ProductRepository(), $this->fixedTenant($tenant));
    }

    private function addons(string $tenant): AddonService
    {
        return new AddonService(new AddonRepository(), new ProductRepository(), $this->fixedTenant($tenant));
    }

    private function reviews(string $tenant): ReviewService
    {
        return new ReviewService(new ReviewRepository(), new ProductRepository(), $this->fixedTenant($tenant));
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
}
