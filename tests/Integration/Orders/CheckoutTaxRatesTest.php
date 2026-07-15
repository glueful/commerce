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
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Tax\DbTaxCalculator;
use Glueful\Extensions\Commerce\Tax\DelegatingTaxCalculator;
use Glueful\Extensions\Commerce\Tax\FlatRateTaxCalculator;
use Glueful\Extensions\Commerce\Tax\TaxRateRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;

/**
 * Checkout end-to-end through the DB tax-rate path (design spec §4/§5/§7):
 * wires the REAL {@see DelegatingTaxCalculator} (Db + FlatRate), seeds rate
 * rows directly, and drives `CheckoutService::quote()` -- mirrors
 * {@see CheckoutShippingZonesTest}'s style for the shipping path. Covers
 * mixed tax classes, `shipping_taxable` on/off, discount+tax interaction, and
 * the `free_shipping` effective-shipping proof, plus the no-rows byte-parity
 * regression gate.
 */
final class CheckoutTaxRatesTest extends CommerceTestCase
{
    public function testCheckoutTaxesMixedClassesAndTaxableShipping(): void
    {
        $this->insertRate(['country' => 'US', 'rate_bps' => 1000, 'label' => 'Standard', 'shipping_taxable' => true]);
        $this->insertRate(['country' => 'US', 'rate_bps' => 500, 'label' => 'Reduced', 'class' => 'reduced']);

        [$tokenA] = $this->seedCartWithProduct('SKU-MIX-A', 1000, null);
        $cart = $this->cart()->byToken($this->context, $tokenA);
        self::assertNotNull($cart);
        [, $variantB] = $this->seedCartWithProduct('SKU-MIX-B', 2000, 'reduced');
        $this->cart()->addLine($this->context, $cart, $variantB, 1);
        $cart = $this->cart()->byToken($this->context, $tokenA);

        $quote = $this->checkout(500)->quote($this->context, $cart, ['country' => 'US'], null);

        // standard line: 1000 * 10% = 100; reduced line: 2000 * 5% = 100;
        // shipping (standard, shipping_taxable): 500 * 10% = 50.
        self::assertSame(250, $quote['totals']->taxTotal);
    }

    public function testCheckoutShippingUntaxedWhenNoShippingTaxableRateMatches(): void
    {
        $this->insertRate([
            'country' => 'US', 'rate_bps' => 1000, 'label' => 'Standard', 'shipping_taxable' => false,
        ]);

        [$token] = $this->seedCartWithProduct('SKU-NST', 1000, null);
        $cart = $this->cart()->byToken($this->context, $token);

        $quote = $this->checkout(500)->quote($this->context, $cart, ['country' => 'US'], null);

        // merchandise taxed (100) but shipping untaxed (no shipping_taxable rate).
        self::assertSame(100, $quote['totals']->taxTotal);
    }

    public function testCheckoutPercentageDiscountReducesTheTaxableBase(): void
    {
        $this->insertRate(['country' => 'US', 'rate_bps' => 1000, 'label' => 'Standard']);

        [$token] = $this->seedCartWithProduct('SKU-DISC', 1000, null);
        $cart = $this->cart()->byToken($this->context, $token);
        $cart = $this->cart()->applyDiscount($this->context, $this->seedDiscount($cart, [
            'code' => 'PCT20', 'type' => 'percentage', 'value' => 2000,
        ]), 'PCT20');

        $quote = $this->checkout(0)->quote($this->context, $cart, ['country' => 'US'], null);

        // subtotal 1000, 20% discount -> discountTotal 200, taxable 800,
        // 10% of 800 = 80 (NOT 100 -- proves discount reduces the tax base).
        self::assertSame(80, $quote['totals']->taxTotal);
    }

