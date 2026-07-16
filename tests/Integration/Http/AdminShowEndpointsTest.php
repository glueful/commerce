<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\AttributeRepository;
use Glueful\Extensions\Commerce\Catalog\AttributeService;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\CategoryService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\ReviewRepository;
use Glueful\Extensions\Commerce\Catalog\ReviewService;
use Glueful\Extensions\Commerce\Catalog\TagRepository;
use Glueful\Extensions\Commerce\Catalog\TagService;
use Glueful\Extensions\Commerce\Http\Admin\AdminAttributeController;
use Glueful\Extensions\Commerce\Http\Admin\AdminCategoryController;
use Glueful\Extensions\Commerce\Http\Admin\AdminReviewController;
use Glueful\Extensions\Commerce\Http\Admin\AdminShippingClassController;
use Glueful\Extensions\Commerce\Http\Admin\AdminShippingZoneController;
use Glueful\Extensions\Commerce\Http\Admin\AdminTagController;
use Glueful\Extensions\Commerce\Http\Admin\AdminTaxRateController;
use Glueful\Extensions\Commerce\Shipping\ShippingClassRepository;
use Glueful\Extensions\Commerce\Shipping\ShippingClassService;
use Glueful\Extensions\Commerce\Shipping\ShippingZoneRepository;
use Glueful\Extensions\Commerce\Shipping\ShippingZoneService;
use Glueful\Extensions\Commerce\Tax\TaxRateRepository;
use Glueful\Extensions\Commerce\Tax\TaxRateService;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Layer 6 Task 4 new show endpoints (design spec §2 decision 3): GET single for
 * categories, tags, attributes (embedding values), shipping zones (embedding
 * locations + methods), shipping methods, shipping classes, tax rates, and
 * reviews. Every show is `commerce:read`, tenant-scoped, and 404s identically
 * for an unknown OR cross-tenant uuid (non-revealing). Discounts' show shipped
 * in Task 3 ({@see DiscountLifecycleTest}); refunds' show lives in
 * {@see RefundsListTest} alongside the new cross-order list.
 */
final class AdminShowEndpointsTest extends CommerceTestCase
{
    // === Categories ==========================================================

    public function testCategoryShowReturnsCategory(): void
    {
        $uuid = $this->seedCategory('catshow00001', 'root', 'Root');

        $response = $this->categoryController()->show(Request::create('/x'), $uuid);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Root', $this->json($response)['data']['name']);
    }

