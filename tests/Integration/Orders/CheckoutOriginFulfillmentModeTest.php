<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Cart\CartRepository;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\DownloadRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * Review fix (Important 2): `commerce_orders.origin`/`.fulfillment_mode` keep a
 * standing DB-level default (design decision documented on migration 022's
 * `down()`-adjacent docblock) rather than a backfill-then-drop-default rewrite --
 * accepted specifically BECAUSE the real production writer, {@see CheckoutService},
 * sets both columns explicitly and never leans on that default. This test proves
 * that claim directly rather than merely trusting the source: it DROPS the standing
 * default on this test's own private connection first (so a writer that merely
 * relied on the default would now hit a NOT NULL failure), then exercises the REAL
 * `placeOrder()` path and asserts it still succeeds with the correct values --
 * which is only possible if `CheckoutService` genuinely writes both columns itself.
 */
final class CheckoutOriginFulfillmentModeTest extends CommerceTestCase
{
    public function testPlaceOrderWritesOriginAndFulfillmentModeExplicitlyNotViaDefault(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        $schema->alterTable('commerce_orders', function ($table): void {
            // Drop the standing default only -- keep NOT NULL. A writer that omits
            // either column would now fail loudly instead of silently defaulting.
            $table->modifyColumn('origin')->string(16)->notNull();
            $table->modifyColumn('fulfillment_mode')->string(16)->notNull();
        });

        $variantUuid = $this->seedVariant('SKU-ORIGIN-1', 5, 1000);
        ['cart' => $cart, 'token' => $token] = $this->cart()->create($this->context);
        $this->cart()->addLine($this->context, $cart, $variantUuid, 1);

        $placed = $this->checkout()->placeOrder(
            $this->context,
            $token,
            ['email' => 'buyer@example.com', 'user_uuid' => null],
            ['shipping' => ['country' => 'US'], 'billing' => ['country' => 'US']],
            'std'
        );

        $order = (new OrderRepository())->findByUuid($this->context, '', (string) $placed['order']['uuid']);
        self::assertNotNull($order);
        self::assertSame('storefront', $order['origin']);
        self::assertSame('delivery', $order['fulfillment_mode']);
    }

    private function seedVariant(string $sku, int $stock, int $price): string
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

        return $variantUuid;
    }

    private function cart(): CartService
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

    private function checkout(): CheckoutService
    {
        return new CheckoutService(
            $this->cart(),
            new DiscountRepository(),
            new DiscountService(new DiscountRepository(), new SentinelTenantResolver()),
            new StockRepository(),
            new PricingEngine(),
            $this->shipping(),
            $this->tax(),
            new OrderNumberGenerator(),
            new OrderRepository(),
            new DownloadRepository(),
            new ManualPaymentCollector(),
            new SentinelTenantResolver()
        );
    }

    private function shipping(): ShippingRateProvider
    {
        return new class implements ShippingRateProvider {
            public function quote(ApplicationContext $context, array $lines, array $shippingAddress): array
            {
                return [new ShippingQuote('std', 'Standard', 500)];
            }
        };
    }

    private function tax(): TaxCalculator
    {
        return new class implements TaxCalculator {
            public function quote(ApplicationContext $context, int $taxableAmount, array $shippingAddress): TaxQuote
            {
                return new TaxQuote(0);
            }
        };
    }
}
