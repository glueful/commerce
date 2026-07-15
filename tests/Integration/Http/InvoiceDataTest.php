<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Cart\CartRepository;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Invoices\ConfigSellerIdentityProvider;
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
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Symfony\Component\HttpFoundation\Request;

final class InvoiceDataTest extends CommerceTestCase
{
    private const COMPLETED_REASON = 'ESCALATED_TO_TIER2_FRAUD_REVIEW_DO_NOT_LEAK';
    private const FAILED_REASON = 'CUSTOMER_DISPUTE_CHARGEBACK_RISK_DO_NOT_LEAK';
    private const FAILURE_DETAIL = 'gateway declined card DO_NOT_LEAK';

    public function testInvoicePayloadShapeMoneyTypesAndCompletedOnlyRefunds(): void
    {
        ['order' => $order] = $this->placeAndPayOrder('SKU-INV-HAPPY', 5, 2, 1000);
        $orderUuid = (string) $order['uuid'];

        $this->insertRefund($orderUuid, [
            'uuid' => 'refundcompl1',
            'idempotency_key' => 'idem-inv-completed',
            'amount' => 500,
            'method' => 'original',
            'status' => 'completed',
            'reason' => self::COMPLETED_REASON,
            'completed_at' => '2026-01-15 10:00:00',
        ]);
        $this->insertRefund($orderUuid, [
            'uuid' => 'refundfail01',
            'idempotency_key' => 'idem-inv-failed',
            'amount' => 999,
            'method' => 'original',
            'status' => 'failed',
            'reason' => self::FAILED_REASON,
            'failure_reason' => self::FAILURE_DETAIL,
        ]);

        $response = $this->invoiceController()->invoiceData(
            Request::create('/commerce/admin/orders/' . $orderUuid . '/invoice-data', 'GET'),
            $orderUuid
        );

        self::assertSame(200, $response->getStatusCode());
        $rawBody = (string) $response->getContent();
        $data = $this->json($response)['data'];

        // schema
        self::assertSame(1, $data['schema_version']);

        // seller: no config set anywhere in this test -> present-as-null, never missing.
        self::assertSame(['name', 'address', 'tax_id'], array_keys($data['seller']));
        self::assertNull($data['seller']['name']);
        self::assertNull($data['seller']['address']);
        self::assertNull($data['seller']['tax_id']);

        // buyer
        self::assertSame('buyer@example.com', $data['buyer']['email']);
        self::assertIsArray($data['buyer']['addresses']);
        self::assertSame('US', $data['buyer']['addresses']['shipping']['country']);

        // order
        self::assertSame($order['order_number'], $data['order']['number']);
        self::assertSame('USD', $data['order']['currency']);
        self::assertSame('paid', $data['order']['status']);
        self::assertSame(['placed_at', 'created_at', 'updated_at'], array_keys($data['order']['dates']));
        self::assertNotEmpty($data['order']['dates']['created_at']);

        // lines
        self::assertCount(1, $data['lines']);
        $line = $data['lines'][0];
        self::assertSame(['name', 'sku', 'quantity', 'unit_minor', 'subtotal_minor', 'addons'], array_keys($line));
        self::assertSame('SKU-INV-HAPPY', $line['sku']);
        self::assertIsInt($line['quantity']);
        self::assertIsInt($line['unit_minor']);
        self::assertIsInt($line['subtotal_minor']);
        self::assertSame(2, $line['quantity']);
        self::assertSame(1000, $line['unit_minor']);
        self::assertSame(2000, $line['subtotal_minor']);
        self::assertSame([], $line['addons']);

        // totals: every *_minor key is a genuine PHP integer, not a numeric string.
        $totals = $data['totals'];
        self::assertSame(
            ['subtotal_minor', 'discount_minor', 'shipping_minor', 'tax_minor', 'grand_minor', 'refunded_minor'],
            array_keys($totals)
        );
        foreach ($totals as $value) {
            self::assertIsInt($value);
        }
        self::assertSame(2000, $totals['subtotal_minor']);
        self::assertSame(0, $totals['discount_minor']);
        self::assertSame(500, $totals['shipping_minor']);
        self::assertSame(0, $totals['tax_minor']);
        self::assertSame(2500, $totals['grand_minor']);
        // Sourced from the order's own `refunded_total` column, not re-summed from refund
        // rows inserted directly here (bypassing RefundService, which is what maintains it).
        self::assertSame(0, $totals['refunded_minor']);

        // refunds: completed only, exact key whitelist, no reason anywhere.
        self::assertCount(1, $data['refunds']);
        $refund = $data['refunds'][0];
        self::assertSame(['date', 'amount_minor', 'method'], array_keys($refund));
        self::assertIsInt($refund['amount_minor']);
        self::assertSame(500, $refund['amount_minor']);
        self::assertSame('original', $refund['method']);
        self::assertSame('2026-01-15 10:00:00', $refund['date']);

        // Serialized-body-level proof: neither refund's reason (nor the failure detail)
        // appears anywhere in the response, and the failed refund did not slip into `refunds`.
        self::assertStringNotContainsString(self::COMPLETED_REASON, $rawBody);
        self::assertStringNotContainsString(self::FAILED_REASON, $rawBody);
        self::assertStringNotContainsString(self::FAILURE_DETAIL, $rawBody);

        // Sanity: both refund rows really exist (the failed one was filtered, not never created).
        self::assertSame(
            2,
            $this->connection->table('commerce_refunds')->where('order_uuid', '=', $orderUuid)->count()
        );
    }

