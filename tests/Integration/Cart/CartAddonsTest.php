<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Cart;

use Glueful\Extensions\Commerce\Cart\CartRepository;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Catalog\AddonRepository;
use Glueful\Extensions\Commerce\Catalog\AddonService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Http\DTOs\AddCartLineData;
use Glueful\Extensions\Commerce\Http\Storefront\CartController;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Cart-level add-on behavior (design spec §4): line identity (variant + addons
 * hash), snapshot immutability against definition edits, guest→user merge
 * semantics, cross-hash stock aggregation, and the storefront cart's sanitized
 * addon echo. Regression: every existing (no-addons) CartServiceTest scenario
 * stays byte-identical -- these tests only add NEW coverage.
 */
final class CartAddonsTest extends CommerceTestCase
{
    // -----------------------------------------------------------------
    // Line identity
    // -----------------------------------------------------------------

    public function testTwoDifferentSelectionsCreateTwoLinesWithDistinctHashes(): void
    {
        ['product_uuid' => $productUuid, 'variant_uuid' => $variantUuid] = $this->seedProduct('SKU-CA1', 100, 1000);
        $addon = $this->createSelectAddon($productUuid, [
            ['key' => 'red', 'label' => 'Red', 'price_delta' => 100],
            ['key' => 'blue', 'label' => 'Blue', 'price_delta' => 200],
        ]);

        $service = $this->service();
        ['cart' => $cart] = $service->create($this->context);
        $cart = $service->addLine($this->context, $cart, $variantUuid, 1, [
            ['addon_uuid' => $addon['uuid'], 'choice_key' => 'red'],
        ]);
        $cart = $service->addLine($this->context, $cart, $variantUuid, 1, [
            ['addon_uuid' => $addon['uuid'], 'choice_key' => 'blue'],
        ]);

        $lines = $this->connection->table('commerce_cart_lines')->where('cart_uuid', '=', $cart['uuid'])->get();
        self::assertCount(2, $lines);
        self::assertNotSame($lines[0]['addons_hash'], $lines[1]['addons_hash']);
        self::assertNotSame('', $lines[0]['addons_hash']);
        self::assertNotSame('', $lines[1]['addons_hash']);
    }

    public function testEqualSelectionMergesQuantityIntoOneLine(): void
    {
        ['product_uuid' => $productUuid, 'variant_uuid' => $variantUuid] = $this->seedProduct('SKU-CA2', 100, 1000);
        $addon = $this->createCheckboxAddon($productUuid, 300);

        $service = $this->service();
        ['cart' => $cart] = $service->create($this->context);
        $cart = $service->addLine($this->context, $cart, $variantUuid, 1, [
            ['addon_uuid' => $addon['uuid'], 'value' => true],
        ]);
        $cart = $service->addLine($this->context, $cart, $variantUuid, 2, [
            ['addon_uuid' => $addon['uuid'], 'value' => true],
        ]);

        $lines = $this->connection->table('commerce_cart_lines')->where('cart_uuid', '=', $cart['uuid'])->get();
        self::assertCount(1, $lines);
        self::assertSame(3, (int) $lines[0]['quantity']);
    }

    public function testNoAddonsCartHashesEmptyAndMergesExactlyAsV1(): void
    {
        ['variant_uuid' => $variantUuid] = $this->seedProduct('SKU-CA3', 100, 1000);

        $service = $this->service();
        ['cart' => $cart] = $service->create($this->context);
        $cart = $service->addLine($this->context, $cart, $variantUuid, 1);
        $cart = $service->addLine($this->context, $cart, $variantUuid, 2);

        $lines = $this->connection->table('commerce_cart_lines')->where('cart_uuid', '=', $cart['uuid'])->get();
        self::assertCount(1, $lines);
        self::assertSame(3, (int) $lines[0]['quantity']);
        self::assertSame('', $lines[0]['addons_hash']);
        self::assertNull($lines[0]['addons']);
    }

    // -----------------------------------------------------------------
    // Guest -> user merge
    // -----------------------------------------------------------------

