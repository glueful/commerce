<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

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
use Glueful\Extensions\Commerce\Events\OrderFulfilled;
use Glueful\Extensions\Commerce\Events\SellerOrderFulfilled;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Invoices\ConfigSellerIdentityProvider;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderFulfillmentService;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderPaymentConfirmation;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\FulfillmentStatus;
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
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Fulfillment rollup + operator fan-out + whole-order cancellation fan-out
 * (design spec §2.8-§2.10, MV2 plan Task 7): the parent-then-children claim
 * chain, the `FulfillmentStatus` rollup vocabulary, `SellerOrderFulfillmentService`'s
 * `fulfill()`/`fanOutFulfill()`, and `AdminOrderController::cancel()`'s
 * partitioned child fan-out -- proved end to end against real, fully-wired
 * collaborators (mirrors `PaymentConfirmationTest`'s wiring conventions).
 */
final class FulfillmentRollupTest extends CommerceTestCase
{
    private const TENANT = '';

    // -----------------------------------------------------------------
    // 1. Two-seller progression: unfulfilled -> partial -> fulfilled.
    // -----------------------------------------------------------------

    public function testTwoSellerOrderRollsUpFromUnfulfilledThroughPartialToFulfilled(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $this->seedSeller('sellerBBBB01', 'Seller B');
        $productX = $this->seedProduct('rollup-x', 1000, 'sellerAAAA01');
        $productY = $this->seedProduct('rollup-y', 2000, 'sellerBBBB01');

        $order = $this->placeTwoSellerOrder($productX, $productY);
        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);
        self::assertSame('unfulfilled', $this->orderRow((string) $order['uuid'])['fulfillment_status']);

        [$childA, $childB] = $this->sellerOrdersFor((string) $order['uuid']);

        $service = $this->fulfillmentService();
        $service->fulfill(
            $this->context,
            self::TENANT,
            (string) $order['uuid'],
            (string) $childA['uuid'],
            $this->tracking('Carrier A'),
            null
        );

        $afterFirst = $this->orderRow((string) $order['uuid']);
        self::assertSame('partial', $afterFirst['fulfillment_status']);
        self::assertSame('paid', $afterFirst['status'], 'the parent lifecycle status must not flip on partial');

        $service->fulfill(
            $this->context,
            self::TENANT,
            (string) $order['uuid'],
            (string) $childB['uuid'],
            $this->tracking('Carrier B'),
            null
        );

        $afterSecond = $this->orderRow((string) $order['uuid']);
        self::assertSame('fulfilled', $afterSecond['fulfillment_status']);
        self::assertSame('fulfilled', $afterSecond['status'], 'every non-canceled child fulfilled flips the parent');
    }

    // -----------------------------------------------------------------
    // 2. Single-seller order rolls straight to fulfilled.
    // -----------------------------------------------------------------

    public function testSingleSellerOrderRollsStraightToFulfilled(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $product = $this->seedProduct('single-x', 1000, 'sellerAAAA01');

        $order = $this->placeOneSellerOrder($product);
        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);

        $child = $this->sellerOrdersFor((string) $order['uuid'])[0];

        $this->fulfillmentService()->fulfill(
            $this->context,
            self::TENANT,
            (string) $order['uuid'],
            (string) $child['uuid'],
            $this->tracking(),
            null
        );

        $updated = $this->orderRow((string) $order['uuid']);
        self::assertSame('fulfilled', $updated['fulfillment_status']);
        self::assertSame('fulfilled', $updated['status']);
    }

    // -----------------------------------------------------------------
    // 3. A canceled child is excluded from the rollup.
    // -----------------------------------------------------------------

    public function testCanceledChildIsExcludedFromTheRollup(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $this->seedSeller('sellerBBBB01', 'Seller B');
        $productX = $this->seedProduct('excl-x', 1000, 'sellerAAAA01');
        $productY = $this->seedProduct('excl-y', 2000, 'sellerBBBB01');

        $order = $this->placeTwoSellerOrder($productX, $productY);
        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);
        [$childA, $childB] = $this->sellerOrdersFor((string) $order['uuid']);

        // Simulate a whole-order cancel fan-out having already canceled seller
        // A's partition (exercised end to end in test 7 below) while leaving
        // the parent order itself 'paid' -- isolating ONLY the rollup's
        // canceled-child exclusion, independent of the cancel() endpoint.
        $this->connection->table('commerce_seller_orders')
            ->where('uuid', '=', $childA['uuid'])
            ->update(['status' => 'canceled']);

        $this->fulfillmentService()->fulfill(
            $this->context,
            self::TENANT,
            (string) $order['uuid'],
            (string) $childB['uuid'],
            $this->tracking(),
            null
        );

        $updated = $this->orderRow((string) $order['uuid']);
        self::assertSame('fulfilled', $updated['fulfillment_status'], 'the canceled child must not block the rollup');
        self::assertSame('fulfilled', $updated['status']);
    }

    // -----------------------------------------------------------------
    // 4. Operator fan-out fulfills every non-canceled child and rolls up.
    // -----------------------------------------------------------------

    public function testOperatorFanOutFulfillsAllChildrenAndRollsUp(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $this->seedSeller('sellerBBBB01', 'Seller B');
        $productX = $this->seedProduct('fanout-x', 1000, 'sellerAAAA01');
        $productY = $this->seedProduct('fanout-y', 2000, 'sellerBBBB01');

        $order = $this->placeTwoSellerOrder($productX, $productY);
        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);

        $result = $this->fulfillmentService()->fanOutFulfill(
            $this->context,
            self::TENANT,
            (string) $order['uuid'],
            $this->tracking('Fan-out Carrier'),
            null
        );

        self::assertSame('fulfilled', $result['fulfillment_status']);
        self::assertSame('fulfilled', $result['status']);

        foreach ($this->sellerOrdersFor((string) $order['uuid']) as $child) {
            self::assertSame('fulfilled', $child['fulfillment_status']);
            self::assertSame('Fan-out Carrier', $child['carrier'], 'fan-out applies the same tracking to every child');
        }
    }

    /**
     * A canceled child is skipped by the fan-out (never touched, never
     * counted against the rollup).
     */
    public function testOperatorFanOutSkipsCanceledChildren(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $this->seedSeller('sellerBBBB01', 'Seller B');
        $productX = $this->seedProduct('fanout-skip-x', 1000, 'sellerAAAA01');
        $productY = $this->seedProduct('fanout-skip-y', 2000, 'sellerBBBB01');

        $order = $this->placeTwoSellerOrder($productX, $productY);
        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);
        [$childA] = $this->sellerOrdersFor((string) $order['uuid']);

        $this->connection->table('commerce_seller_orders')
            ->where('uuid', '=', $childA['uuid'])
            ->update(['status' => 'canceled']);

        $result = $this->fulfillmentService()->fanOutFulfill(
            $this->context,
            self::TENANT,
            (string) $order['uuid'],
            $this->tracking(),
            null
        );

        self::assertSame('fulfilled', $result['fulfillment_status']);
        $stillCanceled = $this->connection->table('commerce_seller_orders')
            ->where('uuid', '=', $childA['uuid'])
            ->first();
        self::assertSame('canceled', $stillCanceled['status']);
        self::assertSame('unfulfilled', $stillCanceled['fulfillment_status'], 'a canceled child is never fulfilled');
    }

    // -----------------------------------------------------------------
    // 5. Whole-order cancel fans out from BOTH pending_payment AND paid.
    // -----------------------------------------------------------------

    public function testWholeOrderCancelFansOutToEveryChildFromPendingPayment(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $this->seedSeller('sellerBBBB01', 'Seller B');
        $productX = $this->seedProduct('cancel-pending-x', 1000, 'sellerAAAA01');
        $productY = $this->seedProduct('cancel-pending-y', 2000, 'sellerBBBB01');

        $order = $this->placeTwoSellerOrder($productX, $productY);
        self::assertSame('pending_payment', $this->orderRow((string) $order['uuid'])['status']);

        $response = $this->adminController()->cancel(
            Request::create('/commerce/admin/orders/' . $order['uuid'] . '/cancel', 'POST'),
            (string) $order['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('canceled', $this->orderRow((string) $order['uuid'])['status']);
        foreach ($this->sellerOrdersFor((string) $order['uuid']) as $child) {
            self::assertSame('canceled', $child['status']);
        }
    }

    public function testWholeOrderCancelFansOutToEveryChildFromPaid(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $this->seedSeller('sellerBBBB01', 'Seller B');
        $productX = $this->seedProduct('cancel-paid-x', 1000, 'sellerAAAA01');
        $productY = $this->seedProduct('cancel-paid-y', 2000, 'sellerBBBB01');

        $order = $this->placeTwoSellerOrder($productX, $productY);
        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);
        self::assertSame('paid', $this->orderRow((string) $order['uuid'])['status']);

        $response = $this->adminController()->cancel(
            Request::create('/commerce/admin/orders/' . $order['uuid'] . '/cancel', 'POST'),
            (string) $order['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('canceled', $this->orderRow((string) $order['uuid'])['status']);
        foreach ($this->sellerOrdersFor((string) $order['uuid']) as $child) {
            self::assertSame('canceled', $child['status']);
        }
    }

    public function testNonPartitionedCancelNeverTouchesSellerOrderTable(): void
    {
        $order = $this->placeNonPartitionedOrder();

        $response = $this->adminController()->cancel(
            Request::create('/commerce/admin/orders/' . $order['uuid'] . '/cancel', 'POST'),
            (string) $order['uuid']
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('canceled', $this->orderRow((string) $order['uuid'])['status']);
        self::assertSame(0, $this->connection->table('commerce_seller_orders')->count());
    }

    // -----------------------------------------------------------------
    // 6. Error semantics: canceled/already-fulfilled -> 409-mapped
    //    \DomainException; unconfirmed / wrong-seller -> non-revealing 404.
    // -----------------------------------------------------------------

    public function testFulfillingACanceledChildThrowsDomainException(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $product = $this->seedProduct('canceled-child-x', 1000, 'sellerAAAA01');

        $order = $this->placeOneSellerOrder($product);
        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);
        $child = $this->sellerOrdersFor((string) $order['uuid'])[0];

        $this->connection->table('commerce_seller_orders')
            ->where('uuid', '=', $child['uuid'])
            ->update(['status' => 'canceled']);

        $this->expectException(\DomainException::class);
        $this->fulfillmentService()->fulfill(
            $this->context,
            self::TENANT,
            (string) $order['uuid'],
            (string) $child['uuid'],
            $this->tracking(),
            null
        );
    }

    public function testFulfillingAnAlreadyFulfilledChildThrowsDomainException(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $product = $this->seedProduct('refulfill-x', 1000, 'sellerAAAA01');

        $order = $this->placeOneSellerOrder($product);
        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);
        $child = $this->sellerOrdersFor((string) $order['uuid'])[0];

        $service = $this->fulfillmentService();
        $service->fulfill(
            $this->context,
            self::TENANT,
            (string) $order['uuid'],
            (string) $child['uuid'],
            $this->tracking(),
            null
        );

        $this->expectException(\DomainException::class);
        $service->fulfill(
            $this->context,
            self::TENANT,
            (string) $order['uuid'],
            (string) $child['uuid'],
            $this->tracking(),
            null
        );
    }

    public function testFulfillingAnUnconfirmedChildIsNonRevealing404(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $product = $this->seedProduct('unconfirmed-x', 1000, 'sellerAAAA01');

        // No markPaid(): the parent stays pending_payment, so the child was
        // never stamped confirmed_at (the §2.12 PII gate).
        $order = $this->placeOneSellerOrder($product);
        $child = $this->sellerOrdersFor((string) $order['uuid'])[0];
        self::assertNull($child['confirmed_at']);

        $this->expectException(NotFoundException::class);
        $this->fulfillmentService()->fulfill(
            $this->context,
            self::TENANT,
            (string) $order['uuid'],
            (string) $child['uuid'],
            $this->tracking(),
            null
        );
    }

    public function testFulfillingWithAWrongSellerActorIsNonRevealing404(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $this->seedSeller('sellerBBBB01', 'Seller B');
        $productX = $this->seedProduct('wrongseller-x', 1000, 'sellerAAAA01');
        $productY = $this->seedProduct('wrongseller-y', 2000, 'sellerBBBB01');

        $order = $this->placeTwoSellerOrder($productX, $productY);
        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);

        $childOfB = null;
        foreach ($this->sellerOrdersFor((string) $order['uuid']) as $row) {
            if ($row['seller_uuid'] === 'sellerBBBB01') {
                $childOfB = $row;
            }
        }
        self::assertNotNull($childOfB);

        $this->expectException(NotFoundException::class);
        $this->fulfillmentService()->fulfill(
            $this->context,
            self::TENANT,
            (string) $order['uuid'],
            (string) $childOfB['uuid'],
            $this->tracking(),
            'sellerAAAA01'
        );
    }

    public function testTheOwningSellerCanFulfillItsOwnChild(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $product = $this->seedProduct('ownseller-x', 1000, 'sellerAAAA01');

        $order = $this->placeOneSellerOrder($product);
        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);
        $child = $this->sellerOrdersFor((string) $order['uuid'])[0];

        $result = $this->fulfillmentService()->fulfill(
            $this->context,
            self::TENANT,
            (string) $order['uuid'],
            (string) $child['uuid'],
            $this->tracking(),
            'sellerAAAA01'
        );

        self::assertSame('fulfilled', $result['fulfillment_status']);
    }

    // -----------------------------------------------------------------
    // 7. Events: SellerOrderFulfilled per transitioned child, OrderFulfilled
    //    exactly once, only after commit.
    // -----------------------------------------------------------------

    public function testEventsFireAfterCommitWithOneOrderFulfilledOnParentFulfilled(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $this->seedSeller('sellerBBBB01', 'Seller B');
        $productX = $this->seedProduct('events-x', 1000, 'sellerAAAA01');
        $productY = $this->seedProduct('events-y', 2000, 'sellerBBBB01');

        $order = $this->placeTwoSellerOrder($productX, $productY);
        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);
        [$childA, $childB] = $this->sellerOrdersFor((string) $order['uuid']);

        $captured = $this->bindEventCapture();
        $service = $this->fulfillmentService();

        $service->fulfill(
            $this->context,
            self::TENANT,
            (string) $order['uuid'],
            (string) $childA['uuid'],
            $this->tracking(),
            null
        );

        self::assertCount(1, $captured->sellerOrderFulfilled, 'the first fulfill dispatches one SellerOrderFulfilled');
        self::assertCount(0, $captured->orderFulfilled, 'the parent is only partial -- no OrderFulfilled yet');

        $service->fulfill(
            $this->context,
            self::TENANT,
            (string) $order['uuid'],
            (string) $childB['uuid'],
            $this->tracking(),
            null
        );

        self::assertCount(2, $captured->sellerOrderFulfilled, 'the second fulfill dispatches its own SellerOrderFulfilled');
        self::assertCount(1, $captured->orderFulfilled, 'OrderFulfilled fires exactly once, when the parent reaches fulfilled');
        self::assertSame((string) $order['uuid'], (string) $captured->orderFulfilled[0]->order['uuid']);
    }

    public function testFanOutDispatchesOneSellerOrderFulfilledPerChildAndOneOrderFulfilled(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $this->seedSeller('sellerBBBB01', 'Seller B');
        $productX = $this->seedProduct('fanout-events-x', 1000, 'sellerAAAA01');
        $productY = $this->seedProduct('fanout-events-y', 2000, 'sellerBBBB01');

        $order = $this->placeTwoSellerOrder($productX, $productY);
        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);

        $captured = $this->bindEventCapture();

        $this->fulfillmentService()->fanOutFulfill(
            $this->context,
            self::TENANT,
            (string) $order['uuid'],
            $this->tracking(),
            null
        );

        self::assertCount(2, $captured->sellerOrderFulfilled);
        self::assertCount(1, $captured->orderFulfilled);
    }

    public function testAnOuterTransactionRollbackEmitsNoEvents(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $product = $this->seedProduct('rollback-events-x', 1000, 'sellerAAAA01');

        $order = $this->placeOneSellerOrder($product);
        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);
        $child = $this->sellerOrdersFor((string) $order['uuid'])[0];

        $captured = $this->bindEventCapture();
        $service = $this->fulfillmentService();

        try {
            db($this->context)->transaction(function () use ($service, $order, $child): void {
                $service->fulfill(
                    $this->context,
                    self::TENANT,
                    (string) $order['uuid'],
                    (string) $child['uuid'],
                    $this->tracking(),
                    null
                );
                throw new \RuntimeException('force outer rollback');
            });
            self::fail('Expected the outer transaction to roll back.');
        } catch (\RuntimeException $e) {
            self::assertSame('force outer rollback', $e->getMessage());
        }

        self::assertSame('paid', $this->orderRow((string) $order['uuid'])['status']);
        self::assertSame('unfulfilled', $this->orderRow((string) $order['uuid'])['fulfillment_status']);
        self::assertCount(0, $captured->sellerOrderFulfilled);
        self::assertCount(0, $captured->orderFulfilled);
    }

    // -----------------------------------------------------------------
    // 8. Pure vocabulary: the all-children-canceled rollup edge.
    // -----------------------------------------------------------------

    public function testRollupOfAllCanceledChildrenIsPinnedToFulfilled(): void
    {
        self::assertSame(FulfillmentStatus::PARENT_FULFILLED, FulfillmentStatus::rollup([
            ['status' => 'canceled', 'fulfillment_status' => 'unfulfilled'],
            ['status' => 'canceled', 'fulfillment_status' => 'fulfilled'],
        ]));
        self::assertSame(FulfillmentStatus::PARENT_FULFILLED, FulfillmentStatus::rollup([]));
    }

    public function testRollupVocabularyAssertionsRejectUnknownValues(): void
    {
        $this->expectException(\DomainException::class);
        FulfillmentStatus::assertParent('partial-ish');
    }

    public function testChildVocabularyAssertionRejectsParentOnlyValue(): void
    {
        $this->expectException(\DomainException::class);
        FulfillmentStatus::assertChild('partial');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function fulfillmentService(): SellerOrderFulfillmentService
    {
        return new SellerOrderFulfillmentService(new OrderRepository(), new SellerOrderRepository());
    }

    private function adminController(): AdminOrderController
    {
        return new AdminOrderController(
            $this->context,
            new OrderRepository(),
            new StockRepository(),
            $this->paymentService(),
            new SentinelTenantResolver(),
            new RefundRepository(),
            new ConfigSellerIdentityProvider(),
            new SellerOrderRepository()
        );
    }

    /** @return array{carrier?:?string, tracking_number?:?string, tracking_url?:?string} */
    private function tracking(string $carrier = 'UPS'): array
    {
        return ['carrier' => $carrier, 'tracking_number' => 'TRACK123', 'tracking_url' => 'https://track.example/123'];
    }

    private function paymentService(): OrderPaymentService
    {
        return new OrderPaymentService(new OrderRepository(), new SellerOrderPaymentConfirmation());
    }

    /** @return object{sellerOrderFulfilled: list<SellerOrderFulfilled>, orderFulfilled: list<OrderFulfilled>} */
    private function bindEventCapture(): object
    {
        $capture = new class {
            /** @var list<SellerOrderFulfilled> */
            public array $sellerOrderFulfilled = [];
            /** @var list<OrderFulfilled> */
            public array $orderFulfilled = [];
        };
        $listeners = new ListenerProvider();
        $eventService = new EventService(new EventDispatcher($listeners), $listeners);
        $eventService->addListener(SellerOrderFulfilled::class, function (SellerOrderFulfilled $e) use ($capture): void {
            $capture->sellerOrderFulfilled[] = $e;
        });
        $eventService->addListener(OrderFulfilled::class, function (OrderFulfilled $e) use ($capture): void {
            $capture->orderFulfilled[] = $e;
        });
        $this->bind(EventService::class, $eventService);

        return $capture;
    }

    /** @return array<string,mixed> the placed parent order */
    private function placeOneSellerOrder(array $product): array
    {
        ['cart' => $cart, 'token' => $token] = $this->cart()->create($this->context);
        $this->cart()->addLine($this->context, $cart, (string) $product['variants'][0]['uuid'], 1);

        $placed = $this->checkoutPartitioned()
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        return $placed['order'];
    }

    /** @return array<string,mixed> the placed parent order */
    private function placeTwoSellerOrder(array $productX, array $productY): array
    {
        $cartService = $this->cart();
        ['cart' => $cart, 'token' => $token] = $cartService->create($this->context);
        $cart = $cartService->addLine($this->context, $cart, (string) $productX['variants'][0]['uuid'], 1);
        $cartService->addLine($this->context, $cart, (string) $productY['variants'][0]['uuid'], 1);

        $placed = $this->checkoutPartitioned()
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        return $placed['order'];
    }

    /** @return array<string,mixed> the placed parent order */
    private function placeNonPartitionedOrder(): array
    {
        $catalog = new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            $this->tenantResolver(),
            new StockRepository()
        );
        $product = $catalog->createProduct($this->context, [
            'slug' => 'non-partitioned-cancel-x',
            'name' => 'non-partitioned-cancel-x',
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => 'NONPARTCANCELX',
                'option_values' => [],
                'price' => 1000,
                'currency' => 'USD',
            ]],
        ]);
        (new StockRepository())->increment($this->context, self::TENANT, (string) $product['variants'][0]['uuid'], 10);

        ['cart' => $cart, 'token' => $token] = $this->cart()->create($this->context);
        $this->cart()->addLine($this->context, $cart, (string) $product['variants'][0]['uuid'], 1);

        $placed = $this->checkoutPlain()
            ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        return $placed['order'];
    }

    private function checkoutPartitioned(): CheckoutService
    {
        return new CheckoutService(
            $this->cart(),
            new DiscountRepository(),
            new DiscountService(new DiscountRepository(), $this->tenantResolver()),
            new StockRepository(),
            new PricingEngine(),
            $this->fakeShipping(),
            $this->aggregateTax(),
            new OrderNumberGenerator(),
            new OrderRepository(),
            new DownloadRepository(),
            new ManualPaymentCollector(),
            $this->tenantResolver(),
            new MarketplaceMode(),
            new SellerRepository(),
            new ProductRepository(),
            new SellerOrderRepository()
        );
    }

    private function checkoutPlain(): CheckoutService
    {
        return new CheckoutService(
            $this->cart(),
            new DiscountRepository(),
            new DiscountService(new DiscountRepository(), $this->tenantResolver()),
            new StockRepository(),
            new PricingEngine(),
            $this->fakeShipping(),
            $this->aggregateTax(),
            new OrderNumberGenerator(),
            new OrderRepository(),
            new DownloadRepository(),
            new ManualPaymentCollector(),
            $this->tenantResolver()
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
            $this->tenantResolver()
        );
    }

    private function tenantResolver(): CurrentTenantResolver
    {
        return new SentinelTenantResolver();
    }

    private function activateMarketplace(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['marketplace' => ['enabled' => true]]);
        $this->connection->table('commerce_marketplace_settings')->insert([
            'uuid' => 'mktsettings1',
            'tenant_uuid' => self::TENANT,
            'status' => 'active',
        ]);
    }

    private function seedSeller(string $uuid, string $name, string $status = 'active'): void
    {
        $this->connection->table('commerce_sellers')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT,
            'slug' => strtolower($uuid),
            'name' => $name,
            'status' => $status,
        ]);
    }

    /** @return array<string,mixed> */
    private function seedProduct(string $slug, int $price, string $sellerUuid, int $stock = 100): array
    {
        $catalog = new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            $this->tenantResolver(),
            new StockRepository()
        );
        $product = $catalog->createProduct($this->context, [
            'slug' => $slug,
            'name' => $slug,
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => strtoupper(str_replace('-', '', $slug)),
                'option_values' => [],
                'price' => $price,
                'currency' => 'USD',
            ]],
        ]);
        (new StockRepository())->increment(
            $this->context,
            self::TENANT,
            (string) $product['variants'][0]['uuid'],
            $stock
        );

        $this->connection->table('commerce_products')
            ->where('uuid', '=', $product['uuid'])
            ->update(['seller_uuid' => $sellerUuid]);

        return $product;
    }

    private function fakeShipping(): ShippingRateProvider
    {
        return new class implements ShippingRateProvider {
            public function quote(ApplicationContext $context, array $lines, array $shippingAddress): array
            {
                return [new ShippingQuote('std', 'Standard', 500)];
            }
        };
    }

    private function aggregateTax(): TaxCalculator
    {
        return new class implements TaxCalculator {
            public function quote(ApplicationContext $context, int $taxableAmount, array $shippingAddress): TaxQuote
            {
                return new TaxQuote(0);
            }
        };
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

    /** @return array<string,mixed> */
    private function orderRow(string $uuid): array
    {
        $row = $this->connection->table('commerce_orders')->where('uuid', '=', $uuid)->first();
        self::assertNotNull($row);

        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function sellerOrdersFor(string $orderUuid): array
    {
        return $this->connection->table('commerce_seller_orders')
            ->where('order_uuid', '=', $orderUuid)
            ->orderBy('partition_number', 'ASC')
            ->get();
    }
}
