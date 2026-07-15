<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Cart\CartRepository;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Catalog\AddonRepository;
use Glueful\Extensions\Commerce\Catalog\AddonService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Http\Storefront\OrderController;
use Glueful\Extensions\Commerce\Invoices\ConfigSellerIdentityProvider;
use Glueful\Extensions\Commerce\Invoices\InvoiceData;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\InsufficientStockException;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Checkout-side add-on behavior (design spec §4): the snapshot copy from cart
 * line to order line, correct totals, stock aggregation across hashed lines at
 * checkout, and the sanitized addon echo on every order-facing projection
 * (storefront order, admin order, invoice data).
 */
final class CheckoutAddonsTest extends CommerceTestCase
{
    public function testCheckoutCopiesSnapshotVerbatimIntoOrderLines(): void
    {
        ['product_uuid' => $productUuid, 'variant_uuid' => $variantUuid] = $this->seedProduct('SKU-CO1', 10, 1000);
        $addon = $this->createSelectAddon($productUuid, [
            ['key' => 'red', 'label' => 'Red', 'price_delta' => 150],
        ]);

        $cart = $this->cart();
        ['cart' => $c, 'token' => $token] = $cart->create($this->context);
        $c = $cart->addLine($this->context, $c, $variantUuid, 2, [
            ['addon_uuid' => $addon['uuid'], 'choice_key' => 'red'],
        ]);
        $cartLine = $this->connection->table('commerce_cart_lines')->where('cart_uuid', '=', $c['uuid'])->first();
        self::assertNotNull($cartLine);

        $placed = $this->checkout()->placeOrder(
            $this->context,
            $token,
            ['email' => 'buyer@example.com', 'user_uuid' => null],
            ['shipping' => ['country' => 'US'], 'billing' => ['country' => 'US']],
            'std'
        );

        $orderLine = $this->connection->table('commerce_order_lines')
            ->where('order_uuid', '=', $placed['order']['uuid'])
            ->first();
        self::assertNotNull($orderLine);
        self::assertSame($cartLine['addons'], $orderLine['addons'], 'snapshot json copied verbatim');
        self::assertSame(1150, (int) $orderLine['unit_price']);
        self::assertSame(2, (int) $orderLine['quantity']);
        self::assertSame(2300, (int) $orderLine['line_total']);
        self::assertSame(2300, (int) $placed['order']['subtotal']);
    }

    public function testTotalsReflectAddonDeltas(): void
    {
        ['product_uuid' => $productUuid, 'variant_uuid' => $variantUuid] = $this->seedProduct('SKU-CO2', 10, 500);
        $addon = $this->createCheckboxAddon($productUuid, 200);

        $cart = $this->cart();
        ['cart' => $c, 'token' => $token] = $cart->create($this->context);
        $cart->addLine($this->context, $c, $variantUuid, 3, [
            ['addon_uuid' => $addon['uuid'], 'value' => true],
        ]);

        $placed = $this->checkout()->placeOrder(
            $this->context,
            $token,
            ['email' => 'buyer@example.com', 'user_uuid' => null],
            ['shipping' => ['country' => 'US'], 'billing' => ['country' => 'US']],
            'std'
        );

        // (500 + 200) * 3 = 2100
        self::assertSame(2100, (int) $placed['order']['subtotal']);
        self::assertSame(2100 + 500, (int) $placed['order']['grand_total']); // + shipping quote (500)
    }

    public function testCheckoutAggregatesStockAcrossHashedLinesAndRollsBackOnOversell(): void
    {
        ['product_uuid' => $productUuid, 'variant_uuid' => $variantUuid] = $this->seedProduct('SKU-CO3', 5, 1000);
        $addon = $this->createCheckboxAddon($productUuid, 100);

        $cartService = $this->cart();
        ['cart' => $c, 'token' => $token] = $cartService->create($this->context);
        $c = $cartService->addLine($this->context, $c, $variantUuid, 3, [
            ['addon_uuid' => $addon['uuid'], 'value' => true],
        ]);
        $cartService->addLine($this->context, $c, $variantUuid, 2);

        // Bypass addLine()'s advisory stock check to simulate the two hashed lines
        // having been added while stock was still sufficient, then stock dropping
        // (e.g. concurrent order) before checkout -- checkout's own decrement is
        // the hard gate and must aggregate across BOTH hashed lines for the
        // variant (3 + 2 = 5, exactly what's in stock) plus a bit more to force
        // an oversell deterministically.
        $extraLine = $this->connection->table('commerce_cart_lines')
            ->where('cart_uuid', '=', $c['uuid'])
            ->where('addons_hash', '=', '')
            ->first();
        self::assertNotNull($extraLine);
        $this->connection->table('commerce_cart_lines')
            ->where('uuid', '=', $extraLine['uuid'])
            ->update(['quantity' => 3]); // total demand becomes 3 + 3 = 6 > 5 in stock

        $this->expectException(InsufficientStockException::class);
        try {
            $this->checkout()->placeOrder(
                $this->context,
                $token,
                ['email' => 'buyer@example.com', 'user_uuid' => null],
                ['shipping' => ['country' => 'US'], 'billing' => ['country' => 'US']],
                'std'
            );
        } finally {
            self::assertSame(0, $this->connection->table('commerce_orders')->count());
            self::assertSame(5, (new StockRepository())->quantity($this->context, '', $variantUuid));
        }
    }

    // -----------------------------------------------------------------
    // Sanitized projections: storefront order, admin order, invoice data
    // -----------------------------------------------------------------