    public function testGuestToUserMergeCombinesOnlyEqualVariantAndHashLines(): void
    {
        ['product_uuid' => $productUuid, 'variant_uuid' => $variantUuid] = $this->seedProduct('SKU-CA4', 100, 1000);
        $addon = $this->createCheckboxAddon($productUuid, 300);
        $service = $this->service();

        // Guest cart: one plain line (hash '') and one hashed line for the SAME variant.
        ['cart' => $guest, 'token' => $guestToken] = $service->create($this->context);
        $guest = $service->addLine($this->context, $guest, $variantUuid, 1);
        $guest = $service->addLine($this->context, $guest, $variantUuid, 2, [
            ['addon_uuid' => $addon['uuid'], 'value' => true],
        ]);
        self::assertCount(2, $this->connection->table('commerce_cart_lines')
            ->where('cart_uuid', '=', $guest['uuid'])->get());

        // User cart already has a plain line (hash '') for the same variant.
        ['cart' => $mine, 'token' => $mineToken] = $service->create($this->context);
        $this->connection->table('commerce_carts')
            ->where('uuid', '=', $mine['uuid'])
            ->update(['user_uuid' => 'user00000042']);
        $mine = $service->byToken($this->context, $mineToken);
        self::assertNotNull($mine);
        $mine = $service->addLine($this->context, $mine, $variantUuid, 5);

        $merged = $service->mergeIntoUser($this->context, $guestToken, 'user00000042');

        $lines = $this->connection->table('commerce_cart_lines')->where('cart_uuid', '=', $merged['uuid'])->get();
        self::assertCount(2, $lines, 'hash "" and the hashed line stay two distinct lines after merge');

        $plain = $this->firstWhere($lines, 'addons_hash', '');
        self::assertNotNull($plain);
        self::assertSame(6, (int) $plain['quantity'], 'plain lines merge: 5 (existing) + 1 (guest) = 6');

        $hashed = array_values(array_filter($lines, static fn (array $l): bool => $l['addons_hash'] !== ''))[0] ?? null;
        self::assertNotNull($hashed);
        self::assertSame(2, (int) $hashed['quantity'], 'no existing hashed line in the user cart: guest quantity copied as-is');
        self::assertNotNull($hashed['addons'], 'snapshot is copied on insert, not rebuilt');

        self::assertSame(
            'abandoned',
            $this->connection->table('commerce_carts')->where('uuid', '=', $guest['uuid'])->first()['status'] ?? null
        );
    }

    // -----------------------------------------------------------------
    // Snapshot immutability against definition edits
    // -----------------------------------------------------------------

    public function testDefinitionPriceEditCreatesNewLineOldLinePriceUnchanged(): void
    {
        ['product_uuid' => $productUuid, 'variant_uuid' => $variantUuid] = $this->seedProduct('SKU-CA5', 100, 1000);
        $addon = $this->createCheckboxAddon($productUuid, 300);
        $service = $this->service();

        ['cart' => $cart] = $service->create($this->context);
        $cart = $service->addLine($this->context, $cart, $variantUuid, 1, [
            ['addon_uuid' => $addon['uuid'], 'value' => true],
        ]);

        $this->addonService()->update($this->context, $addon['uuid'], ['price_delta' => 900]);

        // Re-selecting the SAME addon now builds against the EDITED definition --
        // a different hash, therefore a second line, not a merge.
        $cart = $service->addLine($this->context, $cart, $variantUuid, 1, [
            ['addon_uuid' => $addon['uuid'], 'value' => true],
        ]);

        $lines = $this->connection->table('commerce_cart_lines')->where('cart_uuid', '=', $cart['uuid'])->get();
        self::assertCount(2, $lines);

        $unitPrices = array_map(
            static fn (array $line): int => (int) $line['unit_price'],
            $service->pricedLines($this->context, $cart)
        );
        sort($unitPrices);
        self::assertSame([1300, 1900], $unitPrices, 'old line keeps its 300 delta; new line reflects the edited 900');
    }

    public function testPersistedSnapshotPricingImmuneToDefinitionEdits(): void
    {
        ['product_uuid' => $productUuid, 'variant_uuid' => $variantUuid] = $this->seedProduct('SKU-CA6', 100, 1000);
        $addon = $this->createCheckboxAddon($productUuid, 300);
        $service = $this->service();

        ['cart' => $cart] = $service->create($this->context);
        $cart = $service->addLine($this->context, $cart, $variantUuid, 1, [
            ['addon_uuid' => $addon['uuid'], 'value' => true],
        ]);

        $before = $service->pricedLines($this->context, $cart);
        self::assertCount(1, $before);
        self::assertSame(1300, $before[0]['unit_price']);

        // Edit BOTH price and name/definition shape -- pricedLines() never
        // re-resolves definitions, so neither edit can touch the persisted line.
        $this->addonService()->update($this->context, $addon['uuid'], ['price_delta' => 900]);
        $this->addonService()->update($this->context, $addon['uuid'], ['name' => 'Renamed Gift Wrap']);

        $after = $service->pricedLines($this->context, $cart);
        self::assertCount(1, $after);
        self::assertSame(1300, $after[0]['unit_price']);
    }

    // -----------------------------------------------------------------
    // Negative final price
    // -----------------------------------------------------------------

    public function testNegativeFinalUnitPriceIs422ValidationException(): void
    {
        ['product_uuid' => $productUuid, 'variant_uuid' => $variantUuid] = $this->seedProduct('SKU-CA7', 100, 500);
        $addon = $this->createCheckboxAddon($productUuid, -600);
        $service = $this->service();

        ['cart' => $cart] = $service->create($this->context);

        try {
            $service->addLine($this->context, $cart, $variantUuid, 1, [
                ['addon_uuid' => $addon['uuid'], 'value' => true],
            ]);
            self::fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getStatusCode());
            self::assertArrayHasKey('addons', $e->firstErrors());
        }

