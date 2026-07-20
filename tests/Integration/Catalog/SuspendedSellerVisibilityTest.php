<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Catalog;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Catalog\AddonRepository;
use Glueful\Extensions\Commerce\Catalog\AttributeRepository;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\ReviewRepository;
use Glueful\Extensions\Commerce\Catalog\ReviewService;
use Glueful\Extensions\Commerce\Catalog\TagRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Http\Admin\AdminProductController;
use Glueful\Extensions\Commerce\Http\Storefront\ProductController;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Shipping\ShippingClassRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * MV5b Task 3: the centralized buyer-availability predicate (design spec
 * §2.3) -- {@see ProductRepository::findBuyerAvailableByUuid()},
 * {@see ProductRepository::findBuyerAvailableBySlug()}, and
 * {@see ProductRepository::activeFilteredQuery()} (via `listActive()`) all
 * exclude a seller-backed product the instant its seller is anything other
 * than `active`, while a sellerless product is NEVER excluded and the
 * shared tombstone-only `findLive*` reads (admin/internal) stay completely
 * unaffected. Proves the distinction is real by exercising a representative
 * admin path ({@see AdminProductController::show()}), not merely
 * `findIncludingDeleted*` access.
 */
final class SuspendedSellerVisibilityTest extends CommerceTestCase
{
    private const TENANT = 'tenantSSV001';

    protected function setUp(): void
    {
        parent::setUp();

        // Every case in this file exercises the buyer-availability predicate,
        // which is itself gated on the marketplace INSTALL master switch
        // (design spec §2.1 -- see `ProductRepository::applyBuyerAvailability()`).
        // `MarketplaceRegressionTest` separately pins the switch-OFF byte-
        // identical/zero-query behavior this predicate must never disturb.
        $this->context->mergeConfigDefaults('commerce', ['marketplace' => ['enabled' => true]]);
    }

    // === Active seller: buyer-visible everywhere ================================

    public function testActiveSellerProductIsBuyerVisibleByUuidSlugAndListActive(): void
    {
        $this->seedSeller('sellerActiv1', 'active');
        $this->seedProduct('prodactive01', 'sellerActiv1', 'prod-active');

        $products = new ProductRepository();

        self::assertNotNull($products->findBuyerAvailableByUuid($this->context, self::TENANT, 'prodactive01'));
        self::assertNotNull($products->findBuyerAvailableBySlug($this->context, self::TENANT, 'prod-active'));

        $result = $products->listActive($this->context, self::TENANT, 1, 25, null);
        self::assertSame(1, $result['total']);
        self::assertSame('prodactive01', $result['items'][0]['uuid']);
    }

    // === Non-active seller states: buyer-excluded ===============================

    /** @dataProvider nonActiveSellerStatusProvider */
    public function testNonActiveSellerExcludesProductFromBuyerUuidSlugAndListActive(string $status): void
    {
        $sellerUuid = 'sellerX' . substr(md5($status), 0, 5);
        $productUuid = 'prodX' . substr(md5($status), 0, 7);
        $slug = 'prod-' . $status;

        $this->seedSeller($sellerUuid, $status);
        $this->seedProduct($productUuid, $sellerUuid, $slug);

        $products = new ProductRepository();

        self::assertNull(
            $products->findBuyerAvailableByUuid($this->context, self::TENANT, $productUuid),
            "findBuyerAvailableByUuid must exclude a '{$status}' seller's product"
        );
        self::assertNull(
            $products->findBuyerAvailableBySlug($this->context, self::TENANT, $slug),
            "findBuyerAvailableBySlug must exclude a '{$status}' seller's product"
        );

        $result = $products->listActive($this->context, self::TENANT, 1, 25, null);
        self::assertSame(0, $result['total'], "listActive must exclude a '{$status}' seller's product");
        self::assertSame([], $result['items']);

        // The storefront controller's direct read must collapse to the same
        // non-revealing 404 a delisted/tombstoned product produces.
        try {
            $this->productController()->show(Request::create('/x'), $slug);
            self::fail("expected NotFoundException for a '{$status}' seller's product");
        } catch (NotFoundException $e) {
            self::assertSame('Resource not found.', $e->getMessage());
        }
    }