    public function testCategoryShowUnknownThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->categoryController()->show(Request::create('/x'), 'no-such-cat');
    }

    public function testCategoryShowCrossTenantThrowsNotFound(): void
    {
        $uuid = $this->seedCategory('catshow00002', 'root', 'Root', 'tenant-b');

        $this->expectException(NotFoundException::class);
        $this->categoryController()->show(Request::create('/x'), $uuid);
    }

    // === Tags =================================================================

    public function testTagShowReturnsTag(): void
    {
        $uuid = 'tagshow000001';
        $this->connection->table('commerce_tags')->insert([
            'uuid' => $uuid, 'tenant_uuid' => '', 'slug' => 'summer', 'name' => 'Summer',
        ]);

        $response = $this->tagController()->show(Request::create('/x'), $uuid);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Summer', $this->json($response)['data']['name']);
    }

    public function testTagShowUnknownThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->tagController()->show(Request::create('/x'), 'no-such-tag');
    }

    public function testTagShowCrossTenantThrowsNotFound(): void
    {
        $uuid = 'tagshow000002';
        $this->connection->table('commerce_tags')->insert([
            'uuid' => $uuid, 'tenant_uuid' => 'tenant-b', 'slug' => 'summer', 'name' => 'Summer',
        ]);

        $this->expectException(NotFoundException::class);
        $this->tagController()->show(Request::create('/x'), $uuid);
    }

    // === Attributes (embed values) ===========================================

    public function testAttributeShowEmbedsValuesOrderedByPosition(): void
    {
        $uuid = 'attrshow00001';
        $this->connection->table('commerce_attributes')->insert([
            'uuid' => $uuid, 'tenant_uuid' => '', 'slug' => 'color', 'name' => 'Color', 'position' => 0,
        ]);
        $this->connection->table('commerce_attribute_values')->insert([
            'uuid' => 'attrval0shw01', 'attribute_uuid' => $uuid, 'slug' => 'blue', 'value' => 'Blue', 'position' => 1,
        ]);
        $this->connection->table('commerce_attribute_values')->insert([
            'uuid' => 'attrval0shw02', 'attribute_uuid' => $uuid, 'slug' => 'red', 'value' => 'Red', 'position' => 0,
        ]);

        $response = $this->attributeController()->show(Request::create('/x'), $uuid);

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame(['red', 'blue'], array_column($data['values'], 'slug'));
    }

    public function testAttributeShowUnknownThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->attributeController()->show(Request::create('/x'), 'no-such-attr');
    }

    public function testAttributeShowCrossTenantThrowsNotFound(): void
    {
        $uuid = 'attrshow00002';
        $this->connection->table('commerce_attributes')->insert([
            'uuid' => $uuid, 'tenant_uuid' => 'tenant-b', 'slug' => 'color', 'name' => 'Color', 'position' => 0,
        ]);

        $this->expectException(NotFoundException::class);
        $this->attributeController()->show(Request::create('/x'), $uuid);
    }

    // === Shipping zones (embed locations + methods) ==========================

    public function testShippingZoneShowEmbedsLocationsAndMethods(): void
    {
        $uuid = 'zoneshow00001';
        $this->connection->table('commerce_shipping_zones')->insert([
            'uuid' => $uuid, 'tenant_uuid' => '', 'name' => 'Domestic', 'position' => 0,
        ]);
        $this->connection->table('commerce_shipping_zone_locations')->insert([
            'zone_uuid' => $uuid, 'kind' => 'country', 'value' => 'US',
        ]);
        $this->connection->table('commerce_shipping_methods')->insert([
            'uuid' => 'zonemethshw01', 'zone_uuid' => $uuid, 'kind' => 'flat', 'label' => 'Standard',
            'config' => json_encode(['amount' => 500], JSON_THROW_ON_ERROR), 'position' => 0, 'enabled' => true,
        ]);

        $response = $this->zoneController()->show(Request::create('/x'), $uuid);

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertCount(1, $data['locations']);
        self::assertSame('US', $data['locations'][0]['value']);
        self::assertCount(1, $data['methods']);
        self::assertSame('flat', $data['methods'][0]['kind']);
        self::assertSame(500, $data['methods'][0]['config']['amount']);
    }

    public function testShippingZoneShowUnknownThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->zoneController()->show(Request::create('/x'), 'no-such-zone');
    }

    public function testShippingZoneShowCrossTenantThrowsNotFound(): void
    {
        $uuid = 'zoneshow00002';
        $this->connection->table('commerce_shipping_zones')->insert([
            'uuid' => $uuid, 'tenant_uuid' => 'tenant-b', 'name' => 'Domestic', 'position' => 0,
        ]);

        $this->expectException(NotFoundException::class);
        $this->zoneController()->show(Request::create('/x'), $uuid);
    }

    // === Shipping methods (tenant-scoped via owning zone) ====================

    public function testShippingMethodShowReturnsMethod(): void
    {
        $zoneUuid = 'zonemeth00001';
        $this->connection->table('commerce_shipping_zones')->insert([
            'uuid' => $zoneUuid, 'tenant_uuid' => '', 'name' => 'Domestic', 'position' => 0,
        ]);
        $methodUuid = 'methshow000001';
        $this->connection->table('commerce_shipping_methods')->insert([
            'uuid' => $methodUuid, 'zone_uuid' => $zoneUuid, 'kind' => 'flat', 'label' => 'Standard',
            'config' => json_encode(['amount' => 500], JSON_THROW_ON_ERROR), 'position' => 0, 'enabled' => true,
        ]);

        $response = $this->zoneController()->showMethod(Request::create('/x'), $methodUuid);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Standard', $this->json($response)['data']['label']);
    }

    public function testShippingMethodShowUnknownThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->zoneController()->showMethod(Request::create('/x'), 'no-such-method');
    }

    public function testShippingMethodShowCrossTenantThrowsNotFound(): void
    {
        $zoneUuid = 'zonemeth00002';
        $this->connection->table('commerce_shipping_zones')->insert([
            'uuid' => $zoneUuid, 'tenant_uuid' => 'tenant-b', 'name' => 'Domestic', 'position' => 0,
        ]);
        $methodUuid = 'methshow000002';
        $this->connection->table('commerce_shipping_methods')->insert([
            'uuid' => $methodUuid, 'zone_uuid' => $zoneUuid, 'kind' => 'flat', 'label' => 'Standard',
            'config' => json_encode(['amount' => 500], JSON_THROW_ON_ERROR), 'position' => 0, 'enabled' => true,
        ]);

        $this->expectException(NotFoundException::class);
        $this->zoneController()->showMethod(Request::create('/x'), $methodUuid);
    }

    // === Shipping classes ======================================================

    public function testShippingClassShowReturnsClass(): void
    {
        $uuid = 'classshow0001';
        $this->connection->table('commerce_shipping_classes')->insert([
            'uuid' => $uuid, 'tenant_uuid' => '', 'slug' => 'fragile', 'name' => 'Fragile',
        ]);

        $response = $this->classController()->show(Request::create('/x'), $uuid);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Fragile', $this->json($response)['data']['name']);
    }

    public function testShippingClassShowUnknownThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->classController()->show(Request::create('/x'), 'no-such-class');
    }

    public function testShippingClassShowCrossTenantThrowsNotFound(): void
    {
        $uuid = 'classshow0002';
        $this->connection->table('commerce_shipping_classes')->insert([
            'uuid' => $uuid, 'tenant_uuid' => 'tenant-b', 'slug' => 'fragile', 'name' => 'Fragile',
        ]);

        $this->expectException(NotFoundException::class);
        $this->classController()->show(Request::create('/x'), $uuid);
    }

    // === Tax rates ============================================================

    public function testTaxRateShowReturnsRate(): void
    {
        $uuid = 'rateshow00001';
        $this->connection->table('commerce_tax_rates')->insert([
            'uuid' => $uuid, 'tenant_uuid' => '', 'country' => 'US', 'rate_bps' => 500,
            'label' => 'US Tax', 'priority' => 0, 'class' => 'standard',
        ]);

        $response = $this->rateController()->show(Request::create('/x'), $uuid);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('US Tax', $this->json($response)['data']['label']);
    }

    public function testTaxRateShowUnknownThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->rateController()->show(Request::create('/x'), 'no-such-rate');
    }

    public function testTaxRateShowCrossTenantThrowsNotFound(): void
    {
        $uuid = 'rateshow00002';
        $this->connection->table('commerce_tax_rates')->insert([
            'uuid' => $uuid, 'tenant_uuid' => 'tenant-b', 'country' => 'US', 'rate_bps' => 500,
            'label' => 'US Tax', 'priority' => 0, 'class' => 'standard',
        ]);

        $this->expectException(NotFoundException::class);
        $this->rateController()->show(Request::create('/x'), $uuid);
    }

    // === Reviews ===============================================================

    public function testReviewShowReturnsReview(): void
    {
        $uuid = 'revshow000001';
        $this->connection->table('commerce_reviews')->insert([
            'uuid' => $uuid, 'tenant_uuid' => '', 'product_uuid' => 'prod00000001', 'author_name' => 'Ann',
            'author_email' => 'ann@example.com', 'rating' => 5, 'body' => 'Great!', 'status' => 'pending',
        ]);

        $response = $this->reviewController()->show(Request::create('/x'), $uuid);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Great!', $this->json($response)['data']['body']);
    }

    public function testReviewShowUnknownThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->reviewController()->show(Request::create('/x'), 'no-such-review');
    }

    public function testReviewShowCrossTenantThrowsNotFound(): void
    {
        $uuid = 'revshow000002';
        $this->connection->table('commerce_reviews')->insert([
            'uuid' => $uuid, 'tenant_uuid' => 'tenant-b', 'product_uuid' => 'prod00000001', 'author_name' => 'Ann',
            'author_email' => 'ann@example.com', 'rating' => 5, 'body' => 'Great!', 'status' => 'pending',
        ]);

        $this->expectException(NotFoundException::class);
        $this->reviewController()->show(Request::create('/x'), $uuid);
    }

    // === Seeding + controller helpers ==========================================

    private function seedCategory(string $uuid, string $slug, string $name, string $tenant = ''): string
    {
        $this->connection->table('commerce_categories')->insert([
            'uuid' => $uuid, 'tenant_uuid' => $tenant, 'slug' => $slug, 'name' => $name, 'position' => 0,
        ]);

        return $uuid;
    }

    private function categoryController(string $tenant = ''): AdminCategoryController
    {
        $tenants = $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant);

        return new AdminCategoryController(
            $this->context,
            new CategoryService(new CategoryRepository(), new ProductRepository(), $tenants)
        );
    }

    private function tagController(string $tenant = ''): AdminTagController
    {
        $tenants = $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant);

        return new AdminTagController(
            $this->context,
            new TagService(new TagRepository(), new ProductRepository(), $tenants)
        );
    }

    private function attributeController(string $tenant = ''): AdminAttributeController
    {
        $tenants = $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant);

        return new AdminAttributeController(
            $this->context,
            new AttributeService(new AttributeRepository(), new ProductRepository(), $tenants)
        );
    }

    private function zoneController(string $tenant = ''): AdminShippingZoneController
    {
        $tenants = $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant);

        return new AdminShippingZoneController(
            $this->context,
            new ShippingZoneService(new ShippingZoneRepository(), new ShippingClassRepository(), $tenants)
        );
    }

    private function classController(string $tenant = ''): AdminShippingClassController
    {
        $tenants = $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant);

        return new AdminShippingClassController(
            $this->context,
            new ShippingClassService(new ShippingClassRepository(), $tenants)
        );
    }

    private function rateController(string $tenant = ''): AdminTaxRateController
    {
        $tenants = $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant);

        return new AdminTaxRateController(
            $this->context,
            new TaxRateService(new TaxRateRepository(), $tenants)
        );
    }

    private function reviewController(string $tenant = ''): AdminReviewController
    {
        $tenants = $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant);

        return new AdminReviewController(
            $this->context,
            new ReviewService(new ReviewRepository(), new ProductRepository(), $tenants)
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
