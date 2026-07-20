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
use Glueful\Extensions\Commerce\Events\OrderPaid;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Invoices\ConfigSellerIdentityProvider;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderPaymentConfirmation;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Payments\OrderPaymentConfirmationHandler;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Commerce\Tests\Support\QueryLoggingPdoStatement;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\PaymentConfirmation;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Symfony\Component\HttpFoundation\Request;

/**
 * Payment confirmation & seller PII gate (design spec §2.12, MV2 plan Task 6):
 * `OrderPaymentService::markPaid()`'s single transaction covering the parent
 * `pending_payment -> paid` CAS and the `confirmed_at` stamping of every
 * child, the after-commit-only `OrderPaid` dispatch, the zero-seller-query
 * non-partitioned fast path, both real callers, and the confirmed-scoped
 * seller read seam.
 */
final class PaymentConfirmationTest extends CommerceTestCase
{
    private const TENANT = '';

    // -----------------------------------------------------------------
    // 1. Atomic stamp: every child gets confirmed_at with the parent CAS.
    // -----------------------------------------------------------------

    public function testPartitionedPaidTransitionStampsEveryChildConfirmedAtAtomicallyWithTheParentCas(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $this->seedSeller('sellerBBBB01', 'Seller B');
        $productX = $this->seedProduct('confirm-x', 1000, 'sellerAAAA01');
        $productY = $this->seedProduct('confirm-y', 2000, 'sellerBBBB01');

        $order = $this->placeTwoSellerOrder($productX, $productY);
        self::assertTrue((bool) $order['marketplace_partitioned']);

        $sellerOrdersBefore = $this->sellerOrdersFor((string) $order['uuid']);
        self::assertCount(2, $sellerOrdersBefore);
        foreach ($sellerOrdersBefore as $row) {
            self::assertNull($row['confirmed_at']);
        }

        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);

        self::assertSame('paid', $this->orderRow((string) $order['uuid'])['status']);