    /** @return iterable<string, array{0:string}> */
    public static function nonActiveSellerStatusProvider(): iterable
    {
        yield 'suspended' => ['suspended'];
        yield 'closed' => ['closed'];
        yield 'onboarding' => ['onboarding'];
    }

    // === Sellerless products: never excluded =====================================

    public function testSellerlessProductStaysBuyerVisibleEvenWhenOtherSellersAreSuspended(): void
    {
        // A suspended seller exists in the SAME tenant -- proves the EXISTS
        // predicate's `seller_uuid IS NULL` branch, not an accidental
        // absence of any seller row at all (would be indistinguishable from
        // an inner-join bug that happens to pass when no seller row exists).
        $this->seedSeller('sellerSusp01', 'suspended');
        $this->seedProduct('prodsellless', null, 'prod-sellerless');

        $products = new ProductRepository();

        self::assertNotNull($products->findBuyerAvailableByUuid($this->context, self::TENANT, 'prodsellless'));
        self::assertNotNull($products->findBuyerAvailableBySlug($this->context, self::TENANT, 'prod-sellerless'));

        $result = $products->listActive($this->context, self::TENANT, 1, 25, null);
        self::assertSame(1, $result['total']);
        self::assertSame('prodsellless', $result['items'][0]['uuid']);
    }

    // === findLive*/admin path: unaffected (the tombstone-only distinction) ======

    public function testFindLiveAndAdminPathStillReturnTheSuspendedSellersLiveProduct(): void
    {
        $this->seedSeller('sellerSusp02', 'suspended');
        $this->seedProduct('prodsuspend2', 'sellerSusp02', 'prod-suspend-2');

        $products = new ProductRepository();

        self::assertNotNull(
            $products->findLiveByUuid($this->context, self::TENANT, 'prodsuspend2'),
            'findLiveByUuid must remain tombstone-only and unaffected by seller status'
        );
        self::assertNotNull(
            $products->findLiveBySlug($this->context, self::TENANT, 'prod-suspend-2'),
            'findLiveBySlug must remain tombstone-only and unaffected by seller status'
        );

        // Representative admin/catalog path -- proves the distinction is
        // real HTTP-surface behavior, not merely a second repository method
        // nobody calls.
        $response = $this->adminController()->show(Request::create('/x'), 'prodsuspend2');
        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame('prodsuspend2', $body['data']['uuid']);
    }

    // === Public review submit/read follow buyer availability =====================

    public function testPublicReviewSubmitAndReadFollowBuyerAvailability(): void
    {
        $this->seedSeller('sellerSusp03', 'suspended');
        $this->seedProduct('prodsuspend3', 'sellerSusp03', 'prod-suspend-3');

        $reviews = $this->reviewService();

        try {
            $reviews->createForStorefront($this->context, 'prod-suspend-3', $this->reviewInput());
            self::fail('expected NotFoundException submitting a review for a suspended seller\'s product');
        } catch (NotFoundException $e) {
            self::assertSame('Resource not found.', $e->getMessage());
        }

        try {
            $reviews->listForStorefront($this->context, 'prod-suspend-3', 1, 25);
            self::fail('expected NotFoundException listing reviews for a suspended seller\'s product');
        } catch (NotFoundException $e) {
            self::assertSame('Resource not found.', $e->getMessage());
        }

        // Control: an active seller's product still accepts a submit and
        // serves its approved-review list.
        $this->seedSeller('sellerActiv2', 'active');
        $this->seedProduct('prodactive02', 'sellerActiv2', 'prod-active-2');

        $reviews->createForStorefront($this->context, 'prod-active-2', $this->reviewInput());
        $row = $this->connection->table('commerce_reviews')
            ->where('product_uuid', '=', 'prodactive02')
            ->first();
        self::assertNotNull($row);
        self::assertSame('pending', $row['status']);

        $this->connection->table('commerce_reviews')->where('uuid', '=', $row['uuid'])->update([
            'status' => 'approved',
        ]);
        $listed = $reviews->listForStorefront($this->context, 'prod-active-2', 1, 25);
        self::assertSame(1, $listed['total']);
    }

