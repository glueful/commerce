<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

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
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Invoices\ConfigSellerIdentityProvider;
use Glueful\Extensions\Commerce\Inventory\InventoryService;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerPostingService;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceRefundGuard;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceWorkspaceLock;
use Glueful\Extensions\Commerce\Marketplace\PayoutAccountRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutAccountService;
use Glueful\Extensions\Commerce\Marketplace\PayoutRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutService;
use Glueful\Extensions\Commerce\Marketplace\SellerAttributionService;
use Glueful\Extensions\Commerce\Marketplace\SellerBalanceService;
use Glueful\Extensions\Commerce\Marketplace\SellerLifecycleEventRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderFulfillmentService;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderPaymentConfirmation;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerService;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookDeliveryRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookEndpointRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookEventRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookOutboxPublisher;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookPayloadProjector;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\ExpiryService;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundInput;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundService;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Commerce\Tests\Support\QueryLoggingPdoStatement;
use Glueful\Extensions\Contracts\Payments\DestinationStatus;
use Glueful\Extensions\Contracts\Payments\PayoutCollector;
use Glueful\Extensions\Contracts\Payments\PayoutDestination;
use Glueful\Extensions\Contracts\Payments\PayoutRequest;
use Glueful\Extensions\Contracts\Payments\PayoutResult;
use Glueful\Extensions\Contracts\Payments\PayoutStatusResult;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Queue\QueueManager;
use Symfony\Component\HttpFoundation\Request;

/**
 * Marketplace MV5c-2 Task 4 (design spec §2.3/§2.4/§2.9 -- SECURITY CORE):
 * the transactional per-seller webhook outbox and its ISOLATION boundary.
 *
 * Covers, per the task brief: every real insertion point (both cancellation
 * paths, both payout-paid paths); capture writes only for matching
 * endpoints; multi-seller poison isolation + transfer-out/in; direct stock
 * adjustment emits `stock.adjusted` while checkout/refund/cancel/expiry
 * stock movements never do; a suspended seller's capture starts `paused`;
 * both capture-vs-suspension orderings (DETERMINISTIC/sequential here --
 * this single-connection SQLite harness cannot produce a genuine concurrent
 * race; see this class's own note on {@see self::testCaptureFirstThenSuspensionOrderingLeavesTheAlreadyWrittenDeliveryPendingAndSubsequentCapturesPaused()}
 * for exactly what a live pgsql two-connection lane (Task 8) must still
 * verify); an injected outbox failure rolls back the enclosing business
 * transition; master-off is a zero-query no-op and an active marketplace
 * with no matching endpoint is a single bounded indexed probe with zero
 * writes; a lost `afterCommit()` enqueue hint leaves a recoverable
 * `pending` row.
 */
final class SellerWebhookOutboxTest extends CommerceTestCase
{
    private const TENANT = '';

    private int $endpointSeq = 0;

    // -----------------------------------------------------------------
    // Real insertion points -- one test per event type/call site, including
    // BOTH cancellation paths and BOTH payout-paid paths (brief §Step 1).
    // -----------------------------------------------------------------

    public function testCheckoutPlacementCapturesOrderPlacedPerParticipatingSeller(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $this->seedSeller('sellerBBBB01', 'Seller B');
        $epA = $this->seedEndpoint('sellerAAAA01', ['order.placed']);
        $epB = $this->seedEndpoint('sellerBBBB01', ['order.placed']);
        $productX = $this->seedProduct('op-x', 5000, 'sellerAAAA01');
        $productY = $this->seedProduct('op-y', 3000, 'sellerBBBB01');

        $token = $this->cartWithLines([[$productX, 1], [$productY, 1]]);
        $placed = $this->checkout()->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');
        $order = $placed['order'];

        $eventsA = $this->eventsFor('sellerAAAA01');
        self::assertCount(1, $eventsA);
        self::assertSame('order.placed', $eventsA[0]['event_type']);
        $payloadA = $this->payloadOf($eventsA[0]);
        self::assertSame((string) $order['uuid'], $payloadA['order_uuid']);
        self::assertSame('sellerAAAA01', $payloadA['seller_uuid']);
        self::assertCount(1, $payloadA['lines']);
        self::assertSame('OPX', $payloadA['lines'][0]['sku']);

        $deliveriesA = $this->deliveriesForEndpoint($epA);
        self::assertCount(1, $deliveriesA);
        self::assertSame('pending', $deliveriesA[0]['status']);
        self::assertSame($eventsA[0]['uuid'], $deliveriesA[0]['webhook_event_uuid']);

        $eventsB = $this->eventsFor('sellerBBBB01');
        self::assertCount(1, $eventsB);
        self::assertCount(1, $this->deliveriesForEndpoint($epB));
    }

    public function testMarkPaidCapturesOrderPaidPerParticipatingSeller(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $ep = $this->seedEndpoint('sellerAAAA01', ['order.paid']);
        $product = $this->seedProduct('pd-x', 4000, 'sellerAAAA01');
        $order = $this->placeOneSellerOrder($product);

        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);

        $events = $this->eventsFor('sellerAAAA01');
        self::assertCount(1, $events);
        self::assertSame('order.paid', $events[0]['event_type']);
        $payload = $this->payloadOf($events[0]);
        self::assertSame((string) $order['uuid'], $payload['order_uuid']);
        self::assertGreaterThan(0, $payload['attributed_total']);

