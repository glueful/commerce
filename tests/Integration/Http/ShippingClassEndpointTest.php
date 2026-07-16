<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Http\Admin\AdminShippingClassController;
use Glueful\Extensions\Commerce\Http\DTOs\CreateShippingClassData;
use Glueful\Extensions\Commerce\Http\DTOs\ShippingClassListQuery;
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
 * Shipping class CRUD (spec §2, §6): create/list, immutable-slug PATCH rejection,
 * slug-format normalization matrix, per-tenant slug reuse, and the class-delete
 * refusal-while-referenced (409) -> succeeds-after-detach flow, plus the
 * deterministic class-delete-vs-variant-assign claim stand-in.
 */
final class ShippingClassEndpointTest extends CommerceTestCase
{
    // --- CRUD -----------------------------------------------------

    public function testCreateClassHappyPath(): void
    {
        $response = $this->controller()->store(
            new CreateShippingClassData(slug: 'fragile', name: 'Fragile'),
            Request::create('/x', 'POST')
        );

        self::assertSame(201, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame('fragile', $data['slug']);
        self::assertSame('Fragile', $data['name']);
        self::assertSame(0, (int) $data['revision']);
    }

    public function testCreateClassNormalizesSlugToLowercase(): void
    {
        $response = $this->controller()->store(
            new CreateShippingClassData(slug: 'FRAGILE', name: 'Fragile'),
            Request::create('/x', 'POST')
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('fragile', $this->json($response)['data']['slug']);
    }

    public function testCreateClassDuplicateSlugSameTenantReturns422(): void
    {
        $this->createClass('fragile', 'Fragile');

        $response = $this->controller()->store(
            new CreateShippingClassData(slug: 'fragile', name: 'Fragile Again'),
            Request::create('/x', 'POST')
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('slug', $this->json($response)['error']['details']);
    }

    public function testCreateClassSameSlugDifferentTenantSucceeds(): void
    {
        $this->createClass('fragile', 'Fragile', 'tenant-b');

        $response = $this->controller()->store(
            new CreateShippingClassData(slug: 'fragile', name: 'Fragile'),
            Request::create('/x', 'POST')
        );

        self::assertSame(201, $response->getStatusCode());
    }

    public function testIndexListsOnlyTenantsOwnClasses(): void
    {
        $this->createClass('fragile', 'Fragile');
        $this->createClass('oversized', 'Oversized');
        $this->createClass('other', 'Other Tenant', 'tenant-b');

        $response = $this->controller()->index(new ShippingClassListQuery(), Request::create('/x'));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $this->json($response)['data']);
    }

    // --- Slug format matrix -----------------------------------------------------

    /** @return array<string,array{0:string,1:?string}> input => expected normalized slug, or null if rejected */
    public static function slugFormatProvider(): array
    {
        return [
            'lowercase letters' => ['fragile', 'fragile'],
            'uppercase normalizes' => ['FRAGILE', 'fragile'],
            'mixed case with hyphen' => ['Over-Sized', 'over-sized'],
            'underscore allowed' => ['over_sized', 'over_sized'],
            'single letter' => ['a', 'a'],
            'leading/trailing whitespace trimmed' => [' fragile ', 'fragile'],
            'exactly 16 chars' => ['abcdefghijklmnop', 'abcdefghijklmnop'],
            'digits after first letter' => ['a1b2c3', 'a1b2c3'],
            'starts with digit rejected' => ['1abc', null],
            'contains space rejected' => ['over sized', null],
            'contains dot rejected' => ['over.sized', null],
            '17 chars rejected' => ['abcdefghijklmnopq', null],
            'empty rejected' => ['', null],
        ];
    }

    /** @dataProvider slugFormatProvider */
    public function testCreateClassSlugFormatMatrix(string $raw, ?string $expected): void
    {
        $response = $this->controller()->store(
            new CreateShippingClassData(slug: $raw, name: 'Test'),
            Request::create('/x', 'POST')
        );

        if ($expected === null) {
            self::assertSame(422, $response->getStatusCode(), "slug '{$raw}' should have been rejected");
            self::assertArrayHasKey('slug', $this->json($response)['error']['details']);
        } else {
            self::assertSame(201, $response->getStatusCode(), "slug '{$raw}' should have been accepted");
            self::assertSame($expected, $this->json($response)['data']['slug']);
        }
    }

    // --- Immutable slug on PATCH -----------------------------------------------------

    public function testUpdateRenamesOnly(): void
    {
        $class = $this->createClass('fragile', 'Fragile');

        $response = $this->controller()->update(
            $this->patchRequest(['name' => 'Very Fragile']),
            $class['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame('Very Fragile', $data['name']);
        self::assertSame('fragile', $data['slug']);
        self::assertSame(1, (int) $data['revision']);
    }

    public function testUpdateRejectsSlugKeyEvenWhenSetToItsOwnCurrentValue(): void
    {
        $class = $this->createClass('fragile', 'Fragile');

        $response = $this->controller()->update(
            $this->patchRequest(['slug' => 'fragile']),
            $class['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('slug', $this->json($response)['error']['details']);
        self::assertSame(
            'fragile',
            $this->connection->table('commerce_shipping_classes')
                ->where('uuid', '=', $class['uuid'])->first()['slug']
        );
    }

    public function testUpdateRejectsSlugKeyAttemptingARename(): void
    {
        $class = $this->createClass('fragile', 'Fragile');

        $response = $this->controller()->update(
            $this->patchRequest(['slug' => 'not-fragile']),
            $class['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('slug', $this->json($response)['error']['details']);
    }

    public function testUpdateUnknownClassThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->update($this->patchRequest(['name' => 'x']), 'no-such-class');
    }

    public function testUpdateCrossTenantClassThrowsNotFound(): void
    {
        $class = $this->createClass('fragile', 'Fragile', 'tenant-b');

        $this->expectException(NotFoundException::class);
        $this->controller()->update($this->patchRequest(['name' => 'x']), $class['uuid']);
    }

    // --- Delete: referenced refusal, then success after detach -----------------------------------------------------

    public function testDeleteUnknownClassThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->destroy(Request::create('/x', 'DELETE'), 'no-such-class');
    }

    public function testDeleteCrossTenantClassThrowsNotFound(): void
    {
        $class = $this->createClass('fragile', 'Fragile', 'tenant-b');

        $this->expectException(NotFoundException::class);
        $this->controller()->destroy(Request::create('/x', 'DELETE'), $class['uuid']);
    }

    public function testDeleteUnreferencedClassSucceeds(): void
    {
        $class = $this->createClass('fragile', 'Fragile');

        $response = $this->controller()->destroy(Request::create('/x', 'DELETE'), $class['uuid']);

        self::assertSame(HttpResponse::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertNull((new ShippingClassRepository())->findByUuid($this->context, '', $class['uuid']));
    }

    public function testDeleteReferencedClassReturns409ThenSucceedsAfterDetach(): void
    {
        $class = $this->createClass('fragile', 'Fragile');
        $variantUuid = $this->seedVariantDirect($class['uuid']);

        $refused = $this->controller()->destroy(Request::create('/x', 'DELETE'), $class['uuid']);
        self::assertSame(409, $refused->getStatusCode());
        self::assertNotNull((new ShippingClassRepository())->findByUuid($this->context, '', $class['uuid']));

        $this->connection->table('commerce_variants')
            ->where('uuid', '=', $variantUuid)
            ->update(['shipping_class_uuid' => null]);

        $succeeded = $this->controller()->destroy(Request::create('/x', 'DELETE'), $class['uuid']);
        self::assertSame(HttpResponse::HTTP_NO_CONTENT, $succeeded->getStatusCode());
        self::assertNull((new ShippingClassRepository())->findByUuid($this->context, '', $class['uuid']));
    }

    /**
     * Deterministic stand-in for the class-delete-vs-variant-assign race (mirrors
     * AttributeEndpointTest::testSetProductAttributesWithAttributeDeletedConcurrentlyReturns422()):
     * the class is fully deleted (claim+re-check+delete, one committed
     * transaction, and nothing references it yet so the delete succeeds) BEFORE a
     * variant assignment attempt names it -- exactly as it would appear to a
     * racing assignment that loses the interleave. The assignment's own claim on
     * the now-gone class row affects zero rows, so it must resolve as 422, never
     * silently succeed with a dangling shipping_class_uuid.
     */
    public function testClassDeleteThenVariantAssignReturnsUnprocessable(): void
    {
        $class = $this->createClass('fragile', 'Fragile');
        $this->controller()->destroy(Request::create('/x', 'DELETE'), $class['uuid']);

        $catalog = $this->catalog();
        $product = $catalog->createProduct($this->context, [
            'slug' => 'race-product',
            'name' => 'Race Product',
            'type' => 'physical',
            'variants' => [[
                'sku' => 'RACEPRODUCT1',
                'price' => 1000,
                'currency' => 'USD',
            ]],
        ]);
        $variantUuid = (string) $product['variants'][0]['uuid'];

        try {
            $catalog->updateVariant($this->context, $variantUuid, ['shipping_class_uuid' => $class['uuid']]);
            self::fail('Assigning a deleted shipping class must fail.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('shipping_class_uuid', $e->firstErrors());
        }

        $row = $this->connection->table('commerce_variants')->where('uuid', '=', $variantUuid)->first();
        self::assertNull($row['shipping_class_uuid']);
    }

    // --- Helpers -----------------------------------------------------

    /** @return array<string,mixed> */
    private function createClass(string $slug, string $name, string $tenant = ''): array
    {
        $response = $this->controller($tenant)->store(
            new CreateShippingClassData(slug: $slug, name: $name),
            Request::create('/x', 'POST')
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        return $this->json($response)['data'];
    }

    /** Direct row insert -- a physical variant referencing the given shipping class. */
    private function seedVariantDirect(string $classUuid): string
    {
        $variantUuid = substr('v' . md5($classUuid . 'ref'), 0, 12);
        $this->connection->table('commerce_products')->insert([
            'uuid' => 'prodclsref01',
            'tenant_uuid' => '',
            'slug' => 'cls-ref-product',
            'name' => 'Class Ref Product',
            'type' => 'physical',
            'status' => 'active',
        ]);
        $this->connection->table('commerce_variants')->insert([
            'uuid' => $variantUuid,
            'tenant_uuid' => '',
            'product_uuid' => 'prodclsref01',
            'sku' => 'CLSREFSKU01',
            'option_values' => json_encode([], JSON_THROW_ON_ERROR),
            'price' => 1000,
            'currency' => 'USD',
            'position' => 0,
            'status' => 'active',
            'shipping_class_uuid' => $classUuid,
        ]);

        return $variantUuid;
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

    /** @param array<string,mixed> $body */
    private function patchRequest(array $body): Request
    {
        $request = Request::create('/x', 'PATCH', [], [], [], [], json_encode($body, JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');

        return $request;
    }

    private function controller(string $tenant = ''): AdminShippingClassController
    {
        return new AdminShippingClassController($this->context, $this->classService($tenant));
    }

    private function classService(string $tenant = ''): ShippingClassService
    {
        return new ShippingClassService(
            new ShippingClassRepository(),
            $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant)
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