        self::assertSame(0, $this->connection->table('commerce_cart_lines')->where('cart_uuid', '=', $cart['uuid'])->count());
    }

    // -----------------------------------------------------------------
    // Stock aggregation across hashes
    // -----------------------------------------------------------------

    public function testAddLineAggregatesStockAcrossHashedLinesForTheSameVariant(): void
    {
        ['product_uuid' => $productUuid, 'variant_uuid' => $variantUuid] = $this->seedProduct('SKU-CA8', 5, 1000);
        $addon = $this->createCheckboxAddon($productUuid, 100);
        $service = $this->service();

        ['cart' => $cart] = $service->create($this->context);
        $cart = $service->addLine($this->context, $cart, $variantUuid, 3, [
            ['addon_uuid' => $addon['uuid'], 'value' => true],
        ]);
        $cart = $service->addLine($this->context, $cart, $variantUuid, 2);

        $lines = $this->connection->table('commerce_cart_lines')->where('cart_uuid', '=', $cart['uuid'])->get();
        self::assertCount(2, $lines);
        $total = array_sum(array_map(static fn (array $l): int => (int) $l['quantity'], $lines));
        self::assertSame(5, $total, 'sanity: two hashed lines for the variant already use up all 5 in stock');

        // A THIRD, differently-hashed line for the same variant (checkbox=false is
        // a distinct snapshot/hash) would push the variant's total to 6 > 5 in stock.
        $this->expectException(ValidationException::class);
        $service->addLine($this->context, $cart, $variantUuid, 1, [
            ['addon_uuid' => $addon['uuid'], 'value' => false],
        ]);
    }

    // -----------------------------------------------------------------
    // Storefront cart: sanitized addon echo
    // -----------------------------------------------------------------

    public function testStorefrontCartEchoesOnlyTheWhitelistedAddonFields(): void
    {
        ['product_uuid' => $productUuid, 'variant_uuid' => $variantUuid] = $this->seedProduct('SKU-CA9', 100, 1000);
        $addon = $this->createSelectAddon($productUuid, [
            ['key' => 'red', 'label' => 'Red', 'price_delta' => 150],
        ]);

        $controller = new CartController($this->context, $this->service());
        $created = $controller->create(Request::create('/commerce/cart', 'POST'));
        $token = (string) $this->json($created)['data']['token'];

        $request = Request::create('/commerce/cart/lines', 'POST');
        $request->headers->set('X-Cart-Token', $token);
        $response = $controller->addLine(
            new AddCartLineData($variantUuid, 1, [['addon_uuid' => $addon['uuid'], 'choice_key' => 'red']]),
            $request
        );

        self::assertSame(200, $response->getStatusCode());
        $raw = (string) $response->getContent();
        $body = $this->json($response);

        $lineAddons = $body['data']['lines'][0]['addons'];
        self::assertCount(1, $lineAddons);
        self::assertSame(['name', 'field_type', 'choice_label', 'price_delta'], array_keys($lineAddons[0]));
        self::assertSame('Red', $lineAddons[0]['choice_label']);
        self::assertSame(150, $lineAddons[0]['price_delta']);

        self::assertStringNotContainsString($addon['uuid'], $raw);
        self::assertStringNotContainsString('"choice_key"', $raw);
        self::assertStringNotContainsString('"choices"', $raw);
        self::assertStringNotContainsString('"addon_uuid"', $raw);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function service(): CartService
    {
        return new CartService(
            new CartRepository(),
            new VariantRepository(),
            new ProductRepository(),
            new StockRepository(),
            new DiscountRepository(),
            new PricingEngine(),
            new SentinelTenantResolver(),
            new AddonRepository()
        );
    }

    private function addonService(): AddonService
    {
        return new AddonService(new AddonRepository(), new ProductRepository(), new SentinelTenantResolver());
    }

    /** @return array{product_uuid: string, variant_uuid: string} */
    private function seedProduct(string $sku, int $stock, int $price): array
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
                'option_values' => [],
                'price' => $price,
                'currency' => 'USD',
            ]],
        ]);
        $variantUuid = (string) $product['variants'][0]['uuid'];
        (new StockRepository())->increment($this->context, '', $variantUuid, $stock);

        return ['product_uuid' => (string) $product['uuid'], 'variant_uuid' => $variantUuid];
    }

    /** @return array<string,mixed> */
    private function createCheckboxAddon(string $productUuid, int $priceDelta, bool $required = false): array
    {
        return $this->addonService()->create($this->context, $productUuid, [
            'name' => 'Gift wrap',
            'field_type' => 'checkbox',
            'required' => $required,
            'price_delta' => $priceDelta,
        ]);
    }

    /**
     * @param list<array{key:string,label:string,price_delta:int}> $choices
     * @return array<string,mixed>
     */
    private function createSelectAddon(string $productUuid, array $choices, bool $required = false): array
    {
        return $this->addonService()->create($this->context, $productUuid, [
            'name' => 'Color',
            'field_type' => 'select',
            'required' => $required,
            'choices' => $choices,
        ]);
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed>|null */
    private function firstWhere(array $rows, string $key, mixed $value): ?array
    {
        foreach ($rows as $row) {
            if (($row[$key] ?? null) === $value) {
                return $row;
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function json(\Symfony\Component\HttpFoundation\Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
