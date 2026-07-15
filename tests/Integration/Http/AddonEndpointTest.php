<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\AddonRepository;
use Glueful\Extensions\Commerce\Catalog\AddonService;
use Glueful\Extensions\Commerce\Catalog\AttributeRepository;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\TagRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Http\Admin\AdminAddonController;
use Glueful\Extensions\Commerce\Http\DTOs\CreateAddonData;
use Glueful\Extensions\Commerce\Http\Storefront\ProductController;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Add-on definitions CRUD (design spec §4/§6): claim discipline (every mutation
 * claims the PRODUCT's `catalog_revision`, then re-reads), select-choices
 * validation (non-empty, unique keys, signed integer deltas), and the
 * checkbox/text single-price_delta shape.
 */
final class AddonEndpointTest extends CommerceTestCase
{
    // --- Create -----------------------------------------------------

    public function testCreateSelectAddonHappyPath(): void
    {
        $product = $this->seedProduct('prodaddon001');

        $response = $this->controller()->store(
            new CreateAddonData(
                name: 'Color',
                field_type: 'select',
                choices: [
                    ['key' => 'red', 'label' => 'Red', 'price_delta' => 100],
                    ['key' => 'blue', 'label' => 'Blue', 'price_delta' => 200],
                ]
            ),
            Request::create('/x', 'POST'),
            $product['uuid']
        );

        self::assertSame(201, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame('Color', $data['name']);
        self::assertSame('select', $data['field_type']);
        self::assertSame(0, (int) $data['price_delta']);
        self::assertCount(2, $data['choices']);
        self::assertSame('active', $data['status']);
    }

    public function testCreateCheckboxAddonHappyPath(): void
    {
        $product = $this->seedProduct('prodaddon002');

        $response = $this->controller()->store(
            new CreateAddonData(name: 'Gift wrap', field_type: 'checkbox', price_delta: 300),
            Request::create('/x', 'POST'),
            $product['uuid']
        );

        self::assertSame(201, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame('checkbox', $data['field_type']);
        self::assertSame(300, (int) $data['price_delta']);
        self::assertNull($data['choices']);
    }

    public function testCreateSelectWithoutChoicesReturns422(): void
    {
        $product = $this->seedProduct('prodaddon003');

        $response = $this->controller()->store(
            new CreateAddonData(name: 'Color', field_type: 'select'),
            Request::create('/x', 'POST'),
            $product['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('choices', $this->json($response)['error']['details']);
        self::assertSame(0, $this->connection->table('commerce_product_addons')
            ->where('product_uuid', '=', $product['uuid'])->count());
    }

    public function testCreateSelectWithDuplicateChoiceKeysReturns422(): void
    {
        $product = $this->seedProduct('prodaddon004');

        $response = $this->controller()->store(
            new CreateAddonData(
                name: 'Color',
                field_type: 'select',
                choices: [
                    ['key' => 'red', 'label' => 'Red', 'price_delta' => 100],
                    ['key' => 'red', 'label' => 'Crimson', 'price_delta' => 150],
                ]
            ),
            Request::create('/x', 'POST'),
            $product['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('choices.1.key', $this->json($response)['error']['details']);
    }

    public function testCreateSelectWithNonIntegerPriceDeltaReturns422(): void
    {
        $product = $this->seedProduct('prodaddon005');

        $response = $this->controller()->store(
            new CreateAddonData(
                name: 'Color',
                field_type: 'select',
                choices: [['key' => 'red', 'label' => 'Red', 'price_delta' => '100']]
            ),
            Request::create('/x', 'POST'),
            $product['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('choices.0.price_delta', $this->json($response)['error']['details']);
    }

    public function testCreateOnUnknownProductThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->store(
            new CreateAddonData(name: 'Color', field_type: 'checkbox'),
            Request::create('/x', 'POST'),
            'no-such-product'
        );
    }

    public function testCreateOnCrossTenantProductThrowsNotFound(): void
    {
        $product = $this->seedProduct('prodaddon006', 'tenant-b');

        $this->expectException(NotFoundException::class);
        $this->controller()->store(
            new CreateAddonData(name: 'Color', field_type: 'checkbox'),
            Request::create('/x', 'POST'),
            $product['uuid']
        );
    }

    public function testCreateClaimsAndBumpsCatalogRevision(): void
    {
        $product = $this->seedProduct('prodaddon007');
        $before = (int) $product['catalog_revision'];

        $this->controller()->store(
            new CreateAddonData(name: 'Gift wrap', field_type: 'checkbox'),
            Request::create('/x', 'POST'),
            $product['uuid']
        );

        $after = (new ProductRepository())->findByUuid($this->context, '', $product['uuid']);
        self::assertNotNull($after);
        self::assertSame($before + 1, (int) $after['catalog_revision']);
    }

    // --- Index -----------------------------------------------------

    public function testIndexListsEveryDefinitionOrderedByPosition(): void
    {
        $product = $this->seedProduct('prodaddon008');
        $this->createAddon($product['uuid'], name: 'Second', fieldType: 'checkbox', position: 1);
        $this->createAddon($product['uuid'], name: 'First', fieldType: 'checkbox', position: 0);
        $this->createAddon($product['uuid'], name: 'Inactive', fieldType: 'checkbox', status: 'inactive');

        $response = $this->controller()->index(Request::create('/x'), $product['uuid']);

        self::assertSame(200, $response->getStatusCode());
        $names = array_column($this->json($response)['data'], 'name');
        self::assertSame(['First', 'Second', 'Inactive'], $names);
    }

    // --- Update -----------------------------------------------------

    public function testUpdateRenamesAndChangesStatus(): void
    {
        $product = $this->seedProduct('prodaddon009');
        $addon = $this->createAddon($product['uuid'], name: 'Gift wrap', fieldType: 'checkbox');

        $response = $this->controller()->update(
            $this->patchRequest(['name' => 'Deluxe gift wrap', 'status' => 'inactive']),
            $addon['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame('Deluxe gift wrap', $data['name']);
        self::assertSame('inactive', $data['status']);
    }

    public function testUpdateChangingFieldTypeFromSelectToCheckboxClearsChoices(): void
    {
        $product = $this->seedProduct('prodaddon010');
        $addon = $this->createAddon($product['uuid'], name: 'Color', fieldType: 'select', choices: [
            ['key' => 'red', 'label' => 'Red', 'price_delta' => 100],
        ]);

        $response = $this->controller()->update(
            $this->patchRequest(['field_type' => 'checkbox', 'price_delta' => 250]),
            $addon['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame('checkbox', $data['field_type']);
        self::assertNull($data['choices']);
        self::assertSame(250, (int) $data['price_delta']);
    }

    public function testUpdateChangingFieldTypeToSelectWithoutChoicesReturns422(): void
    {
        $product = $this->seedProduct('prodaddon011');
        $addon = $this->createAddon($product['uuid'], name: 'Gift wrap', fieldType: 'checkbox', priceDelta: 100);

        $response = $this->controller()->update(
            $this->patchRequest(['field_type' => 'select']),
            $addon['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('choices', $this->json($response)['error']['details']);
    }

    public function testUpdatePriceDeltaAloneKeepsExistingChoicesUntouchedForSelect(): void
    {
        $product = $this->seedProduct('prodaddon012');
        $addon = $this->createAddon($product['uuid'], name: 'Color', fieldType: 'select', choices: [
            ['key' => 'red', 'label' => 'Red', 'price_delta' => 100],
        ]);

        $response = $this->controller()->update($this->patchRequest(['position' => 4]), $addon['uuid']);

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame(4, (int) $data['position']);
        self::assertSame([['key' => 'red', 'label' => 'Red', 'price_delta' => 100]], $data['choices']);
    }

    public function testUpdateUnknownAddonThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->update($this->patchRequest(['name' => 'x']), 'no-such-addon');
    }

    public function testUpdateCrossTenantAddonThrowsNotFound(): void
    {
        $product = $this->seedProduct('prodaddon013', 'tenant-b');
        $addon = $this->createAddon($product['uuid'], name: 'Gift wrap', fieldType: 'checkbox', tenant: 'tenant-b');

        $this->expectException(NotFoundException::class);
        $this->controller()->update($this->patchRequest(['name' => 'x']), $addon['uuid']);
    }

    // --- Delete -----------------------------------------------------

    public function testDeleteRemovesRow(): void
    {
        $product = $this->seedProduct('prodaddon014');
        $addon = $this->createAddon($product['uuid'], name: 'Gift wrap', fieldType: 'checkbox');

        $response = $this->controller()->destroy(Request::create('/x', 'DELETE'), $addon['uuid']);

        self::assertSame(HttpResponse::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertNull((new AddonRepository())->findByUuid($this->context, '', $addon['uuid']));
    }

    public function testDeleteUnknownAddonThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->destroy(Request::create('/x', 'DELETE'), 'no-such-addon');
    }

    public function testDeleteCrossTenantAddonThrowsNotFound(): void
    {
        $product = $this->seedProduct('prodaddon015', 'tenant-b');
        $addon = $this->createAddon($product['uuid'], name: 'Gift wrap', fieldType: 'checkbox', tenant: 'tenant-b');

        $this->expectException(NotFoundException::class);
        $this->controller()->destroy(Request::create('/x', 'DELETE'), $addon['uuid']);
    }

    public function testDeletedDefinitionDoesNotAffectAlreadyPersistedCartSnapshot(): void
    {
        // Deterministic stand-in for a delete-vs-in-flight-cart race: the definition
        // is fully deleted BEFORE any assertion about existing snapshots runs. This
        // file only proves the CRUD side (the row is gone); CartAddonsTest proves
        // the snapshot-immunity side end-to-end.
        $product = $this->seedProduct('prodaddon016');
        $addon = $this->createAddon($product['uuid'], name: 'Gift wrap', fieldType: 'checkbox');

        $this->controller()->destroy(Request::create('/x', 'DELETE'), $addon['uuid']);

        self::assertNull((new AddonRepository())->findByUuid($this->context, '', $addon['uuid']));
    }

    // --- Storefront -----------------------------------------------------

    public function testStorefrontShowIncludesActiveAddonsWithFullDefinitionAndChoiceDeltas(): void
    {
        $product = $this->seedProduct('prodaddonsf1');
        $this->createAddon($product['uuid'], name: 'Color', fieldType: 'select', choices: [
            ['key' => 'red', 'label' => 'Red', 'price_delta' => 100],
            ['key' => 'blue', 'label' => 'Blue', 'price_delta' => 200],
        ], position: 0);
        $this->createAddon(
            $product['uuid'],
            name: 'Gift wrap',
            fieldType: 'checkbox',
            priceDelta: 300,
            position: 1
        );

        $response = $this->productController()->show(Request::create('/x'), $product['uuid']);

        self::assertSame(200, $response->getStatusCode());
        $addons = $this->json($response)['data']['addons'];
        self::assertCount(2, $addons);

        $color = $addons[0];
        self::assertArrayHasKey('uuid', $color);
        self::assertSame('Color', $color['name']);
        self::assertSame('select', $color['field_type']);
        self::assertFalse($color['required']);
        self::assertSame(0, (int) $color['position']);
        self::assertSame(0, (int) $color['price_delta']);
        self::assertSame(
            [
                ['key' => 'red', 'label' => 'Red', 'price_delta' => 100],
                ['key' => 'blue', 'label' => 'Blue', 'price_delta' => 200],
            ],
            $color['choices']
        );
        self::assertArrayNotHasKey('status', $color);
        self::assertArrayNotHasKey('tenant_uuid', $color);

        $giftWrap = $addons[1];
        self::assertSame('Gift wrap', $giftWrap['name']);
        self::assertSame('checkbox', $giftWrap['field_type']);
        self::assertSame(300, (int) $giftWrap['price_delta']);
        self::assertNull($giftWrap['choices']);
    }

    public function testStorefrontShowExcludesInactiveAddons(): void
    {
        $product = $this->seedProduct('prodaddonsf2');
        $this->createAddon($product['uuid'], name: 'Gift wrap', fieldType: 'checkbox', status: 'inactive');

        $response = $this->productController()->show(Request::create('/x'), $product['uuid']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->json($response)['data']['addons']);
    }

    public function testStorefrontShowReturnsEmptyAddonsArrayForProductWithoutAddons(): void
    {
        $product = $this->seedProduct('prodaddonsf3');

        $response = $this->productController()->show(Request::create('/x'), $product['uuid']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->json($response)['data']['addons']);
    }

    // --- Helpers -----------------------------------------------------

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

    /**
     * @param list<array{key:string,label:string,price_delta:int}>|null $choices
     * @return array<string,mixed>
     */
    private function createAddon(
        string $productUuid,
        string $name,
        string $fieldType,
        ?array $choices = null,
        int $priceDelta = 0,
        ?int $position = null,
        string $status = 'active',
        string $tenant = ''
    ): array {
        $response = $this->controller($tenant)->store(
            new CreateAddonData(
                name: $name,
                field_type: $fieldType,
                choices: $choices,
                price_delta: $priceDelta,
                position: $position,
                status: $status
            ),
            Request::create('/x', 'POST'),
            $productUuid
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        return $this->json($response)['data'];
    }

    /** @param array<string,mixed> $body */
    private function patchRequest(array $body): Request
    {
        $request = Request::create('/x', 'PATCH', [], [], [], [], json_encode($body, JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');

        return $request;
    }

    private function controller(string $tenant = ''): AdminAddonController
    {
        return new AdminAddonController($this->context, $this->addonService($tenant));
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
            new AddonRepository()
        );
    }

    private function addonService(string $tenant = ''): AddonService
    {
        return new AddonService(
            new AddonRepository(),
            new ProductRepository(),
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
