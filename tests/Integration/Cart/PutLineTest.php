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
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Validation\ValidationException;

/**
 * `putLine()` (Commerce-Slice-2 Task 2): convergent set-to-desired-quantity
 * counterpart to `addLine()`'s accumulate-by-delta. Same claim protocol, same
 * variant+addons line identity, same stock/availability validation -- the
 * only behavioral difference is that `putLine()` treats `$quantity` as the
 * FINAL desired quantity for the matching line (insert/update/remove) rather
 * than a delta added to whatever is already there.
 */
final class PutLineTest extends CommerceTestCase
{
    // -----------------------------------------------------------------
    // Convergent set semantics (vs addLine's accumulation)
    // -----------------------------------------------------------------

    public function testPutLineTwiceWithSameQuantityConvergesToOneLineNotAccumulated(): void
    {
        $variantUuid = $this->seedVariant('SKU-PL1', 10);
        $service = $this->service();
        ['cart' => $cart] = $service->create($this->context);

        $cart = $service->putLine($this->context, $cart, $variantUuid, 2);
        $cart = $service->putLine($this->context, $cart, $variantUuid, 2);

        $lines = $this->connection->table('commerce_cart_lines')->where('cart_uuid', '=', $cart['uuid'])->get();
        self::assertCount(1, $lines);
        self::assertSame(2, (int) $lines[0]['quantity'], 'putLine converges to the DESIRED quantity, not 2+2=4');
    }

    public function testAddLineStillAccumulatesQuantityAcrossRepeatedCalls(): void
    {
        // Regression pin (brief: "addLine() byte-untouched"): the exact contrast
        // case named in the task brief, asserted explicitly in this file.
        $variantUuid = $this->seedVariant('SKU-PL2', 10);
        $service = $this->service();
        ['cart' => $cart] = $service->create($this->context);

        $cart = $service->addLine($this->context, $cart, $variantUuid, 2);
        $cart = $service->addLine($this->context, $cart, $variantUuid, 2);

        $lines = $this->connection->table('commerce_cart_lines')->where('cart_uuid', '=', $cart['uuid'])->get();
        self::assertCount(1, $lines);
        self::assertSame(4, (int) $lines[0]['quantity'], 'addLine still SUMS deltas: 2 + 2 = 4');
    }

    public function testPutLineSequentialPutsConvergeToTheLastDesiredQuantity(): void
    {
        // Deterministic stand-in for concurrent convergence: each put runs its own
        // claimActiveCart()-protected transaction, so a sequence of puts against
        // the same cart+variant+addons identity must land on the LAST desired
        // value, never an accumulation of the intermediate ones.
        $variantUuid = $this->seedVariant('SKU-PL3', 20);
        $service = $this->service();
        ['cart' => $cart] = $service->create($this->context);

        $cart = $service->putLine($this->context, $cart, $variantUuid, 5);
        $cart = $service->putLine($this->context, $cart, $variantUuid, 1);
        $cart = $service->putLine($this->context, $cart, $variantUuid, 7);

        $lines = $this->connection->table('commerce_cart_lines')->where('cart_uuid', '=', $cart['uuid'])->get();
        self::assertCount(1, $lines);
        self::assertSame(7, (int) $lines[0]['quantity']);
    }

    // -----------------------------------------------------------------
    // Insert / remove / no-op
    // -----------------------------------------------------------------

    public function testPutLineInsertsWhenNoMatchingLineExists(): void
    {
        $variantUuid = $this->seedVariant('SKU-PL4', 10);
        $service = $this->service();
        ['cart' => $cart] = $service->create($this->context);

        $cart = $service->putLine($this->context, $cart, $variantUuid, 3);

        $lines = $this->connection->table('commerce_cart_lines')->where('cart_uuid', '=', $cart['uuid'])->get();
        self::assertCount(1, $lines);
        self::assertSame($variantUuid, $lines[0]['variant_uuid']);
        self::assertSame(3, (int) $lines[0]['quantity']);
    }

