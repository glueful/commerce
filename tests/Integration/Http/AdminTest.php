<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

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
use Glueful\Extensions\Commerce\Http\DTOs\FulfillOrderData;
use Glueful\Extensions\Commerce\Http\DTOs\OrderListQuery;
use Glueful\Extensions\Commerce\Http\DTOs\StockAdjustmentData;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Http\Admin\AdminStockController;
use Glueful\Extensions\Commerce\Invoices\ConfigSellerIdentityProvider;
use Glueful\Extensions\Commerce\Inventory\InventoryService;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
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

final class AdminTest extends CommerceTestCase
{
    public function testStockAdjustWritesLedger(): void
    {
        $variantUuid = $this->seedVariant('SKU-ADJUST', 2, 1000);

        $request = Request::create('/commerce/admin/stock/' . $variantUuid . '/adjust', 'POST', [], [], [], [], json_encode([
            'delta' => 3,
            'reason' => 'manual',
        ], JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');

        $response = $this->stockController()->adjust(
            new StockAdjustmentData(delta: 3, reason: 'manual'),
            $request,
            $variantUuid
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(5, (new StockRepository())->quantity($this->context, '', $variantUuid));
        $movement = $this->connection->table('commerce_stock_movements')
            ->where('variant_uuid', '=', $variantUuid)
            ->orderBy('id', 'DESC')
            ->first();
        self::assertSame(3, (int) $movement['delta']);
        self::assertSame('manual', $movement['reason']);
    }

    public function testCancelPendingOrderRestocks(): void
    {
        [$placed, $variantUuid] = $this->placeOrder('SKU-CANCEL', 4, 2);

        $response = $this->orderController()->cancel(
            Request::create('/commerce/admin/orders/' . $placed['order']['uuid'] . '/cancel', 'POST'),
            (string) $placed['order']['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(4, (new StockRepository())->quantity($this->context, '', $variantUuid));
        self::assertSame('canceled', $this->orderRow((string) $placed['order']['uuid'])['status']);
        self::assertSame('release', $this->connection->table('commerce_stock_movements')
            ->where('variant_uuid', '=', $variantUuid)
            ->orderBy('id', 'DESC')
            ->first()['reason']);
    }

    public function testFulfillSetsTracking(): void
    {
        [$placed] = $this->placeOrder('SKU-FULFILL', 3, 1);
        (new OrderPaymentService(new OrderRepository()))->markPaid($this->context, '', (string) $placed['order']['uuid']);

        $request = Request::create('/commerce/admin/orders/' . $placed['order']['uuid'] . '/fulfill', 'POST', [], [], [], [], json_encode([
            'tracking_ref' => 'TRACK123',
        ], JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');

        $response = $this->orderController()->fulfill(
            new FulfillOrderData(tracking_ref: 'TRACK123'),
            $request,
            (string) $placed['order']['uuid']
        );
        $row = $this->orderRow((string) $placed['order']['uuid']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('fulfilled', $row['status']);
        self::assertSame('fulfilled', $row['fulfillment_status']);
        self::assertSame('TRACK123', $row['tracking_ref']);
    }

    public function testAdminOrderListIsPaginated(): void
    {
        $this->placeOrder('SKU-ADMIN-PAGE-A', 3, 1);
        $this->placeOrder('SKU-ADMIN-PAGE-B', 3, 1);
        $this->placeOrder('SKU-ADMIN-PAGE-C', 3, 1);

        $request = Request::create('/commerce/admin/orders?page=2&per_page=2', 'GET');
        $response = $this->orderController()->index(new OrderListQuery(page: 2, per_page: 2), $request);
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $body['current_page']);
        self::assertSame(2, $body['per_page']);
        self::assertSame(3, $body['total']);
        self::assertCount(1, $body['data']);
    }

    public function testInvalidOrderTransitionReturnsConflict(): void
    {
        [$placed] = $this->placeOrder('SKU-BAD-TRANSITION', 3, 1);

        $response = $this->orderController()->fulfill(
            new FulfillOrderData(),
            Request::create('/commerce/admin/orders/' . $placed['order']['uuid'] . '/fulfill', 'POST'),
            (string) $placed['order']['uuid']
        );

        self::assertSame(409, $response->getStatusCode());
    }

    private function stockController(): AdminStockController
    {
        return new AdminStockController($this->context, new InventoryService(
            new StockRepository(),
            new SentinelTenantResolver()
        ));
    }

    private function orderController(): AdminOrderController
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

    /** @return array{0: array{order: array<string,mixed>, guest_token: string, payment: array<string,mixed>}, 1: string} */
    private function placeOrder(string $sku, int $stock, int $quantity): array
    {
        $variantUuid = $this->seedVariant($sku, $stock, 1000);
        ['cart' => $cart, 'token' => $token] = $this->cart()->create($this->context);
        $this->cart()->addLine($this->context, $cart, $variantUuid, $quantity);

        $placed = $this->checkout()->placeOrder(
            $this->context,
            $token,
            ['email' => 'buyer@example.com', 'user_uuid' => null],
            ['shipping' => ['country' => 'US'], 'billing' => ['country' => 'US']],
            'std'
        );

        return [$placed, $variantUuid];
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

    /** @return array<string,mixed> */
    private function orderRow(string $uuid): array
    {
        $row = $this->connection->table('commerce_orders')->where('uuid', '=', $uuid)->first();
        self::assertIsArray($row);

        return $row;
    }

    /** @return array<string,mixed> */
    private function json(\Symfony\Component\HttpFoundation\Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
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
