<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Orders;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventDispatcher;
use Glueful\Events\EventService;
use Glueful\Events\ListenerProvider;
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
use Glueful\Extensions\Commerce\Events\OrderPlaced;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Marketplace\CheckoutConflictException;
use Glueful\Extensions\Commerce\Orders\CheckoutAttemptContext;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Payments\PaymentCollector;
use Glueful\Validation\ValidationException;

/**
 * Durable checkout idempotency coordination (design spec §7, Slice-2 Task 3):
 * the {@see \Glueful\Extensions\Commerce\Orders\CheckoutAttemptAuthority} seam
 * `CheckoutService::placeOrder()` soft-consumes, and the active-cart lookup's
 * move from pre-transaction to inside the placement transaction that makes
 * the seam possible. Commerce never binds an implementation itself, so every
 * authority here is a self-contained test fake ({@see FakeCheckoutAttemptAuthority}) --
 * the same soft-consumption convention {@see SlugLifecycleAuthority} already
 * established for the product-slug ledger.
 */
final class CheckoutAttemptTest extends CommerceTestCase
{
    // --- Byte-parity: null authority/context => zero attempt queries -------

    public function testNullAttemptContextNeverInvokesABoundAuthority(): void
    {
        [$token] = $this->seedCartWithLine('SKU-ATT-NULLCTX', 3, 1, 1000);
        $authority = new FakeCheckoutAttemptAuthority();

        $placed = $this->checkout(authority: $authority)
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        self::assertSame('pending_payment', $placed['order']['status']);
        self::assertSame([], $authority->claimOrReplayCalls);
        self::assertSame([], $authority->completeCalls);
    }

    public function testAttemptContextWithoutABoundAuthorityBehavesNormally(): void
    {
        [$token] = $this->seedCartWithLine('SKU-ATT-NOAUTH', 3, 1, 1000);
        $attempt = new CheckoutAttemptContext('idem-no-authority', 'fingerprint-a');

        $placed = $this->checkout()
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std', $attempt);

        self::assertSame('pending_payment', $placed['order']['status']);
        self::assertSame(1, $this->connection->table('commerce_orders')->count());
    }

    // --- Byte-parity: the cart-lookup move is behavior-neutral -------------

    public function testUnknownCartTokenStillThrows422IdenticallyAfterTheLookupMove(): void
    {
        $authority = new FakeCheckoutAttemptAuthority();
        $attempt = new CheckoutAttemptContext('idem-unknown-token', 'fingerprint-a');

        try {
            $this->checkout(authority: $authority)->placeOrder(
                $this->context,
                'this-token-was-never-minted',
                $this->buyer(),
                $this->addresses(),
                'std',
                $attempt
            );
            self::fail('Expected a ValidationException for an unknown cart token.');
        } catch (ValidationException $e) {
            self::assertSame(['Cart not found or no longer active.'], $e->errorsFor('cart'));
        }

        self::assertSame(0, $this->connection->table('commerce_orders')->count());
        // The authority still ran claimOrReplay (before cart validation, per
        // contract) but never complete() -- no order ever came to exist.
        self::assertCount(1, $authority->claimOrReplayCalls);
        self::assertSame([], $authority->completeCalls);
    }

    public function testAlreadyConvertedCartStillThrows422IdenticallyAfterTheLookupMove(): void
    {
        [$token, $variantUuid] = $this->seedCartWithLine('SKU-ATT-INACTIVE', 5, 2, 1000);
        $checkout = $this->checkout();
        $checkout->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        try {
            $checkout->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');
            self::fail('A converted cart must not create another order.');
        } catch (ValidationException $e) {
            self::assertSame(['Cart not found or no longer active.'], $e->errorsFor('cart'));
            self::assertSame(1, $this->connection->table('commerce_orders')->count());
            self::assertSame(3, (new StockRepository())->quantity($this->context, '', $variantUuid));
        }
    }

    // --- New attempt: complete() runs inside the txn, after the insert -----

    public function testCompleteIsCalledInsideTheTransactionAfterTheOrderExists(): void
    {
        [$token] = $this->seedCartWithLine('SKU-ATT-COMPLETE', 3, 1, 1000);
        $authority = new FakeCheckoutAttemptAuthority();
        $attempt = new CheckoutAttemptContext('idem-complete-once', 'fingerprint-a');

        $placed = $this->checkout(authority: $authority)
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std', $attempt);

        self::assertCount(1, $authority->claimOrReplayCalls);
        self::assertSame($attempt, $authority->claimOrReplayCalls[0]);
        self::assertCount(1, $authority->completeCalls);
        self::assertSame((string) $placed['order']['uuid'], $authority->completeCalls[0]['orderUuid']);
        self::assertSame((string) $placed['order']['order_number'], $authority->completeCalls[0]['orderRef']);
        self::assertSame($placed['guest_token'], $authority->completeCalls[0]['rawGuestToken']);
    }

