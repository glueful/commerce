<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Cart;

use Glueful\Extensions\Commerce\Cart\CartRepository;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * `CartService::pricedLines()`'s `tax_class` additive key (design spec §5
 * wiring): the product's resolved tax class, `null` normalizing to
 * `'standard'`, layered onto the existing shape alongside `line_uuid` and
 * `shipping_class` (Task 4) -- nothing else changes.
 */
final class CartLineTaxClassTest extends CommerceTestCase
{
    public function testPricedLinesResolvesExplicitTaxClass(): void
    {
        $variantUuid = $this->seedVariant('SKU-TC', 5, 1000, 'reduced');
        $service = $this->service();
        ['cart' => $cart] = $service->create($this->context);
        $service->addLine($this->context, $cart, $variantUuid, 1);

        $priced = $service->pricedLines($this->context, $cart);

        self::assertSame('reduced', $priced[0]['tax_class']);
    }

    public function testPricedLinesDefaultsTaxClassToStandardWhenProductHasNone(): void
    {
        $variantUuid = $this->seedVariant('SKU-TCN', 5, 1000, null);
        $service = $this->service();
        ['cart' => $cart] = $service->create($this->context);
        $service->addLine($this->context, $cart, $variantUuid, 1);

        $priced = $service->pricedLines($this->context, $cart);

        self::assertSame('standard', $priced[0]['tax_class']);
    }

    public function testPricedLinesShapeStillCarriesPreExistingKeysUnchanged(): void
    {
        $variantUuid = $this->seedVariant('SKU-TCU', 5, 1234, null);
        $service = $this->service();
        ['cart' => $cart] = $service->create($this->context);
        $service->addLine($this->context, $cart, $variantUuid, 2);

        $line = $service->pricedLines($this->context, $cart)[0];

        self::assertSame(1234, $line['unit_price']);
        self::assertSame(2, $line['quantity']);
        self::assertSame('USD', $line['currency']);
        self::assertSame('SKU-TCU', $line['sku']);
        self::assertSame('physical', $line['type']);
        self::assertSame([], $line['addons']);
        self::assertArrayHasKey('line_uuid', $line);
        self::assertArrayHasKey('shipping_class', $line);
    }

    private function service(): CartService
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

    private function seedVariant(string $sku, int $quantity, int $price, ?string $taxClass): string
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
            'tax_class' => $taxClass,
            'variants' => [[
                'sku' => $sku,
                'option_values' => [],
                'price' => $price,
                'currency' => 'USD',
            ]],
        ]);

        $variantUuid = (string) $product['variants'][0]['uuid'];
        (new StockRepository())->increment($this->context, '', $variantUuid, $quantity);

        return $variantUuid;
    }
}
