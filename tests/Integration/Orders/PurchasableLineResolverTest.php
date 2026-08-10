<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Orders;

use Glueful\Extensions\Commerce\Cart\AddonValidationException;
use Glueful\Extensions\Commerce\Cart\CartRepository;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Catalog\AddonRepository;
use Glueful\Extensions\Commerce\Catalog\AddonService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\PurchasableLineResolver;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Shipping\ShippingClassRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Commerce\Tests\Support\QueryLoggingPdoStatement;
use Glueful\Helpers\Utils;
use Glueful\Validation\ValidationException;

/**
 * Task 7 (admin-order-creation, cycle 2): {@see PurchasableLineResolver}'s
 * two typed entry points over the one private base extracted from
 * `CartService::pricedLines()`.
 *
 * - `resolvePersistedSnapshot()` (carts/checkout): value-identical to
 *   `pricedLines()`'s pre-extraction per-line output, and immune to an
 *   addon-definition edit made AFTER the snapshot it prices was persisted.
 * - `resolveSelections()` (admin drafts): resolves raw selections against
 *   CURRENT active addon definitions, so an edited definition IS picked up
 *   as a fresh, differently-hashed canonical snapshot -- drift a caller can
 *   detect by comparing hashes. It ALSO rejects an unpurchasable product
 *   type (`external`/`grouped`) -- `resolvePersistedSnapshot()` deliberately
 *   does not, since a persisted cart line was already gated at add-time.
 * - Both share variant lookup / buyer-availability rejection, variant-only
 *   option values, digital classification, and the raw per-line
 *   `sellerUuid` fact (never a resolved partitioned boolean -- see
 *   {@see \Glueful\Extensions\Commerce\Orders\ResolvedLine}'s docblock).
 */
final class PurchasableLineResolverTest extends CommerceTestCase
{
    private const TENANT = '';

    // -----------------------------------------------------------------
    // resolvePersistedSnapshot(): byte-identical to pricedLines()
    // -----------------------------------------------------------------

    public function testResolvePersistedSnapshotMatchesCartServicePricedLinesOutputFieldForField(): void
    {
        $classUuid = $this->seedShippingClass('fragile', 'Fragile');
        ['product_uuid' => $productUuid, 'variant_uuid' => $variantUuid] =
            $this->seedProduct('SKU-R1', 1000, 10, $classUuid, 'reduced');
        $addon = $this->createCheckboxAddon($productUuid, 250);

        $cartService = $this->cartService();
        ['cart' => $cart] = $cartService->create($this->context);
        $cartService->addLine($this->context, $cart, $variantUuid, 3, [
            ['addon_uuid' => $addon['uuid'], 'value' => true],
        ]);

        $persistedLine = $this->connection->table('commerce_cart_lines')
            ->where('cart_uuid', '=', $cart['uuid'])
            ->first();
        self::assertNotNull($persistedLine);

        $priced = $cartService->pricedLines($this->context, $cart)[0];

        $snapshot = json_decode((string) $persistedLine['addons'], true);
        $resolved = $this->resolver()->resolvePersistedSnapshot(
            $this->context,
            self::TENANT,
            $variantUuid,
            (int) $persistedLine['quantity'],
            $snapshot
        );

        self::assertSame($priced['product_uuid'], $resolved->productUuid);
        self::assertSame($priced['variant_uuid'], $resolved->variantUuid);
        self::assertSame($priced['unit_price'], $resolved->unitPrice);
        self::assertSame($priced['currency'], $resolved->currency);
        self::assertSame($priced['quantity'], $resolved->quantity);
        self::assertSame($priced['sku'], $resolved->sku);
        self::assertSame($priced['product_name'], $resolved->productName);
        self::assertSame($priced['option_values'], $resolved->optionValues);
        self::assertSame($priced['type'], $resolved->type);
        self::assertSame($priced['addons'], $resolved->addons);
        self::assertSame($priced['shipping_class'], $resolved->shippingClass);
        self::assertSame('fragile', $resolved->shippingClass);
        self::assertSame($priced['tax_class'], $resolved->taxClass);
        self::assertSame('reduced', $resolved->taxClass);
        self::assertSame($priced['commission_kind'], $resolved->commissionKind);
        self::assertSame($priced['commission_bps'], $resolved->commissionBps);
        self::assertSame($priced['commission_fixed'], $resolved->commissionFixed);
        self::assertSame(1250, $resolved->unitPrice, 'sanity: 1000 base + 250 addon delta');
    }

