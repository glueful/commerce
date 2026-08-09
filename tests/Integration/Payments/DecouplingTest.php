<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Payments;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Cart\CartRepository;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\DownloadRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\CommerceServiceProvider;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Payments\OrderPayable;
use Glueful\Extensions\Commerce\Payments\OrderPaymentConfirmationHandler;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\PaymentCollector;
use Glueful\Extensions\Contracts\Payments\PaymentConfirmation;
use Glueful\Extensions\Contracts\Payments\PaymentInitiation;

final class DecouplingTest extends CommerceTestCase
{
    public function testNothingBoundMeansManualFallback(): void
    {
        $collector = CommerceServiceProvider::makePaymentCollector($this->contextContainer());

        self::assertInstanceOf(ManualPaymentCollector::class, $collector);
    }

    public function testBoundCollectorWins(): void
    {
        $bound = new class implements PaymentCollector {
            public function initiate(ApplicationContext $context, PayableReference $payable): PaymentInitiation
            {
                return new PaymentInitiation('fake', 'ok', ['reference' => 'r1']);
            }
        };
        $this->bindings[PaymentCollector::class] = $bound;

        self::assertSame($bound, CommerceServiceProvider::makePaymentCollector($this->contextContainer()));
    }

    public function testManualFlowEndToEnd(): void
    {
        $result = $this->placeSimpleOrder();

        self::assertSame('manual', $result['payment']['status']);

        (new OrderPaymentService(new OrderRepository()))
            ->markPaid($this->context, '', $result['order']['uuid']);

        self::assertSame('paid', $this->orderRow($result['order']['uuid'])['status']);
    }

    public function testConfirmationHandlerMarksPaidOnExactAmount(): void
    {
        $result = $this->placeSimpleOrder();
        $order = $result['order'];

        $this->handler()->confirmed(
            $this->context,
            new PayableReference('commerce_order', $order['uuid'], (int) $order['grand_total'], $order['currency']),
            new PaymentConfirmation('paid', 'ref-1', (int) $order['grand_total'], $order['currency'])
        );

        self::assertSame('paid', $this->orderRow($order['uuid'])['status']);
    }

    public function testAmountMismatchRecordsEventWithoutTransition(): void
    {
        $result = $this->placeSimpleOrder();
        $order = $result['order'];

        $this->handler()->confirmed(
            $this->context,
            new PayableReference('commerce_order', $order['uuid'], (int) $order['grand_total'], $order['currency']),
            new PaymentConfirmation('paid', 'ref-2', (int) $order['grand_total'] - 1, $order['currency'])
        );

        self::assertSame('pending_payment', $this->orderRow($order['uuid'])['status']);
        self::assertCount(1, $this->connection->table('commerce_order_events')
            ->where('order_uuid', '=', $order['uuid'])
            ->where('type', '=', 'payment_amount_mismatch')
            ->get());
    }

    public function testLateConfirmationRoutesToRejection(): void
    {
        $result = $this->placeSimpleOrder();
        $order = $result['order'];
        (new OrderRepository())->transition($this->context, '', $order['uuid'], 'canceled');

        $this->handler()->confirmed(
            $this->context,
            new PayableReference('commerce_order', $order['uuid'], (int) $order['grand_total'], $order['currency']),
            new PaymentConfirmation('paid', 'ref-3', (int) $order['grand_total'], $order['currency'])
        );

        self::assertSame('canceled', $this->orderRow($order['uuid'])['status']);
        self::assertCount(1, $this->connection->table('commerce_order_events')
            ->where('order_uuid', '=', $order['uuid'])
            ->where('type', '=', 'payment_late_rejected')
            ->get());
    }

    public function testHandlerIgnoresOtherPayableTypes(): void
    {
        self::assertFalse($this->handler()->supports('lemma_invoice'));
        self::assertTrue($this->handler()->supports('commerce_order'));
    }

    public function testPlacedOrderInitiatesPaymentWithTheSharedOrderPayableType(): void
    {
        $spy = new class implements PaymentCollector {
            public ?PayableReference $captured = null;

            public function initiate(ApplicationContext $context, PayableReference $payable): PaymentInitiation
            {
                $this->captured = $payable;

                return new PaymentInitiation('spy', 'ok', ['reference' => 'r1']);
            }
        };

        $this->placeSimpleOrder($spy);

        self::assertNotNull($spy->captured);
        self::assertSame(OrderPayable::TYPE, $spy->captured->type);
        self::assertSame('commerce_order', $spy->captured->type);
    }

    private function handler(): OrderPaymentConfirmationHandler
    {
        return new OrderPaymentConfirmationHandler(
            new OrderRepository(),
            new OrderPaymentService(new OrderRepository()),
            new SentinelTenantResolver()
        );
    }

    /** @return array{order: array<string,mixed>, guest_token: string, payment: array<string,mixed>} */
    private function placeSimpleOrder(?PaymentCollector $collector = null): array
    {
        $catalog = new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            new SentinelTenantResolver(),
            new StockRepository()
        );
        $product = $catalog->createProduct($this->context, [
            'slug' => 'simple-' . substr(md5((string) microtime(true)), 0, 6),
            'name' => 'Simple',
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => 'SIMPLE-' . substr(md5((string) microtime(true)), 0, 6),
                'option_values' => [],
                'price' => 1000,
                'currency' => 'USD',
            ]],
        ]);
        $variantUuid = (string) $product['variants'][0]['uuid'];
        (new StockRepository())->increment($this->context, '', $variantUuid, 5);
        ['cart' => $cart, 'token' => $token] = $this->cart()->create($this->context);
        $this->cart()->addLine($this->context, $cart, $variantUuid, 1);

        return $this->checkout($collector)->placeOrder(
            $this->context,
            $token,
            ['email' => 'buyer@example.com', 'user_uuid' => null],
            ['shipping' => ['country' => 'US']],
            'std'
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

    private function checkout(?PaymentCollector $collector = null): CheckoutService
    {
        return new CheckoutService(
            $this->cart(),
            new DiscountRepository(),
            new DiscountService(new DiscountRepository(), new SentinelTenantResolver()),
            new StockRepository(),
            new PricingEngine(),
            $this->shipping(),
            $this->tax(),
            new \Glueful\Extensions\Commerce\Orders\OrderNumberGenerator(),
            new OrderRepository(),
            new DownloadRepository(),
            $collector ?? new ManualPaymentCollector(),
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
    private function orderRow(string $uuid): array
    {
        $row = $this->connection->table('commerce_orders')
            ->where('uuid', '=', $uuid)
            ->first();
        self::assertNotNull($row);

        return $row;
    }
}
