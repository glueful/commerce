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
use Glueful\Extensions\Commerce\Support\TokenHasher;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Validation\ValidationException;

final class CartServiceTest extends CommerceTestCase
{
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

    public function testCreateStoresOnlyHash(): void
    {
        ['cart' => $cart, 'token' => $token] = $this->service()->create($this->context);

        self::assertSame(40, strlen($token));
        $row = $this->connection->table('commerce_carts')
            ->where('uuid', '=', $cart['uuid'])
            ->first();
        self::assertNotNull($row);
        self::assertSame(TokenHasher::hash($token), $row['token_hash']);
        self::assertStringNotContainsString($token, json_encode($row, JSON_THROW_ON_ERROR));
    }

    public function testByTokenRoundTripAndWrongTokenIsNull(): void
    {
        ['cart' => $cart, 'token' => $token] = $this->service()->create($this->context);

        self::assertSame($cart['uuid'], $this->service()->byToken($this->context, $token)['uuid'] ?? null);
        self::assertNull($this->service()->byToken($this->context, str_repeat('0', 40)));
    }

    public function testAddLineChecksTrackedStockAdvisory(): void
    {
        $variantUuid = $this->seedVariant('SKU-S', 1);
        ['cart' => $cart] = $this->service()->create($this->context);

        $this->expectException(ValidationException::class);

        $this->service()->addLine($this->context, $cart, $variantUuid, 2);
    }

    public function testMergeAddsQuantitiesAndAbandonsGuestCart(): void
    {
        $service = $this->service();
        $variantUuid = $this->seedVariant('SKU-M', 10);
        ['cart' => $guest, 'token' => $guestToken] = $service->create($this->context);
        $service->addLine($this->context, $guest, $variantUuid, 2);

        ['cart' => $mine, 'token' => $mineToken] = $service->create($this->context);
        $this->connection->table('commerce_carts')
            ->where('uuid', '=', $mine['uuid'])
            ->update(['user_uuid' => 'user00000001']);
        $mine = $service->byToken($this->context, $mineToken);
        self::assertNotNull($mine);
        $service->addLine($this->context, $mine, $variantUuid, 1);

        $merged = $service->mergeIntoUser($this->context, $guestToken, 'user00000001');
        $line = $this->connection->table('commerce_cart_lines')
            ->where('cart_uuid', '=', $merged['uuid'])
            ->first();

        self::assertNotNull($line);
        self::assertSame(3, (int) $line['quantity']);
        self::assertSame(
            'abandoned',
            $this->connection->table('commerce_carts')->where('uuid', '=', $guest['uuid'])->first()['status'] ?? null
        );
    }

    public function testViewTotalsMatchPricingEngine(): void
    {
        $service = $this->service();
        $variantUuid = $this->seedVariant('SKU-V', 5, 700);
        ['cart' => $cart] = $service->create($this->context);
        $cart = $service->addLine($this->context, $cart, $variantUuid, 3);

        $view = $service->view($this->context, $cart);

        self::assertSame(2100, $view['totals']->subtotal);
        self::assertSame(2100, $view['totals']->grandTotal);
    }

    private function seedVariant(string $sku, int $quantity, int $price = 100): string
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
            'variants' => [
                [
                    'sku' => $sku,
                    'option_values' => [],
                    'price' => $price,
                    'currency' => 'USD',
                ],
            ],
        ]);

        $variantUuid = (string) $product['variants'][0]['uuid'];
        (new StockRepository())->increment($this->context, '', $variantUuid, $quantity);

        return $variantUuid;
    }
}