    public function testResolvePersistedSnapshotUnitPriceUnchangedAfterAddonDefinitionEdit(): void
    {
        ['product_uuid' => $productUuid, 'variant_uuid' => $variantUuid] = $this->seedProduct('SKU-R2', 100, 5);
        $addon = $this->createCheckboxAddon($productUuid, 300);

        $cartService = $this->cartService();
        ['cart' => $cart] = $cartService->create($this->context);
        $cartService->addLine($this->context, $cart, $variantUuid, 1, [
            ['addon_uuid' => $addon['uuid'], 'value' => true],
        ]);
        $line = $this->connection->table('commerce_cart_lines')->where('cart_uuid', '=', $cart['uuid'])->first();
        $persistedSnapshot = json_decode((string) $line['addons'], true);

        $before = $this->resolver()->resolvePersistedSnapshot(
            $this->context,
            self::TENANT,
            $variantUuid,
            1,
            $persistedSnapshot
        );
        self::assertSame(400, $before->unitPrice);

        // Edit BOTH price and name -- resolvePersistedSnapshot NEVER
        // re-resolves definitions, so neither edit can touch the price
        // computed from the already-persisted snapshot.
        $this->addonService()->update($this->context, $addon['uuid'], ['price_delta' => 900]);
        $this->addonService()->update($this->context, $addon['uuid'], ['name' => 'Renamed']);

        $after = $this->resolver()->resolvePersistedSnapshot(
            $this->context,
            self::TENANT,
            $variantUuid,
            1,
            $persistedSnapshot
        );
        self::assertSame(400, $after->unitPrice, 'a definition edit must never reprice an already-persisted snapshot');
        self::assertSame($before->addonsHash, $after->addonsHash);
    }

    public function testCartServicePricedLinesStillEmbedsCartLineUuidInNegativeUnitPriceMessage(): void
    {
        // Defensive backstop regression: pricedLines()'s pre-extraction body
        // embedded the CART LINE's own uuid in this message (the resolver
        // itself only knows the variant uuid) -- CartService must still
        // re-wrap it with the line uuid after delegating to the resolver.
        ['product_uuid' => $productUuid, 'variant_uuid' => $variantUuid] = $this->seedProduct('SKU-R3', 500, 5);
        $addon = $this->createCheckboxAddon($productUuid, 100);

        $cartService = $this->cartService();
        ['cart' => $cart] = $cartService->create($this->context);
        $cartService->addLine($this->context, $cart, $variantUuid, 1, [
            ['addon_uuid' => $addon['uuid'], 'value' => true],
        ]);
        // Corrupt the persisted snapshot's price_delta directly (a corrupted
        // row is the ONLY realistic way to reach this branch -- addLine()
        // itself already rejects a negative final price via AddonSnapshot::build()).
        $line = $this->connection->table('commerce_cart_lines')->where('cart_uuid', '=', $cart['uuid'])->first();
        $corrupted = json_decode((string) $line['addons'], true);
        $corrupted[0]['price_delta'] = -999999;
        $this->connection->table('commerce_cart_lines')
            ->where('uuid', '=', $line['uuid'])
            ->update(['addons' => json_encode($corrupted, JSON_THROW_ON_ERROR)]);

        try {
            $cartService->pricedLines($this->context, $cart);
            self::fail('Expected AddonValidationException.');
        } catch (AddonValidationException $e) {
            self::assertStringContainsString((string) $line['uuid'], $e->getMessage());
            self::assertStringContainsString('computes a negative unit price', $e->getMessage());
        }
    }

