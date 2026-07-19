<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutService;
use Glueful\Extensions\Commerce\Marketplace\SellerBalanceService;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceRouterTestCase;
use Glueful\Helpers\Utils;
use Symfony\Component\HttpFoundation\Request;

/**
 * Seller-scoped financial surfaces (design spec §6.2, MV3 Task 11): windowed
 * financial report, live balance + components, payouts, and the effective
 * commission policy -- over REAL routes, exactly like
 * {@see SellerOrderSurfaceTest} does for the order surfaces. Covers the
 * gross/commission/refunds/reversed/net formula, the report/balance/payouts/
 * commission-policy capability x role matrix, cross-seller/unknown non-
 * revealing 404s, and that no mutation endpoint exists anywhere on this
 * surface.
 *
 * Fixtures seed `commerce_marketplace_ledger` rows by DIRECT insert (never
 * through `LedgerPostingService`) so each test controls its own `created_at`
 * for windowing assertions -- mirrors {@see ReconciliationTest}'s identical
 * direct-row convention for its own ledger fixtures. Payouts are seeded
 * through the REAL {@see PayoutService} (an available balance must actually
 * exist for a payout to record), mirroring {@see ReconciliationTest}'s own
 * `payoutService()` helper.
 */
final class SellerFinancialSurfaceTest extends CommerceRouterTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableMarketplace();
        $this->activateWorkspace();
        $this->bindFakeAuth();
    }

    // -----------------------------------------------------------------
    // Financial report: gross/commission/refunds/reversed/net + windowing.
    // -----------------------------------------------------------------

    public function testSellerSeesOwnFinancialReportWithCorrectNetFormula(): void
    {
        $seller = $this->seedSeller('fin-report', 'ownerFinRep01');
        $this->seedLedgerRow($seller['uuid'], 'sale_credit', 10000, '2026-06-05 10:00:00', 'orderFINREP001');
        $this->seedLedgerRow($seller['uuid'], 'commission_debit', -1000, '2026-06-05 10:00:00', 'orderFINREP001');
        $this->seedLedgerRow($seller['uuid'], 'refund_debit', -2000, '2026-06-06 10:00:00', null, 'refFINREP001');
        $this->seedLedgerRow(
            $seller['uuid'],
            'commission_reversal',
            200,
            '2026-06-06 10:00:00',
            null,
            'refFINREP001'
        );

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerFinRep01',
            'GET',
            "/commerce/seller/{$seller['uuid']}/financials/report"
                . '?from=2026-06-01&to=2026-06-10&currency=USD'
        ));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response)['data'];

        self::assertSame('USD', $body['currency']);
        self::assertSame('2026-06-01', $body['window']['from']);
        self::assertSame('2026-06-10', $body['window']['to']);
        self::assertSame('day', $body['window']['group']);

        $summary = $body['summary'];
        self::assertSame(10000, $summary['gross_minor']);
        self::assertSame(1000, $summary['commission_minor']);
        self::assertSame(2000, $summary['refunds_minor']);
        self::assertSame(200, $summary['commission_reversed_minor']);
        // net = gross - commission - refunds + reversals = 10000 - 1000 - 2000 + 200
        self::assertSame(7200, $summary['net_minor']);
        // live balance = SUM of all signed entries seeded above
        self::assertSame(7200, $summary['balance_minor']);
    }

    public function testFinancialReportWindowingGroupsByWeek(): void
    {
        $seller = $this->seedSeller('fin-week', 'ownerFinWeek1');
        $this->seedLedgerRow($seller['uuid'], 'sale_credit', 1000, '2026-06-01 09:00:00', 'orderFINWEEK01');
        $this->seedLedgerRow($seller['uuid'], 'sale_credit', 2000, '2026-06-02 09:00:00', 'orderFINWEEK02');

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerFinWeek1',
            'GET',
            "/commerce/seller/{$seller['uuid']}/financials/report"
                . '?from=2026-06-01&to=2026-06-07&group=week&currency=USD'
        ));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response)['data'];
        self::assertSame('week', $body['window']['group']);
        self::assertCount(1, $body['series'], 'both days fall in the same ISO week');
        self::assertSame(3000, $body['series'][0]['gross_minor']);
        self::assertSame(3000, $body['summary']['gross_minor']);
    }

    public function testFinancialReportOutsideWindowIsExcludedAndZeroFilled(): void
    {
        $seller = $this->seedSeller('fin-outside', 'ownerFinOut01');
        // Outside the requested window entirely.
        $this->seedLedgerRow($seller['uuid'], 'sale_credit', 5000, '2026-05-01 09:00:00', 'orderFINOUT001');

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerFinOut01',
            'GET',
            "/commerce/seller/{$seller['uuid']}/financials/report"
                . '?from=2026-06-01&to=2026-06-02&currency=USD'
        ));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response)['data'];
        self::assertCount(2, $body['series']);
        foreach ($body['series'] as $bucket) {
            self::assertSame(0, $bucket['gross_minor']);
            self::assertSame(0, $bucket['net_minor']);
        }
        self::assertSame(0, $body['summary']['gross_minor']);
        self::assertSame(0, $body['summary']['net_minor']);
        // the balance is a LIVE figure -- unaffected by the report window --
        // so the seeded, out-of-window sale still shows up here.
        self::assertSame(5000, $body['summary']['balance_minor']);
    }

    // -----------------------------------------------------------------
    // Balance + components, per currency.
    // -----------------------------------------------------------------

    public function testSellerSeesOwnBalanceAndComponentsAcrossAllCurrencies(): void
    {
        $seller = $this->seedSeller('fin-balance', 'ownerFinBal01');
        $this->seedLedgerRow($seller['uuid'], 'sale_credit', 10000, '2026-06-01 09:00:00', 'orderFINBAL001', currency: 'USD');
        $this->seedLedgerRow($seller['uuid'], 'commission_debit', -1000, '2026-06-01 09:00:00', 'orderFINBAL001', currency: 'USD');
        $this->seedLedgerRow($seller['uuid'], 'sale_credit', 5000, '2026-06-01 09:00:00', 'orderFINBAL002', currency: 'EUR');

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerFinBal01',
            'GET',
            "/commerce/seller/{$seller['uuid']}/financials/balance"
        ));

        self::assertSame(200, $response->getStatusCode());
        $balances = $this->json($response)['data']['balances'];
        self::assertCount(2, $balances, 'one entry per distinct ledger currency');

        $byCurrency = [];
        foreach ($balances as $entry) {
            $byCurrency[$entry['currency']] = $entry;
        }

        self::assertSame(9000, $byCurrency['USD']['available']);
        self::assertSame(10000, $byCurrency['USD']['gross_sales']);
        self::assertSame(1000, $byCurrency['USD']['commission']);
        self::assertSame(0, $byCurrency['USD']['reserved']);
        self::assertSame(0, $byCurrency['USD']['paid_out']);
        self::assertSame(0, $byCurrency['USD']['refunds']);
        self::assertSame(0, $byCurrency['USD']['commission_reversed']);
        self::assertSame(0, $byCurrency['USD']['adjustments']);

        self::assertSame(5000, $byCurrency['EUR']['available']);
        self::assertSame(5000, $byCurrency['EUR']['gross_sales']);
    }

    public function testSellerWithNoLedgerActivitySeesAnEmptyBalanceList(): void
    {
        $seller = $this->seedSeller('fin-empty-bal', 'ownerFinEmp01');

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerFinEmp01',
            'GET',
            "/commerce/seller/{$seller['uuid']}/financials/balance"
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->json($response)['data']['balances']);
    }

    // -----------------------------------------------------------------
    // Payouts.
    // -----------------------------------------------------------------

    public function testSellerSeesOwnPayouts(): void
    {
        $seller = $this->seedSeller('fin-payouts', 'ownerFinPay01');
        $this->seedLedgerRow($seller['uuid'], 'sale_credit', 10000, '2026-06-01 09:00:00', 'orderFINPAY001');

        $this->payoutService()->record(
            $this->context,
            $this->tenant,
            $seller['uuid'],
            'USD',
            3000,
            'idem-fin-payout-1',
            'ext-ref-fin-1',
            'first payout',
            'operatorFINPAY1'
        );

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerFinPay01',
            'GET',
            "/commerce/seller/{$seller['uuid']}/payouts"
        ));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame(1, $body['total']);
        self::assertSame(3000, $body['data'][0]['amount']);
        self::assertSame('USD', $body['data'][0]['currency']);
        self::assertSame('ext-ref-fin-1', $body['data'][0]['external_ref']);
        self::assertSame('first payout', $body['data'][0]['note']);
        self::assertArrayNotHasKey('seller_uuid', $body['data'][0], 'redundant -- caller already knows it is their own');
        self::assertArrayNotHasKey('idempotency_key', $body['data'][0], 'internal correctness plumbing, never seller-facing');
    }

    // -----------------------------------------------------------------
    // Effective commission policy (resolved precedence).
    // -----------------------------------------------------------------

    public function testSellerSeesOwnEffectiveCommissionPolicyResolvedFromSellerLevel(): void
    {
        $seller = $this->sellerService()->create(
            $this->context,
            $this->tenant,
            'fin-policy-seller',
            'Fin Policy Seller',
            null,
            'ownerFinPol01'
        );
        $this->connection->table('commerce_sellers')
            ->where('uuid', '=', $seller['uuid'])
            ->update(['commission_kind' => 'fixed', 'commission_fixed' => 250]);

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerFinPol01',
            'GET',
            "/commerce/seller/{$seller['uuid']}/commission-policy"
        ));

        self::assertSame(200, $response->getStatusCode());
        $policy = $this->json($response)['data'];
        self::assertSame('seller', $policy['source']);
        self::assertSame('fixed', $policy['kind']);
        self::assertNull($policy['bps']);
        self::assertSame(250, $policy['fixed']);
    }

    public function testSellerWithNoOverrideSeesTheConfigDefaultCommissionPolicy(): void
    {
        $seller = $this->seedSeller('fin-policy-default', 'ownerFinPolD1');

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerFinPolD1',
            'GET',
            "/commerce/seller/{$seller['uuid']}/commission-policy"
        ));

        self::assertSame(200, $response->getStatusCode());
        $policy = $this->json($response)['data'];
        self::assertSame('config', $policy['source']);
        self::assertSame('percentage', $policy['kind']);
        self::assertSame(0, $policy['bps']);
        self::assertNull($policy['fixed']);
    }

    // -----------------------------------------------------------------
    // Capability x role matrix.
    // -----------------------------------------------------------------

    public function testReportsReadCapabilityAllowsOwnerAdminAnalystButDeniesStaff(): void
    {
        $seller = $this->seedSeller('reports-matrix', 'ownerRepMtx1');
        $this->seedMembership($seller['uuid'], 'adminRepMtx1', 'seller_admin');
        $this->seedMembership($seller['uuid'], 'staffRepMtx1', 'seller_staff');
        $this->seedMembership($seller['uuid'], 'analystRepMt', 'seller_analyst');

        $router = $this->freshRouter();
        $expected = [
            'ownerRepMtx1' => 200,
            'adminRepMtx1' => 200,
            'analystRepMt' => 200,
            'staffRepMtx1' => 403,
        ];
        foreach ($expected as $userUuid => $status) {
            $response = $this->dispatch($router, $this->requestAs(
                $userUuid,
                'GET',
                "/commerce/seller/{$seller['uuid']}/financials/balance"
            ));
            self::assertSame($status, $response->getStatusCode(), "reports.read for {$userUuid}");
        }
    }

    public function testPayoutsReadCapabilityAllowsOwnerAdminAnalystButDeniesStaff(): void
    {
        $seller = $this->seedSeller('payouts-matrix', 'ownerPayMtx1');
        $this->seedMembership($seller['uuid'], 'adminPayMtx1', 'seller_admin');
        $this->seedMembership($seller['uuid'], 'staffPayMtx1', 'seller_staff');
        $this->seedMembership($seller['uuid'], 'analystPayMt', 'seller_analyst');

        $router = $this->freshRouter();
        $expected = [
            'ownerPayMtx1' => 200,
            'adminPayMtx1' => 200,
            'analystPayMt' => 200,
            'staffPayMtx1' => 403,
        ];
        foreach ($expected as $userUuid => $status) {
            $response = $this->dispatch($router, $this->requestAs(
                $userUuid,
                'GET',
                "/commerce/seller/{$seller['uuid']}/payouts"
            ));
            self::assertSame($status, $response->getStatusCode(), "payouts.read for {$userUuid}");
        }
    }

    // -----------------------------------------------------------------
    // Cross-seller / unknown -- non-revealing 404, across every endpoint.
    // -----------------------------------------------------------------

    public function testCrossSellerAndUnknownFinancialSurfacesAreIdenticalNonRevealing404s(): void
    {
        $sellerA = $this->seedSeller('cross-fin-a', 'ownerCrossF1');
        $sellerB = $this->seedSeller('cross-fin-b', 'ownerCrossF2');

        $router = $this->freshRouter();
        $paths = [
            'financials/report',
            'financials/balance',
            'payouts',
            'commission-policy',
        ];

        foreach ($paths as $path) {
            $suffix = $path === 'financials/report' ? '?currency=USD' : '';

            $ownRead = $this->dispatch($router, $this->requestAs(
                'ownerCrossF1',
                'GET',
                "/commerce/seller/{$sellerA['uuid']}/{$path}{$suffix}"
            ));
            // Cross-seller: A requests B's own uuid as the route resource.
            $crossSeller = $this->dispatch($router, $this->requestAs(
                'ownerCrossF1',
                'GET',
                "/commerce/seller/{$sellerB['uuid']}/{$path}{$suffix}"
            ));
            $unknown = $this->dispatch($router, $this->requestAs(
                'ownerCrossF1',
                'GET',
                "/commerce/seller/doesNotExist01/{$path}{$suffix}"
            ));

            self::assertSame(200, $ownRead->getStatusCode(), "{$path}: owner reads their own seller");
            self::assertSame(404, $crossSeller->getStatusCode(), "{$path}: cross-seller must 404");
            self::assertSame(404, $unknown->getStatusCode(), "{$path}: unknown seller must 404");
            self::assertSame(
                $this->json($unknown),
                $this->json($crossSeller),
                "{$path}: cross-seller and unknown-uuid must be byte-identical, never distinguishable"
            );
        }
    }

    // -----------------------------------------------------------------
    // No mutation endpoints on this surface.
    // -----------------------------------------------------------------

    public function testNoMutationVerbIsRegisteredOnAnyFinancialEndpoint(): void
    {
        $seller = $this->seedSeller('no-mutation', 'ownerNoMut001');

        $router = $this->freshRouter();
        foreach (['financials/report', 'financials/balance', 'payouts', 'commission-policy'] as $path) {
            foreach (['POST', 'PATCH', 'PUT', 'DELETE'] as $method) {
                $response = $this->dispatch($router, $this->requestAs(
                    'ownerNoMut001',
                    $method,
                    "/commerce/seller/{$seller['uuid']}/{$path}"
                ));
                self::assertSame(
                    405,
                    $response->getStatusCode(),
                    "{$method} {$path} must not be a registered mutation route"
                );
            }
        }
    }

    // -----------------------------------------------------------------
    // Fixtures + helpers.
    // -----------------------------------------------------------------

    private function seedLedgerRow(
        string $sellerUuid,
        string $entryType,
        int $amount,
        string $createdAt,
        ?string $orderUuid = null,
        ?string $refundUuid = null,
        string $currency = 'USD'
    ): void {
        $this->connection->table('commerce_marketplace_ledger')->insert([
            'uuid' => Utils::generateNanoID(),
            'tenant_uuid' => $this->tenant,
            'account_key' => LedgerRepository::accountKeyForSeller($sellerUuid),
            'account_kind' => 'seller',
            'seller_uuid' => $sellerUuid,
            'currency' => $currency,
            'entry_type' => $entryType,
            'amount' => $amount,
            'order_uuid' => $orderUuid,
            'refund_uuid' => $refundUuid,
            'idempotency_key' => Utils::generateNanoID() . ':' . $entryType,
            'created_at' => $createdAt,
        ]);
    }

    private function payoutService(): PayoutService
    {
        $ledger = new LedgerRepository();

        return new PayoutService(
            new PayoutRepository(),
            $ledger,
            new LedgerAccountLock(),
            new SellerBalanceService($ledger),
            new SellerRepository()
        );
    }

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
}
