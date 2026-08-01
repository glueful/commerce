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
use Glueful\Extensions\Commerce\Contracts\OrderPaymentReturnUrlProvider;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\ExpiryService;
use Glueful\Extensions\Commerce\Orders\InsufficientStockException;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Commerce\Support\TokenHasher;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\PaymentCollector;
use Glueful\Extensions\Contracts\Payments\PaymentInitiation;
use Glueful\Validation\ValidationException;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

final class CheckoutTest extends CommerceTestCase
{
    public function testHappyPathQuoteEqualsCharge(): void
    {
        [$token] = $this->seedCartWithLine('SKU-H', 5, 2, 1000);
        $checkout = $this->checkout();
        $cart = $this->cart()->byToken($this->context, $token);
        self::assertNotNull($cart);

        $quote = $checkout->quote($this->context, $cart, ['country' => 'US'], 'std');
        $placed = $checkout->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        self::assertSame($quote['totals']->grandTotal, (int) $placed['order']['grand_total']);
    }

    public function testSameCartCannotCreateASecondOrder(): void
    {
        [$token, $variantUuid] = $this->seedCartWithLine('SKU-IDEMPOTENT', 5, 2, 1000);
        $checkout = $this->checkout();

        $checkout->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        try {
            $checkout->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');
            self::fail('A converted cart must not create another order.');
        } catch (ValidationException) {
            self::assertSame(1, $this->connection->table('commerce_orders')->count());
            self::assertSame(3, (new StockRepository())->quantity($this->context, '', $variantUuid));
        }
    }

    public function testOversellRollsBackEverything(): void
    {
        [$token, $variantUuid] = $this->seedCartWithLine('SKU-O', 1, 1, 1000);
        $cart = $this->cart()->byToken($this->context, $token);
        self::assertNotNull($cart);
        $line = $this->connection->table('commerce_cart_lines')->where('cart_uuid', '=', $cart['uuid'])->first();
        self::assertNotNull($line);
        $this->connection->table('commerce_cart_lines')->where('uuid', '=', $line['uuid'])->update(['quantity' => 2]);

        $this->expectException(InsufficientStockException::class);
        try {
            $this->checkout()->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');
        } finally {
            self::assertSame(0, $this->connection->table('commerce_orders')->count());
            self::assertSame(1, (new StockRepository())->quantity($this->context, '', $variantUuid));
            self::assertSame('active', $this->connection->table('commerce_carts')->where('uuid', '=', $cart['uuid'])->first()['status']);
        }
    }

    public function testDiscountExhaustedInsideTransactionRollsBack(): void
    {
        [$token, $variantUuid] = $this->seedCartWithLine('SKU-D', 2, 1, 1000);
        $this->seedDiscount(['code' => 'LIMIT', 'usage_limit' => 1, 'usage_count' => 1]);
        $cart = $this->cart()->byToken($this->context, $token);
        self::assertNotNull($cart);
        $cart = $this->cart()->applyDiscount($this->context, $cart, 'LIMIT');

        $this->expectException(ValidationException::class);
        try {
            $this->checkout()->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');
        } finally {
            self::assertSame(0, $this->connection->table('commerce_orders')->count());
            self::assertSame(2, (new StockRepository())->quantity($this->context, '', $variantUuid));
            self::assertSame('active', $this->connection->table('commerce_carts')->where('uuid', '=', $cart['uuid'])->first()['status']);
        }
    }

    public function testPaymentInitFailureLeavesRetryableOrder(): void
    {
        [$token] = $this->seedCartWithLine('SKU-P', 2, 1, 1000);
        $failing = new class implements PaymentCollector {
            public function initiate(ApplicationContext $context, PayableReference $payable): PaymentInitiation
            {
                throw new \RuntimeException('provider down');
            }
        };

        $placed = $this->checkout($failing)->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        self::assertSame('pending_payment', $placed['order']['status']);
        self::assertSame('init_failed', $placed['payment']['status']);
        self::assertNotNull($this->connection->table('commerce_order_events')
            ->where('type', '=', 'payment_init_failed')
            ->first());
    }

    public function testRetryPaymentCompletesFlow(): void
    {
        [$token] = $this->seedCartWithLine('SKU-R', 2, 1, 1000);
        $placed = $this->checkout(new ManualPaymentCollector())
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        $payment = $this->checkout(new ManualPaymentCollector())->retryPayment($this->context, $placed['order']);

        self::assertSame('manual', $payment['status']);
        self::assertNotNull($this->connection->table('commerce_order_events')
            ->where('type', '=', 'payment_initiated')
            ->first());
    }

    public function testPayableMetadataAlwaysCarriesTheBuyerEmail(): void
    {
        // The payable metadata convention (checkout-ui plan Task 2): the collector's gateway leg
        // needs the payer email (Paystack requires it) — supplied on EVERY payable, provider or not.
        [$token] = $this->seedCartWithLine('SKU-M1', 2, 1, 1000);
        $recording = $this->recordingCollector();

        $this->checkout($recording)->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        self::assertSame('buyer@example.com', $recording->last?->metadata['email']);
        self::assertArrayNotHasKey('callback_url', $recording->last->metadata);
        self::assertArrayNotHasKey('cancel_url', $recording->last->metadata);
    }