    // === Reinstatement: re-included with NO product-row change ==================

    public function testReinstatingTheSellerReincludesTheProductWithNoProductRowChange(): void
    {
        $this->seedSeller('sellerFlip01', 'suspended');
        $this->seedProduct('prodflip0001', 'sellerFlip01', 'prod-flip');

        $products = new ProductRepository();
        self::assertNull($products->findBuyerAvailableByUuid($this->context, self::TENANT, 'prodflip0001'));

        $before = $this->connection->table('commerce_products')->where('uuid', '=', 'prodflip0001')->first();
        self::assertNotNull($before);

        // Reinstate the seller -- the product row itself is never touched.
        $this->connection->table('commerce_sellers')
            ->where('uuid', '=', 'sellerFlip01')
            ->update(['status' => 'active']);

        $after = $this->connection->table('commerce_products')->where('uuid', '=', 'prodflip0001')->first();
        self::assertSame($before, $after, 'reinstatement must not mutate the product row in any way');

        self::assertNotNull($products->findBuyerAvailableByUuid($this->context, self::TENANT, 'prodflip0001'));
        $result = $products->listActive($this->context, self::TENANT, 1, 25, null);
        self::assertSame(1, $result['total']);
    }

    // === Gated live PostgreSQL lane ==============================================

    /**
     * Mirrors `Http\ApiParityPgsqlTest`'s gating/fixture-width/self-healing
     * pattern exactly (see that class's docblock) -- no true two-connection
     * race is needed here (this predicate is a pure read), just proof that
     * the `EXISTS`/`IS NULL` predicate resolves identically on real
     * PostgreSQL, not merely under SQLite's looser NULL/type handling.
     */
    public function testActiveSuspendedAndSellerlessVisibilityOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedConnection($this->pgConfig());
        $context = $this->pgsqlContext($connection);
        $tenant = 'tntsspgssv01';