        $sellerOrdersAfter = $this->sellerOrdersFor((string) $order['uuid']);
        self::assertCount(2, $sellerOrdersAfter);
        foreach ($sellerOrdersAfter as $row) {
            self::assertNotNull($row['confirmed_at'], 'every child must be stamped');
        }
    }

    // -----------------------------------------------------------------
    // 2. A failure after the CAS rolls back both the status and the stamps.
    // -----------------------------------------------------------------

    public function testFailureAfterThePaidCasRollsBackBothTheStatusAndAnyConfirmedAtStamp(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $product = $this->seedProduct('rollback-x', 1000, 'sellerAAAA01');
        $order = $this->placeOneSellerOrder($product);
        self::assertTrue((bool) $order['marketplace_partitioned']);

        $service = $this->paymentService(afterPaidHook: function (): void {
            throw new \RuntimeException('forced failure after the paid CAS');
        });

        try {
            $service->markPaid($this->context, self::TENANT, (string) $order['uuid']);
            self::fail('Expected the forced failure to propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('forced failure after the paid CAS', $e->getMessage());
        }

        self::assertSame('pending_payment', $this->orderRow((string) $order['uuid'])['status']);
        $sellerOrders = $this->sellerOrdersFor((string) $order['uuid']);
        self::assertCount(1, $sellerOrders);
        self::assertNull($sellerOrders[0]['confirmed_at'], 'the stamp must roll back with the CAS');
    }

    // -----------------------------------------------------------------
    // 3. OrderPaid waits for the OUTERMOST commit and observes committed
    //    stamps -- never fires at an inner savepoint release.
    // -----------------------------------------------------------------

    public function testOrderPaidDispatchesOnlyAfterTheOutermostCommitAndObservesCommittedStamps(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $product = $this->seedProduct('outer-commit-x', 1000, 'sellerAAAA01');
        $order = $this->placeOneSellerOrder($product);
        $orderUuid = (string) $order['uuid'];

        $observedConfirmedAtDuringDispatch = null;
        $captured = $this->bindEventCapture(function () use (&$observedConfirmedAtDuringDispatch, $orderUuid): void {
            $rows = $this->sellerOrdersFor($orderUuid);
            $observedConfirmedAtDuringDispatch = $rows[0]['confirmed_at'] ?? null;
        });

        $service = $this->paymentService();

        db($this->context)->transaction(function () use ($service, $orderUuid, $captured): void {
            $service->markPaid($this->context, self::TENANT, $orderUuid);
            self::assertCount(0, $captured->events, 'OrderPaid must not dispatch at the inner savepoint release');
        });

        self::assertCount(1, $captured->events);
        self::assertInstanceOf(OrderPaid::class, $captured->events[0]);
        self::assertSame($orderUuid, (string) $captured->events[0]->order['uuid']);
        self::assertNotNull(
            $observedConfirmedAtDuringDispatch,
            'the listener must observe the already-committed confirmed_at stamp'
        );
    }

    // -----------------------------------------------------------------
    // 4. An outer rollback after markPaid() emits nothing and persists
    //    nothing.
    // -----------------------------------------------------------------

    public function testOuterTransactionRollbackAfterMarkPaidEmitsNoEventAndPersistsNothing(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $product = $this->seedProduct('outer-rollback-x', 1000, 'sellerAAAA01');
        $order = $this->placeOneSellerOrder($product);
        $orderUuid = (string) $order['uuid'];

        $captured = $this->bindEventCapture();
        $service = $this->paymentService();

        try {
            db($this->context)->transaction(function () use ($service, $orderUuid): void {
                $service->markPaid($this->context, self::TENANT, $orderUuid);
                throw new \RuntimeException('force outer rollback');
            });
            self::fail('Expected the outer transaction to roll back.');
        } catch (\RuntimeException $e) {
            self::assertSame('force outer rollback', $e->getMessage());
        }

        self::assertSame('pending_payment', $this->orderRow($orderUuid)['status']);
        $sellerOrders = $this->sellerOrdersFor($orderUuid);
        self::assertNull($sellerOrders[0]['confirmed_at']);
        self::assertCount(0, $captured->events, 'a rolled-back outer transaction must dispatch nothing');
    }

    // -----------------------------------------------------------------
    // 5. Non-partitioned: zero seller-table queries, stamps nothing.
    // -----------------------------------------------------------------

    public function testNonPartitionedPaidTransitionExecutesZeroSellerTableQueriesAndStampsNothing(): void
    {
        $order = $this->placeNonPartitionedOrder();
        self::assertFalse((bool) $order['marketplace_partitioned']);

        $pdo = $this->connection->getPDO();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [QueryLoggingPdoStatement::class]);
        QueryLoggingPdoStatement::$queries = [];

        // The service has a confirmation collaborator wired -- proving the
        // gate is the order's OWN marketplace_partitioned flag, checked
        // before any seller table is touched, not merely a missing
        // collaborator.
        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);

        self::assertNotEmpty(QueryLoggingPdoStatement::$queries, 'sanity: markPaid() must run some queries');
        $this->assertNoSellerTableQueries(QueryLoggingPdoStatement::$queries);

        self::assertSame('paid', $this->orderRow((string) $order['uuid'])['status']);
        self::assertSame(0, $this->connection->table('commerce_seller_orders')->count());
    }

    // -----------------------------------------------------------------
    // 6. Both real callers route through the one transactional path.
    // -----------------------------------------------------------------

    public function testAdminMarkPaidControllerRoutesThroughTheOnePathAndStampsConfirmedAt(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $product = $this->seedProduct('admin-markpaid-x', 1000, 'sellerAAAA01');
        $order = $this->placeOneSellerOrder($product);
        $orderUuid = (string) $order['uuid'];

        $controller = new AdminOrderController(
            $this->context,
            new OrderRepository(),
            new StockRepository(),
            $this->paymentService(),
            new SentinelTenantResolver(),
            new RefundRepository(),
            new ConfigSellerIdentityProvider()
        );

        $response = $controller->markPaid(
            Request::create('/commerce/admin/orders/' . $orderUuid . '/mark-paid', 'POST'),
            $orderUuid
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('paid', $this->orderRow($orderUuid)['status']);
        $sellerOrders = $this->sellerOrdersFor($orderUuid);
        self::assertNotNull($sellerOrders[0]['confirmed_at']);
    }

    public function testProviderConfirmationHandlerRoutesThroughTheOnePathAndStampsConfirmedAt(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $product = $this->seedProduct('provider-confirm-x', 1000, 'sellerAAAA01');
        $order = $this->placeOneSellerOrder($product);
        $orderUuid = (string) $order['uuid'];

        $handler = new OrderPaymentConfirmationHandler(
            new OrderRepository(),
            $this->paymentService(),
            new SentinelTenantResolver()
        );

        $handler->confirmed(
            $this->context,
            new PayableReference('commerce_order', $orderUuid, (int) $order['grand_total'], $order['currency']),
            new PaymentConfirmation('paid', 'ref-confirm-1', (int) $order['grand_total'], $order['currency'])
        );

        self::assertSame('paid', $this->orderRow($orderUuid)['status']);
        $sellerOrders = $this->sellerOrdersFor($orderUuid);
        self::assertNotNull($sellerOrders[0]['confirmed_at']);
    }

    // -----------------------------------------------------------------
    // 7. confirmed_at is immutable: a re-entry never overwrites it.
    // -----------------------------------------------------------------

    public function testConfirmedAtIsImmutableOnReEntry(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $product = $this->seedProduct('immutable-x', 1000, 'sellerAAAA01');
        $order = $this->placeOneSellerOrder($product);
        $orderUuid = (string) $order['uuid'];

        $this->paymentService()->markPaid($this->context, self::TENANT, $orderUuid);
        $firstStamp = $this->sellerOrdersFor($orderUuid)[0]['confirmed_at'];
        self::assertNotNull($firstStamp);

        // Direct re-entry into the stamping operation itself (markPaid()
        // cannot be re-invoked -- the order is no longer pending_payment).
        (new SellerOrderPaymentConfirmation())->confirm($this->context, self::TENANT, $orderUuid);

        $secondStamp = $this->sellerOrdersFor($orderUuid)[0]['confirmed_at'];
        self::assertSame($firstStamp, $secondStamp, 'a re-entry must never overwrite an existing stamp');
    }

    public function testConfirmedAtSurvivesAPaidThenCanceledOrder(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $product = $this->seedProduct('cancel-after-paid-x', 1000, 'sellerAAAA01');
        $order = $this->placeOneSellerOrder($product);
        $orderUuid = (string) $order['uuid'];

        $this->paymentService()->markPaid($this->context, self::TENANT, $orderUuid);
        $stampBeforeCancel = $this->sellerOrdersFor($orderUuid)[0]['confirmed_at'];
        self::assertNotNull($stampBeforeCancel);

        $controller = new AdminOrderController(
            $this->context,
            new OrderRepository(),
            new StockRepository(),
            $this->paymentService(),
            new SentinelTenantResolver(),
            new RefundRepository(),
            new ConfigSellerIdentityProvider()
        );
        $response = $controller->cancel(
            Request::create('/commerce/admin/orders/' . $orderUuid . '/cancel', 'POST'),
            $orderUuid
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('canceled', $this->orderRow($orderUuid)['status']);
        $stampAfterCancel = $this->sellerOrdersFor($orderUuid)[0]['confirmed_at'];
        self::assertSame($stampBeforeCancel, $stampAfterCancel, 'canceling a paid order must not disturb its stamp');
    }

    // -----------------------------------------------------------------
    // 8. Confirmed-scoped read seam (Task 8): only confirmed children.
    // -----------------------------------------------------------------

    public function testConfirmedScopedReadReturnsOnlyConfirmedChildren(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $productPaid = $this->seedProduct('scoped-paid-x', 1000, 'sellerAAAA01');
        $productPending = $this->seedProduct('scoped-pending-x', 1000, 'sellerAAAA01');

        $paidOrder = $this->placeOneSellerOrder($productPaid);
        $pendingOrder = $this->placeOneSellerOrder($productPending);

        $repository = new SellerOrderRepository();

        // Before payment: neither order's child is confirmed-visible.
        self::assertSame(
            [],
            $repository->confirmedForSeller($this->context, self::TENANT, 'sellerAAAA01')
        );

        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $paidOrder['uuid']);

        $confirmed = $repository->confirmedForSeller($this->context, self::TENANT, 'sellerAAAA01');
        self::assertCount(1, $confirmed, 'the still-pending order must stay excluded');
        self::assertSame((string) $paidOrder['uuid'], $confirmed[0]['order_uuid']);

        $confirmedSellerOrderUuid = (string) $confirmed[0]['uuid'];
        $pendingSellerOrderUuid = (string) $this->sellerOrdersFor((string) $pendingOrder['uuid'])[0]['uuid'];

        self::assertNotNull($repository->confirmedForSellerByUuid(
            $this->context,
            self::TENANT,
            'sellerAAAA01',
            $confirmedSellerOrderUuid
        ));
        self::assertNull($repository->confirmedForSellerByUuid(
            $this->context,
            self::TENANT,
            'sellerAAAA01',
            $pendingSellerOrderUuid
        ), 'an unconfirmed partition must be invisible through the scoped detail read');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function paymentService(
        ?SellerOrderPaymentConfirmation $confirmation = new SellerOrderPaymentConfirmation(),
        ?callable $afterPaidHook = null,
    ): OrderPaymentService {
        return new OrderPaymentService(new OrderRepository(), $confirmation, $afterPaidHook);
    }

    /** @param (callable():void)|null $onOrderPaid extra side effect run from inside the listener */
    private function bindEventCapture(?callable $onOrderPaid = null): object
    {
        $capture = new class {
            /** @var list<object> */
            public array $events = [];
        };
        $listeners = new ListenerProvider();
        $eventService = new EventService(new EventDispatcher($listeners), $listeners);
        $eventService->addListener(OrderPaid::class, function (OrderPaid $e) use ($capture, $onOrderPaid): void {
            $capture->events[] = $e;
            if ($onOrderPaid !== null) {
                $onOrderPaid();
            }
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
            'slug' => 'non-partitioned-x',
            'name' => 'non-partitioned-x',
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => 'NONPARTX',
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

    /** @param list<string> $queries */
    private function assertNoSellerTableQueries(array $queries): void
    {
        $sellerTables = [
            'commerce_sellers',
            'commerce_seller_memberships',
            'commerce_marketplace_settings',
            'commerce_seller_orders',
        ];
        foreach ($queries as $sql) {
            foreach ($sellerTables as $table) {
                self::assertStringNotContainsString(
                    $table,
                    $sql,
                    "the non-partitioned markPaid() path must issue ZERO {$table} queries; saw: {$sql}"
                );
            }
        }
    }
}
