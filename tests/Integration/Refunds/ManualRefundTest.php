<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Refunds;

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
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\ConcurrentRefundException;
use Glueful\Extensions\Commerce\Orders\Refunds\IdempotencyConflictException;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundInput;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundService;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundValidationException;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Bootstrap\ApplicationContext;

final class ManualRefundTest extends CommerceTestCase
{
    public function testFullRefundViaOmittedAmountCompletesAndTransitionsOrder(): void
    {
        ['order' => $order] = $this->placeAndPayOrder('SKU-FULL', 5, 2, 1000);

        $refund = $this->refundService()->issue(
            $this->context,
            (string) $order['uuid'],
            new RefundInput(null, 'customer request', [], false),
            'idem-full-1'
        );

        self::assertSame('completed', $refund['status']);

        $updated = $this->connection->table('commerce_orders')->where('uuid', '=', $order['uuid'])->first();
        self::assertNotNull($updated);
        self::assertSame((int) $order['grand_total'], (int) $updated['refunded_total']);
        self::assertSame('refunded', $updated['status']);

        $event = $this->connection->table('commerce_order_events')
            ->where('order_uuid', '=', $order['uuid'])
            ->where('type', '=', 'refund.completed')
            ->first();
        self::assertNotNull($event);
        self::assertSame('internal', $event['visibility']);
    }

    public function testPartialRefundLeavesOrderPaid(): void
    {
        ['order' => $order] = $this->placeAndPayOrder('SKU-PART', 5, 2, 1000);
        $partial = (int) $order['grand_total'] - 100;

        $refund = $this->refundService()->issue(
            $this->context,
            (string) $order['uuid'],
            new RefundInput($partial, 'partial', [], false),
            'idem-part-1'
        );

        self::assertSame('completed', $refund['status']);

        $updated = $this->connection->table('commerce_orders')->where('uuid', '=', $order['uuid'])->first();
        self::assertNotNull($updated);
        self::assertSame('paid', $updated['status']);
        self::assertSame($partial, (int) $updated['refunded_total']);
    }

    public function testSecondPartialExceedingRemainderThrows(): void
    {
        ['order' => $order] = $this->placeAndPayOrder('SKU-OVER', 5, 2, 1000);
        $grandTotal = (int) $order['grand_total'];

        $this->refundService()->issue(
            $this->context,
            (string) $order['uuid'],
            new RefundInput($grandTotal - 100, 'first', [], false),
            'idem-over-1'
        );

        $this->expectException(RefundValidationException::class);
        $this->refundService()->issue(
            $this->context,
            (string) $order['uuid'],
            new RefundInput(200, 'second', [], false),
            'idem-over-2'
        );
    }

    public function testWrongStateOrderRejected(): void
    {
        [$token] = $this->seedCartWithLine('SKU-WRONG', 5, 1, 1000);
        $placed = $this->checkout()->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        $this->expectException(RefundValidationException::class);
        $this->refundService()->issue(
            $this->context,
            (string) $placed['order']['uuid'],
            new RefundInput(null, 'too early', [], false),
            'idem-wrong-1'
        );
    }

    public function testRestockRestoresStockAndCapsCumulativeQuantity(): void
    {
        ['order' => $order, 'variantUuid' => $variantUuid, 'lineUuid' => $lineUuid] =
            $this->placeAndPayOrder('SKU-RESTOCK', 5, 2, 1000);

        $beforeRefund = (new StockRepository())->quantity($this->context, '', $variantUuid);
        self::assertSame(3, $beforeRefund);

        $first = $this->refundService()->issue(
            $this->context,
            (string) $order['uuid'],
            new RefundInput(1000, 'one unit', [
                ['order_line_uuid' => $lineUuid, 'quantity' => 1, 'amount' => 1000],
            ], true),
            'idem-restock-1'
        );

        self::assertSame(4, (new StockRepository())->quantity($this->context, '', $variantUuid));
        $movement = $this->connection->table('commerce_stock_movements')
            ->where('reference_uuid', '=', $first['uuid'])
            ->first();
        self::assertNotNull($movement);
        self::assertSame('refund_restock', $movement['reason']);
        self::assertSame(1, (int) $movement['delta']);

        // Second refund attributes the remaining unit; quantity is 2 total across both
        // refunds, exactly the order line quantity — must succeed.
        $this->refundService()->issue(
            $this->context,
            (string) $order['uuid'],
            new RefundInput(1000, 'second unit', [
                ['order_line_uuid' => $lineUuid, 'quantity' => 1, 'amount' => 1000],
            ], true),
            'idem-restock-2'
        );
        self::assertSame(5, (new StockRepository())->quantity($this->context, '', $variantUuid));

        // A third restock attempt for this line would exceed the original order-line
        // quantity (2) since 2 units were already restocked cumulatively.
        $this->expectException(RefundValidationException::class);
        $this->refundService()->issue(
            $this->context,
            (string) $order['uuid'],
            new RefundInput(1, 'third unit', [
                ['order_line_uuid' => $lineUuid, 'quantity' => 1, 'amount' => 1],
            ], true),
            'idem-restock-3'
        );
    }

