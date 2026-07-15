<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\AddonRepository;
use Glueful\Extensions\Commerce\Catalog\AttributeRepository;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\TagRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Http\Admin\AdminProductController;
use Glueful\Extensions\Commerce\Http\DTOs\CreateVariantData;
use Glueful\Extensions\Commerce\Http\DTOs\ProductListQuery;
use Glueful\Extensions\Commerce\Http\Storefront\ProductController;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Shipping\ShippingClassRepository;
use Glueful\Extensions\Commerce\Shipping\ShippingClassService;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Task 3: product `tax_class` + variant `shipping_class_uuid` field wiring
 * end-to-end (design spec §5/§6) -- omission-preserves/explicit-null-clears
 * semantics, open-vocabulary normalization for tax_class, in-tenant existence
 * validation for shipping_class_uuid, and admin+storefront projections carrying
 * BOTH the raw uuid and the resolved slug for variants.
 */
final class VariantShippingClassTest extends CommerceTestCase
{
    // --- Product tax_class: create -----------------------------------------------------

    public function testCreateProductNormalizesTaxClassToLowercase(): void
    {
        $product = $this->catalog()->createProduct($this->context, [
            'slug' => 'tax-class-create',
            'name' => 'Tax Class Create',
            'type' => 'physical',
            'tax_class' => 'DIGITAL_GOODS',
            'variants' => [['sku' => 'TAXCLASSCREATE', 'price' => 1000, 'currency' => 'USD']],
        ]);

        self::assertSame('digital_goods', $product['tax_class']);
    }

    public function testCreateProductOmittingTaxClassDefaultsToNull(): void
    {
        $product = $this->catalog()->createProduct($this->context, [
            'slug' => 'tax-class-omit',
            'name' => 'Tax Class Omit',
            'type' => 'physical',
            'variants' => [['sku' => 'TAXCLASSOMIT', 'price' => 1000, 'currency' => 'USD']],
        ]);

        self::assertNull($product['tax_class']);
    }

    /** @return array<string,array{0:string,1:?string}> */
    public static function taxClassFormatProvider(): array
    {
        return [
            'lowercase' => ['standard', 'standard'],
            'uppercase normalizes' => ['STANDARD', 'standard'],
            'hyphen and underscore' => ['reduced-rate_a', 'reduced-rate_a'],
            'starts with digit rejected' => ['1standard', null],
            'contains space rejected' => ['not valid', null],
            '17 chars rejected' => ['abcdefghijklmnopq', null],
        ];
    }

    /** @dataProvider taxClassFormatProvider */
    public function testCreateProductTaxClassFormatMatrix(string $raw, ?string $expected): void
    {
        $input = [
            'slug' => 'tax-class-fmt-' . substr(md5($raw), 0, 8),
            'name' => 'Tax Class Format',
            'type' => 'physical',
            'tax_class' => $raw,
            'variants' => [['sku' => 'TCF' . substr(md5($raw), 0, 8), 'price' => 1000, 'currency' => 'USD']],
        ];

        if ($expected === null) {
            try {
                $this->catalog()->createProduct($this->context, $input);
                self::fail("tax_class '{$raw}' should have been rejected");
            } catch (ValidationException $e) {
                self::assertArrayHasKey('tax_class', $e->firstErrors());
            }
        } else {
            $product = $this->catalog()->createProduct($this->context, $input);
            self::assertSame($expected, $product['tax_class']);
        }
    }

    // --- Product tax_class: update (omit preserves, explicit null clears) --------------

    public function testUpdateProductTaxClassOmissionPreserves(): void
    {
        $product = $this->seedPhysicalProductWithTaxClass('tax-preserve', 'digital_goods');

        $this->adminController()->update($this->patchRequest(['name' => 'Renamed Only']), $product['uuid']);

        $row = $this->connection->table('commerce_products')->where('uuid', '=', $product['uuid'])->first();
        self::assertSame('digital_goods', $row['tax_class']);
    }

    public function testUpdateProductTaxClassExplicitNullClears(): void
    {
        $product = $this->seedPhysicalProductWithTaxClass('tax-clear', 'digital_goods');

        $response = $this->adminController()->update($this->patchRequest(['tax_class' => null]), $product['uuid']);

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($this->json($response)['data']['tax_class']);
    }