    // -----------------------------------------------------------------
    // resolveSelections(): fresh snapshot, drift-reportable
    // -----------------------------------------------------------------

    public function testResolveSelectionsPicksUpEditedDefinitionAsDifferentHashAndPrice(): void
    {
        ['product_uuid' => $productUuid, 'variant_uuid' => $variantUuid] = $this->seedProduct('SKU-R4', 100, 5);
        $addon = $this->createCheckboxAddon($productUuid, 300);
        $selections = [['addon_uuid' => $addon['uuid'], 'value' => true]];

        $before = $this->resolver()->resolveSelections($this->context, self::TENANT, $variantUuid, 1, $selections);
        self::assertSame(400, $before->unitPrice);

        $this->addonService()->update($this->context, $addon['uuid'], ['price_delta' => 900]);

        $after = $this->resolver()->resolveSelections($this->context, self::TENANT, $variantUuid, 1, $selections);
        self::assertSame(1000, $after->unitPrice, 'resolveSelections re-resolves against the EDITED definition');
        self::assertNotSame(
            $before->addonsHash,
            $after->addonsHash,
            'the edited definition must be reportable as drift via a changed hash'
        );
    }

    public function testResolveSelectionsInvalidAddonRaisesValidationExceptionOnAddonsField(): void
    {
        ['variant_uuid' => $variantUuid] = $this->seedProduct('SKU-R5', 100, 5);

        try {
            $this->resolver()->resolveSelections(
                $this->context,
                self::TENANT,
                $variantUuid,
                1,
                [['addon_uuid' => 'nonexistentAddon']]
            );
            self::fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getStatusCode());
            self::assertArrayHasKey('addons', $e->firstErrors());
        }
    }

    // -----------------------------------------------------------------
    // Shared variant lookup / buyer availability
    // -----------------------------------------------------------------

    public function testBothMethodsRejectAnUnknownVariantWithTheSameMessage(): void
    {
        foreach (['resolvePersistedSnapshot', 'resolveSelections'] as $method) {
            try {
                $this->resolver()->$method($this->context, self::TENANT, 'variantMissing0', 1, []);
                self::fail("Expected ValidationException from {$method}().");
            } catch (ValidationException $e) {
                self::assertSame(['Variant not found.'], $e->errorsFor('variant_uuid'), $method);
            }
        }
    }

    public function testBothMethodsRejectABuyerUnavailableSuspendedSellerProductWithTheSameMessage(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerSUSP001', 'suspended');
        ['variant_uuid' => $variantUuid] = $this->seedProduct('SKU-R6', 100, 5, null, null, 'sellerSUSP001');

        foreach (['resolvePersistedSnapshot', 'resolveSelections'] as $method) {
            try {
                $this->resolver()->$method($this->context, self::TENANT, $variantUuid, 1, []);
                self::fail("Expected ValidationException from {$method}().");
            } catch (ValidationException $e) {
                self::assertSame(
                    ['This product is no longer available.'],
                    $e->errorsFor('variant_uuid'),
                    $method
                );
            }
        }
    }

    public function testCartServicePricedLinesSkipsAGenuinelyOrphanedVariantReference(): void
    {
        ['cart' => $cart] = $this->cartService()->create($this->context);
        (new CartRepository())->insertLine($this->context, (string) $cart['uuid'], 'variantOrphan1', 1);

        $priced = $this->cartService()->pricedLines($this->context, $cart);

        self::assertSame([], $priced, 'a dangling variant reference is silently skipped, exactly as before');
    }

    public function testCartServicePricedLinesSurfacesUnavailableProductAsLinesIndexedField(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerSUSP002', 'suspended');
        ['variant_uuid' => $variantUuid] = $this->seedProduct('SKU-R7', 100, 5, null, null, 'sellerSUSP002');

        $cartService = $this->cartService();
        // Add the line while the seller is active, then suspend afterwards --
        // mirrors SuspendedSellerCheckoutTest's "already in the cart" scenario.
        $this->connection->table('commerce_sellers')->where('uuid', '=', 'sellerSUSP002')->update(['status' => 'active']);
        ['cart' => $cart] = $cartService->create($this->context);
        $cartService->addLine($this->context, $cart, $variantUuid, 1);
        $this->connection->table('commerce_sellers')->where('uuid', '=', 'sellerSUSP002')->update(['status' => 'suspended']);

        try {
            $cartService->pricedLines($this->context, $cart);
            self::fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['This product is no longer available.'], $e->errorsFor('lines.0'));
        }
    }

    // -----------------------------------------------------------------
    // Option values: always variant-derived
    // -----------------------------------------------------------------

    public function testOptionValuesAlwaysComeFromTheVariantForBothMethods(): void
    {
        ['variant_uuid' => $variantUuid] = $this->seedProductWithOptions('SKU-R8', ['color' => 'red', 'size' => 'M']);

        $persisted = $this->resolver()->resolvePersistedSnapshot(
            $this->context,
            self::TENANT,
            $variantUuid,
            1,
            []
        );
        $selected = $this->resolver()->resolveSelections($this->context, self::TENANT, $variantUuid, 1, []);

        self::assertSame(['color' => 'red', 'size' => 'M'], $persisted->optionValues);
        self::assertSame(['color' => 'red', 'size' => 'M'], $selected->optionValues);
    }

    // -----------------------------------------------------------------
    // Digital classification / raw per-line seller identity
    // -----------------------------------------------------------------

    public function testIsDigitalTrueForADigitalProductFalseForPhysical(): void
    {
        ['variant_uuid' => $digitalVariant] = $this->seedProduct('SKU-R9', 100, 5, null, null, null, 'digital');
        ['variant_uuid' => $physicalVariant] = $this->seedProduct('SKU-R10', 100, 5);

        $digital = $this->resolver()->resolvePersistedSnapshot($this->context, self::TENANT, $digitalVariant, 1, []);
        $physical = $this->resolver()->resolvePersistedSnapshot($this->context, self::TENANT, $physicalVariant, 1, []);

        self::assertTrue($digital->isDigital);
        self::assertSame('digital', $digital->type);
        self::assertFalse($physical->isDigital);
        self::assertSame('physical', $physical->type);
    }

    public function testSellerUuidIsNullForAnOrdinaryProductAndSetForASellerAttributedOneForBothMethods(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerATTR001', 'active');
        ['variant_uuid' => $ordinaryVariant] = $this->seedProduct('SKU-R11', 100, 5);
        ['variant_uuid' => $attributedVariant] = $this->seedProduct('SKU-R12', 100, 5, null, null, 'sellerATTR001');

        $ordinaryPersisted = $this->resolver()
            ->resolvePersistedSnapshot($this->context, self::TENANT, $ordinaryVariant, 1, []);
        $ordinarySelected = $this->resolver()
            ->resolveSelections($this->context, self::TENANT, $ordinaryVariant, 1, []);
        $attributedPersisted = $this->resolver()
            ->resolvePersistedSnapshot($this->context, self::TENANT, $attributedVariant, 1, []);
        $attributedSelected = $this->resolver()
            ->resolveSelections($this->context, self::TENANT, $attributedVariant, 1, []);

        self::assertNull($ordinaryPersisted->sellerUuid);
        self::assertNull($ordinarySelected->sellerUuid);
        self::assertSame('sellerATTR001', $attributedPersisted->sellerUuid);
        self::assertSame('sellerATTR001', $attributedSelected->sellerUuid);
    }

    // -----------------------------------------------------------------
    // Shipping-class batching: exactly one query for a multi-line cart
    // -----------------------------------------------------------------

    public function testCartServicePricedLinesResolvesShippingClassesInExactlyOneQueryForAMultiLineCart(): void
    {
        $fragile = $this->seedShippingClass('fragile', 'Fragile');
        $oversized = $this->seedShippingClass('oversized', 'Oversized');
        $variantA = $this->seedProduct('SKU-QC1', 100, 5, $fragile)['variant_uuid'];
        $variantB = $this->seedProduct('SKU-QC2', 100, 5, $oversized)['variant_uuid'];
        $variantC = $this->seedProduct('SKU-QC3', 100, 5)['variant_uuid'];

        $cartService = $this->cartService();
        ['cart' => $cart] = $cartService->create($this->context);
        $cartService->addLine($this->context, $cart, $variantA, 1);
        $cartService->addLine($this->context, $cart, $variantB, 1);
        $cartService->addLine($this->context, $cart, $variantC, 1);

        $pdo = $this->connection->getPDO();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [QueryLoggingPdoStatement::class]);
        QueryLoggingPdoStatement::$queries = [];

        $priced = $cartService->pricedLines($this->context, $cart);

        self::assertCount(3, $priced);
        $bySku = [];
        foreach ($priced as $line) {
            $bySku[$line['sku']] = $line['shipping_class'];
        }
        self::assertSame('fragile', $bySku['SKU-QC1']);
        self::assertSame('oversized', $bySku['SKU-QC2']);
        self::assertNull($bySku['SKU-QC3']);

        $shippingClassQueries = array_values(array_filter(
            QueryLoggingPdoStatement::$queries,
            static fn (string $sql): bool => str_starts_with($sql, 'SELECT')
                && str_contains($sql, 'commerce_shipping_classes')
        ));
        self::assertCount(
            1,
            $shippingClassQueries,
            'a 3-line cart referencing 2 distinct shipping classes must resolve them in ONE batched query'
        );
    }

    // -----------------------------------------------------------------
    // resolveSelections() rejects unpurchasable product types;
    // resolvePersistedSnapshot() stays unaffected (already gated at add-time)
    // -----------------------------------------------------------------

    public function testResolveSelectionsRejectsAnExternalProductType(): void
    {
        $variantUuid = $this->seedExternalProductWithDirectlyInsertedVariant('SKU-R13');

        try {
            $this->resolver()->resolveSelections($this->context, self::TENANT, $variantUuid, 1, []);
            self::fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(
                ["Products of type 'external' cannot be purchased."],
                $e->errorsFor('variant_uuid')
            );
        }
    }

    public function testResolvePersistedSnapshotStillResolvesAnExternalProductTypeUnchanged(): void
    {
        // resolvePersistedSnapshot() carries NO purchasability-type guard --
        // a persisted cart line was already gated at add-time, and this path
        // must stay unchanged from pricedLines()'s pre-extraction body.
        $variantUuid = $this->seedExternalProductWithDirectlyInsertedVariant('SKU-R14');

        $resolved = $this->resolver()->resolvePersistedSnapshot($this->context, self::TENANT, $variantUuid, 1, []);

        self::assertSame('external', $resolved->type);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function resolver(): PurchasableLineResolver
    {
        return new PurchasableLineResolver(
            new VariantRepository(),
            new ProductRepository(),
            new AddonRepository(),
            new ShippingClassRepository()
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
            new SentinelTenantResolver(),
            new AddonRepository(),
            new ShippingClassRepository()
        );
    }

    private function addonService(): AddonService
    {
        return new AddonService(new AddonRepository(), new ProductRepository(), new SentinelTenantResolver());
    }

    private function seedShippingClass(string $slug, string $name): string
    {
        $uuid = 'clas' . substr(md5($slug), 0, 8);
        $this->connection->table('commerce_shipping_classes')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT,
            'slug' => $slug,
            'name' => $name,
        ]);

        return $uuid;
    }

    private function activateMarketplace(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['marketplace' => ['enabled' => true]]);
        $this->connection->table('commerce_marketplace_settings')->insert([
            'uuid' => 'mktsettings1',
            'tenant_uuid' => self::TENANT,
            'status' => 'active',
        ]);
    }

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

    /** @return array{product_uuid: string, variant_uuid: string} */
    private function seedProduct(
        string $sku,
        int $price,
        int $stock,
        ?string $shippingClassUuid = null,
        ?string $taxClass = null,
        ?string $sellerUuid = null,
        string $type = 'physical'
    ): array {
        $catalog = new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            new SentinelTenantResolver(),
            new StockRepository(),
            null,
            new ShippingClassRepository()
        );
        $product = $catalog->createProduct($this->context, [
            'slug' => strtolower($sku),
            'name' => $sku,
            'type' => $type,
            'status' => 'active',
            'tax_class' => $taxClass,
            'variants' => [[
                'sku' => $sku,
                'option_values' => [],
                'price' => $price,
                'currency' => 'USD',
                'shipping_class_uuid' => $shippingClassUuid,
            ]],
        ]);
        $variantUuid = (string) $product['variants'][0]['uuid'];
        (new StockRepository())->increment($this->context, self::TENANT, $variantUuid, $stock);

        if ($sellerUuid !== null) {
            $this->connection->table('commerce_products')
                ->where('uuid', '=', $product['uuid'])
                ->update(['seller_uuid' => $sellerUuid]);
        }

        return ['product_uuid' => (string) $product['uuid'], 'variant_uuid' => $variantUuid];
    }

    /**
     * @param array<string,string> $optionValues
     * @return array{product_uuid: string, variant_uuid: string}
     */
    private function seedProductWithOptions(string $sku, array $optionValues): array
    {
        $catalog = new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            new SentinelTenantResolver(),
            new StockRepository()
        );
        $product = $catalog->createProduct($this->context, [
            'slug' => strtolower($sku),
            'name' => $sku,
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => $sku,
                'option_values' => $optionValues,
                'price' => 100,
                'currency' => 'USD',
            ]],
        ]);
        $variantUuid = (string) $product['variants'][0]['uuid'];
        (new StockRepository())->increment($this->context, self::TENANT, $variantUuid, 5);

        return ['product_uuid' => (string) $product['uuid'], 'variant_uuid' => $variantUuid];
    }

    /**
     * `CatalogService::createProduct()` itself refuses to attach variants to
     * an `external`/`grouped` product ({@see \Glueful\Extensions\Commerce\Cart\CartService::assertVariantCanSupply()}'s
     * own docblock: "a variant referencing one of them could still exist
     * (e.g. seeded directly, or a future code path)") -- so this fixture
     * creates the product NORMALLY (no variants key) and inserts the
     * variant row DIRECTLY, exactly matching that documented scenario.
     */
    private function seedExternalProductWithDirectlyInsertedVariant(string $sku): string
    {
        $catalog = new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            new SentinelTenantResolver(),
            new StockRepository()
        );
        $product = $catalog->createProduct($this->context, [
            'slug' => strtolower($sku),
            'name' => $sku,
            'type' => 'external',
            'status' => 'active',
            'metadata' => ['external_url' => 'https://example.com/' . strtolower($sku)],
        ]);

        $variantUuid = Utils::generateNanoID();
        (new VariantRepository())->insert($this->context, [
            'uuid' => $variantUuid,
            'tenant_uuid' => self::TENANT,
            'product_uuid' => (string) $product['uuid'],
            'sku' => $sku,
            'option_values' => [],
            'price' => 100,
            'currency' => 'USD',
            'position' => 0,
            'status' => 'active',
        ]);
        (new StockRepository())->increment($this->context, self::TENANT, $variantUuid, 5);

        return $variantUuid;
    }

    private function createCheckboxAddon(string $productUuid, int $priceDelta): array
    {
        return $this->addonService()->create($this->context, $productUuid, [
            'name' => 'Gift wrap',
            'field_type' => 'checkbox',
            'required' => false,
            'price_delta' => $priceDelta,
        ]);
    }
}