    public function testRestockWithMissingStockRowRollsBackWholeTransaction(): void
    {
        ['order' => $order, 'variantUuid' => $variantUuid, 'lineUuid' => $lineUuid] =
            $this->placeAndPayOrder('SKU-VANISH', 5, 2, 1000);

        // Simulate the stock row disappearing entirely between checkout and this refund
        // (e.g. the variant/stock record was purged out-of-band) — the row is gone, not
        // merely untracked.
        $this->connection->table('commerce_stock')
            ->where('tenant_uuid', '=', '')
            ->where('variant_uuid', '=', $variantUuid)
            ->forceDelete();
        self::assertNull(
            $this->connection->table('commerce_stock')->where('variant_uuid', '=', $variantUuid)->first()
        );

        $this->expectException(ConcurrentRefundException::class);
        try {
            $this->refundService()->issue(
                $this->context,
                (string) $order['uuid'],
                new RefundInput(1000, 'vanished stock row', [
                    ['order_line_uuid' => $lineUuid, 'quantity' => 1, 'amount' => 1000],
                ], true),
                'idem-vanish-1'
            );
        } finally {
            self::assertNull(
                $this->connection->table('commerce_refunds')
                    ->where('order_uuid', '=', $order['uuid'])
                    ->where('idempotency_key', '=', 'idem-vanish-1')
                    ->first(),
                'No refund row should be persisted when the transaction rolls back.'
            );

            $updated = $this->connection->table('commerce_orders')->where('uuid', '=', $order['uuid'])->first();
            self::assertNotNull($updated);
            self::assertSame(
                (int) $order['refunded_total'],
                (int) $updated['refunded_total'],
                'refunded_total must be unchanged after a rolled-back refund.'
            );

            self::assertNull(
                $this->connection->table('commerce_stock_movements')
                    ->where('variant_uuid', '=', $variantUuid)
                    ->where('reason', '=', 'refund_restock')
                    ->first(),
                'No restock movement should be recorded when the transaction rolls back.'
            );
        }
    }

    public function testReplaySameKeyAndPayloadReturnsSameUuidEvenAfterOrderRefunded(): void
    {
        ['order' => $order] = $this->placeAndPayOrder('SKU-REPLAY', 5, 2, 1000);
        $input = new RefundInput(null, 'replay me', [], false);

        $first = $this->refundService()->issue($this->context, (string) $order['uuid'], $input, 'idem-replay-1');
        self::assertSame('refunded', $this->connection->table('commerce_orders')
            ->where('uuid', '=', $order['uuid'])->first()['status']);

        $second = $this->refundService()->issue($this->context, (string) $order['uuid'], $input, 'idem-replay-1');

        self::assertSame($first['uuid'], $second['uuid']);
        self::assertSame(1, $this->connection->table('commerce_refunds')
            ->where('order_uuid', '=', $order['uuid'])->count());
    }

    public function testSameKeyDifferentPayloadConflicts(): void
    {
        ['order' => $order] = $this->placeAndPayOrder('SKU-CONFLICT', 5, 2, 1000);

        $this->refundService()->issue(
            $this->context,
            (string) $order['uuid'],
            new RefundInput(100, 'first payload', [], false),
            'idem-conflict-1'
        );

        $this->expectException(IdempotencyConflictException::class);
        $this->refundService()->issue(
            $this->context,
            (string) $order['uuid'],
            new RefundInput(200, 'different payload', [], false),
            'idem-conflict-1'
        );
    }

    private function refundService(): RefundService
    {
        return new RefundService(
            new OrderRepository(),
            new RefundRepository(),
            new StockRepository(),
            new SentinelTenantResolver()
        );
    }

    /**
     * Places, pays, and returns an order with a single tracked line.
     *
     * @return array{order: array<string,mixed>, variantUuid: string, lineUuid: string}
     */
    private function placeAndPayOrder(string $sku, int $stock, int $quantity, int $price): array
    {
        [$token, $variantUuid] = $this->seedCartWithLine($sku, $stock, $quantity, $price);
        $placed = $this->checkout()->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');
        $orderUuid = (string) $placed['order']['uuid'];

        (new OrderPaymentService(new OrderRepository()))->markPaid($this->context, '', $orderUuid);
        $order = (new OrderRepository())->findByUuid($this->context, '', $orderUuid);
        self::assertNotNull($order);

        $line = $this->connection->table('commerce_order_lines')->where('order_uuid', '=', $orderUuid)->first();
        self::assertNotNull($line);

        return ['order' => $order, 'variantUuid' => $variantUuid, 'lineUuid' => (string) $line['uuid']];
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

    /** @return array{email: string, user_uuid: null} */
    private function buyer(): array
    {
        return ['email' => 'buyer@example.com', 'user_uuid' => null];
    }

    /** @return array{shipping: array{country: string}, billing: array{country: string}} */
    private function addresses(): array
    {
        return ['shipping' => ['country' => 'US'], 'billing' => ['country' => 'US']];
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
