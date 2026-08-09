<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Orders;

use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Cart\CartRepository;
use Glueful\Extensions\Commerce\Cart\CartService;
use Glueful\Extensions\Commerce\Catalog\DownloadRepository;
use Glueful\Extensions\Commerce\Catalog\ProductRepository;
use Glueful\Extensions\Commerce\Catalog\VariantRepository;
use Glueful\Extensions\Commerce\Console\CustomersLinkGuestsCommand;
use Glueful\Extensions\Commerce\Contracts\ShippingRateProvider;
use Glueful\Extensions\Commerce\Contracts\TaxCalculator;
use Glueful\Extensions\Commerce\Customers\CustomerAggregationRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountRepository;
use Glueful\Extensions\Commerce\Discounts\DiscountService;
use Glueful\Extensions\Commerce\Http\Admin\AdminOrderController;
use Glueful\Extensions\Commerce\Http\Admin\AdminRefundController;
use Glueful\Extensions\Commerce\Http\DTOs\OrderListQuery;
use Glueful\Extensions\Commerce\Http\Storefront\OrderController;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Invoices\ConfigSellerIdentityProvider;
use Glueful\Extensions\Commerce\Marketplace\ReconciliationService;
use Glueful\Extensions\Commerce\Orders\CheckoutService;
use Glueful\Extensions\Commerce\Orders\ExpiryService;
use Glueful\Extensions\Commerce\Orders\OrderNumberGenerator;
use Glueful\Extensions\Commerce\Orders\OrderPaymentService;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\OrderScope;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundService;
use Glueful\Extensions\Commerce\Payments\ManualPaymentCollector;
use Glueful\Extensions\Commerce\Pricing\PricingEngine;
use Glueful\Extensions\Commerce\Pricing\ShippingQuote;
use Glueful\Extensions\Commerce\Pricing\TaxQuote;
use Glueful\Extensions\Commerce\Reports\CustomerReportRepository;
use Glueful\Extensions\Commerce\Reports\ProductSalesReportRepository;
use Glueful\Extensions\Commerce\Reports\ReportWindow;
use Glueful\Extensions\Commerce\Reports\SalesReportRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admin-order-creation cycle 2, Task 8 -- the STRUCTURAL isolation half of the
 * seeded-draft matrix. Every engine `commerce_orders` reader enumerated by the
 * Task 8 audit is exercised here with BOTH a seeded `draft` row and a finalized
 * row present for the same tenant: a draft must be invisible to every finalized
 * surface, and visible only through an explicit, opted-in draft read.
 *
 * The tenant is fixed (`SentinelTenantResolver` resolves `''`) so both the
 * repository-level and controller-level assertions address the same rows.
 */
final class DraftIsolationTest extends CommerceTestCase
{
    private const TENANT = '';

    // -----------------------------------------------------------------
    // State machine at the repository boundary
    // -----------------------------------------------------------------