    public function testConfiguredSellerIdentitySerializesConfiguredValues(): void
    {
        $this->context->mergeConfigDefaults('commerce', [
            'seller' => [
                'name' => 'Acme Supply Co.',
                'address' => '1 Market St, Springfield',
                'tax_id' => 'TAX-99887766',
            ],
        ]);
        ['order' => $order] = $this->placeAndPayOrder('SKU-INV-SELLER', 3, 1, 1200);

        $response = $this->invoiceController()->invoiceData(
            Request::create('/commerce/admin/orders/' . $order['uuid'] . '/invoice-data', 'GET'),
            (string) $order['uuid']
        );

        $data = $this->json($response)['data'];
        self::assertSame([
            'name' => 'Acme Supply Co.',
            'address' => '1 Market St, Springfield',
            'tax_id' => 'TAX-99887766',
        ], $data['seller']);
    }

    public function testUnknownOrderReturns404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->invoiceController()->invoiceData(
            Request::create('/commerce/admin/orders/does-not-exist/invoice-data', 'GET'),
            'does-not-exist'
        );
    }

    public function testCrossTenantOrderReturns404(): void
    {
        $otherTenantOrder = [
            'uuid' => Utils::generateNanoID(),
            'tenant_uuid' => 'tenantOTHER1',
            'order_number' => 'ORD-OTHER-1',
            'status' => 'paid',
            'email' => 'other@example.com',
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => 100,
            'grand_total' => 100,
        ];
        (new OrderRepository())->insert($this->context, $otherTenantOrder);

        // This controller resolves the '' sentinel tenant, so an order that lives under
        // a different tenant_uuid must 404 exactly like an unknown uuid would.
        $this->expectException(NotFoundException::class);
        $this->invoiceController()->invoiceData(
            Request::create('/commerce/admin/orders/' . $otherTenantOrder['uuid'] . '/invoice-data', 'GET'),
            (string) $otherTenantOrder['uuid']
        );
    }

    private function invoiceController(): AdminOrderController
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

    /** @param array<string,mixed> $overrides */
    private function insertRefund(string $orderUuid, array $overrides): void
    {
        $refund = array_merge([
            'tenant_uuid' => '',
            'order_uuid' => $orderUuid,
            'request_fingerprint' => md5((string) ($overrides['idempotency_key'] ?? 'x')),
            'currency' => 'USD',
            'restocked' => false,
        ], $overrides);

        (new RefundRepository())->insert($this->context, $refund, []);
    }

    /**
     * Places, pays, and returns an order with a single tracked line.
     *
     * @return array{order: array<string,mixed>}
     */
    private function placeAndPayOrder(string $sku, int $stock, int $quantity, int $price): array
    {
        $variantUuid = $this->seedVariant($sku, $stock, $price);
        ['cart' => $cart, 'token' => $token] = $this->cart()->create($this->context);
        $this->cart()->addLine($this->context, $cart, $variantUuid, $quantity);

        $placed = $this->checkout()->placeOrder(
            $this->context,
            $token,
            ['email' => 'buyer@example.com', 'user_uuid' => null],
            ['shipping' => ['country' => 'US'], 'billing' => ['country' => 'US']],
            'std'
        );
        $orderUuid = (string) $placed['order']['uuid'];

        (new OrderPaymentService(new OrderRepository()))->markPaid($this->context, '', $orderUuid);
        $order = (new OrderRepository())->findByUuid($this->context, '', $orderUuid);
        self::assertNotNull($order);

        return ['order' => $order];
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

    /** @return array<string,mixed> */
    private function json(\Symfony\Component\HttpFoundation\Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