    public function testUpdateProductTaxClassSetsNewValue(): void
    {
        $product = $this->seedPhysicalProductWithTaxClass('tax-set', null);

        $response = $this->adminController()->update(
            $this->patchRequest(['tax_class' => 'REDUCED']),
            $product['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('reduced', $this->json($response)['data']['tax_class']);
    }

    public function testUpdateProductTaxClassInvalidFormatReturns422(): void
    {
        $product = $this->seedPhysicalProductWithTaxClass('tax-invalid', null);

        $response = $this->adminController()->update(
            $this->patchRequest(['tax_class' => 'not valid']),
            $product['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('tax_class', $this->json($response)['error']['details']);
    }

    // --- Variant shipping_class_uuid: create -----------------------------------------------------

    public function testCreateProductVariantWithShippingClassUuidPersistsAndResolvesSlug(): void
    {
        $class = $this->createClass('fragile', 'Fragile');

        $product = $this->catalog()->createProduct($this->context, [
            'slug' => 'ship-class-create',
            'name' => 'Ship Class Create',
            'type' => 'physical',
            'variants' => [[
                'sku' => 'SHIPCLASSCREATE',
                'price' => 1000,
                'currency' => 'USD',
                'shipping_class_uuid' => $class['uuid'],
            ]],
        ]);

        $variant = $product['variants'][0];
        self::assertSame($class['uuid'], $variant['shipping_class_uuid']);
        self::assertSame('fragile', $variant['shipping_class']);
    }

    public function testCreateVariantWithUnknownShippingClassUuidReturns422(): void
    {
        $product = $this->seedPhysicalProduct('ship-class-unknown');

        $response = $this->adminController()->storeVariant(
            new CreateVariantData(
                sku: 'SHIPCLASSUNKNOWNVAR',
                price: 1000,
                currency: 'USD',
                shipping_class_uuid: 'no-such-class'
            ),
            Request::create('/x', 'POST'),
            $product['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('variants.0.shipping_class_uuid', $this->json($response)['error']['details']);
    }

    public function testCreateProductVariantOmittingShippingClassUuidDefaultsToNull(): void
    {
        $product = $this->catalog()->createProduct($this->context, [
            'slug' => 'ship-class-omit',
            'name' => 'Ship Class Omit',
            'type' => 'physical',
            'variants' => [['sku' => 'SHIPCLASSOMIT', 'price' => 1000, 'currency' => 'USD']],
        ]);

        $variant = $product['variants'][0];
        self::assertNull($variant['shipping_class_uuid']);
        self::assertNull($variant['shipping_class']);
    }

    // --- Variant shipping_class_uuid: update (set/clear/omit) -----------------------------------------------------

    public function testUpdateVariantSetsShippingClassUuid(): void
    {
        $class = $this->createClass('oversized', 'Oversized');
        $product = $this->seedPhysicalProduct('ship-class-set');
        $variantUuid = (string) $product['variants'][0]['uuid'];

        $response = $this->adminController()->updateVariant(
            $this->patchRequest(['shipping_class_uuid' => $class['uuid']]),
            $variantUuid
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->json($response)['data'];
        self::assertSame($class['uuid'], $data['shipping_class_uuid']);
        self::assertSame('oversized', $data['shipping_class']);
    }

    public function testUpdateVariantClearsShippingClassUuidExplicitNull(): void
    {
        $class = $this->createClass('fragile', 'Fragile');
        $product = $this->seedPhysicalProductWithVariantClass('ship-class-clear', $class['uuid']);
        $variantUuid = (string) $product['variants'][0]['uuid'];

        $response = $this->adminController()->updateVariant(
            $this->patchRequest(['shipping_class_uuid' => null]),
            $variantUuid
        );

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertNull($data['shipping_class_uuid']);
        self::assertNull($data['shipping_class']);
    }

    public function testUpdateVariantOmittingShippingClassUuidPreservesAssignment(): void
    {
        $class = $this->createClass('fragile', 'Fragile');
        $product = $this->seedPhysicalProductWithVariantClass('ship-class-preserve', $class['uuid']);
        $variantUuid = (string) $product['variants'][0]['uuid'];

        $response = $this->adminController()->updateVariant(
            $this->patchRequest(['price' => 2000]),
            $variantUuid
        );

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame($class['uuid'], $data['shipping_class_uuid']);
        self::assertSame('fragile', $data['shipping_class']);
        self::assertSame(2000, (int) $data['price']);
    }

    public function testUpdateVariantUnknownShippingClassUuidReturns422AndLeavesAssignmentUnchanged(): void
    {
        $class = $this->createClass('fragile', 'Fragile');
        $product = $this->seedPhysicalProductWithVariantClass('ship-class-unknown-up', $class['uuid']);
        $variantUuid = (string) $product['variants'][0]['uuid'];

        $response = $this->adminController()->updateVariant(
            $this->patchRequest(['shipping_class_uuid' => 'no-such-class']),
            $variantUuid
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('shipping_class_uuid', $this->json($response)['error']['details']);

        $row = $this->connection->table('commerce_variants')->where('uuid', '=', $variantUuid)->first();
        self::assertSame($class['uuid'], $row['shipping_class_uuid']);
    }

    public function testUpdateVariantShippingClassAssignmentBumpsProductCatalogRevisionAndClassRevision(): void
    {
        $class = $this->createClass('fragile', 'Fragile');
        $product = $this->seedPhysicalProduct('ship-class-bump');
        $variantUuid = (string) $product['variants'][0]['uuid'];

        $this->adminController()->updateVariant(
            $this->patchRequest(['shipping_class_uuid' => $class['uuid']]),
            $variantUuid
        );

        $productRow = $this->connection->table('commerce_products')->where('uuid', '=', $product['uuid'])->first();
        $classRow = $this->connection->table('commerce_shipping_classes')->where('uuid', '=', $class['uuid'])->first();
        self::assertSame(1, (int) $productRow['catalog_revision']);
        self::assertSame(1, (int) $classRow['revision']);
    }

    public function testUpdateVariantUnknownVariantThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->adminController()->updateVariant(
            $this->patchRequest(['shipping_class_uuid' => null]),
            'no-such-variant'
        );
    }

    // --- Admin + storefront projections both carry uuid AND resolved slug --------------

    public function testAdminShowProjectionCarriesUuidAndResolvedSlug(): void
    {
        $class = $this->createClass('fragile', 'Fragile');
        $product = $this->seedPhysicalProductWithVariantClass('ship-class-admin-show', $class['uuid']);

        $response = $this->adminController()->show(Request::create('/x'), (string) $product['uuid']);

        self::assertSame(200, $response->getStatusCode());
        $variant = $this->json($response)['data']['variants'][0];
        self::assertSame($class['uuid'], $variant['shipping_class_uuid']);
        self::assertSame('fragile', $variant['shipping_class']);
    }

    public function testStorefrontShowProjectionCarriesUuidAndResolvedSlug(): void
    {
        $class = $this->createClass('fragile', 'Fragile');
        $product = $this->seedPhysicalProductWithVariantClass('ship-class-sf-show', $class['uuid']);

        $response = $this->productController()->show(Request::create('/x'), (string) $product['slug']);

        self::assertSame(200, $response->getStatusCode());
        $variant = $this->json($response)['data']['variants'][0];
        self::assertSame($class['uuid'], $variant['shipping_class_uuid']);
        self::assertSame('fragile', $variant['shipping_class']);
    }

    public function testStorefrontIndexProjectionCarriesUuidAndResolvedSlugBatched(): void
    {
        $classA = $this->createClass('fragile', 'Fragile');
        $classB = $this->createClass('oversized', 'Oversized');
        $productA = $this->seedPhysicalProductWithVariantClass('sf-index-a', $classA['uuid']);
        $productB = $this->seedPhysicalProductWithVariantClass('sf-index-b', $classB['uuid']);

        $response = $this->productController()->index(new ProductListQuery());

        self::assertSame(200, $response->getStatusCode());
        $byUuid = [];
        foreach ($this->json($response)['data'] as $item) {
            $byUuid[$item['uuid']] = $item;
        }

        self::assertSame('fragile', $byUuid[$productA['uuid']]['variants'][0]['shipping_class']);
        self::assertSame('oversized', $byUuid[$productB['uuid']]['variants'][0]['shipping_class']);
    }

    // --- Helpers -----------------------------------------------------------------

    /** @return array<string,mixed> */
    private function createClass(string $slug, string $name, string $tenant = ''): array
    {
        $service = new ShippingClassService(
            new ShippingClassRepository(),
            $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant)
        );

        return $service->create($this->context, ['slug' => $slug, 'name' => $name]);
    }

    /** @return array<string,mixed> product with one active physical variant, no shipping class */
    private function seedPhysicalProduct(string $slug, string $tenant = ''): array
    {
        return $this->catalog($tenant)->createProduct($this->context, [
            'slug' => $slug,
            'name' => $slug,
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => strtoupper(str_replace('-', '', $slug)),
                'price' => 1000,
                'currency' => 'USD',
            ]],
        ]);
    }

    /** @return array<string,mixed> */
    private function seedPhysicalProductWithVariantClass(string $slug, string $classUuid, string $tenant = ''): array
    {
        return $this->catalog($tenant)->createProduct($this->context, [
            'slug' => $slug,
            'name' => $slug,
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => strtoupper(str_replace('-', '', $slug)),
                'price' => 1000,
                'currency' => 'USD',
                'shipping_class_uuid' => $classUuid,
            ]],
        ]);
    }

    /** @return array<string,mixed> */
    private function seedPhysicalProductWithTaxClass(string $slug, ?string $taxClass, string $tenant = ''): array
    {
        return $this->catalog($tenant)->createProduct($this->context, [
            'slug' => $slug,
            'name' => $slug,
            'type' => 'physical',
            'status' => 'active',
            'tax_class' => $taxClass,
            'variants' => [[
                'sku' => strtoupper(str_replace('-', '', $slug)),
                'price' => 1000,
                'currency' => 'USD',
            ]],
        ]);
    }

    private function catalog(string $tenant = ''): CatalogService
    {
        return new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant),
            new StockRepository(),
            new ProductChildrenRepository(),
            new ShippingClassRepository()
        );
    }

    private function adminController(string $tenant = ''): AdminProductController
    {
        return new AdminProductController(
            $this->context,
            $this->catalog($tenant),
            new ProductRepository(),
            new VariantRepository(),
            $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant),
            new ShippingClassRepository()
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
            new ProductChildrenRepository(),
            new AddonRepository(),
            new ShippingClassRepository()
        );
    }

    /** @param array<string,mixed> $body */
    private function patchRequest(array $body): Request
    {
        $request = Request::create('/x', 'PATCH', [], [], [], [], json_encode($body, JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');

        return $request;
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
