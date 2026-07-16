<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Cart\CartRepository;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Catalog\AddonRepository;
use Glueful\Extensions\Commerce\Catalog\AttributeRepository;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Catalog\ProductChildrenRepository;
use Glueful\Extensions\Commerce\Catalog\ProductMediaRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\TagRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Http\Admin\AdminProductController;
use Glueful\Extensions\Commerce\Http\DTOs\AddCartLineData;
use Glueful\Extensions\Commerce\Http\DTOs\CreateProductData;
use Glueful\Extensions\Commerce\Http\DTOs\CreateVariantData;
use Glueful\Extensions\Commerce\Http\DTOs\ProductVariantData;
use Glueful\Extensions\Commerce\Http\DTOs\SetProductChildrenData;
use Glueful\Extensions\Commerce\Http\Storefront\CartController;
use Glueful\Extensions\Commerce\Http\Storefront\ProductController;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Task 5: product types (external/grouped) + grouped-product children.
 *
 * Covers: type-vs-variant creation gating (CatalogService::planCreationVariants),
 * external metadata validation, storeVariant rejection for external/grouped,
 * children set-list (claim/re-read discipline, ordering, idempotent replace,
 * per-child validation), type immutability once variants/children/cart-order
 * references exist, the CartService::addLine defense-in-depth gate, and
 * storefront `children`/`external` echo.
 */
final class ProductTypesTest extends CommerceTestCase
{
    // --- Creation: type gates variant requirements ---------------------------

    public function testCreateExternalProductWithZeroVariantsSucceeds(): void
    {
        $response = $this->adminController()->store(
            new CreateProductData(
                slug: 'ext-lamp',
                name: 'External Lamp',
                type: 'external',
                status: 'active',
                metadata: ['external_url' => 'https://vendor.example.com/lamp'],
                variants: []
            ),
            Request::create('/x', 'POST')
        );

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->json($response)['data'];
        self::assertSame('external', $data['type']);
        self::assertSame([], $data['variants']);
    }