    public function testReturnUrlProviderFeedsCallbackAndCancelOnPlacementAndRetry(): void
    {
        // The host-bound provider receives the COMPLETED order (number final), so the same path
        // serves initial placement, durable replay, and retryPayment — initiatePayment is the
        // single call site all three share.
        [$token] = $this->seedCartWithLine('SKU-M2', 2, 1, 1000);
        $recording = $this->recordingCollector();

        $placed = $this->checkoutWith($recording, $this->urlProvider())
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        $number = (string) $placed['order']['order_number'];
        self::assertSame("https://shop.test/checkout/return/{$number}", $recording->last?->metadata['callback_url']);
        self::assertSame("https://shop.test/checkout/cancel/{$number}", $recording->last->metadata['cancel_url']);
        self::assertSame('buyer@example.com', $recording->last->metadata['email']);

        $retryRecording = $this->recordingCollector();
        $this->checkoutWith($retryRecording, $this->urlProvider())
            ->retryPayment($this->context, $placed['order']);

        self::assertSame("https://shop.test/checkout/return/{$number}", $retryRecording->last?->metadata['callback_url']);
    }

    public function testProviderExceptionMapsToInitFailedWithoutRollingBackTheOrder(): void
    {
        [$token] = $this->seedCartWithLine('SKU-M3', 2, 1, 1000);
        $throwing = new class implements OrderPaymentReturnUrlProvider {
            public function urlsFor(ApplicationContext $context, array $order): ?array
            {
                throw new \RuntimeException('resolver exploded');
            }
        };

        $placed = $this->checkoutWith($this->recordingCollector(), $throwing)
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        self::assertSame('pending_payment', $placed['order']['status']);
        self::assertSame('init_failed', $placed['payment']['status']);
        self::assertNotNull($this->connection->table('commerce_order_events')
            ->where('type', '=', 'payment_init_failed')
            ->first());
    }

    public function testInvalidProviderOutputMapsToInitFailedAndLogs(): void
    {
        [$token] = $this->seedCartWithLine('SKU-M4', 2, 1, 1000);
        $insecure = new class implements OrderPaymentReturnUrlProvider {
            public function urlsFor(ApplicationContext $context, array $order): ?array
            {
                return ['return' => 'http://insecure.test/return', 'cancel' => 'https://shop.test/cancel'];
            }
        };
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };

        $placed = $this->checkoutWith($this->recordingCollector(), $insecure, $logger)
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        self::assertSame('init_failed', $placed['payment']['status']);
        self::assertSame('pending_payment', $placed['order']['status']);
        self::assertNotSame([], $logger->messages, 'invalid provider output must be logged');
    }

    public function testAbsentProviderAddsNoUrlMetadata(): void
    {
        [$token] = $this->seedCartWithLine('SKU-M5', 2, 1, 1000);
        $recording = $this->recordingCollector();

        $this->checkoutWith($recording, null)
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        self::assertSame(['email' => 'buyer@example.com'], $recording->last?->metadata);
    }

    public function testExpiryRestocksAndCancels(): void
    {
        [$token, $variantUuid] = $this->seedCartWithLine('SKU-E', 3, 2, 1000);
        $placed = $this->checkout()->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');
        $this->connection->table('commerce_orders')
            ->where('uuid', '=', $placed['order']['uuid'])
            ->update(['placed_at' => gmdate('Y-m-d H:i:s', time() - 7200)]);

        $expired = (new ExpiryService(new OrderRepository(), new StockRepository(), new SentinelTenantResolver()))
            ->expireStale($this->context);

        self::assertSame(1, $expired);
        self::assertSame(3, (new StockRepository())->quantity($this->context, '', $variantUuid));
        self::assertSame('release', $this->connection->table('commerce_stock_movements')
            ->where('variant_uuid', '=', $variantUuid)
            ->orderBy('id', 'DESC')
            ->first()['reason']);
    }

    public function testGuestTokenReturnedRawOnceAndStoredHashed(): void
    {
        [$token] = $this->seedCartWithLine('SKU-G', 2, 1, 1000);
        $placed = $this->checkout()->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        self::assertSame(40, strlen($placed['guest_token']));
        self::assertSame(
            TokenHasher::hash($placed['guest_token']),
            $this->connection->table('commerce_orders')
                ->where('uuid', '=', $placed['order']['uuid'])
                ->first()['guest_token_hash']
        );
    }

    /** @return PaymentCollector&object{last: ?PayableReference} */
    private function recordingCollector(): PaymentCollector
    {
        return new class implements PaymentCollector {
            public ?PayableReference $last = null;

            public function initiate(ApplicationContext $context, PayableReference $payable): PaymentInitiation
            {
                $this->last = $payable;

                return new PaymentInitiation('test', 'manual', ['instructions' => 'recorded']);
            }
        };
    }

    private function urlProvider(): OrderPaymentReturnUrlProvider
    {
        return new class implements OrderPaymentReturnUrlProvider {
            public function urlsFor(ApplicationContext $context, array $order): ?array
            {
                $number = (string) $order['order_number'];

                return [
                    'return' => 'https://shop.test/checkout/return/' . $number,
                    'cancel' => 'https://shop.test/checkout/cancel/' . $number,
                ];
            }
        };
    }

    private function checkoutWith(
        PaymentCollector $collector,
        ?OrderPaymentReturnUrlProvider $returnUrls,
        ?LoggerInterface $logger = null,
    ): CheckoutService {
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
            $collector,
            new SentinelTenantResolver(),
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $returnUrls,
            $logger,
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
            new OrderNumberGenerator(),
            new OrderRepository(),
            new DownloadRepository(),
            $collector ?? new ManualPaymentCollector(),
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

    /** @param array<string,mixed> $overrides */
    private function seedDiscount(array $overrides): void
    {
        $this->connection->table('commerce_discounts')->insert(array_merge([
            'uuid' => 'disc' . substr(md5((string) ($overrides['code'] ?? 'CODE')), 0, 8),
            'code' => 'SAVE10',
            'type' => 'percentage',
            'value' => 1000,
            'usage_limit' => null,
            'once_per_buyer' => 0,
            'usage_count' => 0,
            'status' => 'active',
        ], $overrides));
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