        try {
            $connection->table('commerce_sellers')->insert([
                'uuid' => 'sellerpgact1',
                'tenant_uuid' => $tenant,
                'slug' => 'seller-pg-active',
                'name' => 'Active PG',
                'status' => 'active',
            ]);
            $connection->table('commerce_sellers')->insert([
                'uuid' => 'sellerpgsus1',
                'tenant_uuid' => $tenant,
                'slug' => 'seller-pg-suspended',
                'name' => 'Suspended PG',
                'status' => 'suspended',
            ]);

            $connection->table('commerce_products')->insert([
                'uuid' => 'prodpgactiv1',
                'tenant_uuid' => $tenant,
                'slug' => 'prod-pg-active',
                'name' => 'Active PG Product',
                'type' => 'physical',
                'status' => 'active',
                'seller_uuid' => 'sellerpgact1',
            ]);
            $connection->table('commerce_products')->insert([
                'uuid' => 'prodpgsuspn1',
                'tenant_uuid' => $tenant,
                'slug' => 'prod-pg-suspended',
                'name' => 'Suspended PG Product',
                'type' => 'physical',
                'status' => 'active',
                'seller_uuid' => 'sellerpgsus1',
            ]);
            $connection->table('commerce_products')->insert([
                'uuid' => 'prodpgnosel1',
                'tenant_uuid' => $tenant,
                'slug' => 'prod-pg-sellerless',
                'name' => 'Sellerless PG Product',
                'type' => 'physical',
                'status' => 'active',
                'seller_uuid' => null,
            ]);

            $products = new ProductRepository();

            self::assertNotNull($products->findBuyerAvailableByUuid($context, $tenant, 'prodpgactiv1'));
            self::assertNull($products->findBuyerAvailableByUuid($context, $tenant, 'prodpgsuspn1'));
            self::assertNotNull($products->findBuyerAvailableByUuid($context, $tenant, 'prodpgnosel1'));

            self::assertNotNull($products->findLiveByUuid($context, $tenant, 'prodpgsuspn1'));

            $result = $products->listActive($context, $tenant, 1, 25, null);
            $uuids = array_column($result['items'], 'uuid');
            sort($uuids);
            self::assertSame(['prodpgactiv1', 'prodpgnosel1'], $uuids);
        } finally {
            $this->cleanupPgsqlFixture($connection, $tenant);
        }
    }

    // === Fixtures + helpers =======================================================

    private function seedSeller(string $uuid, string $status): void
    {
        $this->connection->table('commerce_sellers')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT,
            'slug' => strtolower($uuid),
            'name' => $uuid,
            'status' => $status,
        ]);
    }

    /** @return array<string,mixed> */
    private function seedProduct(string $uuid, ?string $sellerUuid, string $slug): array
    {
        $row = [
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT,
            'slug' => $slug,
            'name' => $uuid,
            'type' => 'physical',
            'status' => 'active',
            'seller_uuid' => $sellerUuid,
        ];
        $this->connection->table('commerce_products')->insert($row);

        return $row;
    }

    /** @return array{rating:int,body:string,author_name:string,author_email:string} */
    private function reviewInput(): array
    {
        return [
            'rating' => 5,
            'body' => 'Great product, would buy again.',
            'author_name' => 'Jane Doe',
            'author_email' => 'jane@example.com',
        ];
    }

    private function reviewService(): ReviewService
    {
        return new ReviewService(new ReviewRepository(), new ProductRepository(), $this->fixedTenant());
    }

    private function productController(): ProductController
    {
        return new ProductController(
            $this->context,
            new ProductRepository(),
            new VariantRepository(),
            $this->fixedTenant(),
            new ProductMediaRepository(),
            new CategoryRepository(),
            new TagRepository(),
            new AttributeRepository(),
            new ProductChildrenRepository(),
            new AddonRepository()
        );
    }

    private function adminController(): AdminProductController
    {
        return new AdminProductController(
            $this->context,
            new CatalogService(
                new ProductRepository(),
                new VariantRepository(),
                $this->fixedTenant(),
                new StockRepository()
            ),
            new ProductRepository(),
            new VariantRepository(),
            $this->fixedTenant(),
            new ShippingClassRepository()
        );
    }

    private function fixedTenant(): CurrentTenantResolver
    {
        return new class (self::TENANT) implements CurrentTenantResolver {
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
    private function json(\Symfony\Component\HttpFoundation\Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function cleanupPgsqlFixture(Connection $connection, string $tenant): void
    {
        $connection->table('commerce_products')->where('tenant_uuid', '=', $tenant)->forceDelete();
        $connection->table('commerce_sellers')->where('tenant_uuid', '=', $tenant)->delete();
    }

    /** @return array<string,mixed> */
    private function pgConfig(): array
    {
        return [
            'engine' => 'pgsql',
            'pgsql' => [
                'host' => getenv('DB_PGSQL_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('DB_PGSQL_PORT') ?: 5432),
                'db' => getenv('DB_PGSQL_DATABASE') ?: 'glueful_test',
                'user' => getenv('DB_PGSQL_USERNAME') ?: 'postgres',
                'pass' => getenv('DB_PGSQL_PASSWORD') ?: '',
                'schema' => getenv('DB_PGSQL_SCHEMA') ?: 'public',
            ],
            'pooling' => ['enabled' => false],
        ];
    }

    /** @param array<string,mixed> $pgConfig */
    private function migratedConnection(array $pgConfig): Connection
    {
        $connection = new Connection($pgConfig);
        $schema = $connection->getSchemaBuilder();
        foreach (static::MIGRATIONS as $migration) {
            (new $migration())->up($schema);
        }

        return $connection;
    }

    private function pgsqlContext(Connection $connection): ApplicationContext
    {
        $container = new class ($connection) implements ContainerInterface {
            public function __construct(private Connection $connection)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === 'database' || $id === Connection::class) {
                    return $this->connection;
                }

                throw new \RuntimeException("Unknown service: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === 'database' || $id === Connection::class;
            }
        };

        $context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
        $context->setContainer($container);
        $context->mergeConfigDefaults('commerce', require __DIR__ . '/../../../config/commerce.php');
        $context->mergeConfigDefaults('commerce', ['marketplace' => ['enabled' => true]]);

        return $context;
    }

    private function skipUnlessPgsql(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            self::markTestSkipped('Requires a PostgreSQL test lane to prove the predicate is portable.');
        }
    }
}