    public function testTransitionRejectsTheDraftToPendingPaymentFinalizePairEvenThoughItIsAllowed(): void
    {
        $this->seedOrder('draftorder01', 'draft');

        try {
            (new OrderRepository())->transition($this->context, self::TENANT, 'draftorder01', 'pending_payment');
            self::fail('transition() must refuse to perform the draft finalize pair.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('finalizeDraftTransition', $e->getMessage());
        }

        self::assertSame('draft', $this->statusOf('draftorder01'));
        // The rejection happens BEFORE any write and records no audit row.
        self::assertSame([], $this->eventTypesFor('draftorder01'));
    }

    /**
     * SYMMETRY with the finalize pair (review fix): `draft -> canceled` is
     * legal in {@see \Glueful\Extensions\Commerce\Orders\OrderStateMachine} but
     * is equally dedicated-path-only. Letting it through `transition()` would
     * SUCCEED while silently skipping the
     * {@see \Glueful\Extensions\Commerce\Orders\Events\DraftOrderEvents} audit
     * row -- a canceled draft with no record of why it died. The sanctioned
     * door is `DraftCleanupService::cancelDraft()`, which bypasses the state
     * machine entirely and is therefore unaffected by this refusal.
     */
    public function testTransitionAlsoRejectsTheDraftToCanceledPair(): void
    {
        $this->seedOrder('draftorder02', 'draft');

        try {
            (new OrderRepository())->transition($this->context, self::TENANT, 'draftorder02', 'canceled');
            self::fail('transition() must refuse to cancel a draft.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('DraftCleanupService::cancelDraft()', $e->getMessage());
        }

        self::assertSame('draft', $this->statusOf('draftorder02'));
        self::assertSame([], $this->eventTypesFor('draftorder02'));
    }

    /**
     * An outright ILLEGAL draft pair must still report as the invalid
     * transition it is, not as a routing mistake -- the dedicated-path refusal
     * is ordered AFTER the state-machine assertion precisely so this holds.
     */
    public function testAnIllegalDraftPairStillReportsAsAnInvalidTransition(): void
    {
        $this->seedOrder('draftorder29', 'draft');

        try {
            (new OrderRepository())->transition($this->context, self::TENANT, 'draftorder29', 'paid');
            self::fail('draft -> paid must be rejected.');
        } catch (\DomainException $e) {
            self::assertSame('Invalid order transition draft -> paid.', $e->getMessage());
        }

        self::assertSame('draft', $this->statusOf('draftorder29'));
    }

    public function testFinalizeDraftTransitionFlipsADraftToPendingPayment(): void
    {
        $this->seedOrder('draftorder03', 'draft');

        (new OrderRepository())->finalizeDraftTransition($this->context, self::TENANT, 'draftorder03');

        self::assertSame('pending_payment', $this->statusOf('draftorder03'));
        self::assertSame(['status:pending_payment'], $this->eventTypesFor('draftorder03'));
        self::assertNotNull($this->orderRow('draftorder03')['updated_at']);
    }

    public function testFinalizeDraftTransitionRefusesANonDraftOrder(): void
    {
        $this->seedOrder('draftorder04', 'pending_payment', ['order_number' => 'ORD-000004']);

        $this->expectException(\DomainException::class);

        (new OrderRepository())->finalizeDraftTransition($this->context, self::TENANT, 'draftorder04');
    }

    public function testFinalizeDraftTransitionRefusesAnUnknownOrCrossTenantOrder(): void
    {
        $this->seedOrder('draftorder05', 'draft', ['tenant_uuid' => 'othertenant1']);

        try {
            (new OrderRepository())->finalizeDraftTransition($this->context, self::TENANT, 'draftorder05');
            self::fail('a cross-tenant draft must never finalize.');
        } catch (\DomainException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        try {
            (new OrderRepository())->finalizeDraftTransition($this->context, self::TENANT, 'nosuchorder1');
            self::fail('an unknown order must never finalize.');
        } catch (\DomainException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        self::assertSame('draft', $this->statusOf('draftorder05'));
    }

    /**
     * The compare-and-set is single-shot: the SECOND caller for the same draft
     * loses deterministically (0 affected rows -> throw), never silently
     * re-finalizing an already-finalized order. The genuinely CONCURRENT loser
     * lives in the pgsql lane ({@see DraftFinalizeTransitionPgsqlTest}); this
     * is its sequential proof.
     */
    public function testFinalizeDraftTransitionIsSingleShotSoASecondCallerLoses(): void
    {
        $this->seedOrder('draftorder06', 'draft');
        $repo = new OrderRepository();
        $repo->finalizeDraftTransition($this->context, self::TENANT, 'draftorder06');

        try {
            $repo->finalizeDraftTransition($this->context, self::TENANT, 'draftorder06');
            self::fail('the second finalize must lose.');
        } catch (\DomainException $e) {
            self::assertNotSame('', $e->getMessage());
        }

        self::assertSame('pending_payment', $this->statusOf('draftorder06'));
        // Exactly one audit row -- the loser wrote nothing.
        self::assertSame(['status:pending_payment'], $this->eventTypesFor('draftorder06'));
    }

    // -----------------------------------------------------------------
    // OrderRepository readers
    // -----------------------------------------------------------------

    public function testCurrencyLockGuardIgnoresDrafts(): void
    {
        $repo = new OrderRepository();
        $this->seedOrder('draftorder07', 'draft');
        $this->seedOrder('draftorder08', 'draft');

        // Drafts are not recorded money history: the store currency is still changeable.
        self::assertFalse($repo->anyExistsForTenant($this->context, self::TENANT));

        $this->seedOrder('realorder001', 'paid', ['order_number' => 'ORD-000101']);

        self::assertTrue($repo->anyExistsForTenant($this->context, self::TENANT));
    }

    public function testFindByUuidHidesDraftsUnlessExplicitlyRequested(): void
    {
        $repo = new OrderRepository();
        $this->seedOrder('draftorder09', 'draft');

        self::assertNull($repo->findByUuid($this->context, self::TENANT, 'draftorder09'));

        $draft = $repo->findByUuid($this->context, self::TENANT, 'draftorder09', true);
        self::assertIsArray($draft);
        self::assertSame('draft', $draft['status']);
    }

    public function testFindByNumberCannotResolveADraft(): void
    {
        $repo = new OrderRepository();
        // Defensive: a draft normally carries a NULL order_number, but even a
        // draft that somehow holds one must not resolve on the number surface.
        $this->seedOrder('draftorder10', 'draft', ['order_number' => 'ORD-DRAFT-1']);

        self::assertNull($repo->findByNumber($this->context, self::TENANT, 'ORD-DRAFT-1'));
    }

    public function testListForExcludesDrafts(): void
    {
        $repo = new OrderRepository();
        $this->seedOrder('draftorder11', 'draft');
        $this->seedOrder('realorder002', 'paid', ['order_number' => 'ORD-000102']);

        $uuids = array_column($repo->listFor($this->context, self::TENANT), 'uuid');

        self::assertSame(['realorder002'], $uuids);
    }

    public function testPaginatedForExcludesDraftsEvenWhenStatusDraftIsExplicitlyFiltered(): void
    {
        $repo = new OrderRepository();
        $this->seedOrder('draftorder12', 'draft');
        $this->seedOrder('realorder003', 'paid', ['order_number' => 'ORD-000103']);

        $all = $repo->paginatedFor($this->context, self::TENANT, [], 1, 50);
        self::assertSame(1, $all['total']);
        self::assertSame(['realorder003'], array_column($all['items'], 'uuid'));

        // `?status=draft` on the ordinary orders surface must NOT be a back door.
        $filtered = $repo->paginatedFor($this->context, self::TENANT, ['status' => 'draft'], 1, 50);
        self::assertSame(0, $filtered['total']);
        self::assertSame([], $filtered['items']);
    }

    public function testPaginatedForIncludesDraftsOnlyOnExplicitRequest(): void
    {
        $repo = new OrderRepository();
        $this->seedOrder('draftorder13', 'draft');
        $this->seedOrder('realorder004', 'paid', ['order_number' => 'ORD-000104']);

        $result = $repo->paginatedFor($this->context, self::TENANT, ['status' => 'draft'], 1, 50, true);

        self::assertSame(1, $result['total']);
        self::assertSame(['draftorder13'], array_column($result['items'], 'uuid'));
    }

    public function testProductAttributedReadsExcludeDrafts(): void
    {
        $repo = new OrderRepository();
        $variantUuid = $this->seedVariantForProduct('prodisolate1', 'varisolate01', 'SKU-DRAFT-ISO');
        $this->seedOrder('draftorder14', 'draft');
        $this->seedOrder('realorder005', 'paid', ['order_number' => 'ORD-000105']);
        $this->seedLine('lineisolate1', 'draftorder14', $variantUuid, 5000);
        $this->seedLine('lineisolate2', 'realorder005', $variantUuid, 1000);

        $recent = $repo->recentForProduct($this->context, self::TENANT, 'prodisolate1', 10);
        self::assertSame(['realorder005'], array_column($recent, 'uuid'));

        $summary = $repo->productOrderSummary($this->context, self::TENANT, 'prodisolate1', '1970-01-01 00:00:00');
        self::assertSame(1, $summary['orders']);
        self::assertSame(1000, $summary['revenue_minor']);
    }

    public function testFinancialAndFulfillmentClaimsRefuseDrafts(): void
    {
        $repo = new OrderRepository();
        $this->seedOrder('draftorder15', 'draft');

        self::assertFalse($repo->claimOrderFinancialMutation($this->context, self::TENANT, 'draftorder15'));

        try {
            $repo->claimFulfillmentMutation($this->context, self::TENANT, 'draftorder15');
            self::fail('a draft must never be claimable for fulfillment.');
        } catch (NotFoundException $e) {
            self::assertSame('Resource not found.', $e->getMessage());
        }

        // Neither claim bumped a revision counter.
        $row = $this->orderRow('draftorder15');
        self::assertSame(0, (int) $row['refund_revision']);
        self::assertSame(0, (int) $row['fulfillment_revision']);
    }

    public function testGuestLinkingNeverStampsADraft(): void
    {
        $repo = new OrderRepository();
        $this->seedOrder('draftorder16', 'draft', ['email' => 'walkin@example.com']);

        self::assertFalse(
            $repo->linkGuestToUser($this->context, self::TENANT, 'draftorder16', 'someuser001')
        );
        self::assertNull($this->orderRow('draftorder16')['user_uuid']);
    }

    public function testGuestLinkingCommandNeverScansDrafts(): void
    {
        $this->seedOrder('draftorder17', 'draft', ['email' => 'ghostdraft@example.com']);
        $this->bind(UserProviderInterface::class, new class implements UserProviderInterface {
            public function findByUuid(string $uuid): ?UserIdentity
            {
                return null;
            }

            public function findByLogin(string $login): ?UserIdentity
            {
                return new UserIdentity(uuid: 'linkeduser99', email: 'ghostdraft@example.com');
            }

            public function verifyCredentials(string $identifier, string $password): ?UserIdentity
            {
                return null;
            }
        });

        $tester = new CommandTester(new CustomersLinkGuestsCommand($this->context->getContainer(), $this->context));
        $tester->execute([]);

        self::assertStringContainsString(
            'No guest orders found.',
            (string) preg_replace('/\s+/', ' ', $tester->getDisplay())
        );
        self::assertNull($this->orderRow('draftorder17')['user_uuid']);
    }

    // -----------------------------------------------------------------
    // Aggregations and reports
    // -----------------------------------------------------------------

    public function testCustomerAggregationExcludesDrafts(): void
    {
        $repo = new CustomerAggregationRepository();
        $this->seedOrder('draftorder18', 'draft', [
            'email' => 'buyer@example.com',
            'user_uuid' => 'buyeruser001',
            'grand_total' => 90000,
        ]);
        $this->seedOrder('realorder006', 'paid', [
            'order_number' => 'ORD-000106',
            'email' => 'buyer@example.com',
            'user_uuid' => 'buyeruser001',
            'grand_total' => 1000,
        ]);
        $this->seedOrder('draftorder19', 'draft', ['email' => 'guest@example.com', 'grand_total' => 70000]);
        $this->seedOrder('realorder007', 'paid', [
            'order_number' => 'ORD-000107',
            'email' => 'guest@example.com',
            'grand_total' => 2000,
        ]);

        $listing = $repo->paginate($this->context, self::TENANT, [], 'total_spent', 'desc', 1, 50);
        self::assertSame(2, $listing['total']);
        foreach ($listing['items'] as $item) {
            self::assertSame(1, $item['orders_count']);
        }
        self::assertSame([2000, 1000], array_column($listing['items'], 'total_spent_minor'));

        $byUser = $repo->findByUser($this->context, self::TENANT, 'buyeruser001');
        self::assertIsArray($byUser);
        self::assertSame(1, $byUser['orders_count']);
        self::assertSame(1000, $byUser['total_spent_minor']);

        $byEmail = $repo->findByEmail($this->context, self::TENANT, 'guest@example.com');
        self::assertIsArray($byEmail);
        self::assertSame(1, $byEmail['orders_count']);
        self::assertSame(2000, $byEmail['total_spent_minor']);
    }

    public function testCustomerAggregationDetailIsNullForADraftOnlyCustomer(): void
    {
        $repo = new CustomerAggregationRepository();
        $this->seedOrder('draftorder20', 'draft', ['email' => 'draftonly@example.com', 'user_uuid' => 'draftuser001']);

        self::assertNull($repo->findByUser($this->context, self::TENANT, 'draftuser001'));
        self::assertNull($repo->findByEmail($this->context, self::TENANT, 'draftonly@example.com'));
    }

    public function testSalesReportExcludesDraftsFromEveryFigureIncludingPendingOrders(): void
    {
        $day = gmdate('Y-m-d');
        $this->seedOrder('draftorder21', 'draft', [
            'grand_total' => 90000,
            'placed_at' => $day . ' 10:00:00',
            'created_at' => $day . ' 10:00:00',
        ]);
        $this->seedOrder('realorder008', 'paid', [
            'order_number' => 'ORD-000108',
            'grand_total' => 1000,
            'placed_at' => $day . ' 11:00:00',
            'created_at' => $day . ' 11:00:00',
        ]);
        $this->seedOrder('realorder009', 'pending_payment', [
            'order_number' => 'ORD-000109',
            'grand_total' => 500,
            'placed_at' => $day . ' 12:00:00',
            'created_at' => $day . ' 12:00:00',
        ]);

        $result = (new SalesReportRepository())->salesByDay(
            $this->context,
            self::TENANT,
            ReportWindow::fromDates($day, $day)
        );

        self::assertSame(1, $result['orders'][$day]['orders_count']);
        self::assertSame(1000, $result['orders'][$day]['gross_minor']);
        self::assertSame(1, $result['pending_orders']);
    }

    public function testProductSalesReportExcludesDrafts(): void
    {
        $day = gmdate('Y-m-d');
        $variantUuid = $this->seedVariantForProduct('prodreport01', 'varreport001', 'SKU-REPORT-ISO');
        $this->seedOrder('draftorder22', 'draft', [
            'placed_at' => $day . ' 10:00:00',
            'created_at' => $day . ' 10:00:00',
        ]);
        $this->seedOrder('realorder010', 'paid', [
            'order_number' => 'ORD-000110',
            'placed_at' => $day . ' 11:00:00',
            'created_at' => $day . ' 11:00:00',
        ]);
        $this->seedLine('linereport01', 'draftorder22', $variantUuid, 90000);
        $this->seedLine('linereport02', 'realorder010', $variantUuid, 1000);

        $result = (new ProductSalesReportRepository())->paginate(
            $this->context,
            self::TENANT,
            ReportWindow::fromDates($day, $day),
            'revenue',
            1,
            50
        );

        self::assertSame(1, $result['total']);
        self::assertSame(1000, $result['items'][0]['revenue_minor']);
    }

    public function testCustomerReportExcludesDrafts(): void
    {
        $day = gmdate('Y-m-d');
        // A draft-only customer must never be counted as a new customer.
        $this->seedOrder('draftorder23', 'draft', [
            'email' => 'draftcustomer@example.com',
            'placed_at' => $day . ' 10:00:00',
            'created_at' => $day . ' 10:00:00',
        ]);
        $this->seedOrder('realorder011', 'paid', [
            'order_number' => 'ORD-000111',
            'email' => 'realcustomer@example.com',
            'placed_at' => $day . ' 11:00:00',
            'created_at' => $day . ' 11:00:00',
        ]);

        $result = (new CustomerReportRepository())->bucketCounts(
            $this->context,
            self::TENANT,
            ReportWindow::fromDates($day, $day)
        );

        self::assertSame(1, $result['summary']['new_customers']);
        self::assertSame(0, $result['summary']['returning_customers']);
    }

    // -----------------------------------------------------------------
    // Expiry
    // -----------------------------------------------------------------

    public function testExpiryServiceNeverTouchesDrafts(): void
    {
        $ancient = gmdate('Y-m-d H:i:s', time() - (365 * 86400));
        $this->seedOrder('draftorder24', 'draft', ['placed_at' => $ancient, 'created_at' => $ancient]);
        $this->seedOrder('realorder012', 'pending_payment', [
            'order_number' => 'ORD-000112',
            'placed_at' => $ancient,
            'created_at' => $ancient,
        ]);

        $expired = (new ExpiryService(
            new OrderRepository(),
            new StockRepository(),
            new SentinelTenantResolver()
        ))->expireStale($this->context);

        self::assertSame(1, $expired);
        self::assertSame('draft', $this->statusOf('draftorder24'));
        self::assertSame('canceled', $this->statusOf('realorder012'));
        self::assertSame([], $this->eventTypesFor('draftorder24'));
    }

    // -----------------------------------------------------------------
    // HTTP surfaces
    // -----------------------------------------------------------------

    public function testStorefrontMineAndShowCannotSeeOrResolveDrafts(): void
    {
        $this->seedOrder('draftorder25', 'draft', [
            'user_uuid' => 'storeuser001',
            'email' => 'store@example.com',
            'order_number' => 'ORD-DRAFT-25',
        ]);
        $this->seedOrder('realorder013', 'paid', [
            'order_number' => 'ORD-000113',
            'user_uuid' => 'storeuser001',
            'email' => 'store@example.com',
        ]);

        $request = Request::create('/commerce/orders', 'GET');
        $request->attributes->set('user', ['uuid' => 'storeuser001']);

        $payload = $this->json($this->storefrontController()->mine(new OrderListQuery(), $request));
        self::assertSame(1, $payload['total']);
        self::assertSame(['realorder013'], array_column($payload['data'], 'uuid'));

        $showRequest = Request::create('/commerce/orders/ORD-DRAFT-25', 'GET');
        $showRequest->attributes->set('user', ['uuid' => 'storeuser001']);

        $this->expectException(NotFoundException::class);
        $this->storefrontController()->show($showRequest, 'ORD-DRAFT-25');
    }

    public function testAdminOrdersIndexExcludesDrafts(): void
    {
        $this->seedOrder('draftorder26', 'draft');
        $this->seedOrder('realorder014', 'paid', ['order_number' => 'ORD-000114']);

        $payload = $this->json($this->adminController()->index(
            new OrderListQuery(),
            Request::create('/commerce/admin/orders', 'GET')
        ));

        self::assertSame(1, $payload['total']);
        self::assertSame(['realorder014'], array_column($payload['data'], 'uuid'));

        $filtered = $this->json($this->adminController()->index(
            new OrderListQuery(status: 'draft'),
            Request::create('/commerce/admin/orders?status=draft', 'GET')
        ));
        self::assertSame(0, $filtered['total']);
    }

    public function testAdminOrderShowAndCancelRefuseADraft(): void
    {
        $this->seedOrder('draftorder27', 'draft');

        try {
            $this->adminController()->show(Request::create('/commerce/admin/orders/draftorder27', 'GET'), 'draftorder27');
            self::fail('the finalized-order admin surface must not resolve a draft.');
        } catch (NotFoundException $e) {
            self::assertSame('Resource not found.', $e->getMessage());
        }

        try {
            $this->adminController()->cancel(
                Request::create('/commerce/admin/orders/draftorder27/cancel', 'POST'),
                'draftorder27'
            );
            self::fail('the finalized-order cancel surface must not resolve a draft.');
        } catch (NotFoundException $e) {
            self::assertSame('Resource not found.', $e->getMessage());
        }

        self::assertSame('draft', $this->statusOf('draftorder27'));
    }

    public function testAdminRefundSurfacesRefuseDrafts(): void
    {
        $this->seedOrder('draftorder28', 'draft', ['grand_total' => 5000]);

        $this->expectException(NotFoundException::class);

        $this->adminRefundController()->index(
            Request::create('/commerce/admin/orders/draftorder28/refunds', 'GET'),
            'draftorder28'
        );
    }

    // -----------------------------------------------------------------
    // Number-less drafts
    // -----------------------------------------------------------------

    public function testDraftsWithoutOrderNumbersBreakNothingThatFormatsNumbers(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->seedOrder('draftnonum' . $i . '1', 'draft', ['order_number' => null, 'email' => null]);
        }
        $this->seedOrder('realorder015', 'paid', ['order_number' => 'ORD-000115']);

        // Number allocation is sequence-driven and completely unaffected.
        $generator = new OrderNumberGenerator();
        self::assertSame('ORD-000001', $generator->next($this->context, self::TENANT));
        self::assertSame('ORD-000002', $generator->next($this->context, self::TENANT));

        // Every number-formatting surface renders without touching a NULL number.
        $payload = $this->json($this->adminController()->index(
            new OrderListQuery(),
            Request::create('/commerce/admin/orders', 'GET')
        ));
        self::assertSame(['ORD-000115'], array_column($payload['data'], 'order_number'));

        $customers = (new CustomerAggregationRepository())
            ->paginate($this->context, self::TENANT, [], 'last_order_at', 'desc', 1, 50);
        self::assertSame(1, $customers['total']);
    }

    public function testTheSharedPredicateIsTheOneDraftStatusAuthority(): void
    {
        self::assertSame('draft', OrderScope::DRAFT);
        self::assertSame("status <> 'draft'", OrderScope::excludeDraftsSql());
        self::assertSame("o.status <> 'draft'", OrderScope::excludeDraftsSql('o'));
        // The positive form the two dedicated CAS writes interpolate, so the
        // literal 'draft' is written exactly once in production code.
        self::assertSame("status = 'draft'", OrderScope::isDraftSql());
        self::assertSame("commerce_orders.status = 'draft'", OrderScope::isDraftSql('commerce_orders'));
    }

    /**
     * Marketplace reconciliation (review fix, matrix completeness): a completed
     * refund is joined through `commerce_orders`, so an (impossible today, but
     * structurally reachable) draft-owned refund must not be scanned. The
     * partitioned PAID control proves the scan itself is live -- without it,
     * an empty report would be indistinguishable from a broken query.
     */
    public function testMarketplaceReconciliationNeverScansADraftOwnedRefund(): void
    {
        $this->seedOrder('draftorder30', 'draft', ['marketplace_partitioned' => true]);
        $this->seedOrder('realorder016', 'paid', [
            'order_number' => 'ORD-000116',
            'marketplace_partitioned' => true,
        ]);
        $this->seedCompletedRefund('refunddraft1', 'draftorder30', 500);
        $this->seedCompletedRefund('refundreal01', 'realorder016', 500);

        $report = (new ReconciliationService())->scan($this->context, self::TENANT);

        $flaggedOrders = array_values(array_unique(array_merge(
            array_column($report['missing'], 'order_uuid'),
            array_column($report['duplicate'], 'order_uuid'),
            array_column($report['mismatched'], 'order_uuid')
        )));

        self::assertContains('realorder016', $flaggedOrders, 'the paid control must be scanned');
        self::assertNotContains('draftorder30', $flaggedOrders);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $overrides */
    private function seedOrder(string $uuid, string $status, array $overrides = []): void
    {
        $isDraft = $status === 'draft';
        $this->connection->table('commerce_orders')->insert(array_merge([
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT,
            'order_number' => $isDraft ? null : 'ORD-' . $uuid,
            'status' => $status,
            'email' => $isDraft ? null : 'buyer@example.com',
            'guest_token_hash' => $isDraft ? null : str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
            'origin' => $isDraft ? 'admin' : 'storefront',
            'fulfillment_mode' => $isDraft ? 'in_store' : 'delivery',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ], $overrides));
    }

    private function seedVariantForProduct(string $productUuid, string $variantUuid, string $sku): string
    {
        $this->connection->table('commerce_products')->insert([
            'uuid' => $productUuid,
            'tenant_uuid' => self::TENANT,
            'name' => 'Isolation product',
            'slug' => 'isolation-' . $productUuid,
            'status' => 'active',
        ]);
        $this->connection->table('commerce_variants')->insert([
            'uuid' => $variantUuid,
            'tenant_uuid' => self::TENANT,
            'product_uuid' => $productUuid,
            'sku' => $sku,
            'option_values' => '[]',
            'price' => 1000,
            'currency' => 'USD',
        ]);

        return $variantUuid;
    }

    private function seedCompletedRefund(string $uuid, string $orderUuid, int $amount): void
    {
        $this->connection->table('commerce_refunds')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT,
            'order_uuid' => $orderUuid,
            'idempotency_key' => 'idem-' . $uuid,
            'request_fingerprint' => str_repeat('f', 64),
            'amount' => $amount,
            'currency' => 'USD',
            'method' => 'manual',
            'status' => 'completed',
            'completed_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function seedLine(string $uuid, string $orderUuid, string $variantUuid, int $lineTotal): void
    {
        $this->connection->table('commerce_order_lines')->insert([
            'uuid' => $uuid,
            'order_uuid' => $orderUuid,
            'variant_uuid' => $variantUuid,
            'product_name' => 'Isolation product',
            'sku' => 'SKU-' . $uuid,
            'option_values' => '[]',
            'unit_price' => $lineTotal,
            'quantity' => 1,
            'line_total' => $lineTotal,
        ]);
    }

    /** @return array<string,mixed> */
    private function orderRow(string $uuid): array
    {
        $row = $this->connection->table('commerce_orders')->where('uuid', '=', $uuid)->first();
        self::assertIsArray($row);

        return $row;
    }

    private function statusOf(string $uuid): string
    {
        return (string) $this->orderRow($uuid)['status'];
    }

    /** @return list<string> */
    private function eventTypesFor(string $uuid): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['type'],
            $this->connection->table('commerce_order_events')
                ->where('order_uuid', '=', $uuid)
                ->orderBy('id', 'ASC')
                ->get()
        );
    }

    private function storefrontController(): OrderController
    {
        return new OrderController(
            $this->context,
            new OrderRepository(),
            $this->checkout(),
            new SentinelTenantResolver(),
            new RefundRepository()
        );
    }

    private function adminController(): AdminOrderController
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

    private function adminRefundController(): AdminRefundController
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

    private function checkout(): CheckoutService
    {
        $cart = new CartService(
            new CartRepository(),
            new VariantRepository(),
            new ProductRepository(),
            new StockRepository(),
            new DiscountRepository(),
            new PricingEngine(),
            new SentinelTenantResolver()
        );

        return new CheckoutService(
            $cart,
            new DiscountRepository(),
            new DiscountService(new DiscountRepository(), new SentinelTenantResolver()),
            new StockRepository(),
            new PricingEngine(),
            new class implements ShippingRateProvider {
                /** @param list<array<string,mixed>> $lines @param array<string,mixed> $shippingAddress */
                public function quote(ApplicationContext $context, array $lines, array $shippingAddress): array
                {
                    return [new ShippingQuote('std', 'Standard', 500)];
                }
            },
            new class implements TaxCalculator {
                /** @param array<string,mixed> $shippingAddress */
                public function quote(
                    ApplicationContext $context,
                    int $taxableAmount,
                    array $shippingAddress
                ): TaxQuote {
                    return new TaxQuote(0);
                }
            },
            new OrderNumberGenerator(),
            new OrderRepository(),
            new DownloadRepository(),
            new ManualPaymentCollector(),
            new SentinelTenantResolver()
        );
    }

    /** @return array<string,mixed> */
    private function json(\Symfony\Component\HttpFoundation\Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