    public function testCreateExternalProductRequiresExternalUrl(): void
    {
        $response = $this->adminController()->store(
            new CreateProductData(
                slug: 'ext-no-url',
                name: 'No URL',
                type: 'external',
                status: 'active',
                metadata: [],
                variants: []
            ),
            Request::create('/x', 'POST')
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('metadata.external_url', $this->json($response)['error']['details']);
    }

    public function testCreateExternalProductRejectsBadScheme(): void
    {
        $response = $this->adminController()->store(
            new CreateProductData(
                slug: 'ext-bad-scheme',
                name: 'Bad Scheme',
                type: 'external',
                status: 'active',
                metadata: ['external_url' => 'ftp://vendor.example.com/lamp'],
                variants: []
            ),
            Request::create('/x', 'POST')
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('metadata.external_url', $this->json($response)['error']['details']);
    }

    public function testCreateExternalProductAcceptsOptionalButtonLabel(): void
    {
        $response = $this->adminController()->store(
            new CreateProductData(
                slug: 'ext-with-label',
                name: 'With Label',
                type: 'external',
                status: 'active',
                metadata: ['external_url' => 'https://vendor.example.com/x', 'button_label' => 'Buy on Vendor'],
                variants: []
            ),
            Request::create('/x', 'POST')
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('Buy on Vendor', $this->json($response)['data']['metadata']['button_label']);
    }

    public function testCreateGroupedProductWithZeroVariantsSucceeds(): void
    {
        $response = $this->adminController()->store(
            new CreateProductData(
                slug: 'bundle-1',
                name: 'Bundle One',
                type: 'grouped',
                status: 'active',
                variants: []
            ),
            Request::create('/x', 'POST')
        );

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([], $this->json($response)['data']['variants']);
    }

    public function testCreateExternalProductWithVariantsRejected(): void
    {
        $response = $this->adminController()->store(
            new CreateProductData(
                slug: 'ext-with-variant',
                name: 'External With Variant',
                type: 'external',
                status: 'active',
                metadata: ['external_url' => 'https://vendor.example.com/x'],
                variants: [new ProductVariantData(sku: 'EXTV1', price: 100, currency: 'USD')]
            ),
            Request::create('/x', 'POST')
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('variants', $this->json($response)['error']['details']);
    }

    public function testCreateGroupedProductWithVariantsRejected(): void
    {
        $response = $this->adminController()->store(
            new CreateProductData(
                slug: 'grouped-with-variant',
                name: 'Grouped With Variant',
                type: 'grouped',
                status: 'active',
                variants: [new ProductVariantData(sku: 'GRPV1', price: 100, currency: 'USD')]
            ),
            Request::create('/x', 'POST')
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('variants', $this->json($response)['error']['details']);
    }

    public function testPhysicalProductStillRequiresAtLeastOneVariant(): void
    {
        $response = $this->adminController()->store(
            new CreateProductData(slug: 'physical-empty', name: 'Physical Empty', type: 'physical', variants: []),
            Request::create('/x', 'POST')
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('variants', $this->json($response)['error']['details']);
    }

    public function testCreateProductRejectsUnknownType(): void
    {
        $response = $this->adminController()->store(
            new CreateProductData(slug: 'bogus-type', name: 'Bogus', type: 'subscription', variants: []),
            Request::create('/x', 'POST')
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('type', $this->json($response)['error']['details']);
    }

    // --- storeVariant rejected for external/grouped ---------------------------

    public function testStoreVariantRejectedForExternalProduct(): void
    {
        $product = $this->seedExternalProduct('ext-novariant');

        $response = $this->adminController()->storeVariant(
            new CreateVariantData(sku: 'SHOULDFAIL', price: 100, currency: 'USD'),
            Request::create('/x', 'POST'),
            $product['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->connection->table('commerce_variants')
            ->where('product_uuid', '=', $product['uuid'])->count());
    }

    public function testStoreVariantRejectedForGroupedProduct(): void
    {
        $product = $this->seedGroupedProduct('grp-novariant');

        $response = $this->adminController()->storeVariant(
            new CreateVariantData(sku: 'SHOULDFAIL2', price: 100, currency: 'USD'),
            Request::create('/x', 'POST'),
            $product['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
    }

    // --- Children set-list: happy path, ordering, idempotence -----------------

    public function testSetChildrenHappyPathReturnsChildrenInSubmittedOrder(): void
    {
        $parent = $this->seedGroupedProduct('grp-parent-1');
        $childA = $this->seedPhysicalProduct('child-a');
        $childB = $this->seedDigitalProduct('child-b');

        $response = $this->adminController()->setChildren(
            new SetProductChildrenData(child_uuids: [$childB['uuid'], $childA['uuid']]),
            Request::create('/x', 'PUT'),
            $parent['uuid']
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $rows = $this->json($response)['data'];
        self::assertSame([$childB['uuid'], $childA['uuid']], array_column($rows, 'uuid'));

        $stored = $this->connection->table('commerce_product_children')
            ->where('product_uuid', '=', $parent['uuid'])
            ->orderBy('position', 'ASC')
            ->get();
        self::assertSame([$childB['uuid'], $childA['uuid']], array_column($stored, 'child_uuid'));
        self::assertSame([0, 1], array_map('intval', array_column($stored, 'position')));
    }

    public function testSetChildrenIdempotentReplacementProducesSameContent(): void
    {
        $parent = $this->seedGroupedProduct('grp-parent-2');
        $child = $this->seedPhysicalProduct('child-idem');

        $first = $this->adminController()->setChildren(
            new SetProductChildrenData(child_uuids: [$child['uuid']]),
            Request::create('/x', 'PUT'),
            $parent['uuid']
        );
        $second = $this->adminController()->setChildren(
            new SetProductChildrenData(child_uuids: [$child['uuid']]),
            Request::create('/x', 'PUT'),
            $parent['uuid']
        );

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
        self::assertSame(
            array_column($this->json($first)['data'], 'uuid'),
            array_column($this->json($second)['data'], 'uuid')
        );
        self::assertSame(1, $this->connection->table('commerce_product_children')
            ->where('product_uuid', '=', $parent['uuid'])->count());
    }

    public function testSetChildrenRemovesUnlistedChildren(): void
    {
        $parent = $this->seedGroupedProduct('grp-parent-3');
        $childA = $this->seedPhysicalProduct('child-c');
        $childB = $this->seedPhysicalProduct('child-d');

        $this->adminController()->setChildren(
            new SetProductChildrenData(child_uuids: [$childA['uuid'], $childB['uuid']]),
            Request::create('/x', 'PUT'),
            $parent['uuid']
        );

        $response = $this->adminController()->setChildren(
            new SetProductChildrenData(child_uuids: [$childB['uuid']]),
            Request::create('/x', 'PUT'),
            $parent['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([$childB['uuid']], array_column($this->json($response)['data'], 'uuid'));
        self::assertSame(1, $this->connection->table('commerce_product_children')
            ->where('product_uuid', '=', $parent['uuid'])->count());
    }

    public function testSetChildrenEmptyListDetachesAll(): void
    {
        $parent = $this->seedGroupedProduct('grp-parent-4');
        $child = $this->seedPhysicalProduct('child-e');
        $this->adminController()->setChildren(
            new SetProductChildrenData(child_uuids: [$child['uuid']]),
            Request::create('/x', 'PUT'),
            $parent['uuid']
        );

        $response = $this->adminController()->setChildren(
            new SetProductChildrenData(child_uuids: []),
            Request::create('/x', 'PUT'),
            $parent['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->json($response)['data']);
        self::assertSame(0, $this->connection->table('commerce_product_children')
            ->where('product_uuid', '=', $parent['uuid'])->count());
    }

    // --- Children set-list: validation -----------------------------------------

    public function testSetChildrenCrossTenantChildReturns422(): void
    {
        $parent = $this->seedGroupedProduct('grp-parent-5');
        $foreignChild = $this->seedPhysicalProduct('child-foreign', 'tenant-b');

        $response = $this->adminController()->setChildren(
            new SetProductChildrenData(child_uuids: [$foreignChild['uuid']]),
            Request::create('/x', 'PUT'),
            $parent['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('child_uuids.0', $this->json($response)['error']['details']);
        self::assertSame(0, $this->connection->table('commerce_product_children')
            ->where('product_uuid', '=', $parent['uuid'])->count());
    }

    public function testSetChildrenUnknownChildReturns422(): void
    {
        $parent = $this->seedGroupedProduct('grp-parent-6');

        $response = $this->adminController()->setChildren(
            new SetProductChildrenData(child_uuids: ['no-such-prod']),
            Request::create('/x', 'PUT'),
            $parent['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('child_uuids.0', $this->json($response)['error']['details']);
    }

    public function testSetChildrenGroupedProductAsChildReturns422(): void
    {
        $parent = $this->seedGroupedProduct('grp-parent-7');
        $otherGrouped = $this->seedGroupedProduct('grp-as-child');

        $response = $this->adminController()->setChildren(
            new SetProductChildrenData(child_uuids: [$otherGrouped['uuid']]),
            Request::create('/x', 'PUT'),
            $parent['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('child_uuids.0', $this->json($response)['error']['details']);
    }

    public function testSetChildrenExternalProductAsChildReturns422(): void
    {
        $parent = $this->seedGroupedProduct('grp-parent-8');
        $external = $this->seedExternalProduct('ext-as-child');

        $response = $this->adminController()->setChildren(
            new SetProductChildrenData(child_uuids: [$external['uuid']]),
            Request::create('/x', 'PUT'),
            $parent['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
    }

    public function testSetChildrenSelfReferenceReturns422(): void
    {
        $parent = $this->seedGroupedProduct('grp-self-ref');

        $response = $this->adminController()->setChildren(
            new SetProductChildrenData(child_uuids: [$parent['uuid']]),
            Request::create('/x', 'PUT'),
            $parent['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('child_uuids.0', $this->json($response)['error']['details']);
    }

    public function testSetChildrenNonGroupedParentReturns422(): void
    {
        $parent = $this->seedPhysicalProduct('not-grouped-1');
        $child = $this->seedPhysicalProduct('child-f');

        $response = $this->adminController()->setChildren(
            new SetProductChildrenData(child_uuids: [$child['uuid']]),
            Request::create('/x', 'PUT'),
            $parent['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('type', $this->json($response)['error']['details']);
    }

    public function testSetChildrenUnknownParentThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->adminController()->setChildren(
            new SetProductChildrenData(child_uuids: []),
            Request::create('/x', 'PUT'),
            'no-such-parent'
        );
    }

    public function testSetChildrenCrossTenantParentThrowsNotFound(): void
    {
        $parent = $this->seedGroupedProduct('grp-parent-tb', 'tenant-b');

        $this->expectException(NotFoundException::class);
        $this->adminController()->setChildren(
            new SetProductChildrenData(child_uuids: []),
            Request::create('/x', 'PUT'),
            $parent['uuid']
        );
    }

    /**
     * Proves the post-claim re-read is what decides eligibility, not any earlier
     * snapshot: the child is physical when created, then its type is flipped
     * directly in the database (standing in for a concurrent mutation that landed
     * between an operator's decision to include it and this call). The set-list
     * must reject it based on the FRESH read taken after claiming, not silently
     * attach a now-grouped product as a child.
     */
    public function testSetChildrenRejectsChildWhoseTypeChangedSinceItWasDiscovered(): void
    {
        $parent = $this->seedGroupedProduct('grp-parent-race');
        $child = $this->seedPhysicalProduct('child-race');

        $this->connection->table('commerce_products')
            ->where('uuid', '=', $child['uuid'])
            ->update(['type' => 'grouped']);

        $response = $this->adminController()->setChildren(
            new SetProductChildrenData(child_uuids: [$child['uuid']]),
            Request::create('/x', 'PUT'),
            $parent['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->connection->table('commerce_product_children')
            ->where('product_uuid', '=', $parent['uuid'])->count());
    }

    // --- Type immutability ------------------------------------------------------

    public function testTypeChangeRejectedWhenVariantsPresent(): void
    {
        $product = $this->seedPhysicalProduct('immut-variants');

        $response = $this->adminController()->update($this->patchRequest(['type' => 'digital']), $product['uuid']);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('type', $this->json($response)['error']['details']);
        self::assertSame(
            'physical',
            $this->connection->table('commerce_products')->where('uuid', '=', $product['uuid'])->first()['type']
        );
    }

    public function testTypeChangeDigitalToPhysicalWithVariantsPresentRejected(): void
    {
        $product = $this->seedDigitalProduct('immut-digital');

        $response = $this->adminController()->update($this->patchRequest(['type' => 'physical']), $product['uuid']);

        self::assertSame(422, $response->getStatusCode());
    }

    public function testTypeChangeRejectedWhenIsParentOfChildren(): void
    {
        $parent = $this->seedGroupedProduct('immut-is-parent');
        $child = $this->seedPhysicalProduct('immut-parent-child');
        $this->adminController()->setChildren(
            new SetProductChildrenData(child_uuids: [$child['uuid']]),
            Request::create('/x', 'PUT'),
            $parent['uuid']
        );

        $response = $this->adminController()->update($this->patchRequest(['type' => 'external']), $parent['uuid']);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('type', $this->json($response)['error']['details']);
    }

    public function testTypeChangeRejectedWhenIsChildOfAParent(): void
    {
        $parent = $this->seedGroupedProduct('immut-is-child-p');
        $child = $this->seedPhysicalProduct('immut-is-child-c');
        $this->adminController()->setChildren(
            new SetProductChildrenData(child_uuids: [$child['uuid']]),
            Request::create('/x', 'PUT'),
            $parent['uuid']
        );

        $response = $this->adminController()->update($this->patchRequest(['type' => 'digital']), $child['uuid']);

        self::assertSame(422, $response->getStatusCode());
    }

    public function testTypeChangeRejectedWhenCartLineReferencesVariant(): void
    {
        $product = $this->seedPhysicalProduct('immut-cart-ref');
        $variantUuid = (string) $product['variants'][0]['uuid'];
        (new StockRepository())->increment($this->context, '', $variantUuid, 5);
        ['cart' => $cart] = $this->cartService()->create($this->context);
        $this->cartService()->addLine($this->context, $cart, $variantUuid, 1);

        $response = $this->adminController()->update($this->patchRequest(['type' => 'digital']), $product['uuid']);

        self::assertSame(422, $response->getStatusCode());
    }

    public function testTypeChangeRejectedWhenOrderLineReferencesVariant(): void
    {
        $product = $this->seedPhysicalProduct('immut-order-ref');
        $variantUuid = (string) $product['variants'][0]['uuid'];
        $this->connection->table('commerce_order_lines')->insert([
            'uuid' => 'ordlnimmut01',
            'order_uuid' => 'ordimmut0001',
            'variant_uuid' => $variantUuid,
            'product_name' => 'Immut Order Ref',
            'sku' => 'IMMUTORDREF',
            'option_values' => json_encode([], JSON_THROW_ON_ERROR),
            'unit_price' => 1000,
            'quantity' => 1,
            'line_total' => 1000,
        ]);

        $response = $this->adminController()->update($this->patchRequest(['type' => 'digital']), $product['uuid']);

        self::assertSame(422, $response->getStatusCode());
    }

    public function testTypeChangeToInvalidValueReturns422(): void
    {
        $product = $this->seedGroupedProduct('immut-bogus-type');

        $response = $this->adminController()->update($this->patchRequest(['type' => 'subscription']), $product['uuid']);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('type', $this->json($response)['error']['details']);
    }

    public function testTypeChangeToExternalWithoutMetadataReturns422(): void
    {
        $product = $this->seedGroupedProduct('immut-to-ext-bad');

        $response = $this->adminController()->update($this->patchRequest(['type' => 'external']), $product['uuid']);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('metadata.external_url', $this->json($response)['error']['details']);
    }

    /**
     * ALLOWED type change: a grouped product with no children/variants/cart-order
     * references switching to external (with valid metadata supplied on the same
     * call) satisfies the spec's simple rule (no variants, no children, no
     * cart/order refs) even though its OLD type is being abandoned.
     */
    public function testTypeChangeGroupedToExternalWithValidMetadataAllowed(): void
    {
        $product = $this->seedGroupedProduct('immut-allowed-1');

        $response = $this->adminController()->update(
            $this->patchRequest(['type' => 'external', 'metadata' => ['external_url' => 'https://example.com/y']]),
            $product['uuid']
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('external', $this->json($response)['data']['type']);
    }

    /** ALLOWED: external (zero variants/children/refs) switching to grouped needs no extra data. */
    public function testTypeChangeExternalToGroupedAllowed(): void
    {
        $product = $this->seedExternalProduct('immut-allowed-2');

        $response = $this->adminController()->update($this->patchRequest(['type' => 'grouped']), $product['uuid']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('grouped', $this->json($response)['data']['type']);
    }

    // --- Review finding 1: metadata-only PATCH on an external product ----------

    /**
     * Review finding: `updateProduct()` previously only re-validated
     * `metadata.external_url` inside the `type`-key branch, so a metadata-only
     * PATCH (no `type` in the payload) on an already-external product could strip
     * or corrupt `external_url` without any validation at all -- a stored
     * external product with a null/missing url on the storefront. The fix
     * re-validates whenever the update touches `metadata` AND the product's
     * EFFECTIVE type (incoming `type` key, else the current stored type) is
     * `external`.
     */
    public function testMetadataOnlyPatchRemovingExternalUrlOnExternalProductReturns422(): void
    {
        $product = $this->seedExternalProduct('meta-only-strip', '', 'https://vendor.example.com/keep');

        $response = $this->adminController()->update(
            $this->patchRequest(['metadata' => ['button_label' => 'Buy now']]),
            $product['uuid']
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertArrayHasKey('metadata.external_url', $this->json($response)['error']['details']);
        self::assertSame(
            'https://vendor.example.com/keep',
            json_decode(
                (string) $this->connection->table('commerce_products')
                    ->where('uuid', '=', $product['uuid'])->first()['metadata'],
                true
            )['external_url'],
            'A rejected metadata-only update must not have touched the stored external_url.'
        );
    }

    public function testMetadataOnlyPatchWithValidNewUrlOnExternalProductPersists(): void
    {
        $product = $this->seedExternalProduct('meta-only-swap', '', 'https://vendor.example.com/old');

        $response = $this->adminController()->update(
            $this->patchRequest(['metadata' => ['external_url' => 'https://vendor.example.com/new']]),
            $product['uuid']
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(
            'https://vendor.example.com/new',
            json_decode(
                (string) $this->connection->table('commerce_products')
                    ->where('uuid', '=', $product['uuid'])->first()['metadata'],
                true
            )['external_url']
        );
    }

    /** A metadata-only PATCH on a non-external product needs no external_url at all. */
    public function testMetadataOnlyPatchOnPhysicalProductDoesNotRequireExternalUrl(): void
    {
        $product = $this->seedPhysicalProduct('meta-only-physical');

        $response = $this->adminController()->update(
            $this->patchRequest(['metadata' => ['note' => 'internal only']]),
            $product['uuid']
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    // --- Cart gate: defense in depth for external/grouped variants -------------

    public function testAddLineRejectsVariantOnExternalProduct(): void
    {
        $product = $this->seedExternalProduct('cartgate-ext');
        // Catalog blocks variant creation for external products outright, so this
        // seeds the variant row directly -- exactly the state the defense-in-depth
        // gate exists to catch (a variant referencing a non-purchasable product by
        // whatever means).
        $variantUuid = $this->seedVariantDirect($product['uuid'], 'CARTGATEEXT');

        $create = $this->cartController()->create(Request::create('/x', 'POST'));
        $token = (string) $this->json($create)['data']['token'];

        $request = $this->addLineRequest($token, $variantUuid, 1);
        $response = $this->cartController()->addLine(
            new AddCartLineData(variant_uuid: $variantUuid, quantity: 1),
            $request
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('external', (string) $response->getContent());
        self::assertSame(0, $this->connection->table('commerce_cart_lines')
            ->where('variant_uuid', '=', $variantUuid)->count());
    }

    public function testAddLineRejectsVariantOnGroupedProduct(): void
    {
        $product = $this->seedGroupedProduct('cartgate-grp');
        $variantUuid = $this->seedVariantDirect($product['uuid'], 'CARTGATEGRP');

        $create = $this->cartController()->create(Request::create('/x', 'POST'));
        $token = (string) $this->json($create)['data']['token'];

        $request = $this->addLineRequest($token, $variantUuid, 1);
        $response = $this->cartController()->addLine(
            new AddCartLineData(variant_uuid: $variantUuid, quantity: 1),
            $request
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('grouped', (string) $response->getContent());
    }

    public function testAddLineStillAllowsPhysicalVariant(): void
    {
        $product = $this->seedPhysicalProduct('cartgate-phys');
        $variantUuid = (string) $product['variants'][0]['uuid'];
        (new StockRepository())->increment($this->context, '', $variantUuid, 5);

        $create = $this->cartController()->create(Request::create('/x', 'POST'));
        $token = (string) $this->json($create)['data']['token'];

        $response = $this->cartController()->addLine(
            new AddCartLineData(variant_uuid: $variantUuid, quantity: 1),
            $this->addLineRequest($token, $variantUuid, 1)
        );

        self::assertSame(200, $response->getStatusCode());
    }

    // --- Storefront echo ---------------------------------------------------------

    public function testStorefrontShowEchoesChildrenForGroupedProductOrderedByPosition(): void
    {
        $parent = $this->seedGroupedProduct('sf-grouped-1');
        $childA = $this->seedPhysicalProduct('sf-child-a');
        $childB = $this->seedDigitalProduct('sf-child-b');
        $this->adminController()->setChildren(
            new SetProductChildrenData(child_uuids: [$childB['uuid'], $childA['uuid']]),
            Request::create('/x', 'PUT'),
            $parent['uuid']
        );

        $response = $this->productController()->show(Request::create('/x'), (string) $parent['slug']);

        self::assertSame(200, $response->getStatusCode());
        $children = $this->json($response)['data']['children'];
        self::assertSame(['sf-child-b', 'sf-child-a'], array_column($children, 'slug'));
        self::assertArrayHasKey('cover_url', $children[0]);
        self::assertArrayNotHasKey('external', $this->json($response)['data']);
    }

    /**
     * Review finding 4: `childrenPayload()` resolves each child's cover via a
     * single batched `ProductMediaRepository::coversForProducts()` IN-query
     * rather than one `coverFor()` call per child. Two children with distinct
     * covers (plus one with none) proves the batching maps covers back to the
     * correct child rather than smearing/misaligning results.
     */
    public function testStorefrontShowResolvesEachChildsOwnCoverViaBatchedLookup(): void
    {
        require_once __DIR__ . '/../../../vendor/glueful/framework/migrations/uploads/001_CreateBlobsTable.php';
        (new \Glueful\Migrations\Uploads\CreateBlobsTable())->up($this->connection->getSchemaBuilder());
        $this->seedBlobDirect('blobchildcov1');
        $this->seedBlobDirect('blobchildcov2');

        $parent = $this->seedGroupedProduct('sf-grouped-covers');
        $childWithCoverA = $this->seedPhysicalProduct('sf-cover-child-a');
        $childWithCoverB = $this->seedPhysicalProduct('sf-cover-child-b');
        $childNoCover = $this->seedPhysicalProduct('sf-cover-child-c');
        $this->connection->table('commerce_product_media')->insert([
            'uuid' => 'medchildcov01',
            'tenant_uuid' => '',
            'product_uuid' => $childWithCoverA['uuid'],
            'blob_uuid' => 'blobchildcov1',
            'role' => 'cover',
            'position' => 0,
        ]);
        $this->connection->table('commerce_product_media')->insert([
            'uuid' => 'medchildcov02',
            'tenant_uuid' => '',
            'product_uuid' => $childWithCoverB['uuid'],
            'blob_uuid' => 'blobchildcov2',
            'role' => 'cover',
            'position' => 0,
        ]);

        $this->adminController()->setChildren(
            new SetProductChildrenData(
                child_uuids: [$childWithCoverA['uuid'], $childWithCoverB['uuid'], $childNoCover['uuid']]
            ),
            Request::create('/x', 'PUT'),
            $parent['uuid']
        );

        $response = $this->productController()->show(Request::create('/x'), (string) $parent['slug']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $children = $this->json($response)['data']['children'];
        $bySlug = array_combine(array_column($children, 'slug'), $children);
        self::assertSame('/blobs/blobchildcov1', $bySlug['sf-cover-child-a']['cover_url']);
        self::assertSame('/blobs/blobchildcov2', $bySlug['sf-cover-child-b']['cover_url']);
        self::assertNull($bySlug['sf-cover-child-c']['cover_url']);
    }

    public function testStorefrontShowEchoesExternalUrlAndButtonLabel(): void
    {
        $product = $this->seedExternalProduct('sf-external-1', '', 'https://vendor.example.com/z');
        $this->connection->table('commerce_products')
            ->where('uuid', '=', $product['uuid'])
            ->update(['metadata' => json_encode(
                ['external_url' => 'https://vendor.example.com/z', 'button_label' => 'Shop Now'],
                JSON_THROW_ON_ERROR
            )]);

        $response = $this->productController()->show(Request::create('/x'), (string) $product['slug']);

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame('https://vendor.example.com/z', $data['external']['url']);
        self::assertSame('Shop Now', $data['external']['button_label']);
        self::assertArrayNotHasKey('children', $data);
    }

    /**
     * Review finding: `ProductChildrenRepository::childProductsForProduct()`
     * joined `commerce_product_children` -> `commerce_products` with no
     * `status`/`deleted_at` filter, so a grouped parent's storefront `children`
     * echo could leak a draft or soft-deleted child. The fix mirrors
     * `ProductController::show()`'s own visibility rule for the primary
     * resource (`status === 'active'` AND `deleted_at IS NULL`) onto the
     * children join.
     */
    public function testStorefrontShowChildrenEchoOmitsDraftAndSoftDeletedChildren(): void
    {
        $parent = $this->seedGroupedProduct('sf-children-visibility');
        $active = $this->seedPhysicalProduct('sf-child-active');
        $draft = $this->seedPhysicalProduct('sf-child-draft');
        $softDeleted = $this->seedPhysicalProduct('sf-child-soft-deleted');

        // Attach all three while every child is still active -- setChildren()'s
        // own re-read only requires physical/digital, so this reflects the real
        // scenario the leak comes from: a child's status/deleted_at changes
        // LATER, independently, without the (stale) children join row ever
        // being touched again.
        $this->adminController()->setChildren(
            new SetProductChildrenData(child_uuids: [
                $active['uuid'],
                $draft['uuid'],
                $softDeleted['uuid'],
            ]),
            Request::create('/x', 'PUT'),
            $parent['uuid']
        );

        $this->connection->table('commerce_products')
            ->where('uuid', '=', $draft['uuid'])
            ->update(['status' => 'draft']);
        $this->connection->table('commerce_products')
            ->where('uuid', '=', $softDeleted['uuid'])
            ->delete();

        $response = $this->productController()->show(Request::create('/x'), (string) $parent['slug']);

        self::assertSame(200, $response->getStatusCode());
        $children = $this->json($response)['data']['children'];
        self::assertSame(['sf-child-active'], array_column($children, 'slug'));
    }

    /**
     * The finding's other half: the admin set-list response (the closest thing
     * to an admin "GET children") must stay UNFILTERED -- an operator managing a
     * grouped product's children needs to see a draft child that's already
     * attached, not have it silently vanish from the response body.
     */
    public function testAdminSetChildrenResponseIncludesDraftChildUnfiltered(): void
    {
        $parent = $this->seedGroupedProduct('admin-children-visibility');
        $draft = $this->catalog()->createProduct($this->context, [
            'slug' => 'admin-child-draft',
            'name' => 'admin-child-draft',
            'type' => 'physical',
            'status' => 'draft',
            'variants' => [[
                'sku' => 'ADMINCHILDDRAFT',
                'option_values' => [],
                'price' => 500,
                'currency' => 'USD',
            ]],
        ]);

        $response = $this->adminController()->setChildren(
            new SetProductChildrenData(child_uuids: [$draft['uuid']]),
            Request::create('/x', 'PUT'),
            $parent['uuid']
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['admin-child-draft'], array_column($this->json($response)['data'], 'slug'));
    }

    public function testStorefrontShowOmitsChildrenAndExternalKeysForPhysicalProduct(): void
    {
        $product = $this->seedPhysicalProduct('sf-physical-1');

        $response = $this->productController()->show(Request::create('/x'), (string) $product['slug']);

        $data = $this->json($response)['data'];
        self::assertArrayNotHasKey('children', $data);
        self::assertArrayNotHasKey('external', $data);
    }

    // --- Helpers -----------------------------------------------------------------

    /** @return array<string,mixed> product w/ one physical (USD 1000) variant */
    private function seedPhysicalProduct(string $slug, string $tenant = ''): array
    {
        return $this->catalog($tenant)->createProduct($this->context, [
            'slug' => $slug,
            'name' => $slug,
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => strtoupper(str_replace('-', '', $slug)),
                'option_values' => [],
                'price' => 1000,
                'currency' => 'USD',
            ]],
        ]);
    }

    /** @return array<string,mixed> product w/ one digital (USD 500) variant */
    private function seedDigitalProduct(string $slug, string $tenant = ''): array
    {
        return $this->catalog($tenant)->createProduct($this->context, [
            'slug' => $slug,
            'name' => $slug,
            'type' => 'digital',
            'status' => 'active',
            'variants' => [[
                'sku' => strtoupper(str_replace('-', '', $slug)) . 'D',
                'option_values' => [],
                'price' => 500,
                'currency' => 'USD',
            ]],
        ]);
    }

    /** @return array<string,mixed> */
    private function seedExternalProduct(string $slug, string $tenant = '', string $url = 'https://example.com/x'): array
    {
        return $this->catalog($tenant)->createProduct($this->context, [
            'slug' => $slug,
            'name' => $slug,
            'type' => 'external',
            'status' => 'active',
            'metadata' => ['external_url' => $url],
            'variants' => [],
        ]);
    }

    /** @return array<string,mixed> */
    private function seedGroupedProduct(string $slug, string $tenant = ''): array
    {
        return $this->catalog($tenant)->createProduct($this->context, [
            'slug' => $slug,
            'name' => $slug,
            'type' => 'grouped',
            'status' => 'active',
            'variants' => [],
        ]);
    }

    /**
     * Direct row insert bypassing CatalogService entirely -- the catalog path now
     * blocks variant creation for external/grouped products, so this is the only
     * way to exercise CartService's defense-in-depth gate (see class docblock's
     * "seed defensively via direct row manipulation").
     */
    private function seedVariantDirect(string $productUuid, string $sku, int $price = 1000, string $tenant = ''): string
    {
        $variantUuid = substr('v' . md5($productUuid . $sku), 0, 12);
        $this->connection->table('commerce_variants')->insert([
            'uuid' => $variantUuid,
            'tenant_uuid' => $tenant,
            'product_uuid' => $productUuid,
            'sku' => $sku,
            'option_values' => json_encode([], JSON_THROW_ON_ERROR),
            'price' => $price,
            'currency' => 'USD',
            'position' => 0,
            'status' => 'active',
        ]);

        return $variantUuid;
    }

    /** Only used by the batched-cover-lookup test; requires the blobs table to already exist. */
    private function seedBlobDirect(string $uuid): void
    {
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
    }

    private function addLineRequest(string $token, string $variantUuid, int $quantity): Request
    {
        $request = Request::create('/x', 'POST', [], [], [], [], json_encode(
            ['variant_uuid' => $variantUuid, 'quantity' => $quantity],
            JSON_THROW_ON_ERROR
        ));
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('X-Cart-Token', $token);

        return $request;
    }

    /** @param array<string,mixed> $body */
    private function patchRequest(array $body): Request
    {
        $request = Request::create('/x', 'PATCH', [], [], [], [], json_encode($body, JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');

        return $request;
    }

    private function catalog(string $tenant = ''): CatalogService
    {
        return new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant),
            new StockRepository(),
            new ProductChildrenRepository()
        );
    }

    private function adminController(string $tenant = ''): AdminProductController
    {
        return new AdminProductController(
            $this->context,
            $this->catalog($tenant),
            new ProductRepository(),
            new VariantRepository(),
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
            new ProductChildrenRepository(),
            new AddonRepository()
        );
    }

    private function cartService(): CartService
    {
        return new CartService(
            new CartRepository(),
            new VariantRepository(),
            new ProductRepository(),
            new StockRepository(),
            new DiscountRepository(),
            new PricingEngine(),
            new SentinelTenantResolver()
        );
    }

    private function cartController(): CartController
    {
        return new CartController($this->context, $this->cartService());
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