    public function testPutLineWithZeroQuantityRemovesExistingLine(): void
    {
        $variantUuid = $this->seedVariant('SKU-PL5', 10);
        $service = $this->service();
        ['cart' => $cart] = $service->create($this->context);
        $cart = $service->addLine($this->context, $cart, $variantUuid, 4);
        self::assertSame(
            1,
            $this->connection->table('commerce_cart_lines')->where('cart_uuid', '=', $cart['uuid'])->count()
        );

        $cart = $service->putLine($this->context, $cart, $variantUuid, 0);

        self::assertSame(
            0,
            $this->connection->table('commerce_cart_lines')->where('cart_uuid', '=', $cart['uuid'])->count()
        );
    }

    public function testPutLineWithNegativeQuantityAlsoRemovesExistingLine(): void
    {
        $variantUuid = $this->seedVariant('SKU-PL5B', 10);
        $service = $this->service();
        ['cart' => $cart] = $service->create($this->context);
        $cart = $service->addLine($this->context, $cart, $variantUuid, 4);

        $cart = $service->putLine($this->context, $cart, $variantUuid, -1);

        self::assertSame(
            0,
            $this->connection->table('commerce_cart_lines')->where('cart_uuid', '=', $cart['uuid'])->count()
        );
    }

    public function testPutLineWithZeroQuantityOnAbsentLineIsCleanNoOp(): void
    {
        $variantUuid = $this->seedVariant('SKU-PL6', 10);
        $service = $this->service();
        ['cart' => $cart] = $service->create($this->context);

        $result = $service->putLine($this->context, $cart, $variantUuid, 0);

        self::assertSame($cart['uuid'], $result['uuid']);
        self::assertSame(
            0,
            $this->connection->table('commerce_cart_lines')->where('cart_uuid', '=', $cart['uuid'])->count()
        );
    }

    // -----------------------------------------------------------------
    // Line identity: same as addLine (variant + addons hash)
    // -----------------------------------------------------------------

    public function testPutLineWithDifferentAddonSelectionsCreatesDistinctLines(): void
    {
        ['product_uuid' => $productUuid, 'variant_uuid' => $variantUuid] = $this->seedProduct('SKU-PL7', 100, 1000);
        $addon = $this->createSelectAddon($productUuid, [
            ['key' => 'red', 'label' => 'Red', 'price_delta' => 100],
            ['key' => 'blue', 'label' => 'Blue', 'price_delta' => 200],
        ]);
        $service = $this->service();
        ['cart' => $cart] = $service->create($this->context);

        $cart = $service->putLine($this->context, $cart, $variantUuid, 1, [
            ['addon_uuid' => $addon['uuid'], 'choice_key' => 'red'],
        ]);
        $cart = $service->putLine($this->context, $cart, $variantUuid, 1, [
            ['addon_uuid' => $addon['uuid'], 'choice_key' => 'blue'],
        ]);

        $lines = $this->connection->table('commerce_cart_lines')->where('cart_uuid', '=', $cart['uuid'])->get();
        self::assertCount(2, $lines);
        self::assertNotSame($lines[0]['addons_hash'], $lines[1]['addons_hash']);
    }

    public function testPutLineWithSameAddonSelectionConvergesTheMatchingLineOnly(): void
    {
        ['product_uuid' => $productUuid, 'variant_uuid' => $variantUuid] = $this->seedProduct('SKU-PL8', 100, 1000);
        $addon = $this->createCheckboxAddon($productUuid, 300);
        $service = $this->service();
        ['cart' => $cart] = $service->create($this->context);

        $cart = $service->putLine($this->context, $cart, $variantUuid, 1, [
            ['addon_uuid' => $addon['uuid'], 'value' => true],
        ]);
        $cart = $service->putLine($this->context, $cart, $variantUuid, 5, [
            ['addon_uuid' => $addon['uuid'], 'value' => true],
        ]);

        $lines = $this->connection->table('commerce_cart_lines')->where('cart_uuid', '=', $cart['uuid'])->get();
        self::assertCount(1, $lines);
        self::assertSame(5, (int) $lines[0]['quantity']);
    }

