<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventDispatcher;
use Glueful\Events\EventService;
use Glueful\Events\ListenerProvider;
use Glueful\Extensions\Commerce\Events\Listeners\ProviderChargebackListener;
use Glueful\Extensions\Commerce\Http\Admin\AdminReserveController;
use Glueful\Extensions\Commerce\Http\Seller\SellerFinancialController;
use Glueful\Extensions\Commerce\Marketplace\AdjustmentService;
use Glueful\Extensions\Commerce\Marketplace\ChargebackIntegrityException;
use Glueful\Extensions\Commerce\Marketplace\ChargebackRepository;
use Glueful\Extensions\Commerce\Marketplace\ChargebackService;
use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceWorkspaceLock;
use Glueful\Extensions\Commerce\Marketplace\PayoutAccountRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutRepository;
use Glueful\Extensions\Commerce\Marketplace\ReserveConsumptionService;
use Glueful\Extensions\Commerce\Marketplace\ReservePolicyEventRepository;
use Glueful\Extensions\Commerce\Marketplace\ReservePolicyService;
use Glueful\Extensions\Commerce\Marketplace\ReserveRepository;
use Glueful\Extensions\Commerce\Marketplace\ReserveService;
use Glueful\Extensions\Commerce\Marketplace\SellerBalanceService;
use Glueful\Extensions\Commerce\Marketplace\SellerOrderRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Reports\SellerFinancialReportRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceRouterTestCase;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\ProviderChargebackEvent;
use Glueful\Helpers\Utils;
use Glueful\Http\Response;
use Glueful\Routing\Middleware\RequireScopeMiddleware;
use Glueful\Routing\RouteMiddleware;
use Glueful\Routing\Router;
use Symfony\Component\HttpFoundation\Request;

/**
 * The chargeback event LISTENER + the operator/seller HTTP surfaces for
 * reserves/chargebacks/debt (design spec §2.1/§2.4/§2.5/§2.8/§6, MV5a Task 16):
 * {@see ProviderChargebackListener} mapping a dispatched contract event to
 * {@see ChargebackService::ingest()} (and propagating, never swallowing, a genuine
 * ingest failure); {@see AdminReserveController}'s FULL operator surface (reserve
 * policy, chargeback ingestion + partial attribution, manual hold/release, debt
 * forgiveness, a seller's reserves+debt read) exercised over REAL routes through a
 * REAL {@see Router} + middleware pipeline, mirroring {@see PayoutSurfaceTest}'s
 * admin-side harness; and {@see SellerFinancialController::reserves()}'s SANITIZED
 * seller-facing allow-list.
 */
final class ReserveChargebackSurfaceTest extends CommerceRouterTestCase
{
    private LedgerRepository $ledger;
    private LedgerAccountLock $lock;
    private ReserveRepository $reserveRepo;
    private ReservePolicyService $reservePolicy;
    private ReserveService $reserveService;
    private ChargebackRepository $chargebackRepo;
    private ChargebackService $chargebackService;
    private AdjustmentService $adjustments;
    private SellerBalanceService $balances;
    private SellerRepository $sellers;
    private OrderRepository $orders;
    private MarketplaceMode $marketplaceMode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = new LedgerRepository();
        $this->lock = new LedgerAccountLock();
        $this->reserveRepo = new ReserveRepository();
        $this->sellers = new SellerRepository();
        $this->reservePolicy = new ReservePolicyService(
            $this->sellers,
            new MarketplaceWorkspaceLock(),
            new ReservePolicyEventRepository()
        );
        $this->reserveService = new ReserveService(
            $this->reservePolicy,
            $this->reserveRepo,
            $this->ledger,
            $this->lock
        );
        $this->orders = new OrderRepository();
        $this->chargebackRepo = new ChargebackRepository();
        $this->chargebackService = new ChargebackService(
            $this->orders,
            $this->chargebackRepo,
            new SellerOrderRepository(),
            $this->ledger,
            $this->lock,
            new ReserveConsumptionService($this->reserveRepo, $this->ledger)
        );
        $this->adjustments = new AdjustmentService($this->ledger, $this->lock);
        $this->balances = new SellerBalanceService($this->ledger);
        $this->marketplaceMode = new MarketplaceMode();