    public function testCheckoutFreeShippingDiscountLeavesShippingUntaxedProvingEffectiveShippingUsed(): void
    {
        $this->insertRate(['country' => 'US', 'rate_bps' => 1000, 'label' => 'Standard', 'shipping_taxable' => true]);

        [$token] = $this->seedCartWithProduct('SKU-FS', 1000, null);
        $cart = $this->cart()->byToken($this->context, $token);
        $cart = $this->cart()->applyDiscount($this->context, $this->seedDiscount($cart, [
            'code' => 'FREESHIP', 'type' => 'free_shipping', 'value' => 0,
        ]), 'FREESHIP');

        $quote = $this->checkout(500)->quote($this->context, $cart, ['country' => 'US'], null);

        // Merchandise tax (100) only -- if the ORIGINAL 500 shipping amount
        // were passed instead of the effective (post-free_shipping) 0, this
        // would be 150 (100 + 50 shipping tax).
        self::assertSame(100, $quote['totals']->taxTotal);
        self::assertSame(0, $quote['totals']->shippingTotal);
    }

    public function testCheckoutNoRateRowsIsByteIdenticalToFlatRatePath(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['tax' => ['flat_rate_bps' => 825]]);

        [$token] = $this->seedCartWithProduct('SKU-FLAT', 1000, null);
        $cart = $this->cart()->byToken($this->context, $token);

        $quote = $this->checkout(500)->quote($this->context, $cart, ['country' => 'US'], null);

        // Legacy base = subtotal(1000) + shipping(500) = 1500 -- drive the
        // REAL FlatRateTaxCalculator directly for the comparison value, so
        // this proves byte-parity against production code, not a
        // reimplemented formula.
        $expected = (new FlatRateTaxCalculator())->quote($this->context, 1500, ['country' => 'US']);
        self::assertSame($expected->amount, $quote['totals']->taxTotal);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function checkout(int $shippingAmount): CheckoutService
    {
        return new CheckoutService(
            $this->cart(),
            new DiscountRepository(),
            new DiscountService(new DiscountRepository(), new SentinelTenantResolver()),
            new StockRepository(),
            new PricingEngine(),
            $this->shipping($shippingAmount),
            new DelegatingTaxCalculator(
                new DbTaxCalculator(new TaxRateRepository(), new SentinelTenantResolver()),
                new FlatRateTaxCalculator()
            ),
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

    private function shipping(int $amount): ShippingRateProvider
    {
        return new class ($amount) implements ShippingRateProvider {
            public function __construct(private int $amount)
            {
            }

            public function quote(ApplicationContext $context, array $lines, array $shippingAddress): array
            {
                return [new ShippingQuote('std', 'Standard', $this->amount)];
            }
        };
    }

    /** @return array{0: string, 1: string} token, variant_uuid */
    private function seedCartWithProduct(string $sku, int $price, ?string $taxClass): array
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
        (new StockRepository())->increment($this->context, '', $variantUuid, 5);
        ['cart' => $cart, 'token' => $token] = $this->cart()->create($this->context);
        $this->cart()->addLine($this->context, $cart, $variantUuid, 1);

        return [$token, $variantUuid];
    }

    /**
     * @param array<string,mixed> $cart
     * @param array<string,mixed> $overrides
     * @return array<string,mixed> the cart, reloaded
     */
    private function seedDiscount(array $cart, array $overrides): array
    {
        $this->connection->table('commerce_discounts')->insert(array_merge([
            'uuid' => 'disc' . substr(md5((string) ($overrides['code'] ?? 'CODE')), 0, 8),
            'code' => 'CODE',
            'type' => 'percentage',
            'value' => 0,
            'usage_limit' => null,
            'once_per_buyer' => 0,
            'usage_count' => 0,
            'status' => 'active',
        ], $overrides));

        return $cart;
    }

    /** @param array<string,mixed> $overrides */
    private function insertRate(array $overrides): void
    {
        $uuid = $overrides['uuid'] ?? ('rate' . substr(md5((string) random_int(0, PHP_INT_MAX)), 0, 8));
        $this->connection->table('commerce_tax_rates')->insert(array_merge([
            'uuid' => $uuid,
            'tenant_uuid' => '',
            'country' => 'US',
            'state' => null,
            'postcode_pattern' => null,
            'rate_bps' => 0,
            'label' => 'Tax',
            'priority' => 0,
            'shipping_taxable' => false,
            'class' => 'standard',
        ], $overrides));
    }
}
