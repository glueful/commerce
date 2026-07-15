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
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Commerce\Shipping\ConfigShippingRateProvider;
use Glueful\Extensions\Commerce\Shipping\DbShippingRateProvider;
use Glueful\Extensions\Commerce\Shipping\DelegatingShippingRateProvider;
use Glueful\Extensions\Commerce\Shipping\ShippingZoneRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * Checkout end-to-end through the DB shipping-zone path (design spec §4/§7):
 * wires the REAL {@see DelegatingShippingRateProvider} (Db + Config) rather
 * than a test double, seeds zone/method rows directly, and drives
 * `CheckoutService::quote()`/`placeOrder()` exactly like the existing
 * {@see CheckoutTest} does for the config path.
 */
final class CheckoutShippingZonesTest extends CommerceTestCase
{
    public function testCheckoutQuotesThroughDbZonePath(): void
    {
        $this->insertZoneWithFlatMethod('US Zone', 'US', 750);

        [$token] = $this->seedCartWithLine('SKU-DBZ', 5, 2, 1000);
        $cart = $this->cart()->byToken($this->context, $token);
        self::assertNotNull($cart);

        $quote = $this->checkout()->quote($this->context, $cart, ['country' => 'US'], null);

        self::assertCount(1, $quote['shipping_options']);
        self::assertSame(750, $quote['shipping_options'][0]->amount);
        self::assertSame(750, $quote['totals']->shippingTotal);
        self::assertSame(2000 + 750, $quote['totals']->grandTotal);
    }

    public function testCheckoutPlaceOrderChargesTheDbQuotedShippingAmount(): void
    {
        $this->insertZoneWithFlatMethod('US Zone', 'US', 750);

        [$token] = $this->seedCartWithLine('SKU-DBZ2', 5, 2, 1000);

        $quoteForComparison = $this->checkout()->quote(
            $this->context,
            $this->cart()->byToken($this->context, $token),
            ['country' => 'US'],
            null
        );

        $placed = $this->checkout()->placeOrder(
            $this->context,
            $token,
            ['email' => 'buyer@example.com', 'user_uuid' => null],
            ['shipping' => ['country' => 'US'], 'billing' => ['country' => 'US']],
            null
        );

        self::assertSame((int) $quoteForComparison['totals']->grandTotal, (int) $placed['order']['grand_total']);
        self::assertSame(2750, (int) $placed['order']['grand_total']);
    }

    public function testCheckoutNoMatchingZoneYieldsNoShippingOptions(): void
    {
        $this->insertZoneWithFlatMethod('CA Zone', 'CA', 750);

        [$token] = $this->seedCartWithLine('SKU-DBZ3', 5, 1, 1000);
        $cart = $this->cart()->byToken($this->context, $token);
        self::assertNotNull($cart);

        $quote = $this->checkout()->quote($this->context, $cart, ['country' => 'US'], null);

        self::assertSame([], $quote['shipping_options']);
    }

    private function insertZoneWithFlatMethod(string $zoneName, string $country, int $amount): void
    {
        $zoneUuid = 'zone' . substr(md5($zoneName), 0, 8);
        $this->connection->table('commerce_shipping_zones')->insert([
            'uuid' => $zoneUuid,
            'tenant_uuid' => '',
            'name' => $zoneName,
            'position' => 0,
        ]);
        $this->connection->table('commerce_shipping_zone_locations')->insert([
            'zone_uuid' => $zoneUuid,
            'kind' => 'country',
            'value' => $country,
        ]);
        $this->connection->table('commerce_shipping_methods')->insert([
            'uuid' => 'meth' . substr(md5($zoneName), 0, 8),
            'zone_uuid' => $zoneUuid,
            'kind' => 'flat',
            'label' => 'Standard',
            'config' => json_encode(['amount' => $amount], JSON_THROW_ON_ERROR),
            'position' => 0,
        ]);
    }

    private function checkout(): CheckoutService
    {
        return new CheckoutService(
            $this->cart(),
            new DiscountRepository(),
            new DiscountService(new DiscountRepository(), new SentinelTenantResolver()),
            new StockRepository(),
            new PricingEngine(),
            new DelegatingShippingRateProvider(
                new DbShippingRateProvider(new ShippingZoneRepository(), new SentinelTenantResolver()),
                new ConfigShippingRateProvider()
            ),
            $this->tax(),
            new OrderNumberGenerator(),
            new OrderRepository(),
            new DownloadRepository(),
            new ManualPaymentCollector(),
            new SentinelTenantResolver()
        );
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

    /** @return array{0: string, 1: string} */
    private function seedCartWithLine(string $sku, int $stock, int $quantity, int $price): array
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
        ['cart' => $cart, 'token' => $token] = $this->cart()->create($this->context);
        $this->cart()->addLine($this->context, $cart, $variantUuid, $quantity);

        return [$token, $variantUuid];
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