        self::assertCount(1, $this->deliveriesForEndpoint($ep));
    }

    public function testAdminCancelCapturesOrderCanceledWithOperatorSource(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $ep = $this->seedEndpoint('sellerAAAA01', ['order.canceled']);
        $product = $this->seedProduct('cancel-op-x', 2000, 'sellerAAAA01');
        $order = $this->placeOneSellerOrder($product);

        $response = $this->adminOrderController()->cancel(
            Request::create('/commerce/admin/orders/' . $order['uuid'] . '/cancel', 'POST'),
            (string) $order['uuid']
        );
        self::assertSame(200, $response->getStatusCode());

        $events = $this->eventsFor('sellerAAAA01');
        self::assertCount(1, $events);
        self::assertSame('order.canceled', $events[0]['event_type']);
        $payload = $this->payloadOf($events[0]);
        self::assertSame('operator', $payload['cancellation_source']);
        self::assertCount(1, $this->deliveriesForEndpoint($ep));
    }

    public function testExpireStaleCapturesOrderCanceledWithExpiredSource(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $ep = $this->seedEndpoint('sellerAAAA01', ['order.canceled']);
        $product = $this->seedProduct('cancel-exp-x', 2000, 'sellerAAAA01');
        $order = $this->placeOneSellerOrder($product);

        $this->connection->table('commerce_orders')
            ->where('uuid', '=', $order['uuid'])
            ->update(['placed_at' => gmdate('Y-m-d H:i:s', time() - 7200)]);

        $expired = $this->expiryService()->expireStale($this->context);
        self::assertSame(1, $expired);

        $events = $this->eventsFor('sellerAAAA01');
        self::assertCount(1, $events);
        self::assertSame('order.canceled', $events[0]['event_type']);
        $payload = $this->payloadOf($events[0]);
        self::assertSame('expired', $payload['cancellation_source']);
        self::assertCount(1, $this->deliveriesForEndpoint($ep));
    }

    public function testSellerOrderFulfillmentCapturesSellerOrderFulfilled(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $ep = $this->seedEndpoint('sellerAAAA01', ['seller_order.fulfilled']);
        $product = $this->seedProduct('fulfill-x', 2500, 'sellerAAAA01');
        $order = $this->placeOneSellerOrder($product);
        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);
        $sellerOrder = $this->sellerOrdersFor((string) $order['uuid'])[0];

        $this->fulfillmentService()->fulfill(
            $this->context,
            self::TENANT,
            (string) $order['uuid'],
            (string) $sellerOrder['uuid'],
            ['carrier' => 'ups', 'tracking_number' => 'TRACK123', 'tracking_url' => null],
            null
        );

        $events = $this->eventsFor('sellerAAAA01');
        self::assertCount(1, $events);
        self::assertSame('seller_order.fulfilled', $events[0]['event_type']);
        $payload = $this->payloadOf($events[0]);
        self::assertSame('ups', $payload['carrier']);
        self::assertSame('TRACK123', $payload['tracking_number']);
        self::assertCount(1, $this->deliveriesForEndpoint($ep));
    }

    public function testRefundCompletionCapturesRefundCompletedAttributedToOwningSeller(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $ep = $this->seedEndpoint('sellerAAAA01', ['refund.completed']);
        $product = $this->seedProduct('refund-x', 3000, 'sellerAAAA01');
        $order = $this->placeOneSellerOrder($product);
        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order['uuid']);

        $this->refundService()->issue(
            $this->context,
            (string) $order['uuid'],
            new RefundInput(null, 'full refund', [], false),
            'idem-refund-cap-1'
        );

        $events = $this->eventsFor('sellerAAAA01');
        self::assertCount(1, $events);
        self::assertSame('refund.completed', $events[0]['event_type']);
        $payload = $this->payloadOf($events[0]);
        self::assertSame((string) $order['uuid'], $payload['order_uuid']);
        self::assertGreaterThan(0, $payload['amount']);
        self::assertNotEmpty($payload['lines']);
        self::assertCount(1, $this->deliveriesForEndpoint($ep));
    }

    public function testManualPayoutRecordCapturesPayoutRecorded(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $ep = $this->seedEndpoint('sellerAAAA01', ['payout.recorded']);
        $this->seedAvailable('sellerAAAA01', 5000);

        $this->payoutService()->record(
            $this->context,
            self::TENANT,
            'sellerAAAA01',
            'USD',
            2000,
            'idem-payout-manual-1',
            'ext-ref-1',
            null,
            'operatorPAY001'
        );

        $events = $this->eventsFor('sellerAAAA01');
        self::assertCount(1, $events);
        self::assertSame('payout.recorded', $events[0]['event_type']);
        $payload = $this->payloadOf($events[0]);
        self::assertSame('manual', $payload['method']);
        self::assertSame(2000, $payload['amount']);
        self::assertSame('ext-ref-1', $payload['external_ref']);
        self::assertCount(1, $this->deliveriesForEndpoint($ep));
    }

    public function testProviderPayoutFinalizeCapturesPayoutRecordedExactlyOnceOnTheWinningCas(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $ep = $this->seedEndpoint('sellerAAAA01', ['payout.recorded']);
        $this->seedReadyPayoutAccount('sellerAAAA01');
        $this->seedAvailable('sellerAAAA01', 5000);

        $collector = new TestPayoutCollector([new PayoutResult(PayoutResult::PAID, 'prov-ref-1')]);
        $payout = $this->payoutService($collector)->execute(
            $this->context,
            self::TENANT,
            'sellerAAAA01',
            'USD',
            2000,
            'operatorPAY002'
        );
        self::assertSame('paid', $payout['status']);

        $events = $this->eventsFor('sellerAAAA01');
        self::assertCount(1, $events, 'the RESERVE step must never itself capture -- only the winning PAID finalize');
        $payload = $this->payloadOf($events[0]);
        self::assertSame('provider', $payload['method']);
        self::assertNull($payload['external_ref']);
        self::assertCount(1, $this->deliveriesForEndpoint($ep));
    }

    public function testProductAdoptionCapturesProductAdopted(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $ep = $this->seedEndpoint('sellerAAAA01', ['product.adopted']);
        $product = $this->seedUnownedProduct('adopt-x', 1500);

        $this->attributionService()->assign($this->context, self::TENANT, $product['uuid'], 'sellerAAAA01');

        $events = $this->eventsFor('sellerAAAA01');
        self::assertCount(1, $events);
        self::assertSame('product.adopted', $events[0]['event_type']);
        $payload = $this->payloadOf($events[0]);
        self::assertSame($product['uuid'], $payload['product_uuid']);
        self::assertCount(1, $this->deliveriesForEndpoint($ep));
    }

    public function testProductTransferEmitsDistinctTransferOutAndTransferInSnapshots(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $this->seedSeller('sellerBBBB01', 'Seller B');
        $epA = $this->seedEndpoint('sellerAAAA01', ['product.adopted', 'product.transferred']);
        $epB = $this->seedEndpoint('sellerBBBB01', ['product.adopted', 'product.transferred']);
        $product = $this->seedUnownedProduct('transfer-x', 1500);

        $attribution = $this->attributionService();
        $attribution->assign($this->context, self::TENANT, $product['uuid'], 'sellerAAAA01');
        $attribution->assign($this->context, self::TENANT, $product['uuid'], 'sellerBBBB01');

        $transferOut = $this->latestEventOfType($this->eventsFor('sellerAAAA01'), 'product.transferred');
        $payloadOut = $this->payloadOf($transferOut);
        self::assertSame('out', $payloadOut['direction']);
        self::assertSame('sellerBBBB01', $payloadOut['counterparty_seller_uuid']);

        $transferIn = $this->latestEventOfType($this->eventsFor('sellerBBBB01'), 'product.transferred');
        $payloadIn = $this->payloadOf($transferIn);
        self::assertSame('in', $payloadIn['direction']);
        self::assertSame('sellerAAAA01', $payloadIn['counterparty_seller_uuid']);

        self::assertCount(2, $this->deliveriesForEndpoint($epA), 'seller A: adopted + transfer-out');
        self::assertCount(1, $this->deliveriesForEndpoint($epB), 'seller B: transfer-in only');
    }

    // -----------------------------------------------------------------
    // stock.adjusted: direct adjust() captures; every OTHER stock movement
    // (checkout decrement, cancel/refund/expiry restock) never does.
    // -----------------------------------------------------------------

    public function testDirectAdjustCapturesStockAdjustedButOtherStockMovementsNeverDo(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $this->seedEndpoint('sellerAAAA01', ['stock.adjusted']);
        $product = $this->seedProduct('stock-x', 2000, 'sellerAAAA01', 100);
        $variantUuid = (string) $product['variants'][0]['uuid'];

        $this->inventoryService()->adjust($this->context, $variantUuid, -5, 'shrinkage');

        $events = $this->eventsFor('sellerAAAA01');
        self::assertCount(1, $events, 'direct adjust() must capture exactly one stock.adjusted event');
        self::assertSame('stock.adjusted', $events[0]['event_type']);
        $payload = $this->payloadOf($events[0]);
        self::assertSame(-5, $payload['delta']);
        self::assertSame(95, $payload['quantity_after']);
        self::assertSame('shrinkage', $payload['reason']);

        // Checkout decrement -- StockRepository::decrement() directly, never InventoryService::adjust().
        $token = $this->cartWithLines([[$product, 1]]);
        $order = $this->checkout()->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std')['order'];
        self::assertCount(1, $this->eventsFor('sellerAAAA01'), 'checkout decrement must not emit stock.adjusted');

        // Operator cancel restock -- AdminOrderController::releaseStock(), never InventoryService::adjust().
        $this->adminOrderController()->cancel(
            Request::create('/commerce/admin/orders/' . $order['uuid'] . '/cancel', 'POST'),
            (string) $order['uuid']
        );
        self::assertCount(1, $this->eventsFor('sellerAAAA01'), 'cancel restock must not emit stock.adjusted');

        // Refund restock -- RefundService::restockLines(), never InventoryService::adjust().
        $order2 = $this->placeOneSellerOrder($product);
        $this->paymentService()->markPaid($this->context, self::TENANT, (string) $order2['uuid']);
        $orderLine = $this->connection->table('commerce_order_lines')
            ->where('order_uuid', '=', (string) $order2['uuid'])
            ->first();
        $this->refundService()->issue(
            $this->context,
            (string) $order2['uuid'],
            new RefundInput(null, 'restock refund', [[
                'order_line_uuid' => (string) $orderLine['uuid'],
                'quantity' => 1,
                'amount' => (int) $order2['grand_total'],
            ]], true),
            'idem-refund-stock-1'
        );
        self::assertSame(
            1,
            $this->countEventsOfType('sellerAAAA01', 'stock.adjusted'),
            'refund restock must not add a second stock.adjusted event -- still only the direct adjust() above'
        );

        // Expiry restock -- ExpiryService::expireStale(), never InventoryService::adjust().
        $order3 = $this->placeOneSellerOrder($product);
        $this->connection->table('commerce_orders')
            ->where('uuid', '=', $order3['uuid'])
            ->update(['placed_at' => gmdate('Y-m-d H:i:s', time() - 7200)]);
        $this->expiryService()->expireStale($this->context);

        self::assertSame(
            1,
            $this->countEventsOfType('sellerAAAA01', 'stock.adjusted'),
            'expiry restock must not add a second stock.adjusted event'
        );
    }

    // -----------------------------------------------------------------
    // Capture writes only for matching (active + subscribed) endpoints.
    // -----------------------------------------------------------------

    public function testCaptureWritesOnlyForEndpointsSubscribedToTheEventType(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $this->seedEndpoint('sellerAAAA01', ['order.paid']); // wrong event -- must never match
        $epRight = $this->seedEndpoint('sellerAAAA01', ['order.placed', 'stock.adjusted']);
        $this->seedEndpoint('sellerAAAA01', ['order.placed'], 'disabled'); // disabled -- must never match

        $this->captureDirectly('order.placed', 'sellerAAAA01', $this->minimalOrderPlacedSlice('orderMATCH01'));

        $events = $this->eventsFor('sellerAAAA01');
        self::assertCount(1, $events);
        $deliveries = $this->connection->table('commerce_seller_webhook_deliveries')
            ->where('webhook_event_uuid', '=', $events[0]['uuid'])
            ->get();
        self::assertCount(1, $deliveries);
        self::assertSame($epRight, $deliveries[0]['endpoint_uuid']);
    }

    // -----------------------------------------------------------------
    // Multi-seller poison isolation -- the flagship security test.
    // -----------------------------------------------------------------

    public function testMultiSellerOrderPlacedPayloadIsolatesEachSellersOwnDataOnly(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A Legit');
        $this->seedSeller('sellerBBBB01', 'POISON-SELLER-B-SECRET-9f3a');
        $this->seedEndpoint('sellerAAAA01', ['order.placed']);
        $this->seedEndpoint('sellerBBBB01', ['order.placed']);
        $productX = $this->seedProduct('poison-x', 5000, 'sellerAAAA01');
        $productY = $this->seedProduct('POISONPRODUCTYMARKER7b2c', 3000, 'sellerBBBB01');

        $token = $this->cartWithLines([[$productX, 1], [$productY, 1]]);
        $this->checkout()->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        $payloadA = $this->payloadOf($this->eventsFor('sellerAAAA01')[0]);
        $rawA = (string) json_encode($payloadA);
        self::assertStringNotContainsString('POISON-SELLER-B-SECRET-9f3a', $rawA);
        self::assertStringNotContainsString('POISONPRODUCTYMARKER7b2c', $rawA);
        self::assertStringNotContainsString('sellerBBBB01', $rawA);
        self::assertSame('sellerAAAA01', $payloadA['seller_uuid']);
        self::assertCount(1, $payloadA['lines']);
        self::assertSame('POISONX', $payloadA['lines'][0]['sku']);

        $payloadB = $this->payloadOf($this->eventsFor('sellerBBBB01')[0]);
        $rawB = (string) json_encode($payloadB);
        self::assertStringNotContainsString('sellerAAAA01', $rawB);
        self::assertStringNotContainsString('Seller A Legit', $rawB);
        self::assertStringContainsString('POISONPRODUCTYMARKER7b2c', $rawB, 'seller B legitimately keeps its OWN data');
    }

    // -----------------------------------------------------------------
    // Suspended capture starts paused; both capture-vs-suspension orderings.
    // -----------------------------------------------------------------

    public function testSuspendedSellerCaptureStartsPausedWithZeroRemainingDelay(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $ep = $this->seedEndpoint('sellerAAAA01', ['order.placed']);

        $this->sellerService()->suspend($this->context, self::TENANT, 'sellerAAAA01', 'policy violation', 'op1');

        $this->captureDirectly('order.placed', 'sellerAAAA01', $this->minimalOrderPlacedSlice('orderSUSP001'));

        $deliveries = $this->deliveriesForEndpoint($ep);
        self::assertCount(1, $deliveries);
        self::assertSame('paused', $deliveries[0]['status']);
        self::assertSame('seller_suspended', $deliveries[0]['pause_reason']);
        self::assertSame(0, (int) $deliveries[0]['paused_remaining_seconds']);
        self::assertNotNull($deliveries[0]['paused_at']);
    }

    /**
     * DETERMINISTIC/sequential ordering (brief: "pgsql two-connection race lanes are Task 8 --
     * deterministic ordering here is fine"). This proves what Task 4 itself owns: the shared
     * `SellerRepository::claimRevision()` primitive correctly serializes a capture() and a
     * concurrent suspend() against the SAME `commerce_sellers` row (the revision advances by
     * exactly one per claim, never lost/skipped), and that whichever commits FIRST is what the
     * OTHER observes. A capture() that committed before a LATER suspend() is never retroactively
     * rewritten -- that would require a suspend()-side pause-sweep over already-committed webhook
     * rows, which is NOT part of Task 4 (SellerService::suspend() has no knowledge of webhook
     * deliveries) and is out of scope here; the very NEXT capture (after suspension) correctly
     * observes 'suspended'.
     *
     * Task 8 (live pgsql, two real connections) must additionally verify the genuinely concurrent
     * interleaving this single-connection SQLite harness cannot produce: a capture() and a
     * suspend() issued from two separate connections racing to claim the SAME seller revision,
     * proving the row-level lock itself (not just sequential ordering) is what serializes them.
     */
    public function testCaptureFirstThenSuspensionOrderingLeavesTheAlreadyWrittenDeliveryPendingAndSubsequentCapturesPaused(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $ep = $this->seedEndpoint('sellerAAAA01', ['order.placed']);

        $this->captureDirectly('order.placed', 'sellerAAAA01', $this->minimalOrderPlacedSlice('orderRACE001'));
        $firstDelivery = $this->deliveriesForEndpoint($ep)[0];
        self::assertSame('pending', $firstDelivery['status']);

        $this->sellerService()->suspend($this->context, self::TENANT, 'sellerAAAA01', 'policy violation', 'op1');

        $stillPending = $this->connection->table('commerce_seller_webhook_deliveries')
            ->where('uuid', '=', $firstDelivery['uuid'])
            ->first();
        self::assertSame(
            'pending',
            $stillPending['status'],
            'capture-first must not be retroactively rewritten by a later suspend()'
        );

        $this->captureDirectly('order.placed', 'sellerAAAA01', $this->minimalOrderPlacedSlice('orderRACE002'));
        $rows = $this->deliveriesForEndpoint($ep);
        self::assertCount(2, $rows);
        self::assertSame('paused', $rows[1]['status']);

        // Three claims total: the first capture(), suspend(), and the second capture() above --
        // each an affected-row-checked +1 bump, never lost/skipped/double-applied.
        $seller = $this->connection->table('commerce_sellers')->where('uuid', '=', 'sellerAAAA01')->first();
        self::assertSame(3, (int) $seller['revision'], 'the seller revision must advance by exactly one per claim');
    }

    // -----------------------------------------------------------------
    // Injected outbox failure rolls back the WHOLE business transition.
    // -----------------------------------------------------------------

    public function testInjectedOutboxFailureRollsBackTheWholeBusinessTransition(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $this->seedEndpoint('sellerAAAA01', ['order.placed']);
        $product = $this->seedProduct('rollback-x', 2000, 'sellerAAAA01', 50);
        $variantUuid = (string) $product['variants'][0]['uuid'];

        $throwing = $this->webhooksPublisher(static function (): void {
            throw new \RuntimeException('forced outbox failure');
        });

        $token = $this->cartWithLines([[$product, 1]]);
        try {
            $this->checkout(webhooks: $throwing)
                ->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');
            self::fail('expected the forced outbox failure to propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('forced outbox failure', $e->getMessage());
        }

        self::assertSame(0, $this->connection->table('commerce_orders')->count(), 'the order insert must roll back');
        self::assertSame(0, $this->connection->table('commerce_seller_orders')->count());
        self::assertSame(50, $this->stockQuantity($variantUuid), 'the stock decrement must roll back too');
        self::assertSame(
            0,
            $this->connection->table('commerce_seller_webhook_events')->count(),
            'the event snapshot itself must never survive the rollback'
        );
        self::assertSame(0, $this->connection->table('commerce_seller_webhook_deliveries')->count());
    }

    // -----------------------------------------------------------------
    // Off-invariance: master-off zero query; active + no endpoint = one probe.
    // -----------------------------------------------------------------

    public function testMasterOffCaptureIsAZeroQueryNoOp(): void
    {
        // commerce.marketplace.enabled defaults to false -- no activateMarketplace() call.
        $publisher = $this->webhooksPublisher();

        $pdo = $this->connection->getPDO();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [QueryLoggingPdoStatement::class]);
        QueryLoggingPdoStatement::$queries = [];

        db($this->context)->transaction(function () use ($publisher): void {
            $publisher->capture($this->context, self::TENANT, 'order.placed', [
                'data' => ['sellerAAAA01' => $this->minimalOrderPlacedSlice('orderOFF001')],
            ]);
        });

        self::assertSame([], QueryLoggingPdoStatement::$queries);
    }

    public function testMasterOffDirectAdjustPaysNoWebhookPayloadReads(): void
    {
        // Off-invariance (design spec §6): with the marketplace master switch
        // off, InventoryService::adjust() must behave byte-identically to a
        // pre-MV1 install -- in particular it must NOT run the variant/product
        // SELECTs that captureStockAdjusted() issues purely to build the
        // stock.adjusted payload. Seed a real owned product+variant first (so
        // those reads WOULD find a seller if the guard were missing), THEN
        // adjust with the switch off.
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $product = $this->seedProduct('stock-off', 2000, 'sellerAAAA01', 100);
        $variantUuid = (string) $product['variants'][0]['uuid'];

        $pdo = $this->connection->getPDO();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [QueryLoggingPdoStatement::class]);
        QueryLoggingPdoStatement::$queries = [];

        // commerce.marketplace.enabled defaults to false -- no activateMarketplace() call.
        $quantityAfter = $this->inventoryService()->adjust($this->context, $variantUuid, -5, 'shrinkage');
        self::assertSame(95, $quantityAfter);

        $payloadReads = array_values(array_filter(
            QueryLoggingPdoStatement::$queries,
            static fn (string $sql): bool => str_starts_with($sql, 'SELECT')
                && (str_contains($sql, 'commerce_product_variants') || str_contains($sql, 'commerce_products'))
        ));
        self::assertSame([], $payloadReads, 'master-off adjust() must not run the webhook payload variant/product SELECTs');
        self::assertSame(0, $this->connection->table('commerce_seller_webhook_events')->count());
    }

    public function testActiveMarketplaceWithNoMatchingEndpointRunsExactlyOneEndpointProbeAndWritesNothing(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        // Deliberately no endpoints registered at all for this seller.
        $publisher = $this->webhooksPublisher();

        $pdo = $this->connection->getPDO();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [QueryLoggingPdoStatement::class]);
        QueryLoggingPdoStatement::$queries = [];

        db($this->context)->transaction(function () use ($publisher): void {
            $publisher->capture($this->context, self::TENANT, 'order.placed', [
                'data' => ['sellerAAAA01' => $this->minimalOrderPlacedSlice('orderOFF002')],
            ]);
        });

        // SELECT-only: excludes the driver's own one-time PRAGMA table_info() schema
        // introspection (the soft-delete auto-filter's column-detection cache, an internal
        // framework mechanism -- never "the probe" this assertion is about).
        $endpointSelects = array_values(array_filter(
            QueryLoggingPdoStatement::$queries,
            static fn (string $sql): bool => str_starts_with($sql, 'SELECT')
                && str_contains($sql, 'commerce_seller_webhook_endpoints')
        ));
        self::assertCount(1, $endpointSelects, 'exactly one bounded indexed subscription probe');
        self::assertSame(0, $this->connection->table('commerce_seller_webhook_events')->count());
        self::assertSame(0, $this->connection->table('commerce_seller_webhook_deliveries')->count());
    }

    // -----------------------------------------------------------------
    // A lost afterCommit() enqueue hint leaves a recoverable pending row.
    // -----------------------------------------------------------------

    public function testLostAfterCommitEnqueueHintLeavesARecoverablePendingRow(): void
    {
        $this->activateMarketplace();
        $this->seedSeller('sellerAAAA01', 'Seller A');
        $ep = $this->seedEndpoint('sellerAAAA01', ['order.placed']);
        $this->bind(QueueManager::class, new ThrowingQueueManager());

        $publisher = $this->webhooksPublisher();
        db($this->context)->transaction(function () use ($publisher): void {
            $publisher->capture($this->context, self::TENANT, 'order.placed', [
                'data' => ['sellerAAAA01' => $this->minimalOrderPlacedSlice('orderQ001')],
            ]);
        });

        $deliveries = $this->deliveriesForEndpoint($ep);
        self::assertCount(1, $deliveries);
        self::assertSame(
            'pending',
            $deliveries[0]['status'],
            'the durable pending row must survive a lost/failed enqueue hint -- it is the authority, not the hint'
        );
    }

    // -----------------------------------------------------------------
    // Helpers -- service/collaborator wiring.
    // -----------------------------------------------------------------

    private function webhooksPublisher(?callable $beforeWriteHook = null): SellerWebhookOutboxPublisher
    {
        return new SellerWebhookOutboxPublisher(
            new MarketplaceMode(),
            new SellerRepository(),
            new SellerWebhookEndpointRepository(),
            new SellerWebhookEventRepository(),
            new SellerWebhookDeliveryRepository(),
            new SellerWebhookPayloadProjector(),
            null,
            $beforeWriteHook
        );
    }

    /** @param array<string,mixed> $sellerSlice */
    private function captureDirectly(string $eventType, string $sellerUuid, array $sellerSlice): void
    {
        $publisher = $this->webhooksPublisher();
        db($this->context)->transaction(function () use ($publisher, $eventType, $sellerUuid, $sellerSlice): void {
            $publisher->capture($this->context, self::TENANT, $eventType, [
                'data' => [$sellerUuid => $sellerSlice],
            ]);
        });
    }

    /** @return array<string,mixed> */
    private function minimalOrderPlacedSlice(string $orderUuid): array
    {
        return [
            'order_uuid' => $orderUuid,
            'order_number' => 'ORD-' . $orderUuid,
            'currency' => 'USD',
            'occurred_at' => gmdate('Y-m-d H:i:s'),
            'seller_order_uuid' => 'sel' . $orderUuid,
            'seller_reference' => 'ORD-1',
            'subtotal' => 1000,
            'allocated_discount' => 0,
            'allocated_shipping' => 0,
            'allocated_tax' => 0,
            'attributed_total' => 1000,
            'commission_amount' => 0,
            'lines' => [],
        ];
    }

    private function checkout(
        ?callable $afterOwnershipSnapshotHook = null,
        ?SellerWebhookOutboxPublisher $webhooks = null
    ): CheckoutService {
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
            new SellerOrderRepository(),
            $afterOwnershipSnapshotHook,
            $webhooks ?? $this->webhooksPublisher()
        );
    }

    private function paymentService(): OrderPaymentService
    {
        return new OrderPaymentService(
            new OrderRepository(),
            new SellerOrderPaymentConfirmation(),
            null,
            new SellerOrderRepository(),
            $this->ledgerPostingService(),
            $this->webhooksPublisher()
        );
    }

    private function refundService(): RefundService
    {
        return new RefundService(
            new OrderRepository(),
            new RefundRepository(),
            new StockRepository(),
            $this->tenantResolver(),
            null,
            new MarketplaceRefundGuard(new RefundRepository()),
            $this->ledgerPostingService(),
            new SellerRepository(),
            $this->webhooksPublisher()
        );
    }

    private function payoutService(?PayoutCollector $collector = null): PayoutService
    {
        return new PayoutService(
            new PayoutRepository(),
            new LedgerRepository(),
            new LedgerAccountLock(),
            new SellerBalanceService(new LedgerRepository()),
            new SellerRepository(),
            null,
            $collector,
            $collector !== null ? new PayoutAccountService(new PayoutAccountRepository()) : null,
            $this->webhooksPublisher()
        );
    }

    private function fulfillmentService(): SellerOrderFulfillmentService
    {
        return new SellerOrderFulfillmentService(
            new OrderRepository(),
            new SellerOrderRepository(),
            new SellerRepository(),
            $this->webhooksPublisher()
        );
    }

    private function attributionService(): SellerAttributionService
    {
        return new SellerAttributionService(
            new MarketplaceWorkspaceLock(),
            new SellerRepository(),
            new ProductRepository(),
            null,
            $this->webhooksPublisher()
        );
    }

    private function inventoryService(): InventoryService
    {
        return new InventoryService(
            new StockRepository(),
            $this->tenantResolver(),
            new VariantRepository(),
            new ProductRepository(),
            new SellerRepository(),
            $this->webhooksPublisher()
        );
    }

    private function adminOrderController(): AdminOrderController
    {
        return new AdminOrderController(
            $this->context,
            new OrderRepository(),
            new StockRepository(),
            $this->paymentService(),
            $this->tenantResolver(),
            new RefundRepository(),
            new ConfigSellerIdentityProvider(),
            new SellerOrderRepository(),
            $this->fulfillmentService(),
            $this->webhooksPublisher()
        );
    }

    private function expiryService(): ExpiryService
    {
        return new ExpiryService(
            new OrderRepository(),
            new StockRepository(),
            $this->tenantResolver(),
            new SellerOrderRepository(),
            $this->webhooksPublisher()
        );
    }

    private function ledgerPostingService(): LedgerPostingService
    {
        return new LedgerPostingService(new LedgerRepository(), new LedgerAccountLock());
    }

    private function sellerService(): SellerService
    {
        return new SellerService(
            new SellerRepository(),
            new SellerMembershipRepository(),
            new SellerLifecycleEventRepository()
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

    // -----------------------------------------------------------------
    // Helpers -- seeding/reads.
    // -----------------------------------------------------------------

    private function activateMarketplace(): void
    {
        $this->context->mergeConfigDefaults('commerce', ['marketplace' => ['enabled' => true]]);
        $this->connection->table('commerce_marketplace_settings')->insert([
            'uuid' => 'mktsettwh001',
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

    private function seedEndpoint(string $sellerUuid, array $events, string $status = 'active'): string
    {
        $uuid = 'ep' . str_pad((string) (++$this->endpointSeq), 10, '0', STR_PAD_LEFT);
        $this->connection->table('commerce_seller_webhook_endpoints')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT,
            'seller_uuid' => $sellerUuid,
            'url' => 'https://example.test/hook',
            'subscribed_events' => json_encode($events, JSON_THROW_ON_ERROR),
            'status' => $status,
            'revision' => 0,
            'consecutive_failures' => 0,
            'created_by' => 'creatorWH0001',
        ]);

        return $uuid;
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
        (new StockRepository())->increment($this->context, self::TENANT, (string) $product['variants'][0]['uuid'], $stock);

        $this->connection->table('commerce_products')
            ->where('uuid', '=', $product['uuid'])
            ->update(['seller_uuid' => $sellerUuid]);

        return $product;
    }

    /** @return array<string,mixed> an active, LIVE product with no owning seller yet */
    private function seedUnownedProduct(string $slug, int $price): array
    {
        $catalog = new CatalogService(
            new ProductRepository(),
            new VariantRepository(),
            $this->tenantResolver(),
            new StockRepository()
        );

        return $catalog->createProduct($this->context, [
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
    }

    private function seedAvailable(string $seller, int $amount, string $currency = 'USD'): void
    {
        (new LedgerRepository())->post($this->context, self::TENANT, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller($seller),
            'seller_uuid' => $seller,
            'currency' => $currency,
            'entry_type' => 'sale_credit',
            'amount' => $amount,
            'order_uuid' => 'orderWHSEED01',
            'idempotency_key' => 'orderWHSEED01:' . $seller . ':sale_credit',
        ]);
    }

    private function seedReadyPayoutAccount(string $sellerUuid, string $provider = 'default'): void
    {
        $this->connection->table('commerce_seller_payout_accounts')->insert([
            'uuid' => 'acctWH000001',
            'tenant_uuid' => self::TENANT,
            'seller_uuid' => $sellerUuid,
            'provider' => $provider,
            'account_ref' => 'acct-ref-wh-1',
            'readiness_state' => 'ready',
            'last_synced_at' => null,
            'failure_code' => null,
        ]);
    }

    /** @return array<string,mixed> the placed parent order */
    private function placeOneSellerOrder(array $product): array
    {
        $token = $this->cartWithLines([[$product, 1]]);
        $placed = $this->checkout()->placeOrder($this->context, $token, $this->buyer(), $this->addresses(), 'std');

        return $placed['order'];
    }

    /**
     * @param list<array{0:array<string,mixed>,1:int}> $productsAndQuantities
     * @return string cart token
     */
    private function cartWithLines(array $productsAndQuantities): string
    {
        $cartService = $this->cart();
        ['cart' => $cart, 'token' => $token] = $cartService->create($this->context);
        foreach ($productsAndQuantities as [$product, $quantity]) {
            $cartService->addLine($this->context, $cart, (string) $product['variants'][0]['uuid'], $quantity);
        }

        return $token;
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

    /** @return list<array<string,mixed>> */
    private function sellerOrdersFor(string $orderUuid): array
    {
        return $this->connection->table('commerce_seller_orders')
            ->where('order_uuid', '=', $orderUuid)
            ->orderBy('partition_number', 'ASC')
            ->get();
    }

    private function stockQuantity(string $variantUuid): int
    {
        $row = $this->connection->table('commerce_stock')->where('variant_uuid', '=', $variantUuid)->first();

        return (int) ($row['quantity'] ?? 0);
    }

    /** @return list<array<string,mixed>> */
    private function eventsFor(string $sellerUuid): array
    {
        return $this->connection->table('commerce_seller_webhook_events')
            ->where('tenant_uuid', '=', self::TENANT)
            ->where('seller_uuid', '=', $sellerUuid)
            ->orderBy('id', 'ASC')
            ->get();
    }

    private function countEventsOfType(string $sellerUuid, string $eventType): int
    {
        return count(array_filter(
            $this->eventsFor($sellerUuid),
            static fn (array $e): bool => (string) $e['event_type'] === $eventType
        ));
    }

    /** @return list<array<string,mixed>> */
    private function deliveriesForEndpoint(string $endpointUuid): array
    {
        return $this->connection->table('commerce_seller_webhook_deliveries')
            ->where('tenant_uuid', '=', self::TENANT)
            ->where('endpoint_uuid', '=', $endpointUuid)
            ->orderBy('id', 'ASC')
            ->get();
    }

    /** @param array<string,mixed> $eventRow @return array<string,mixed> */
    private function payloadOf(array $eventRow): array
    {
        $decoded = json_decode((string) $eventRow['payload'], true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param list<array<string,mixed>> $events
     * @return array<string,mixed>
     */
    private function latestEventOfType(array $events, string $eventType): array
    {
        $matching = array_values(array_filter(
            $events,
            static fn (array $e): bool => (string) $e['event_type'] === $eventType
        ));
        self::assertNotEmpty($matching, "No captured event of type '{$eventType}'.");

        return $matching[count($matching) - 1];
    }
}

/** Minimal collector: only transfer() (PAID) is exercised by this suite's provider-payout test. */
final class TestPayoutCollector implements PayoutCollector
{
    /** @param list<PayoutResult> $queue */
    public function __construct(private array $queue)
    {
    }

    public function transfer(ApplicationContext $context, PayoutDestination $destination, PayoutRequest $request): PayoutResult
    {
        if ($this->queue === []) {
            throw new \RuntimeException('TestPayoutCollector queue exhausted.');
        }

        return array_shift($this->queue);
    }

    public function status(ApplicationContext $context, PayoutDestination $destination, string $idempotencyKey): PayoutStatusResult
    {
        throw new \LogicException('TestPayoutCollector::status() is not exercised by this suite.');
    }

    public function inspectDestination(ApplicationContext $context, PayoutDestination $destination): DestinationStatus
    {
        throw new \LogicException('TestPayoutCollector::inspectDestination() is not exercised by this suite.');
    }
}

/** A QueueManager whose push() always fails -- proves a lost enqueue hint never threatens the durable row. */
final class ThrowingQueueManager extends QueueManager
{
    public function __construct()
    {
        // Deliberately never calls parent::__construct() -- this fake only ever needs push()
        // to throw; every other QueueManager internal (driver registry, plugin manager, config)
        // stays uninitialized and is never touched.
    }

    public function push(string $job, array $data = [], ?string $queue = null, ?string $connection = null): string
    {
        throw new \RuntimeException('queue unavailable');
    }
}