        $this->enableMarketplace();
        $this->activateWorkspace();
    }

    // ===================================================================
    // 1. Listener: ingests a dispatched event end-to-end; propagates
    //    (never swallows) a genuine ingest failure for payvia redelivery.
    // ===================================================================

    public function testListenerIngestsADispatchedProviderChargebackEventEndToEnd(): void
    {
        $this->seedOrder(['uuid' => 'orderLST00001', 'grand_total' => 5000]);

        $event = $this->chargebackEvent([
            'providerEventId' => 'evt_listener_1',
            'amount' => 5000,
            'payable' => ['id' => 'orderLST00001', 'amount' => 5000],
        ]);

        $this->eventService()->dispatchOrFail($event);

        $row = $this->chargebackRepo->findByProviderEvent($this->context, $this->tenant, 'stripe', 'evt_listener_1');
        self::assertNotNull($row, 'the listener must have ingested the dispatched event.');
        self::assertSame('posted', $row['status'], 'no seller orders seeded => fully marketplace-funded.');
    }

    /**
     * A `kind=chargeback` awaiting a business classification (`awaiting_attribution`/
     * `integrity_hold`) is a NORMAL RETURN from `ingest()`, never a throw -- proven
     * implicitly by the test above reaching `posted` without any exception. This test
     * proves the OTHER half: a genuine runtime failure inside `ingest()` (a conflicting
     * replay under the SAME `(tenant, provider, provider_event_id)`, throwing
     * {@see ChargebackIntegrityException}) is NEVER swallowed by the listener -- it
     * propagates all the way out of `EventService::dispatchOrFail()`, exactly the
     * "leave payvia's durable event retryable" contract design spec §5 requires.
     */
    public function testListenerDoesNotSwallowAGenuineIngestFailureItPropagatesForRedelivery(): void
    {
        $this->seedOrder(['uuid' => 'orderLST00002', 'grand_total' => 5000]);

        $first = $this->chargebackEvent([
            'providerEventId' => 'evt_listener_conflict',
            'amount' => 2000,
            'payable' => ['id' => 'orderLST00002', 'amount' => 5000],
        ]);
        $this->eventService()->dispatchOrFail($first);

        // Same provider_event_id, DIFFERENT amount -- ChargebackRepository::insert()'s
        // own conflict verify throws ChargebackIntegrityException from deep inside
        // ChargebackService::ingest().
        $conflicting = $this->chargebackEvent([
            'providerEventId' => 'evt_listener_conflict',
            'amount' => 3000,
            'payable' => ['id' => 'orderLST00002', 'amount' => 5000],
        ]);

        try {
            $this->eventService()->dispatchOrFail($conflicting);
            self::fail('Expected the listener failure to propagate out of dispatchOrFail().');
        } catch (ChargebackIntegrityException) {
            $this->addToAssertionCount(1);
        }

        self::assertCount(
            1,
            $this->connection->table('commerce_chargebacks')->get(),
            'a swallowed/misrouted failure must never silently lose or duplicate the row.'
        );
    }

    private function eventService(): EventService
    {
        $listeners = new ListenerProvider();
        $eventService = new EventService(new EventDispatcher($listeners), $listeners);
        $listener = new ProviderChargebackListener($this->context, $this->chargebackService);
        $eventService->addListener(ProviderChargebackEvent::class, [$listener, 'onProviderChargeback']);

        return $eventService;
    }

    // ===================================================================
    // 2. Operator: reserve policy (design spec §2.1).
    // ===================================================================

    public function testOperatorSetsWorkspaceReservePolicyOverRealRoute(): void
    {
        $router = $this->freshRouter();

        $response = $this->dispatch($router, $this->operatorRequest(
            'PATCH',
            '/commerce/admin/marketplace/settings/reserves',
            ['reserve_bps' => 250, 'reserve_days' => 7]
        ));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response)['data'];
        self::assertSame(250, (int) $body['reserve_bps']);
        self::assertSame(7, (int) $body['reserve_days']);

        $settings = $this->marketplaceMode->settingsRowFor($this->context, $this->tenant);
        self::assertSame(250, (int) $settings['reserve_bps']);
        self::assertSame(7, (int) $settings['reserve_days']);
    }

    public function testOperatorSetsPerSellerReservePolicyOverrideOverRealRoute(): void
    {
        $seller = $this->seedSeller('surf-rsv-policy', 'ownerRSVPOL01');
        $router = $this->freshRouter();

        $response = $this->dispatch($router, $this->operatorRequest(
            'PATCH',
            "/commerce/admin/marketplace/sellers/{$seller['uuid']}/reserve-policy",
            ['reserve_bps' => 500, 'reserve_days' => 14]
        ));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response)['data'];
        self::assertSame(500, (int) $body['reserve_bps']);
        self::assertSame(14, (int) $body['reserve_days']);

        $resolved = $this->reservePolicy->resolve($this->context, $this->tenant, $seller['uuid']);
        self::assertSame(500, $resolved['reserve_bps']);
        self::assertSame(14, $resolved['reserve_days']);
    }

    // ===================================================================
    // 3. Operator: chargeback ingestion + partial attribution (design
    //    spec §2.4/§2.5) -- the SAME ChargebackService::ingest() the
    //    listener uses.
    // ===================================================================

    public function testOperatorIngestsANormalizedChargebackOverRealRoute(): void
    {
        $this->seedOrder(['uuid' => 'orderOPIN0001', 'grand_total' => 5000]);
        $router = $this->freshRouter();

        $response = $this->dispatch($router, $this->operatorRequest(
            'POST',
            '/commerce/admin/marketplace/chargebacks',
            [
                'provider' => 'stripe',
                'provider_event_id' => 'evt_op_ingest_1',
                'payment_reference' => 'pay_ref_op_1',
                'payable_type' => 'commerce_order',
                'payable_id' => 'orderOPIN0001',
                'payable_amount' => 5000,
                'amount' => 5000,
                'currency' => 'USD',
                'reason_code' => 'fraudulent',
                'occurred_at' => '2026-07-01T12:00:00Z',
            ]
        ));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response)['data'];
        self::assertSame('posted', $body['status']);
        self::assertSame('orderOPIN0001', $body['order_uuid']);
        self::assertSame('evt_op_ingest_1', $body['provider_event_id']);
    }

    public function testOperatorSuppliesAttributionLinesForAPartialChargebackOverRealRoute(): void
    {
        $this->seedOrder(['uuid' => 'orderATTR0001', 'grand_total' => 1000]);
        $this->seedSellerOrder([
            'uuid' => 'selordATTR001',
            'order_uuid' => 'orderATTR0001',
            'seller_uuid' => 'sellerATTR001',
            'currency' => 'USD',
            'subtotal' => 1000,
            'attributed_total' => 1000,
        ]);
        $this->seedOrderLine([
            'uuid' => 'lineATTR00001',
            'order_uuid' => 'orderATTR0001',
            'seller_uuid' => 'sellerATTR001',
            'line_total' => 1000,
            'commission_basis' => 1000,
            'commission_amount' => 0,
        ]);
        $this->seedSaleLedger('orderATTR0001', 'sellerATTR001', 'USD', 1000, 0);

        $chargeback = $this->chargebackService->ingest($this->context, $this->chargebackEvent([
            'providerEventId' => 'evt_attr_1',
            'amount' => 400,
            'payable' => ['id' => 'orderATTR0001', 'amount' => 1000],
        ]));
        self::assertSame('awaiting_attribution', $chargeback['status']);

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->operatorRequest(
            'POST',
            "/commerce/admin/marketplace/chargebacks/{$chargeback['uuid']}/attribution",
            ['lines' => [['order_line_uuid' => 'lineATTR00001', 'amount' => 400]]]
        ));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response)['data'];
        self::assertSame('posted', $body['status']);

        $lines = $this->connection->table('commerce_chargeback_lines')
            ->where('chargeback_uuid', '=', $chargeback['uuid'])
            ->get();
        self::assertCount(1, $lines);
        self::assertSame(400, (int) $lines[0]['amount']);
    }

    // ===================================================================
    // 4. Operator: manual reserve hold/release (design spec §2.8) --
    //    Idempotency-Key REQUIRED for the hold; an exact replay is safe.
    // ===================================================================

    public function testOperatorManualHoldRejectsAMissingIdempotencyKeyHeader(): void
    {
        $seller = $this->seedSeller('surf-mh-noheader', 'ownerMHNOHDR1');
        $router = $this->freshRouter();

        $response = $this->dispatch($router, $this->operatorRequest(
            'POST',
            '/commerce/admin/marketplace/reserves/holds',
            ['seller_uuid' => $seller['uuid'], 'currency' => 'USD', 'amount' => 500, 'reason' => 'fraud review']
        ));

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('idempotency_key', $this->json($response)['error']['details']);
        self::assertSame(
            0,
            $this->connection->table('commerce_seller_reserves')->where('seller_uuid', '=', $seller['uuid'])->count()
        );
    }

    public function testOperatorManualHoldExactReplayIsSafeNoDoubleHold(): void
    {
        $seller = $this->seedSeller('surf-mh-replay', 'ownerMHRPLY01');
        $router = $this->freshRouter();
        $body = ['seller_uuid' => $seller['uuid'], 'currency' => 'USD', 'amount' => 750, 'reason' => 'fraud review'];

        $first = $this->manualHoldRequest($body, 'idem-op-mh-replay-1');
        $firstResponse = $this->dispatch($router, $first);

        $second = $this->manualHoldRequest($body, 'idem-op-mh-replay-1');
        $secondResponse = $this->dispatch($router, $second);

        self::assertSame(200, $firstResponse->getStatusCode());
        self::assertSame(200, $secondResponse->getStatusCode());
        self::assertSame(
            $this->json($firstResponse)['data']['uuid'],
            $this->json($secondResponse)['data']['uuid']
        );
        self::assertSame(
            1,
            $this->connection->table('commerce_seller_reserves')->where('seller_uuid', '=', $seller['uuid'])->count(),
            'an exact replay must never double-hold.'
        );

        $balance = $this->balances->balance($this->context, $this->tenant, $seller['uuid'], 'USD');
        self::assertSame(750, $balance['reserved']);
    }

    public function testOperatorReleasesAManualReserveHoldOverRealRoute(): void
    {
        $seller = $this->seedSeller('surf-mh-release', 'ownerMHRLS001');
        $router = $this->freshRouter();

        $holdResponse = $this->dispatch($router, $this->manualHoldRequest(
            ['seller_uuid' => $seller['uuid'], 'currency' => 'USD', 'amount' => 600, 'reason' => 'fraud review'],
            'idem-op-mh-release-1'
        ));
        $reserveUuid = $this->json($holdResponse)['data']['uuid'];

        $response = $this->dispatch($router, $this->operatorRequest(
            'POST',
            "/commerce/admin/marketplace/reserves/{$reserveUuid}/release"
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(600, (int) $this->json($response)['data']['released_amount']);

        $balance = $this->balances->balance($this->context, $this->tenant, $seller['uuid'], 'USD');
        self::assertSame(0, $balance['reserved']);
    }

    // ===================================================================
    // 5. Operator: audited debt forgiveness (design spec §2.8) --
    //    Idempotency-Key REQUIRED; an exact replay is safe.
    // ===================================================================

    public function testOperatorForgiveDebtRejectsAMissingIdempotencyKeyHeader(): void
    {
        $seller = $this->seedSeller('surf-forgive-noheader', 'ownerFRGNOH01');
        $this->seedDebt($seller['uuid'], 'USD', 500);
        $router = $this->freshRouter();

        $response = $this->dispatch($router, $this->operatorRequest(
            'POST',
            "/commerce/admin/marketplace/sellers/{$seller['uuid']}/debt/forgive",
            ['currency' => 'USD', 'amount' => 500, 'reason' => 'goodwill forgiveness']
        ));

        self::assertSame(422, $response->getStatusCode());
        $balance = $this->balances->balance($this->context, $this->tenant, $seller['uuid'], 'USD');
        self::assertSame(500, $balance['debt'], 'a rejected request must never touch the balance.');
    }

    public function testOperatorForgiveDebtExactReplayIsSafeNoDoubleCredit(): void
    {
        $seller = $this->seedSeller('surf-forgive-replay', 'ownerFRGRPL01');
        $this->seedDebt($seller['uuid'], 'USD', 500);
        $router = $this->freshRouter();
        $body = ['currency' => 'USD', 'amount' => 500, 'reason' => 'goodwill forgiveness'];

        $first = $this->forgiveDebtRequest($seller['uuid'], $body, 'idem-op-forgive-1');
        $firstResponse = $this->dispatch($router, $first);

        $second = $this->forgiveDebtRequest($seller['uuid'], $body, 'idem-op-forgive-1');
        $secondResponse = $this->dispatch($router, $second);

        self::assertSame(200, $firstResponse->getStatusCode());
        self::assertSame(200, $secondResponse->getStatusCode());
        self::assertSame(0, (int) $this->json($secondResponse)['data']['debt']);

        $balance = $this->balances->balance($this->context, $this->tenant, $seller['uuid'], 'USD');
        self::assertSame(0, $balance['debt']);
        self::assertSame(500, $balance['adjustments'], 'a replay must never double-credit.');
    }

    // ===================================================================
    // 6. Operator: read a seller's reserves + debt (design spec §6).
    // ===================================================================

    public function testOperatorReadsASellersReservesAndDebtOverRealRoute(): void
    {
        $seller = $this->seedSeller('surf-read-reserves', 'ownerRDRSV001');
        $router = $this->freshRouter();

        // A prior sale funds the hold, so `debt` reads a clean `0` here -- the debt/
        // negative-balance math itself is covered elsewhere (ManualReserveTest).
        $this->seedSaleLedger('orderRDRSV0001', $seller['uuid'], 'USD', 300, 0);

        $this->dispatch($router, $this->manualHoldRequest(
            ['seller_uuid' => $seller['uuid'], 'currency' => 'USD', 'amount' => 300, 'reason' => 'fraud review'],
            'idem-op-read-1'
        ));

        $response = $this->dispatch($router, $this->operatorRequest(
            'GET',
            "/commerce/admin/marketplace/sellers/{$seller['uuid']}/reserves"
        ));

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertSame(300, $data['balances'][0]['reserved']);
        self::assertSame(0, $data['balances'][0]['debt']);
        self::assertCount(1, $data['reserves']);
        self::assertSame('manual', $data['reserves'][0]['source_kind']);
        self::assertSame(300, $data['reserves'][0]['remaining']);
        self::assertSame('held', $data['reserves'][0]['status']);
    }

    // ===================================================================
    // 7. Tenant binding: a cross-tenant body/target attempt cannot
    //    escape the resolved tenant (design spec §6).
    // ===================================================================

    public function testManualHoldWithACrossTenantSellerUuidCannotEscapeTheResolvedTenant(): void
    {
        $otherTenant = 'tenantOTHER1';
        $foreignSeller = 'sellerFOREIGN1';
        $this->sellers->insert($this->context, [
            'uuid' => $foreignSeller,
            'tenant_uuid' => $otherTenant,
            'slug' => 'foreign-seller-1',
            'name' => 'Foreign Seller',
        ]);

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->manualHoldRequest(
            ['seller_uuid' => $foreignSeller, 'currency' => 'USD', 'amount' => 500, 'reason' => 'fraud review'],
            'idem-op-cross-tenant-1'
        ));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(
            0,
            $this->connection->table('commerce_seller_reserves')->where('seller_uuid', '=', $foreignSeller)->count(),
            'a cross-tenant seller_uuid must never create a reserve row under ANY tenant.'
        );
    }

    public function testForgiveDebtWithACrossTenantSellerUuidCannotEscapeTheResolvedTenant(): void
    {
        $otherTenant = 'tenantOTHER2';
        $foreignSeller = 'sellerFOREIGN2';
        $this->sellers->insert($this->context, [
            'uuid' => $foreignSeller,
            'tenant_uuid' => $otherTenant,
            'slug' => 'foreign-seller-2',
            'name' => 'Foreign Seller',
        ]);
        $this->ledger->post($this->context, $otherTenant, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller($foreignSeller),
            'seller_uuid' => $foreignSeller,
            'currency' => 'USD',
            'entry_type' => 'chargeback_debit',
            'amount' => -500,
            'idempotency_key' => 'seed:' . $foreignSeller . ':chargeback_debit',
        ]);

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->forgiveDebtRequest(
            $foreignSeller,
            ['currency' => 'USD', 'amount' => 500, 'reason' => 'goodwill'],
            'idem-op-cross-tenant-forgive-1'
        ));

        self::assertSame(404, $response->getStatusCode());

        // The FOREIGN tenant's own debt is untouched -- no adjustment was posted
        // against it under the resolved (wrong) tenant either.
        $foreignBalance = $this->balances->balance($this->context, $otherTenant, $foreignSeller, 'USD');
        self::assertSame(500, $foreignBalance['debt']);
    }

    public function testIngestChargebackForACrossTenantOrderNeverResolvesOrEscapesTheTenant(): void
    {
        $otherTenant = 'tenantOTHER3';
        $this->orders->insert($this->context, [
            'uuid' => 'orderFOREIGN01',
            'tenant_uuid' => $otherTenant,
            'order_number' => 'ORD-FOREIGN01',
            'status' => 'paid',
            'marketplace_partitioned' => true,
            'email' => 'buyer@example.com',
            'guest_token_hash' => hash('sha256', 'orderFOREIGN01'),
            'currency' => 'USD',
            'subtotal' => 5000,
            'grand_total' => 5000,
        ]);

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->operatorRequest(
            'POST',
            '/commerce/admin/marketplace/chargebacks',
            [
                'provider' => 'stripe',
                'provider_event_id' => 'evt_cross_tenant_1',
                'payment_reference' => 'pay_ref_cross_1',
                'payable_type' => 'commerce_order',
                'payable_id' => 'orderFOREIGN01',
                'payable_amount' => 5000,
                'amount' => 5000,
                'currency' => 'USD',
                'occurred_at' => '2026-07-01T12:00:00Z',
            ]
        ));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response)['data'];
        self::assertSame('integrity_hold', $body['status'], 'a cross-tenant order must never resolve.');
        self::assertNull($body['order_uuid']);
    }

    // ===================================================================
    // 8. Seller: sanitized reserved/upcoming-releases/debt (design spec §6).
    // ===================================================================

    public function testSellerReadsOwnReservedUpcomingReleasesAndDebtSanitized(): void
    {
        $seller = $this->seedSeller('surf-seller-read', 'ownerSELRD001');
        $router = $this->freshRouter();

        // A prior sale funds the reserve hold, so the only negative-balance
        // contribution left is the debt seeded below -- isolates the assertion.
        $this->seedSaleLedger('orderSELRD0001', $seller['uuid'], 'USD', 400, 0);
        $future = gmdate('Y-m-d H:i:s', time() + 3600 * 24 * 5);
        $this->seedRollingHold('resvSELRD0001', $seller['uuid'], 'USD', 400, 'selordSELRD01', $future);
        $this->seedDebt($seller['uuid'], 'USD', 150);

        $response = $this->dispatch($router, $this->requestAs(
            'ownerSELRD001',
            'GET',
            "/commerce/seller/{$seller['uuid']}/financials/reserves"
        ));

        self::assertSame(200, $response->getStatusCode());
        $balance = $this->json($response)['data']['balances'][0];

        self::assertSame(['currency', 'reserved', 'debt', 'upcoming_releases'], array_keys($balance));
        self::assertSame('USD', $balance['currency']);
        self::assertSame(400, $balance['reserved']);
        self::assertSame(150, $balance['debt']);
        self::assertCount(1, $balance['upcoming_releases']);
        self::assertSame(['amount', 'release_at'], array_keys($balance['upcoming_releases'][0]));
        self::assertSame(400, $balance['upcoming_releases'][0]['amount']);
    }

    public function testSellerUpcomingReleasesExcludesAnIndefiniteManualHold(): void
    {
        $seller = $this->seedSeller('surf-seller-indef', 'ownerSELIND001');
        $router = $this->freshRouter();

        $this->dispatch($router, $this->manualHoldRequest(
            ['seller_uuid' => $seller['uuid'], 'currency' => 'USD', 'amount' => 900, 'reason' => 'indefinite hold'],
            'idem-op-seller-indef-1'
        ));

        $response = $this->dispatch($router, $this->requestAs(
            'ownerSELIND001',
            'GET',
            "/commerce/seller/{$seller['uuid']}/financials/reserves"
        ));

        self::assertSame(200, $response->getStatusCode());
        $balance = $this->json($response)['data']['balances'][0];
        self::assertSame(900, $balance['reserved'], 'an indefinite manual hold still counts toward reserved.');
        self::assertSame(
            [],
            $balance['upcoming_releases'],
            'an indefinite manual hold (release_at IS NULL) has no known release time.'
        );
    }

    // ===================================================================
    // 9. Poison-string test: raw internals never leak to the seller.
    // ===================================================================

    public function testPoisonedChargebackAndReserveFieldsNeverLeakToSellerReserveResponses(): void
    {
        $seller = $this->seedSeller('surf-poison-rsv', 'ownerPOISRSV1');
        $poison = 'POISONMARKER-' . Utils::generateNanoID();

        $this->seedOrder(['uuid' => 'orderPOISON01', 'grand_total' => 5000]);
        $this->chargebackRepo->insert($this->context, $this->tenant, [
            'provider' => 'stripe',
            'provider_event_id' => $poison . '-evtid',
            'payment_reference' => $poison . '-payref',
            'order_uuid' => 'orderPOISON01',
            'amount' => 5000,
            'currency' => 'USD',
            'reason_code' => $poison . '-reason',
            'occurred_at' => '2026-07-01 12:00:00',
            'kind' => 'chargeback',
            'related_chargeback_uuid' => null,
            'status' => 'posted',
        ]);

        $router = $this->freshRouter();
        $this->dispatch($router, $this->manualHoldRequest(
            [
                'seller_uuid' => $seller['uuid'],
                'currency' => 'USD',
                'amount' => 250,
                'reason' => $poison . '-holdreason',
            ],
            $poison . '-idem'
        ));

        $response = $this->dispatch($router, $this->requestAs(
            'ownerPOISRSV1',
            'GET',
            "/commerce/seller/{$seller['uuid']}/financials/reserves"
        ));

        self::assertSame(200, $response->getStatusCode());
        $content = (string) $response->getContent();
        self::assertStringNotContainsString($poison . '-evtid', $content);
        self::assertStringNotContainsString($poison . '-payref', $content);
        self::assertStringNotContainsString($poison . '-reason', $content);
        self::assertStringNotContainsString($poison . '-holdreason', $content);
        self::assertStringNotContainsString($poison . '-idem', $content);
    }

    // ===================================================================
    // 10. Cross-seller seller-read -> non-revealing 404.
    // ===================================================================

    public function testCrossSellerAndUnknownSellerReserveReadIs404NonRevealing(): void
    {
        $sellerA = $this->seedSeller('surf-cross-rsv-a', 'ownerCRRSVA01');
        $sellerB = $this->seedSeller('surf-cross-rsv-b', 'ownerCRRSVB01');
        $router = $this->freshRouter();

        $ownRead = $this->dispatch($router, $this->requestAs(
            'ownerCRRSVA01',
            'GET',
            "/commerce/seller/{$sellerA['uuid']}/financials/reserves"
        ));
        $crossSeller = $this->dispatch($router, $this->requestAs(
            'ownerCRRSVA01',
            'GET',
            "/commerce/seller/{$sellerB['uuid']}/financials/reserves"
        ));
        $unknown = $this->dispatch($router, $this->requestAs(
            'ownerCRRSVA01',
            'GET',
            '/commerce/seller/doesNotExist01/financials/reserves'
        ));

        self::assertSame(200, $ownRead->getStatusCode());
        self::assertSame(404, $crossSeller->getStatusCode());
        self::assertSame(404, $unknown->getStatusCode());
        self::assertSame($this->json($unknown), $this->json($crossSeller));
    }

    // ===================================================================
    // 11. No operator "reverse a chargeback" route exists (design spec §2.10).
    // ===================================================================

    public function testNoOperatorReverseChargebackRouteExistsAnywhere(): void
    {
        $router = $this->freshRouter();

        foreach (
            [
            '/commerce/admin/marketplace/chargebacks/someChargeback1/reverse',
            '/commerce/admin/marketplace/chargebacks/someChargeback1/reversal',
            '/commerce/admin/marketplace/reserves/someReserve01/reverse',
            ] as $path
        ) {
            $response = $this->dispatch($router, $this->operatorRequest('POST', $path));
            self::assertContains(
                $response->getStatusCode(),
                [404, 405],
                "{$path} must not be a registered mutation route."
            );
        }
    }

    // -----------------------------------------------------------------
    // Fixtures + helpers.
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function seedOrder(array $overrides = []): array
    {
        $tenant = (string) ($overrides['tenant_uuid'] ?? $this->tenant);
        unset($overrides['tenant_uuid']);
        $uuid = (string) ($overrides['uuid'] ?? 'orderRSV00001');
        unset($overrides['uuid']);

        $row = array_merge([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_number' => 'ORD-' . $uuid,
            'status' => 'paid',
            'marketplace_partitioned' => true,
            'email' => 'buyer@example.com',
            'guest_token_hash' => hash('sha256', $uuid),
            'currency' => 'USD',
            'subtotal' => 5000,
            'grand_total' => 5000,
        ], $overrides);

        $this->orders->insert($this->context, $row);

        return $this->orders->findByUuid($this->context, $tenant, $uuid) ?? [];
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function seedSellerOrder(array $overrides): array
    {
        $row = array_merge([
            'tenant_uuid' => $this->tenant,
            'seller_name_snapshot' => 'Seller',
            'partition_number' => 1,
            'seller_reference' => (string) $overrides['uuid'] . '-ref',
            'tax_attribution_method' => 'proportional',
            'allocated_discount' => 0,
            'allocated_shipping_discount' => 0,
            'allocated_shipping' => 0,
            'allocated_tax' => 0,
            'commission_amount' => 0,
            'confirmed_at' => '2026-01-01 00:00:00',
            'status' => 'open',
            'fulfillment_status' => 'unfulfilled',
            'revision' => 0,
        ], $overrides);

        $this->connection->table('commerce_seller_orders')->insert($row);

        return $row;
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function seedOrderLine(array $overrides): array
    {
        $uuid = (string) $overrides['uuid'];

        $row = array_merge([
            'variant_uuid' => 'variant' . substr(md5($uuid), 0, 8),
            'product_name' => 'Product ' . $uuid,
            'sku' => 'SKU-' . $uuid,
            'option_values' => '[]',
            'unit_price' => (int) ($overrides['line_total'] ?? 0),
            'quantity' => 1,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'commission_basis' => 0,
            'commission_amount' => 0,
            'seller_uuid' => null,
        ], $overrides);

        $this->connection->table('commerce_order_lines')->insert($row);

        return $row;
    }

    private function seedSaleLedger(
        string $orderUuid,
        string $sellerUuid,
        string $currency,
        int $saleCredit,
        int $commissionDebit
    ): void {
        $this->ledger->post($this->context, $this->tenant, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller($sellerUuid),
            'seller_uuid' => $sellerUuid,
            'currency' => $currency,
            'entry_type' => 'sale_credit',
            'amount' => $saleCredit,
            'order_uuid' => $orderUuid,
            'idempotency_key' => "{$orderUuid}:{$sellerUuid}:sale_credit",
        ]);
        if ($commissionDebit > 0) {
            $this->ledger->post($this->context, $this->tenant, [
                'account_kind' => 'seller',
                'account_key' => LedgerRepository::accountKeyForSeller($sellerUuid),
                'seller_uuid' => $sellerUuid,
                'currency' => $currency,
                'entry_type' => 'commission_debit',
                'amount' => -$commissionDebit,
                'order_uuid' => $orderUuid,
                'idempotency_key' => "{$orderUuid}:{$sellerUuid}:commission_debit",
            ]);
        }
    }

    private function seedDebt(string $sellerUuid, string $currency, int $debtAmount): void
    {
        $this->ledger->post($this->context, $this->tenant, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller($sellerUuid),
            'seller_uuid' => $sellerUuid,
            'currency' => $currency,
            'entry_type' => 'chargeback_debit',
            'amount' => -$debtAmount,
            'idempotency_key' => 'seed:' . $sellerUuid . ':chargeback_debit',
        ]);
    }

    private function seedRollingHold(
        string $reserveUuid,
        string $sellerUuid,
        string $currency,
        int $amount,
        string $sellerOrderUuid,
        string $releaseAt
    ): void {
        $this->reserveRepo->insertRollingHold($this->context, $this->tenant, [
            'uuid' => $reserveUuid,
            'seller_uuid' => $sellerUuid,
            'currency' => $currency,
            'seller_order_uuid' => $sellerOrderUuid,
            'amount' => $amount,
            'reserve_bps_snapshot' => 250,
            'reserve_days_snapshot' => 5,
            'held_at' => gmdate('Y-m-d H:i:s'),
            'release_at' => $releaseAt,
        ]);
        $this->ledger->post($this->context, $this->tenant, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller($sellerUuid),
            'seller_uuid' => $sellerUuid,
            'currency' => $currency,
            'entry_type' => 'reserve_hold',
            'amount' => -$amount,
            'seller_order_uuid' => $sellerOrderUuid,
            'payout_uuid' => null,
            'reserve_uuid' => $reserveUuid,
            'idempotency_key' => "{$sellerOrderUuid}:{$sellerUuid}:reserve_hold",
        ]);
    }

    /** @param array<string,mixed> $overrides */
    private function chargebackEvent(array $overrides = []): ProviderChargebackEvent
    {
        $currency = (string) ($overrides['currency'] ?? 'USD');
        $payableOverrides = $overrides['payable'] ?? [];

        $payable = new PayableReference(
            (string) ($payableOverrides['type'] ?? 'commerce_order'),
            (string) ($payableOverrides['id'] ?? 'orderRSV00001'),
            (int) ($payableOverrides['amount'] ?? 5000),
            (string) ($payableOverrides['currency'] ?? $currency),
        );

        return new ProviderChargebackEvent(
            (string) ($overrides['tenantUuid'] ?? $this->tenant),
            (string) ($overrides['provider'] ?? 'stripe'),
            (string) ($overrides['providerEventId'] ?? 'evt_' . bin2hex(random_bytes(6))),
            (string) ($overrides['paymentReference'] ?? 'pay_ref_1'),
            $payable,
            (int) ($overrides['amount'] ?? 5000),
            $currency,
            $overrides['reasonCode'] ?? 'fraudulent',
            (string) ($overrides['occurredAt'] ?? '2026-07-01T12:00:00Z'),
            (string) ($overrides['kind'] ?? ProviderChargebackEvent::KIND_CHARGEBACK),
            $overrides['relatedEventId'] ?? null,
        );
    }

    private function manualHoldRequest(array $body, string $idempotencyKey): Request
    {
        $request = $this->operatorRequest('POST', '/commerce/admin/marketplace/reserves/holds', $body);
        $request->headers->set('Idempotency-Key', $idempotencyKey);

        return $request;
    }

    private function forgiveDebtRequest(string $sellerUuid, array $body, string $idempotencyKey): Request
    {
        $request = $this->operatorRequest(
            'POST',
            "/commerce/admin/marketplace/sellers/{$sellerUuid}/debt/forgive",
            $body
        );
        $request->headers->set('Idempotency-Key', $idempotencyKey);

        return $request;
    }

    /** Same convention as {@see SellerFinancialSurfaceTest}'s own `requestAs()`. */
    private function requestAs(string $userUuid, string $method, string $uri, array $body = []): Request
    {
        $content = $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR);
        $request = Request::create($uri, $method, [], [], [], [], $content);
        if ($content !== null) {
            $request->headers->set('Content-Type', 'application/json');
        }
        $request->headers->set('X-Test-User', $userUuid);

        return $request;
    }

    private function operatorRequest(string $method, string $uri, array $body = []): Request
    {
        $content = $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR);
        $request = Request::create($uri, $method, [], [], [], [], $content);
        if ($content !== null) {
            $request->headers->set('Content-Type', 'application/json');
        }
        $request->headers->set('X-Test-User', 'operatorSURF01');
        $request->headers->set('X-Test-Scopes', 'commerce:read,commerce:write');

        return $request;
    }

    /**
     * Builds a fresh {@see Router} bound to REAL {@see AdminReserveController}/
     * {@see SellerFinancialController} instances sharing THIS test's own
     * repositories/services, mirroring {@see PayoutSurfaceTest::freshRouter()}'s exact
     * admin-side harness (a fake `auth` setting both the seller-side `user` array
     * attribute AND the admin-side `auth.user`/`api_key_scopes` attributes, plus a REAL
     * `require_scope` middleware).
     */
    protected function freshRouter(): Router
    {
        $this->bind(ApplicationContext::class, $this->context);
        $this->bind('commerce_seller', $this->buildSellerMiddleware());
        $this->bind('auth', $this->buildScopedAuthMiddleware());
        $this->bind('require_scope', new RequireScopeMiddleware());

        $this->bind(SellerFinancialController::class, new SellerFinancialController(
            $this->context,
            new SellerFinancialReportRepository(),
            $this->balances,
            new PayoutRepository(),
            $this->marketplaceMode,
            $this->fixedTenant(),
            new PayoutAccountRepository(),
            $this->reserveService
        ));

        $this->bind(AdminReserveController::class, new AdminReserveController(
            $this->context,
            $this->reservePolicy,
            $this->chargebackService,
            $this->reserveService,
            $this->adjustments,
            $this->balances,
            $this->sellers,
            $this->marketplaceMode,
            $this->fixedTenant()
        ));

        $router = new Router($this->contextContainer());
        require __DIR__ . '/../../../routes.php';

        return $router;
    }

    private function buildScopedAuthMiddleware(): RouteMiddleware
    {
        return new class implements RouteMiddleware {
            public function handle(Request $request, callable $next, mixed ...$params): mixed
            {
                $userUuid = $request->headers->get('X-Test-User');
                if ($userUuid === null || $userUuid === '') {
                    return Response::unauthorized('Authentication required');
                }

                $request->attributes->set('user', ['uuid' => $userUuid]);
                $request->attributes->set('auth.user', new UserIdentity($userUuid));

                $scopesHeader = $request->headers->get('X-Test-Scopes');
                if ($scopesHeader !== null && trim($scopesHeader) !== '') {
                    $request->attributes->set(
                        'api_key_scopes',
                        array_map('trim', explode(',', $scopesHeader))
                    );
                }

                return $next($request);
            }
        };
    }
}
