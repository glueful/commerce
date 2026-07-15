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
use Glueful\Extensions\Commerce\Shipping\ShippingClassRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * `CartService::pricedLines()` additive keys (design spec §2/§4 wiring): the
 * NEW `line_uuid` and `shipping_class` keys layered onto the pre-existing
 * shape. Also the regression gate that nothing else in that shape moved --
 * existing consumers (addons/addons_hash) are untouched by this change.
 */
final class CartLineShippingClassTest extends CommerceTestCase
{
    public function testPricedLinesIncludesLineUuidMatchingThePersistedCartLine(): void
    {
        $variantUuid = $this->seedVariant('SKU-LU', 5, 1000);
        $service = $this->service();
        ['cart' => $cart] = $service->create($this->context);
        $service->addLine($this->context, $cart, $variantUuid, 1);

        $persistedLine = $this->connection->table('commerce_cart_lines')
            ->where('cart_uuid', '=', $cart['uuid'])
            ->first();
        self::assertNotNull($persistedLine);

        $priced = $service->pricedLines($this->context, $cart);

        self::assertCount(1, $priced);
        self::assertSame((string) $persistedLine['uuid'], $priced[0]['line_uuid']);
    }

    public function testPricedLinesResolvesShippingClassSlugForVariantWithClass(): void
    {
        $classUuid = $this->seedShippingClass('fragile', 'Fragile');
        $variantUuid = $this->seedVariant('SKU-SC', 5, 1000, $classUuid);
        $service = $this->service();
        ['cart' => $cart] = $service->create($this->context);
        $service->addLine($this->context, $cart, $variantUuid, 1);

        $priced = $service->pricedLines($this->context, $cart);

        self::assertSame('fragile', $priced[0]['shipping_class']);
    }

    public function testPricedLinesShippingClassNullWhenVariantHasNoClass(): void
    {
        $variantUuid = $this->seedVariant('SKU-NC', 5, 1000);
        $service = $this->service();
        ['cart' => $cart] = $service->create($this->context);
        $service->addLine($this->context, $cart, $variantUuid, 1);

        $priced = $service->pricedLines($this->context, $cart);

        self::assertNull($priced[0]['shipping_class']);
    }

    public function testPricedLinesResolvesShippingClassAcrossMultipleLinesInOneBatch(): void
    {
        $fragileUuid = $this->seedShippingClass('fragile', 'Fragile');
        $oversizedUuid = $this->seedShippingClass('oversized', 'Oversized');
        $variantA = $this->seedVariant('SKU-MA', 5, 1000, $fragileUuid);
        $variantB = $this->seedVariant('SKU-MB', 5, 1000, $oversizedUuid);
        $variantC = $this->seedVariant('SKU-MC', 5, 1000);

        $service = $this->service();
        ['cart' => $cart] = $service->create($this->context);
        $cart = $service->addLine($this->context, $cart, $variantA, 1);
        $cart = $service->addLine($this->context, $cart, $variantB, 1);
        $service->addLine($this->context, $cart, $variantC, 1);

        $priced = $service->pricedLines($this->context, $cart);
        $bySku = [];
        foreach ($priced as $line) {
            $bySku[$line['sku']] = $line['shipping_class'];
        }

        self::assertSame('fragile', $bySku['SKU-MA']);
        self::assertSame('oversized', $bySku['SKU-MB']);
        self::assertNull($bySku['SKU-MC']);
    }

    public function testPricedLinesShapeStillCarriesPreExistingKeysUnchanged(): void
    {
        $variantUuid = $this->seedVariant('SKU-SH', 5, 1234);
        $service = $this->service();
        ['cart' => $cart] = $service->create($this->context);
        $service->addLine($this->context, $cart, $variantUuid, 2);

        $line = $service->pricedLines($this->context, $cart)[0];

        self::assertSame(1234, $line['unit_price']);
        self::assertSame(2, $line['quantity']);
        self::assertSame('USD', $line['currency']);
        self::assertSame('SKU-SH', $line['sku']);
        self::assertSame('physical', $line['type']);
        self::assertSame([], $line['addons']);
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

    private function seedShippingClass(string $slug, string $name): string
    {
        $uuid = 'clas' . substr(md5($slug), 0, 8);
        $this->connection->table('commerce_shipping_classes')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => '',
            'slug' => $slug,
            'name' => $name,
        ]);

        return $uuid;
    }

    private function seedVariant(
        string $sku,
        int $quantity,
        int $price = 100,
        ?string $shippingClassUuid = null
    ): string {
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
            'type' => 'physical',
            'status' => 'active',
            'variants' => [
                [
                    'sku' => $sku,
                    'option_values' => [],
                    'price' => $price,
                    'currency' => 'USD',
                    'shipping_class_uuid' => $shippingClassUuid,
                ],
            ],
        ]);

        $variantUuid = (string) $product['variants'][0]['uuid'];
        (new StockRepository())->increment($this->context, '', $variantUuid, $quantity);

        return $variantUuid;
    }
}
