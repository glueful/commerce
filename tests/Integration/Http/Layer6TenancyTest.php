<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\AddonRepository;
use Glueful\Extensions\Commerce\Catalog\AttributeRepository;
use Glueful\Extensions\Commerce\Catalog\AttributeService;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\TagRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Http\Admin\AdminAttributeController;
use Glueful\Extensions\Commerce\Http\Admin\AdminShippingZoneController;
use Glueful\Extensions\Commerce\Http\Admin\AdminTaxRateController;
use Glueful\Extensions\Commerce\Http\DTOs\AttributeListQuery;
use Glueful\Extensions\Commerce\Http\DTOs\ProductListQuery;
use Glueful\Extensions\Commerce\Http\DTOs\ShippingZoneListQuery;
use Glueful\Extensions\Commerce\Http\DTOs\TaxRateListQuery;
use Glueful\Extensions\Commerce\Http\Storefront\ProductController;
use Glueful\Extensions\Commerce\Shipping\ShippingClassRepository;
use Glueful\Extensions\Commerce\Shipping\ShippingZoneRepository;
use Glueful\Extensions\Commerce\Shipping\ShippingZoneService;
use Glueful\Extensions\Commerce\Tax\TaxRateRepository;
use Glueful\Extensions\Commerce\Tax\TaxRateService;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Two-tenant spot-suite (Layer 6 Task 7, plan scope item 5): fills the
 * genuine tenant-isolation gaps left after Groups A-C. Most Layer 6 lists/
 * shows already carry an explicit cross-tenant assertion (products, tags,
 * discounts, refunds, every show endpoint via `AdminShowEndpointsTest`,
 * shipping classes via its list's embedded tenant-b fixture) -- this file
 * covers the handful that don't: the admin attribute/shipping-zone/tax-rate
 * lists (each new-in-Layer-6 pagination retrofit, none of which had an
 * other-tenant fixture in `AdminListRetrofitTest`), and the storefront
 * product-filter's category resolution across two tenants sharing the exact
 * same slug (categories are unique per `(tenant_uuid, slug)`, so the SAME
 * slug string legitimately resolves to two different category uuids -- the
 * filter must never let tenant A's request resolve tenant B's category or
 * see tenant B's product).
 */
final class Layer6TenancyTest extends CommerceTestCase
{
    private const TENANT_B = 'l6tenantB001';

    public function testAdminAttributeIndexExcludesOtherTenantsAttributes(): void
    {
        $this->seedAttribute('l6tnattrA001', 'color', 'Color', tenant: '');
        $this->seedAttribute('l6tnattrB001', 'size', 'Size', tenant: self::TENANT_B);

        $body = $this->json($this->attributeController('')->index(
            new AttributeListQuery(),
            Request::create('/x')
        ));

        self::assertSame(1, $body['total']);
        self::assertSame(['l6tnattrA001'], array_column($body['data'], 'uuid'));
    }

    public function testAdminShippingZoneIndexExcludesOtherTenantsZones(): void
    {
        $this->seedZone('l6tnzoneA001', 'Domestic', tenant: '');
        $this->seedZone('l6tnzoneB001', 'Theirs', tenant: self::TENANT_B);

        $body = $this->json($this->zoneController('')->index(new ShippingZoneListQuery(), Request::create('/x')));

        self::assertSame(1, $body['total']);
        self::assertSame(['l6tnzoneA001'], array_column($body['data'], 'uuid'));
    }

    public function testAdminTaxRateIndexExcludesOtherTenantsRates(): void
    {
        $this->seedTaxRate('l6tnrateA001', 'US', tenant: '');
        $this->seedTaxRate('l6tnrateB001', 'CA', tenant: self::TENANT_B);

        $body = $this->json($this->rateController('')->index(new TaxRateListQuery()));

        self::assertSame(1, $body['total']);
        self::assertSame(['l6tnrateA001'], array_column($body['data'], 'uuid'));
    }

    /**
     * Both tenants use the IDENTICAL category slug 'shared-cat' -- legitimate,
     * since the uniqueness constraint is `(tenant_uuid, slug)`. Filtering
     * tenant A's storefront product list by `category=shared-cat` must resolve
     * ONLY tenant A's category uuid and return ONLY tenant A's product, never
     * cross-resolving or leaking tenant B's.
     */
    public function testStorefrontCategoryFilterResolvesOnlyTheRequestingTenantsCategoryForASharedSlug(): void
    {
        $this->connection->table('commerce_categories')->insert([
            'uuid' => 'l6tncatA0001', 'tenant_uuid' => '', 'parent_uuid' => null,
            'slug' => 'shared-cat', 'name' => 'Shared A', 'position' => 0,
        ]);
        $this->connection->table('commerce_categories')->insert([
            'uuid' => 'l6tncatB0001', 'tenant_uuid' => self::TENANT_B, 'parent_uuid' => null,
            'slug' => 'shared-cat', 'name' => 'Shared B', 'position' => 0,
        ]);

        $productA = 'l6tnprodA001';
        $this->connection->table('commerce_products')->insert([
            'uuid' => $productA, 'tenant_uuid' => '', 'slug' => 'prod-a-shared',
            'name' => 'A', 'type' => 'physical', 'status' => 'active',
        ]);
        $this->connection->table('commerce_product_categories')->insert([
            'product_uuid' => $productA, 'category_uuid' => 'l6tncatA0001',
        ]);

        $productB = 'l6tnprodB001';
        $this->connection->table('commerce_products')->insert([
            'uuid' => $productB, 'tenant_uuid' => self::TENANT_B, 'slug' => 'prod-b-shared',
            'name' => 'B', 'type' => 'physical', 'status' => 'active',
        ]);
        $this->connection->table('commerce_product_categories')->insert([
            'product_uuid' => $productB, 'category_uuid' => 'l6tncatB0001',
        ]);

        $bodyA = $this->json($this->productController('')->index(new ProductListQuery(category: 'shared-cat')));
        self::assertSame(1, $bodyA['total']);
        self::assertSame([$productA], array_column($bodyA['data'], 'uuid'));

        $bodyB = $this->json($this->productController(self::TENANT_B)->index(
            new ProductListQuery(category: 'shared-cat')
        ));
        self::assertSame(1, $bodyB['total']);
        self::assertSame([$productB], array_column($bodyB['data'], 'uuid'));
    }

    // --- Seeding helpers -------------------------------------------------------

    private function seedAttribute(string $uuid, string $slug, string $name, string $tenant = ''): void
    {
        $this->connection->table('commerce_attributes')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'slug' => $slug,
            'name' => $name,
        ]);
    }

    private function seedZone(string $uuid, string $name, string $tenant = ''): void
    {
        $this->connection->table('commerce_shipping_zones')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'name' => $name,
            'position' => 0,
        ]);
    }

    private function seedTaxRate(string $uuid, string $country, string $tenant = ''): void
    {
        $this->connection->table('commerce_tax_rates')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'country' => $country,
            'rate_bps' => 500,
            'label' => 'Rate',
            'priority' => 0,
            'class' => 'standard',
        ]);
    }

    // --- Controller factories ---------------------------------------------------

    private function attributeController(string $tenant): AdminAttributeController
    {
        $tenants = $this->fixedTenant($tenant);

        return new AdminAttributeController(
            $this->context,
            new AttributeService(new AttributeRepository(), new ProductRepository(), $tenants)
        );
    }

    private function zoneController(string $tenant): AdminShippingZoneController
    {
        $tenants = $this->fixedTenant($tenant);

        return new AdminShippingZoneController(
            $this->context,
            new ShippingZoneService(new ShippingZoneRepository(), new ShippingClassRepository(), $tenants)
        );
    }

    private function rateController(string $tenant): AdminTaxRateController
    {
        $tenants = $this->fixedTenant($tenant);

        return new AdminTaxRateController(
            $this->context,
            new TaxRateService(new TaxRateRepository(), $tenants)
        );
    }

    private function productController(string $tenant): ProductController
    {
        return new ProductController(
            $this->context,
            new ProductRepository(),
            new VariantRepository(),
            $this->fixedTenant($tenant),
            new ProductMediaRepository(),
            new CategoryRepository(),
            new TagRepository(),
            new AttributeRepository(),
            new ProductChildrenRepository(),
            new AddonRepository()
        );
    }

    private function fixedTenant(string $tenant): CurrentTenantResolver
    {
        if ($tenant === '') {
            return new SentinelTenantResolver();
        }

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
        self::assertSame(200, $response->getStatusCode());

        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