    public function testForcedFailureInCompleteRollsBackBothTheOrderAndTheAttemptBinding(): void
    {
        [$token, $variantUuid] = $this->seedCartWithLine('SKU-ATT-FORCEDFAIL', 5, 2, 1000);
        $authority = new FakeCheckoutAttemptAuthority(onComplete: function (ApplicationContext $c, string $orderUuid): void {
            // Real DB write, INSIDE the same placement transaction --
            // proves this write is undone together with the order below,
            // not just that the in-memory fake "forgets" it.
            (new OrderRepository())->recordEvent($c, $orderUuid, 'test_attempt_bound_marker');
            throw new \RuntimeException('forced failure after the attempt-binding write');
        });
        $attempt = new CheckoutAttemptContext('idem-forced-failure', 'fingerprint-a');

        try {
            $this->checkout(authority: $authority)
                ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std', $attempt);
            self::fail('Expected the forced complete() failure to propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('forced failure after the attempt-binding write', $e->getMessage());
        }

        self::assertSame(0, $this->connection->table('commerce_orders')->count());
        self::assertSame(
            0,
            $this->connection->table('commerce_order_events')
                ->where('type', '=', 'test_attempt_bound_marker')
                ->count()
        );
        self::assertSame(5, (new StockRepository())->quantity($this->context, '', $variantUuid));
    }

    // --- Replay: same order + credential, no second OrderPlaced ------------

    public function testReplayReturnsTheStoredOrderAndCredentialWithoutASecondOrderPlacedAndReinitiatesPayment(): void
    {
        [$token] = $this->seedCartWithLine('SKU-ATT-REPLAY', 3, 1, 1000);
        $authority = new FakeCheckoutAttemptAuthority();
        $attempt = new CheckoutAttemptContext('idem-replay', 'fingerprint-a');
        $captured = $this->bindEventCapture();

        $first = $this->checkout(authority: $authority)
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std', $attempt);

        self::assertCount(1, $captured->events);
        self::assertSame('manual', $first['payment']['status']);

        // Same key + same fingerprint, on a brand-new CheckoutService
        // instance (a fresh HTTP request in real life) with a completely
        // different (never-claimed) cart token -- proves the replay never
        // even reaches cart validation.
        $second = $this->checkout(authority: $authority)
            ->placeOrder($this->context, 'a-cart-token-never-minted', $this->buyer(), $this->addresses(), 'std', $attempt);

        self::assertSame($first['order']['uuid'], $second['order']['uuid']);
        self::assertSame($first['guest_token'], $second['guest_token']);
        self::assertSame('manual', $second['payment']['status']);

        // No second OrderPlaced dispatched for the replay.
        self::assertCount(1, $captured->events);
        self::assertSame($first['order']['uuid'], (string) $captured->events[0]->order['uuid']);

        // Still only one order row ever created ...
        self::assertSame(1, $this->connection->table('commerce_orders')->count());
        // ... but the payment collector was invoked again, post-commit, for
        // the replay (design spec §7: contractually idempotent by payable).
        self::assertSame(
            2,
            $this->connection->table('commerce_order_events')
                ->where('order_uuid', '=', $first['order']['uuid'])
                ->where('type', '=', 'payment_initiated')
                ->count()
        );

        // claimOrReplay ran on both calls; complete() ran on only the first.
        self::assertCount(2, $authority->claimOrReplayCalls);
        self::assertCount(1, $authority->completeCalls);
    }

    // --- Fingerprint mismatch: 409-shaped throw, whole txn rolls back ------

    public function testFingerprintMismatchOnKeyReuseThrowsAndRollsBackTheWholeTransaction(): void
    {
        [$token] = $this->seedCartWithLine('SKU-ATT-MISMATCH', 3, 1, 1000);
        $authority = new FakeCheckoutAttemptAuthority();

        $first = $this->checkout(authority: $authority)->placeOrder(
            $this->context,
            $token,
            $this->buyer(),
            $this->addresses(),
            'std',
            new CheckoutAttemptContext('idem-mismatch', 'fingerprint-a')
        );

        [$secondToken] = $this->seedCartWithLine('SKU-ATT-MISMATCH-2', 3, 1, 1000);

        try {
            $this->checkout(authority: $authority)->placeOrder(
                $this->context,
                $secondToken,
                $this->buyer(),
                $this->addresses(),
                'std',
                new CheckoutAttemptContext('idem-mismatch', 'fingerprint-b')
            );
            self::fail('Expected a CheckoutConflictException on fingerprint mismatch.');
        } catch (CheckoutConflictException $e) {
            self::assertSame('checkout_conflict', $e->errorCode);
        }

        // No second order; the ONLY order in the database is the first call's.
        self::assertSame(1, $this->connection->table('commerce_orders')->count());
        self::assertSame($first['order']['uuid'], $this->connection->table('commerce_orders')->first()['uuid']);
        // The second cart was never even claimed -- claimOrReplay throws
        // before cart validation is ever reached.
        self::assertSame('active', $this->connection->table('commerce_carts')
            ->where('token_hash', '=', hash('sha256', $secondToken))
            ->first()['status']);
    }

    // --- Helpers -------------------------------------------------------

    /**
     * Binds a real EventService into the test container and returns a capture object whose
     * `events` list is appended to (in dispatch order) as OrderPlaced fires. Mirrors
     * {@see \Glueful\Extensions\Commerce\Tests\Integration\Orders\OrderFulfilledDispatchTest}'s
     * identical helper.
     */
    private function bindEventCapture(): object
    {
        $capture = new class {
            /** @var list<object> */
            public array $events = [];
        };
        $listeners = new ListenerProvider();
        $eventService = new EventService(new EventDispatcher($listeners), $listeners);
        $eventService->addListener(OrderPlaced::class, function (OrderPlaced $e) use ($capture): void {
            $capture->events[] = $e;
        });
        $this->bind(EventService::class, $eventService);

        return $capture;
    }

    private function checkout(
        ?PaymentCollector $collector = null,
        ?FakeCheckoutAttemptAuthority $authority = null,
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
            $collector ?? new ManualPaymentCollector(),
            new SentinelTenantResolver(),
            attemptAuthority: $authority
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