    public function testStorefrontOrderEchoesOnlyTheWhitelistedAddonFields(): void
    {
        $placed = $this->placeOrderWithAddon('SKU-CO4');
        $number = (string) $placed['order']['order_number'];

        $request = Request::create("/commerce/orders/{$number}", 'GET');
        $request->headers->set('X-Order-Token', (string) $placed['guest_token']);

        $response = $this->orderController()->show($request, $number);
        $raw = (string) $response->getContent();
        $body = $this->json($response);

        $lineAddons = $body['data']['lines'][0]['addons'];
        self::assertCount(1, $lineAddons);
        self::assertSame(['name', 'field_type', 'choice_label', 'price_delta'], array_keys($lineAddons[0]));
        self::assertStringNotContainsString($placed['addon_uuid'], $raw);
        self::assertStringNotContainsString('"choice_key"', $raw);
        self::assertStringNotContainsString('"choices"', $raw);
        self::assertStringNotContainsString('"addon_uuid"', $raw);
    }

    public function testAdminOrderEchoesOnlyTheWhitelistedAddonFields(): void
    {
        $placed = $this->placeOrderWithAddon('SKU-CO5');

        $response = $this->adminOrderController()->show(
            Request::create('/commerce/admin/orders/' . $placed['order']['uuid'], 'GET'),
            (string) $placed['order']['uuid']
        );
        $raw = (string) $response->getContent();
        $body = $this->json($response);

        $lineAddons = $body['data']['lines'][0]['addons'];
        self::assertCount(1, $lineAddons);
        self::assertSame(['name', 'field_type', 'choice_label', 'price_delta'], array_keys($lineAddons[0]));
        self::assertStringNotContainsString($placed['addon_uuid'], $raw);
        self::assertStringNotContainsString('"choice_key"', $raw);
        self::assertStringNotContainsString('"choices"', $raw);
        self::assertStringNotContainsString('"addon_uuid"', $raw);
    }

    public function testInvoiceDataEchoesOnlyTheWhitelistedAddonFields(): void
    {
        $placed = $this->placeOrderWithAddon('SKU-CO6');
        $orderUuid = (string) $placed['order']['uuid'];
        $tenant = '';

        $orders = new OrderRepository();
        $lines = $orders->linesForOrder($this->context, $tenant, $orderUuid);
        $order = $orders->findByUuid($this->context, $tenant, $orderUuid);
        self::assertNotNull($order);

        $data = InvoiceData::build($this->context, $order, $lines, [], ['name' => null, 'address' => null, 'tax_id' => null]);
        $raw = json_encode($data, JSON_THROW_ON_ERROR);

        $lineAddons = $data['lines'][0]['addons'];
        self::assertCount(1, $lineAddons);
        self::assertSame(['name', 'field_type', 'choice_label', 'price_delta'], array_keys($lineAddons[0]));
        self::assertStringNotContainsString($placed['addon_uuid'], $raw);
        self::assertStringNotContainsString('"choice_key"', $raw);
        self::assertStringNotContainsString('"choices"', $raw);
        self::assertStringNotContainsString('"addon_uuid"', $raw);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /**
     * Places an order for a single select-addon line and pays it, returning the
     * placed order plus the addon's uuid (needed for the leak assertions).
     *
     * @return array{order: array<string,mixed>, guest_token: string, addon_uuid: string}
     */
    private function placeOrderWithAddon(string $sku): array
    {
        ['product_uuid' => $productUuid, 'variant_uuid' => $variantUuid] = $this->seedProduct($sku, 10, 1000);
        $addon = $this->createSelectAddon($productUuid, [
            ['key' => 'red', 'label' => 'Red', 'price_delta' => 150],
        ]);

        $cart = $this->cart();
        ['cart' => $c, 'token' => $token] = $cart->create($this->context);
        $cart->addLine($this->context, $c, $variantUuid, 1, [
            ['addon_uuid' => $addon['uuid'], 'choice_key' => 'red'],
        ]);

        $placed = $this->checkout()->placeOrder(
            $this->context,
            $token,
            ['email' => 'buyer@example.com', 'user_uuid' => null],
            ['shipping' => ['country' => 'US'], 'billing' => ['country' => 'US']],
            'std'
        );

        (new OrderPaymentService(new OrderRepository()))->markPaid($this->context, '', (string) $placed['order']['uuid']);
        $order = (new OrderRepository())->findByUuid($this->context, '', (string) $placed['order']['uuid']);
        self::assertNotNull($order);

        return ['order' => $order, 'guest_token' => (string) $placed['guest_token'], 'addon_uuid' => (string) $addon['uuid']];
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

    private function addonService(): AddonService
    {
        return new AddonService(new AddonRepository(), new ProductRepository(), new SentinelTenantResolver());
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

    private function cart(): CartService
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
            new ManualPaymentCollector(),
            new SentinelTenantResolver()
        );
    }

    private function orderController(): OrderController
    {
        return new OrderController(
            $this->context,
            new OrderRepository(),
            $this->checkout(),
            new SentinelTenantResolver(),
            new RefundRepository()
        );
    }

    private function adminOrderController(): AdminOrderController
    {
        return new AdminOrderController(
            $this->context,
            new OrderRepository(),
            new StockRepository(),
            new OrderPaymentService(new OrderRepository()),
            new SentinelTenantResolver(),
            new RefundRepository(),
            new ConfigSellerIdentityProvider()
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

    /** @return array<string,mixed> */
    private function json(\Symfony\Component\HttpFoundation\Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
