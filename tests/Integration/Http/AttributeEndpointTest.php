<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\AttributeRepository;
use Glueful\Extensions\Commerce\Catalog\AttributeService;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\TagRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Http\Admin\AdminAttributeController;
use Glueful\Extensions\Commerce\Http\DTOs\CreateAttributeData;
use Glueful\Extensions\Commerce\Http\DTOs\CreateAttributeValueData;
use Glueful\Extensions\Commerce\Http\DTOs\SetProductAttributesData;
use Glueful\Extensions\Commerce\Http\Storefront\ProductController;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class AttributeEndpointTest extends CommerceTestCase
{
    // --- Attribute CRUD -----------------------------------------------------

    public function testCreateAttributeHappyPath(): void
    {
        $response = $this->controller()->store(
            new CreateAttributeData(slug: 'color', name: 'Color'),
            Request::create('/x', 'POST')
        );

        self::assertSame(201, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame('color', $data['slug']);
        self::assertSame('Color', $data['name']);
        self::assertSame(0, (int) $data['revision']);
        self::assertSame([], $data['values']);
    }

    public function testCreateDuplicateSlugSameTenantReturns422(): void
    {
        $this->createAttribute('color', 'Color');

        $response = $this->controller()->store(
            new CreateAttributeData(slug: 'color', name: 'Color Duplicate'),
            Request::create('/x', 'POST')
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('slug', $this->json($response)['error']['details']);
    }

    public function testCreateSameSlugDifferentTenantSucceeds(): void
    {
        $this->createAttribute('color', 'Color', 'tenant-b');

        $response = $this->controller()->store(
            new CreateAttributeData(slug: 'color', name: 'Color'),
            Request::create('/x', 'POST')
        );

        self::assertSame(201, $response->getStatusCode());
    }

    public function testIndexListsAllAttributesForTenant(): void
    {
        $this->createAttribute('color', 'Color');
        $this->createAttribute('size', 'Size');
        $this->createAttribute('other-tenant-attr', 'Other', 'tenant-b');

        $response = $this->controller()->index(Request::create('/x'));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $this->json($response)['data']);
    }

    public function testUpdateRenamesFields(): void
    {
        $attribute = $this->createAttribute('color', 'Color');

        $response = $this->controller()->update(
            $this->patchRequest(['name' => 'Colour', 'position' => 3]),
            $attribute['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame('Colour', $data['name']);
        self::assertSame(3, (int) $data['position']);
        self::assertSame(1, (int) $data['revision']);
    }

    public function testUpdateSlugConflictReturns422(): void
    {
        $this->createAttribute('color', 'Color');
        $other = $this->createAttribute('size', 'Size');

        $response = $this->controller()->update(
            $this->patchRequest(['slug' => 'color']),
            $other['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('slug', $this->json($response)['error']['details']);
    }

    public function testUpdateUnknownAttributeThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->update($this->patchRequest(['name' => 'x']), 'no-such-attribute');
    }

    public function testUpdateCrossTenantAttributeThrowsNotFound(): void
    {
        $attribute = $this->createAttribute('color', 'Color', 'tenant-b');

        $this->expectException(NotFoundException::class);
        $this->controller()->update($this->patchRequest(['name' => 'x']), $attribute['uuid']);
    }

    public function testDeleteUnknownAttributeThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->destroy(Request::create('/x', 'DELETE'), 'no-such-attribute');
    }

    public function testDeleteCrossTenantAttributeThrowsNotFound(): void
    {
        $attribute = $this->createAttribute('color', 'Color', 'tenant-b');

        $this->expectException(NotFoundException::class);
        $this->controller()->destroy(Request::create('/x', 'DELETE'), $attribute['uuid']);
    }

    // --- Value CRUD -----------------------------------------------------

    public function testCreateValueHappyPath(): void
    {
        $attribute = $this->createAttribute('color', 'Color');

        $response = $this->controller()->storeValue(
            new CreateAttributeValueData(slug: 'red', value: 'Red'),
            Request::create('/x', 'POST'),
            $attribute['uuid']
        );

        self::assertSame(201, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame('red', $data['slug']);
        self::assertSame('Red', $data['value']);
        self::assertSame($attribute['uuid'], $data['attribute_uuid']);
    }

    public function testCreateValueDuplicateSlugSameAttributeReturns422(): void
    {
        $attribute = $this->createAttribute('color', 'Color');
        $this->createValue($attribute['uuid'], 'red', 'Red');

        $response = $this->controller()->storeValue(
            new CreateAttributeValueData(slug: 'red', value: 'Crimson'),
            Request::create('/x', 'POST'),
            $attribute['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('slug', $this->json($response)['error']['details']);
    }

    public function testCreateValueSameSlugDifferentAttributeSucceeds(): void
    {
        $a = $this->createAttribute('color', 'Color');
        $b = $this->createAttribute('finish', 'Finish');
        $this->createValue($a['uuid'], 'matte', 'Matte');

        $response = $this->controller()->storeValue(
            new CreateAttributeValueData(slug: 'matte', value: 'Matte'),
            Request::create('/x', 'POST'),
            $b['uuid']
        );

        self::assertSame(201, $response->getStatusCode());
    }

    public function testCreateValueOnUnknownAttributeThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->storeValue(
            new CreateAttributeValueData(slug: 'red', value: 'Red'),
            Request::create('/x', 'POST'),
            'no-such-attribute'
        );
    }

    public function testCreateValueOnCrossTenantAttributeThrowsNotFound(): void
    {
        $attribute = $this->createAttribute('color', 'Color', 'tenant-b');

        $this->expectException(NotFoundException::class);
        $this->controller()->storeValue(
            new CreateAttributeValueData(slug: 'red', value: 'Red'),
            Request::create('/x', 'POST'),
            $attribute['uuid']
        );
    }

    public function testValueListOrderedByPosition(): void
    {
        $attribute = $this->createAttribute('color', 'Color');
        $this->createValue($attribute['uuid'], 'blue', 'Blue', 1);
        $this->createValue($attribute['uuid'], 'red', 'Red', 0);

        $response = $this->controller()->index(Request::create('/x'));

        $found = null;
        foreach ($this->json($response)['data'] as $row) {
            if ($row['uuid'] === $attribute['uuid']) {
                $found = $row;
            }
        }
        self::assertNotNull($found);
        self::assertSame(['red', 'blue'], array_column($found['values'], 'slug'));
    }

    public function testUpdateValueChangesFields(): void
    {
        $attribute = $this->createAttribute('color', 'Color');
        $value = $this->createValue($attribute['uuid'], 'red', 'Red');

        $response = $this->controller()->updateValue(
            $this->patchRequest(['value' => 'Crimson', 'position' => 5]),
            $value['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame('Crimson', $data['value']);
        self::assertSame(5, (int) $data['position']);
    }

    public function testUpdateValueSlugConflictReturns422(): void
    {
        $attribute = $this->createAttribute('color', 'Color');
        $this->createValue($attribute['uuid'], 'red', 'Red');
        $blue = $this->createValue($attribute['uuid'], 'blue', 'Blue');

        $response = $this->controller()->updateValue(
            $this->patchRequest(['slug' => 'red']),
            $blue['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('slug', $this->json($response)['error']['details']);
    }

    public function testUpdateUnknownValueThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->updateValue($this->patchRequest(['value' => 'x']), 'no-such-value');
    }

    public function testUpdateCrossTenantValueThrowsNotFound(): void
    {
        $attribute = $this->createAttribute('color', 'Color', 'tenant-b');
        $value = $this->createValue($attribute['uuid'], 'red', 'Red', null, 'tenant-b');

        $this->expectException(NotFoundException::class);
        $this->controller()->updateValue($this->patchRequest(['value' => 'x']), $value['uuid']);
    }

    public function testDeleteValueRemovesRow(): void
    {
        $attribute = $this->createAttribute('color', 'Color');
        $value = $this->createValue($attribute['uuid'], 'red', 'Red');

        $response = $this->controller()->destroyValue(Request::create('/x', 'DELETE'), $value['uuid']);

        self::assertSame(HttpResponse::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertNull((new AttributeRepository())->findValueByUuid($this->context, $value['uuid']));
    }

    public function testDeleteUnknownValueThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->destroyValue(Request::create('/x', 'DELETE'), 'no-such-value');
    }

    public function testDeleteCrossTenantValueThrowsNotFound(): void
    {
        $attribute = $this->createAttribute('color', 'Color', 'tenant-b');
        $value = $this->createValue($attribute['uuid'], 'red', 'Red', null, 'tenant-b');

        $this->expectException(NotFoundException::class);
        $this->controller()->destroyValue(Request::create('/x', 'DELETE'), $value['uuid']);
    }

    // --- Attribute delete cascades -----------------------------------------------------

    public function testDeleteAttributeCascadesValuesAndProductAssignments(): void
    {
        $attribute = $this->createAttribute('color', 'Color');
        $this->createValue($attribute['uuid'], 'red', 'Red');
        $product = $this->seedProduct('prodattrcas1');
        $this->assignAttribute($product['uuid'], $attribute['uuid'], ['red']);

        $response = $this->controller()->destroy(Request::create('/x', 'DELETE'), $attribute['uuid']);

        self::assertSame(HttpResponse::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertNull((new AttributeRepository())->findByUuid($this->context, '', $attribute['uuid']));
        self::assertSame(
            [],
            (new AttributeRepository())->valuesForAttribute($this->context, $attribute['uuid'])
        );
        self::assertSame(0, $this->connection->table('commerce_product_attributes')
            ->where('product_uuid', '=', $product['uuid'])->count());
    }

    // --- Product assignment set-list -----------------------------------------------------

    public function testSetProductAttributesHappyPathMixOfGlobalAndCustomRows(): void
    {
        $product = $this->seedProduct('prodattr0001');
        $attribute = $this->createAttribute('color', 'Color');
        $this->createValue($attribute['uuid'], 'red', 'Red');
        $this->createValue($attribute['uuid'], 'blue', 'Blue');

        $response = $this->controller()->setForProduct(
            new SetProductAttributesData(attributes: [
                ['attribute_uuid' => $attribute['uuid'], 'values' => ['red', 'blue']],
                ['name' => 'Care Note', 'values' => ['Hand wash only']],
            ]),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        $rows = $this->json($response)['data'];
        self::assertCount(2, $rows);

        $global = $rows[0];
        self::assertSame($attribute['uuid'], $global['attribute_uuid']);
        self::assertSame('color', $global['attribute_slug']);
        self::assertSame('Color', $global['attribute_name']);
        self::assertSame(['red', 'blue'], $global['values']);

        $custom = $rows[1];
        self::assertNull($custom['attribute_uuid']);
        self::assertSame('Care Note', $custom['name']);
        self::assertSame(['Hand wash only'], $custom['values']);
    }

    public function testSetProductAttributesInvalidValueSlugReturns422(): void
    {
        $product = $this->seedProduct('prodattr0002');
        $attribute = $this->createAttribute('color', 'Color');
        $this->createValue($attribute['uuid'], 'red', 'Red');

        $response = $this->controller()->setForProduct(
            new SetProductAttributesData(attributes: [
                ['attribute_uuid' => $attribute['uuid'], 'values' => ['green']],
            ]),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('attributes.0.values.0', $this->json($response)['error']['details']);
        self::assertSame(0, $this->connection->table('commerce_product_attributes')
            ->where('product_uuid', '=', $product['uuid'])->count());
    }

    /**
     * Delete-all/insert-all rollback coverage: unlike
     * testSetProductAttributesInvalidValueSlugReturns422() (an empty product, which
     * can't distinguish "never touched" from "touched then rolled back"), this seeds
     * the product with a real, valid mix of a global and a custom row first, THEN
     * submits a replacement payload containing one invalid row. The 422 must leave
     * the pre-existing rows byte-identical -- content AND position -- proving the
     * set-list replacement transaction doesn't destroy prior data when the proposed
     * replacement fails.
     */
    public function testSetProductAttributesInvalidValueSlugPreservesExistingAssignmentsOnRollback(): void
    {
        $product = $this->seedProduct('prodattr0009');
        $attribute = $this->createAttribute('color', 'Color');
        $this->createValue($attribute['uuid'], 'red', 'Red');
        $this->createValue($attribute['uuid'], 'blue', 'Blue');

        $initial = $this->controller()->setForProduct(
            new SetProductAttributesData(attributes: [
                ['attribute_uuid' => $attribute['uuid'], 'values' => ['red', 'blue'], 'position' => 0],
                ['name' => 'Care Note', 'values' => ['Hand wash only'], 'position' => 1],
            ]),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );
        self::assertSame(200, $initial->getStatusCode());

        $before = $this->connection->table('commerce_product_attributes')
            ->where('product_uuid', '=', $product['uuid'])
            ->orderBy('position', 'ASC')
            ->get();
        self::assertCount(2, $before);

        $response = $this->controller()->setForProduct(
            new SetProductAttributesData(attributes: [
                ['attribute_uuid' => $attribute['uuid'], 'values' => ['green']],
            ]),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('attributes.0.values.0', $this->json($response)['error']['details']);

        $after = $this->connection->table('commerce_product_attributes')
            ->where('product_uuid', '=', $product['uuid'])
            ->orderBy('position', 'ASC')
            ->get();

        self::assertSame($before, $after, 'A failed set-list must leave pre-existing assignments untouched.');
    }

    public function testSetProductAttributesUnknownAttributeReturns422(): void
    {
        $product = $this->seedProduct('prodattr0003');

        $response = $this->controller()->setForProduct(
            new SetProductAttributesData(attributes: [
                ['attribute_uuid' => 'no-such-attribute', 'values' => []],
            ]),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('attributes', $this->json($response)['error']['details']);
        self::assertSame(0, $this->connection->table('commerce_product_attributes')
            ->where('product_uuid', '=', $product['uuid'])->count());
    }

    public function testSetProductAttributesCustomRowWithoutNameReturns422(): void
    {
        $product = $this->seedProduct('prodattr0007');

        $response = $this->controller()->setForProduct(
            new SetProductAttributesData(attributes: [
                ['values' => ['x']],
            ]),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('attributes.0.name', $this->json($response)['error']['details']);
    }

    public function testSetProductAttributesDuplicateGlobalRowsInOnePayloadReturns422(): void
    {
        $product = $this->seedProduct('prodattr0004');
        $attribute = $this->createAttribute('color', 'Color');
        $this->createValue($attribute['uuid'], 'red', 'Red');

        $response = $this->controller()->setForProduct(
            new SetProductAttributesData(attributes: [
                ['attribute_uuid' => $attribute['uuid'], 'values' => ['red']],
                ['attribute_uuid' => $attribute['uuid'], 'values' => ['red']],
            ]),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('attributes', $this->json($response)['error']['details']);
        self::assertSame(0, $this->connection->table('commerce_product_attributes')
            ->where('product_uuid', '=', $product['uuid'])->count());
    }

    public function testSetProductAttributesIdempotentReplacementProducesSameContent(): void
    {
        $product = $this->seedProduct('prodattr0005');
        $attribute = $this->createAttribute('color', 'Color');
        $this->createValue($attribute['uuid'], 'red', 'Red');
        $this->createValue($attribute['uuid'], 'blue', 'Blue');

        $payload = [
            ['attribute_uuid' => $attribute['uuid'], 'values' => ['red']],
            ['name' => 'Care Note', 'values' => ['Hand wash only']],
        ];

        $first = $this->controller()->setForProduct(
            new SetProductAttributesData(attributes: $payload),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );
        self::assertSame(200, $first->getStatusCode());

        $second = $this->controller()->setForProduct(
            new SetProductAttributesData(attributes: $payload),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );
        self::assertSame(200, $second->getStatusCode());

        self::assertSame(
            $this->withoutUuid($this->json($first)['data']),
            $this->withoutUuid($this->json($second)['data'])
        );
        self::assertSame(2, $this->connection->table('commerce_product_attributes')
            ->where('product_uuid', '=', $product['uuid'])->count());
    }

    public function testSetProductAttributesRemovesUnlistedAttributes(): void
    {
        $product = $this->seedProduct('prodattr0006');
        $a = $this->createAttribute('color', 'Color');
        $this->createValue($a['uuid'], 'red', 'Red');

        $this->controller()->setForProduct(
            new SetProductAttributesData(attributes: [
                ['attribute_uuid' => $a['uuid'], 'values' => ['red']],
                ['name' => 'Temp Note', 'values' => ['x']],
            ]),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        $response = $this->controller()->setForProduct(
            new SetProductAttributesData(attributes: []),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->json($response)['data']);
        self::assertSame(0, $this->connection->table('commerce_product_attributes')
            ->where('product_uuid', '=', $product['uuid'])->count());
    }

    public function testSetProductAttributesUnknownProductThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->setForProduct(
            new SetProductAttributesData(attributes: []),
            Request::create('/x', 'PUT'),
            'no-such-product'
        );
    }

    public function testSetProductAttributesWithAttributeDeletedConcurrentlyReturns422(): void
    {
        // Deterministic stand-in for an attribute-delete-vs-assignment race: the
        // attribute is fully deleted (claim+cascade+delete, one committed
        // transaction) BEFORE the assignment call runs, exactly as it would appear
        // to a racing assignment that loses the interleave. Assignment must resolve
        // it as unknown, not create a dangling join row.
        $product = $this->seedProduct('prodattr0008');
        $attribute = $this->createAttribute('color', 'Color');
        $this->createValue($attribute['uuid'], 'red', 'Red');
        $this->controller()->destroy(Request::create('/x', 'DELETE'), $attribute['uuid']);

        $response = $this->controller()->setForProduct(
            new SetProductAttributesData(attributes: [
                ['attribute_uuid' => $attribute['uuid'], 'values' => ['red']],
            ]),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->connection->table('commerce_product_attributes')
            ->where('product_uuid', '=', $product['uuid'])->count());
    }

    // --- Storefront -----------------------------------------------------

    public function testStorefrontShowEchoesVisibleAttributesOnlyWithGlobalAndCustomShapes(): void
    {
        $product = $this->seedProduct('prodattrsf01');
        $attribute = $this->createAttribute('color', 'Color');
        $this->createValue($attribute['uuid'], 'red', 'Red');

        $this->controller()->setForProduct(
            new SetProductAttributesData(attributes: [
                ['attribute_uuid' => $attribute['uuid'], 'values' => ['red'], 'visible' => true],
                ['name' => 'Care Note', 'values' => ['Hand wash only'], 'visible' => true],
                ['name' => 'Hidden Note', 'values' => ['secret'], 'visible' => false],
            ]),
            Request::create('/x', 'PUT'),
            $product['uuid']
        );

        $response = $this->productController()->show(Request::create('/x'), $product['uuid']);

        self::assertSame(200, $response->getStatusCode());
        $attributes = $this->json($response)['data']['attributes'];
        self::assertCount(2, $attributes);

        $global = $attributes[0];
        self::assertSame('color', $global['slug']);
        self::assertSame('Color', $global['name']);
        self::assertSame(['red'], $global['values']);

        $custom = $attributes[1];
        self::assertSame('Care Note', $custom['name']);
        self::assertSame(['Hand wash only'], $custom['values']);
        self::assertArrayNotHasKey('slug', $custom);

        foreach ($attributes as $row) {
            self::assertNotSame('Hidden Note', $row['name'] ?? null);
        }
    }

    // --- Helpers -----------------------------------------------------

    /** @return array<string,mixed> */
    private function createAttribute(string $slug, string $name, string $tenant = ''): array
    {
        $response = $this->controller($tenant)->store(
            new CreateAttributeData(slug: $slug, name: $name),
            Request::create('/x', 'POST')
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        return $this->json($response)['data'];
    }

    /** @return array<string,mixed> */
    private function createValue(
        string $attributeUuid,
        string $slug,
        string $value,
        ?int $position = null,
        string $tenant = ''
    ): array {
        $response = $this->controller($tenant)->storeValue(
            new CreateAttributeValueData(slug: $slug, value: $value, position: $position),
            Request::create('/x', 'POST'),
            $attributeUuid
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        return $this->json($response)['data'];
    }

    private function assignAttribute(string $productUuid, string $attributeUuid, array $values): void
    {
        $this->connection->table('commerce_product_attributes')->insert([
            'uuid' => 'pa' . substr(md5($productUuid . $attributeUuid), 0, 10),
            'product_uuid' => $productUuid,
            'attribute_uuid' => $attributeUuid,
            'name' => null,
            'values' => json_encode($values, JSON_THROW_ON_ERROR),
            'used_for_variants' => false,
            'visible' => true,
            'position' => 0,
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

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function withoutUuid(array $rows): array
    {
        return array_map(static function (array $row): array {
            unset($row['uuid'], $row['id']);
            return $row;
        }, $rows);
    }

    private function controller(string $tenant = ''): AdminAttributeController
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