    // -----------------------------------------------------------------
    // Stock/validation identical to addLine: desired quantity, not delta
    // -----------------------------------------------------------------

    public function testPutLineInsufficientDesiredStockThrowsSame422AsAddLine(): void
    {
        $variantUuid = $this->seedVariant('SKU-PL9', 1);
        $service = $this->service();
        ['cart' => $cart] = $service->create($this->context);

        try {
            $service->putLine($this->context, $cart, $variantUuid, 2);
            self::fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getStatusCode());
            self::assertArrayHasKey('quantity', $e->firstErrors());
            self::assertSame('Requested quantity exceeds available stock.', $e->firstErrors()['quantity']);
        }

        self::assertSame(
            0,
            $this->connection->table('commerce_cart_lines')->where('cart_uuid', '=', $cart['uuid'])->count()
        );
    }

    public function testPutLineValidatesTheDesiredQuantityNotADeltaFromTheExistingLine(): void
    {
        $variantUuid = $this->seedVariant('SKU-PL10', 3);
        $service = $this->service();
        ['cart' => $cart] = $service->create($this->context);

        // Desired quantity 2, well within the 3 in stock.
        $cart = $service->putLine($this->context, $cart, $variantUuid, 2);

        // Desired quantity 3 (not 2+3=5): still within stock, must succeed.
        $cart = $service->putLine($this->context, $cart, $variantUuid, 3);
        $lines = $this->connection->table('commerce_cart_lines')->where('cart_uuid', '=', $cart['uuid'])->get();
        self::assertCount(1, $lines);
        self::assertSame(3, (int) $lines[0]['quantity']);

        // Desired quantity 4 exceeds the 3 in stock -> 422, line left untouched at 3.
        $this->expectException(ValidationException::class);
        try {
            $service->putLine($this->context, $cart, $variantUuid, 4);
        } finally {
            $line = $this->connection->table('commerce_cart_lines')
                ->where('cart_uuid', '=', $cart['uuid'])->first();
            self::assertNotNull($line);
            self::assertSame(3, (int) $line['quantity'], 'a rejected put must not mutate the existing line');
        }
    }

    // -----------------------------------------------------------------
    // Claim protocol: same as addLine (a checkout-claimed cart refuses mutation)
    // -----------------------------------------------------------------

    public function testPutLineRefusesACartClaimedByCheckout(): void
    {
        $variantUuid = $this->seedVariant('SKU-PL11', 5);
        $service = $this->service();
        ['cart' => $cart] = $service->create($this->context);
        $service->claimForCheckout($this->context, $cart);

        $this->expectException(ValidationException::class);
        $service->putLine($this->context, $cart, $variantUuid, 1);
    }

    // -----------------------------------------------------------------
    // Return shape: same as addLine
    // -----------------------------------------------------------------

    public function testPutLineReturnsTheReloadedCartShapeSameAsAddLine(): void
    {
        $variantUuid = $this->seedVariant('SKU-PL12', 10, 700);
        $service = $this->service();
        ['cart' => $cart] = $service->create($this->context);

        $viaAdd = $service->addLine($this->context, $cart, $variantUuid, 1);
        $cart2 = $service->create($this->context)['cart'];
        $viaPut = $service->putLine($this->context, $cart2, $variantUuid, 1);

        self::assertSame(array_keys($viaAdd), array_keys($viaPut));
    }

    // -----------------------------------------------------------------
    // Fixtures (mirrors CartServiceTest / CartAddonsTest)
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

    private function seedVariant(string $sku, int $quantity, int $price = 100): string
    {
        return $this->seedProduct($sku, $quantity, $price)['variant_uuid'];
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
}
