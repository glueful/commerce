<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Auth\UserIdentity;
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
use Glueful\Extensions\Commerce\Http\Admin\AdminRefundController;
use Glueful\Extensions\Commerce\Http\DTOs\CreateRefundData;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundService;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Symfony\Component\HttpFoundation\Request;

final class RefundEndpointTest extends CommerceTestCase
{
    public function testMissingIdempotencyKeyReturns422(): void
    {
        ['order' => $order] = $this->placeAndPayOrder('SKU-REF-NOKEY', 5, 1, 1000);

        $response = $this->refundController()->store(
            new CreateRefundData(),
            Request::create('/commerce/admin/orders/' . $order['uuid'] . '/refunds', 'POST'),
            (string) $order['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        $body = $this->json($response);
        self::assertArrayHasKey('idempotency_key', $body['error']['details']);
    }

    public function testOversizeIdempotencyKeyReturns422(): void
    {
        ['order' => $order] = $this->placeAndPayOrder('SKU-REF-BIGKEY', 5, 1, 1000);

        $request = Request::create('/commerce/admin/orders/' . $order['uuid'] . '/refunds', 'POST');
        $request->headers->set('Idempotency-Key', str_repeat('a', 129));

        $response = $this->refundController()->store(new CreateRefundData(), $request, (string) $order['uuid']);

        self::assertSame(422, $response->getStatusCode());
        $body = $this->json($response);
        self::assertArrayHasKey('idempotency_key', $body['error']['details']);
    }

    public function testHappyFullRefundReturnsSuccessPayload(): void
    {
        ['order' => $order] = $this->placeAndPayOrder('SKU-REF-FULL', 5, 1, 1000);

        $request = Request::create('/commerce/admin/orders/' . $order['uuid'] . '/refunds', 'POST');
        $request->headers->set('Idempotency-Key', 'idem-happy-1');
        $request->attributes->set('auth.user', new UserIdentity(uuid: 'adminactor01'));

        $response = $this->refundController()->store(
            new CreateRefundData(reason: 'customer request'),
            $request,
            (string) $order['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame('completed', $body['data']['status']);
        self::assertSame((int) $order['grand_total'], (int) $body['data']['amount']);
        self::assertSame('customer request', $body['data']['reason']);
        self::assertSame('adminactor01', $body['data']['initiated_by']);
    }

    public function testReplaySameKeyAndPayloadReturnsSameUuid(): void
    {
        ['order' => $order] = $this->placeAndPayOrder('SKU-REF-REPLAY', 5, 1, 1000);

        $request = Request::create('/commerce/admin/orders/' . $order['uuid'] . '/refunds', 'POST');
        $request->headers->set('Idempotency-Key', 'idem-replay-http-1');

        $first = $this->refundController()->store(new CreateRefundData(), $request, (string) $order['uuid']);
        $second = $this->refundController()->store(new CreateRefundData(), $request, (string) $order['uuid']);

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
        $firstBody = $this->json($first);
        $secondBody = $this->json($second);
        self::assertSame($firstBody['data']['uuid'], $secondBody['data']['uuid']);
        self::assertSame(1, $this->connection->table('commerce_refunds')
            ->where('order_uuid', '=', $order['uuid'])->count());
    }

    public function testDifferentPayloadSameKeyReturns409(): void
    {
        ['order' => $order] = $this->placeAndPayOrder('SKU-REF-CONFLICT', 5, 1, 1000);
        $grandTotal = (int) $order['grand_total'];

        $first = Request::create('/commerce/admin/orders/' . $order['uuid'] . '/refunds', 'POST');
        $first->headers->set('Idempotency-Key', 'idem-conflict-http-1');
        $this->refundController()->store(
            new CreateRefundData(amount: $grandTotal - 100),
            $first,
            (string) $order['uuid']
        );

        $second = Request::create('/commerce/admin/orders/' . $order['uuid'] . '/refunds', 'POST');
        $second->headers->set('Idempotency-Key', 'idem-conflict-http-1');
        $response = $this->refundController()->store(
            new CreateRefundData(amount: 100),
            $second,
            (string) $order['uuid']
        );

        self::assertSame(409, $response->getStatusCode());
    }

    public function testOverAmountReturns422(): void
    {
        ['order' => $order] = $this->placeAndPayOrder('SKU-REF-OVER', 5, 1, 1000);
        $grandTotal = (int) $order['grand_total'];

        $request = Request::create('/commerce/admin/orders/' . $order['uuid'] . '/refunds', 'POST');
        $request->headers->set('Idempotency-Key', 'idem-over-http-1');

        $response = $this->refundController()->store(
            new CreateRefundData(amount: $grandTotal + 1000),
            $request,
            (string) $order['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        $body = $this->json($response);
        self::assertArrayHasKey('refund', $body['error']['details']);
    }

    public function testBadShapeLineElementMissingOrderLineUuidReturns422KeyedLines(): void
    {
        ['order' => $order] = $this->placeAndPayOrder('SKU-REF-BADSHAPE', 5, 1, 1000);

        $request = Request::create('/commerce/admin/orders/' . $order['uuid'] . '/refunds', 'POST');
        $request->headers->set('Idempotency-Key', 'idem-badshape-1');

        $response = $this->refundController()->store(
            new CreateRefundData(lines: [
                ['quantity' => 1, 'amount' => 100],
            ]),
            $request,
            (string) $order['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        $body = $this->json($response);
        self::assertArrayHasKey('lines', $body['error']['details']);
        self::assertArrayNotHasKey('refund', $body['error']['details']);
    }

    public function testDuplicateOrderLineAttributionReturns422WithoutWritingARefund(): void
    {
        ['order' => $order] = $this->placeAndPayOrder('SKU-REF-DUPLINE', 5, 2, 1000);
        $line = $this->connection->table('commerce_order_lines')
            ->where('order_uuid', '=', $order['uuid'])
            ->first();
        self::assertNotNull($line);

        $request = Request::create('/commerce/admin/orders/' . $order['uuid'] . '/refunds', 'POST');
        $request->headers->set('Idempotency-Key', 'idem-duplicate-line-1');
        $response = $this->refundController()->store(
            new CreateRefundData(
                amount: 1000,
                lines: [
                    ['order_line_uuid' => $line['uuid'], 'quantity' => 1, 'amount' => 500],
                    ['order_line_uuid' => $line['uuid'], 'quantity' => 1, 'amount' => 500],
                ],
                restock: true
            ),
            $request,
            (string) $order['uuid']
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->connection->table('commerce_refunds')->count());
    }

    public function testIndexListsRefundsForOrder(): void
    {
        ['order' => $order] = $this->placeAndPayOrder('SKU-REF-INDEX', 5, 1, 1000);

        $request = Request::create('/commerce/admin/orders/' . $order['uuid'] . '/refunds', 'POST');
        $request->headers->set('Idempotency-Key', 'idem-index-http-1');
        $this->refundController()->store(new CreateRefundData(), $request, (string) $order['uuid']);

        $response = $this->refundController()->index(
            Request::create('/commerce/admin/orders/' . $order['uuid'] . '/refunds', 'GET'),
            (string) $order['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertCount(1, $body['data']);
        self::assertSame('idem-index-http-1', $body['data'][0]['idempotency_key']);
    }

    public function testIndexUnknownOrderReturns404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->refundController()->index(
            Request::create('/commerce/admin/orders/does-not-exist/refunds', 'GET'),
            'does-not-exist'
        );
    }

    private function refundController(): AdminRefundController
    {
        return new AdminRefundController(
            $this->context,
            new OrderRepository(),
            new RefundRepository(),
            new RefundService(
                new OrderRepository(),
                new RefundRepository(),
                new StockRepository(),
                new SentinelTenantResolver()
            ),
            new SentinelTenantResolver()
        );
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

    /** @return array<string,mixed> */
    private function json(\Symfony\Component\HttpFoundation\Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
