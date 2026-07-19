<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\Admin\AdminPayoutController;
use Glueful\Extensions\Commerce\Http\Seller\SellerFinancialController;
use Glueful\Extensions\Commerce\Marketplace\AdjustmentService;
use Glueful\Extensions\Commerce\Marketplace\LedgerAccountLock;
use Glueful\Extensions\Commerce\Marketplace\LedgerRepository;
use Glueful\Extensions\Commerce\Marketplace\MarketplaceMode;
use Glueful\Extensions\Commerce\Marketplace\PayoutAccountRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutAccountService;
use Glueful\Extensions\Commerce\Marketplace\PayoutRepository;
use Glueful\Extensions\Commerce\Marketplace\PayoutService;
use Glueful\Extensions\Commerce\Marketplace\SellerBalanceService;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Reports\SellerFinancialReportRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceRouterTestCase;
use Glueful\Extensions\Contracts\Payments\DestinationStatus;
use Glueful\Extensions\Contracts\Payments\PayoutCollector;
use Glueful\Extensions\Contracts\Payments\PayoutDestination;
use Glueful\Extensions\Contracts\Payments\PayoutRequest;
use Glueful\Extensions\Contracts\Payments\PayoutResult;
use Glueful\Extensions\Contracts\Payments\PayoutStatusResult;
use Glueful\Helpers\Utils;
use Glueful\Routing\Middleware\RequireScopeMiddleware;
use Glueful\Routing\RouteMiddleware;
use Glueful\Routing\Router;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Operator + seller HTTP surfaces for provider payouts (design spec §2.3/§2.6/§2.7/§2.8/§6.2,
 * MV4 Task 10), exercised over REAL routes through a REAL {@see Router} + middleware pipeline --
 * mirroring {@see SellerFinancialSurfaceTest}'s convention for the seller side, extended with a
 * genuine `commerce:write`-scoped `auth`/`require_scope` fake for the admin side (this repo has
 * no prior router-based admin test harness -- {@see OperatorFinancialSurfaceTest}'s own docblock
 * notes this and dispatches admin controllers in-process instead; this suite is the first to
 * build the real thing, since Task 10 explicitly requires exercising these routes end-to-end).
 *
 * Covers: operator single-seller execute/retry/attach/sync; `PayoutException` (a plain
 * `\DomainException` with no framework HTTP mapping) surfacing as `422` via the controller's own
 * explicit catch, never a 500; the seller's sanitized payout projection + payout-account
 * readiness read; that raw provider internals (failure_reason/idempotency_key/destination_ref/
 * account_ref) never leak to a seller; that the pre-existing manual-payout projection shape is
 * unchanged; cross-seller/unknown non-revealing 404s; and that no batch/reverse HTTP surface
 * exists anywhere (design spec §2.6/§2.8 -- CLI/provider-reported only).
 */
final class PayoutSurfaceTest extends CommerceRouterTestCase
{
    private const PROVIDER = 'default';

    private LedgerRepository $ledger;
    private PayoutRepository $payoutsRepo;
    private PayoutAccountRepository $payoutAccountsRepo;
    private SellerBalanceService $balances;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = new LedgerRepository();
        $this->payoutsRepo = new PayoutRepository();
        $this->payoutAccountsRepo = new PayoutAccountRepository();
        $this->balances = new SellerBalanceService($this->ledger);

        $this->enableMarketplace();
        $this->activateWorkspace();
        $this->context->overrideConfig('commerce.marketplace.payouts.default_provider', self::PROVIDER);
    }

    // -----------------------------------------------------------------
    // Operator: execute (design spec §2.3/§2.6).
    // -----------------------------------------------------------------

    public function testOperatorExecutesPayoutOverRealRouteAndReturnsThePayout(): void
    {
        $seller = $this->seedSeller('surf-exec', 'ownerSURFEX01');
        $this->seedAvailable($seller['uuid'], 5000);
        $this->seedReadyAccount($seller['uuid'], self::PROVIDER, 'acct-exec-ref');

        $collector = new SurfaceFakePayoutCollector(transferQueue: [new PayoutResult(PayoutResult::PAID, 'prov-exec-1')]);
        $router = $this->freshRouter($collector);

        $response = $this->dispatch($router, $this->operatorRequest(
            'POST',
            '/commerce/admin/marketplace/payouts/execute',
            ['seller_uuid' => $seller['uuid'], 'currency' => 'USD', 'amount' => 1200]
        ));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response)['data'];
        self::assertSame('paid', $body['status']);
        self::assertSame(1200, (int) $body['amount']);
        self::assertSame('provider', $body['method']);
        self::assertSame('prov-exec-1', $body['provider_ref']);
        self::assertCount(1, $collector->transferCalls);
    }

    public function testOperatorExecuteWithInsufficientAvailableIs422NotServerError(): void
    {
        $seller = $this->seedSeller('surf-nsf', 'ownerSURFNSF1');
        $this->seedAvailable($seller['uuid'], 100);
        $this->seedReadyAccount($seller['uuid'], self::PROVIDER, 'acct-nsf-ref');

        $router = $this->freshRouter(new SurfaceFakePayoutCollector());

        $response = $this->dispatch($router, $this->operatorRequest(
            'POST',
            '/commerce/admin/marketplace/payouts/execute',
            ['seller_uuid' => $seller['uuid'], 'currency' => 'USD', 'amount' => 5000]
        ));

        self::assertSame(422, $response->getStatusCode(), 'PayoutException must map to 422, never a 500.');
        $body = $this->json($response);
        self::assertFalse($body['success']);
        self::assertStringContainsString('exceeds available balance', $body['error']['details']['payout']);
    }

    public function testOperatorExecuteWithNoReadyDestinationIs422NotServerError(): void
    {
        $seller = $this->seedSeller('surf-nrd', 'ownerSURFNRD1');
        $this->seedAvailable($seller['uuid'], 5000);
        // No account attached at all.

        $router = $this->freshRouter(new SurfaceFakePayoutCollector());

        $response = $this->dispatch($router, $this->operatorRequest(
            'POST',
            '/commerce/admin/marketplace/payouts/execute',
            ['seller_uuid' => $seller['uuid'], 'currency' => 'USD', 'amount' => 500]
        ));

        self::assertSame(422, $response->getStatusCode(), 'PayoutException must map to 422, never a 500.');
        $body = $this->json($response);
        self::assertStringContainsString('No ready payout destination', $body['error']['details']['payout']);
    }

    // -----------------------------------------------------------------
    // Operator: retry a specific payout (design spec §2.6).
    // -----------------------------------------------------------------

    public function testOperatorRetriesAFailedRetryablePayoutBeforeItIsDueWhileSweepStillSkipsIt(): void
    {
        $seller = $this->seedSeller('surf-retry', 'ownerSURFRT01');
        $this->seedAvailable($seller['uuid'], 5000);
        $this->seedReadyAccount($seller['uuid'], self::PROVIDER, 'acct-retry-ref');

        $collector = new SurfaceFakePayoutCollector(transferQueue: [
            new PayoutResult(PayoutResult::RETRYABLE_FAILURE, null, 'card_declined', 'poisonRAWFAILREASON01'),
            new PayoutResult(PayoutResult::PAID, 'prov-retry-2'),
        ]);
        $router = $this->freshRouter($collector);

        $executed = $this->dispatch($router, $this->operatorRequest(
            'POST',
            '/commerce/admin/marketplace/payouts/execute',
            ['seller_uuid' => $seller['uuid'], 'currency' => 'USD', 'amount' => 700]
        ));
        self::assertSame(200, $executed->getStatusCode());
        $payoutUuid = (string) $this->json($executed)['data']['uuid'];

        $failedRow = $this->payoutsRepo->findByUuid($this->context, $this->tenant, $payoutUuid);
        self::assertNotNull($failedRow);
        self::assertSame('failed', $failedRow['status']);
        self::assertTrue((bool) $failedRow['retryable']);
        self::assertSame(1, (int) $failedRow['attempt_count']);
        self::assertGreaterThan(
            time(),
            strtotime((string) $failedRow['next_attempt_at']),
            'the backoff window must still be in the future -- this test proves the OPERATOR path '
                . 'works despite that, never that the row happened to already be due.'
        );

        // The retry SWEEP's own due-selection (design spec §2.6) must still skip this row: the
        // backoff window has not elapsed, and the sweep NEVER bypasses the due-time gate -- only
        // the operator retry below does.
        $maxAttempts = (int) config($this->context, 'commerce.marketplace.payouts.max_attempts', 5);
        self::assertSame(
            [],
            $this->payoutsRepo->dueForRetry($this->context, $this->tenant, $maxAttempts),
            'the sweep must never claim a not-yet-due retryable payout.'
        );

        // No forcePastDue(): the operator retry endpoint must succeed on its own, bypassing the
        // due-time gate (design spec §2.6) -- the whole point of a manual operator retry is
        // retrying NOW rather than waiting out the backoff window.
        $retried = $this->dispatch($router, $this->operatorRequest(
            'POST',
            "/commerce/admin/marketplace/payouts/{$payoutUuid}/retry"
        ));

        self::assertSame(200, $retried->getStatusCode());
        $body = $this->json($retried)['data'];
        self::assertSame('paid', $body['status']);
        self::assertSame(2, (int) $body['attempt_count'], 'the retry CAS must increment attempt_count exactly once.');
        self::assertCount(2, $collector->transferCalls);
    }

    public function testOperatorRetryRejectsATerminalOrExhaustedPayoutWith422(): void
    {
        $seller = $this->seedSeller('surf-term', 'ownerSURFTM01');
        $this->seedAvailable($seller['uuid'], 5000);
        $this->seedReadyAccount($seller['uuid'], self::PROVIDER, 'acct-term-ref');

        $collector = new SurfaceFakePayoutCollector(transferQueue: [
            new PayoutResult(PayoutResult::TERMINAL_FAILURE, null, 'account_closed', 'poisonTERMRAW01'),
        ]);
        $router = $this->freshRouter($collector);

        $executed = $this->dispatch($router, $this->operatorRequest(
            'POST',
            '/commerce/admin/marketplace/payouts/execute',
            ['seller_uuid' => $seller['uuid'], 'currency' => 'USD', 'amount' => 400]
        ));
        self::assertSame(200, $executed->getStatusCode());
        $payoutUuid = (string) $this->json($executed)['data']['uuid'];

        $row = $this->payoutsRepo->findByUuid($this->context, $this->tenant, $payoutUuid);
        self::assertNotNull($row);
        self::assertSame('failed', $row['status']);
        self::assertFalse((bool) $row['retryable'], 'a TERMINAL_FAILURE must never be retryable.');

        $response = $this->dispatch($router, $this->operatorRequest(
            'POST',
            "/commerce/admin/marketplace/payouts/{$payoutUuid}/retry"
        ));

        self::assertSame(422, $response->getStatusCode());
        $body = $this->json($response);
        self::assertStringContainsString('create a new payout', $body['error']['details']['payout']);

        // Never resurrected: the row is untouched.
        $after = $this->payoutsRepo->findByUuid($this->context, $this->tenant, $payoutUuid);
        self::assertSame('failed', $after['status']);
        self::assertSame(1, (int) $after['attempt_count']);
    }

    public function testOperatorRetryOfAnUnknownPayoutUuidIs422NotFoundOrServerError(): void
    {
        $router = $this->freshRouter(new SurfaceFakePayoutCollector());

        $response = $this->dispatch($router, $this->operatorRequest(
            'POST',
            '/commerce/admin/marketplace/payouts/doesNotExist0/retry'
        ));

        // Not a 500: an unmatched retry CAS (no such row) is treated identically to an
        // exhausted/terminal one -- claimRetryableForAttempt() simply affects 0 rows.
        self::assertSame(422, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Operator: attach + sync a payout account (design spec §2.7).
    // -----------------------------------------------------------------

    public function testOperatorAttachesAndSyncsAPayoutAccountOverRealRoutes(): void
    {
        $seller = $this->seedSeller('surf-acct', 'ownerSURFAC01');

        $collector = new SurfaceFakePayoutCollector(inspectQueue: [new DestinationStatus(DestinationStatus::READY)]);
        $router = $this->freshRouter($collector);

        $attached = $this->dispatch($router, $this->operatorRequest(
            'POST',
            '/commerce/admin/marketplace/payouts/accounts',
            ['seller_uuid' => $seller['uuid'], 'provider' => self::PROVIDER, 'account_ref' => 'acct-attach-1']
        ));
        self::assertSame(200, $attached->getStatusCode());
        $attachedBody = $this->json($attached)['data'];
        self::assertSame('pending', $attachedBody['readiness_state']);
        self::assertSame('acct-attach-1', $attachedBody['account_ref']);

        $synced = $this->dispatch($router, $this->operatorRequest(
            'POST',
            '/commerce/admin/marketplace/payouts/accounts/sync',
            ['seller_uuid' => $seller['uuid'], 'provider' => self::PROVIDER]
        ));
        self::assertSame(200, $synced->getStatusCode());
        $syncedBody = $this->json($synced)['data'];
        self::assertSame('ready', $syncedBody['readiness_state']);
        self::assertNotNull($syncedBody['last_synced_at']);
        self::assertCount(1, $collector->inspectCalls);
        self::assertSame('acct-attach-1', $collector->inspectCalls[0]['accountRef']);
    }

    // -----------------------------------------------------------------
    // No batch / no reverse HTTP surface (design spec §2.6/§2.8).
    // -----------------------------------------------------------------

    public function testNoBatchOrReverseRouteExistsOnTheOperatorSurface(): void
    {
        $router = $this->freshRouter(new SurfaceFakePayoutCollector());

        foreach ([
            '/commerce/admin/marketplace/payouts/batch',
            '/commerce/admin/marketplace/payouts/run-batch',
            '/commerce/admin/marketplace/payouts/somePayout01/reverse',
            '/commerce/admin/marketplace/payouts/somePayout01/clawback',
        ] as $path) {
            $response = $this->dispatch($router, $this->operatorRequest('POST', $path));
            self::assertContains(
                $response->getStatusCode(),
                [404, 405],
                "{$path} must not be a registered mutation route."
            );
        }
    }

    // -----------------------------------------------------------------
    // Seller: sanitized payout projection (design spec §6.2).
    // -----------------------------------------------------------------

    public function testSellerSeesOwnSanitizedProviderPayoutProjection(): void
    {
        $seller = $this->seedSeller('surf-sproj', 'ownerSURFSP01');
        $this->seedAvailable($seller['uuid'], 5000);
        $this->seedReadyAccount($seller['uuid'], self::PROVIDER, 'acct-sproj-ref');

        $collector = new SurfaceFakePayoutCollector(transferQueue: [
            new PayoutResult(PayoutResult::RETRYABLE_FAILURE, null, 'card_declined', 'raw-provider-text-never-shown'),
        ]);
        $router = $this->freshRouter($collector);

        $executed = $this->dispatch($router, $this->operatorRequest(
            'POST',
            '/commerce/admin/marketplace/payouts/execute',
            ['seller_uuid' => $seller['uuid'], 'currency' => 'USD', 'amount' => 900]
        ));
        self::assertSame(200, $executed->getStatusCode());

        $response = $this->dispatch($router, $this->requestAs(
            'ownerSURFSP01',
            'GET',
            "/commerce/seller/{$seller['uuid']}/payouts"
        ));

        self::assertSame(200, $response->getStatusCode());
        $row = $this->json($response)['data'][0];

        self::assertSame('failed', $row['status']);
        self::assertSame(self::PROVIDER, $row['provider']);
        self::assertSame('card_declined', $row['failure_code']);
        self::assertSame('The destination declined the transfer.', $row['failure_message']);
        self::assertNull($row['external_ref'], 'a provider row has no caller-supplied external_ref.');
        // An operator-executed payout DOES carry the resolved actor (unlike a CLI-scheduled
        // batch row, see testProviderRowRendersNullableExternalRefAndCreatedByWithoutCrashing).
        self::assertSame('operatorSURF01', $row['created_by']);
        self::assertArrayNotHasKey('failure_reason', $row);
        self::assertArrayNotHasKey('idempotency_key', $row);
        self::assertArrayNotHasKey('destination_ref', $row);
        self::assertStringNotContainsString(
            'raw-provider-text-never-shown',
            (string) $response->getContent(),
            'the raw provider failure_reason must never reach the seller.'
        );
    }

    public function testSellerSeesOwnPayoutAccountReadinessAllowlistOnly(): void
    {
        $seller = $this->seedSeller('surf-ready', 'ownerSURFRD01');
        $this->seedReadyAccount($seller['uuid'], 'alpha', 'acct-alpha-ref');
        $this->seedPendingAccount($seller['uuid'], 'beta', 'acct-beta-ref');

        $router = $this->freshRouter(new SurfaceFakePayoutCollector());
        $response = $this->dispatch($router, $this->requestAs(
            'ownerSURFRD01',
            'GET',
            "/commerce/seller/{$seller['uuid']}/payouts/accounts"
        ));

        self::assertSame(200, $response->getStatusCode());
        $rows = $this->json($response)['data'];
        self::assertCount(2, $rows);

        self::assertSame(['provider', 'readiness_state', 'last_synced_at'], array_keys($rows[0]));
        self::assertSame('alpha', $rows[0]['provider']);
        self::assertSame('ready', $rows[0]['readiness_state']);
        self::assertNotNull($rows[0]['last_synced_at']);

        self::assertSame(['provider', 'readiness_state', 'last_synced_at'], array_keys($rows[1]));
        self::assertSame('beta', $rows[1]['provider']);
        self::assertSame('pending', $rows[1]['readiness_state']);
        self::assertNull($rows[1]['last_synced_at']);

        self::assertStringNotContainsString('acct-alpha-ref', (string) $response->getContent());
        self::assertStringNotContainsString('acct-beta-ref', (string) $response->getContent());
    }

    // -----------------------------------------------------------------
    // Poison-string test: raw provider internals never leak to the seller.
    // -----------------------------------------------------------------

    public function testPoisonedProviderInternalsNeverLeakToSellerPayoutsOrReadinessSurfaces(): void
    {
        $seller = $this->seedSeller('surf-poison', 'ownerSURFPO01');
        $poison = 'POISONMARKER-' . Utils::generateNanoID();

        $this->connection->table('commerce_payouts')->insert([
            'uuid' => 'payoutPOISON1',
            'tenant_uuid' => $this->tenant,
            'seller_uuid' => $seller['uuid'],
            'currency' => 'USD',
            'amount' => 500,
            'external_ref' => null,
            'note' => null,
            'created_by' => null,
            'idempotency_key' => $poison . '-idem',
            'status' => 'failed',
            'method' => 'provider',
            'provider' => self::PROVIDER,
            'provider_ref' => null,
            'destination_ref' => $poison . '-dest',
            'failure_code' => 'card_declined',
            'failure_reason' => $poison . '-reason',
            'retryable' => true,
            'attempt_count' => 1,
        ]);

        $this->connection->table('commerce_seller_payout_accounts')->insert([
            'uuid' => 'acctPOISON001',
            'tenant_uuid' => $this->tenant,
            'seller_uuid' => $seller['uuid'],
            'provider' => self::PROVIDER,
            'account_ref' => $poison . '-acctref',
            'readiness_state' => 'ready',
            'last_synced_at' => gmdate('Y-m-d H:i:s'),
            'failure_code' => null,
        ]);

        $router = $this->freshRouter(new SurfaceFakePayoutCollector());

        $payoutsResponse = $this->dispatch($router, $this->requestAs(
            'ownerSURFPO01',
            'GET',
            "/commerce/seller/{$seller['uuid']}/payouts"
        ));
        $readinessResponse = $this->dispatch($router, $this->requestAs(
            'ownerSURFPO01',
            'GET',
            "/commerce/seller/{$seller['uuid']}/payouts/accounts"
        ));

        self::assertSame(200, $payoutsResponse->getStatusCode());
        self::assertSame(200, $readinessResponse->getStatusCode());

        foreach ([$payoutsResponse, $readinessResponse] as $response) {
            self::assertStringNotContainsString($poison . '-idem', (string) $response->getContent());
            self::assertStringNotContainsString($poison . '-dest', (string) $response->getContent());
            self::assertStringNotContainsString($poison . '-reason', (string) $response->getContent());
            self::assertStringNotContainsString($poison . '-acctref', (string) $response->getContent());
        }
    }

    // -----------------------------------------------------------------
    // Regression: the existing manual-payout projection shape is unchanged.
    // -----------------------------------------------------------------

    public function testExistingManualPayoutJsonRemainsByteIdentical(): void
    {
        $seller = $this->seedSeller('surf-manual', 'ownerSURFMN01');
        $this->seedAvailable($seller['uuid'], 5000);

        $this->payoutService(null)->record(
            $this->context,
            $this->tenant,
            $seller['uuid'],
            'USD',
            2500,
            'idem-surf-manual-1',
            'ext-ref-surf-1',
            'a manual note',
            'operatorSURFMN1'
        );

        $router = $this->freshRouter(new SurfaceFakePayoutCollector());
        $response = $this->dispatch($router, $this->requestAs(
            'ownerSURFMN01',
            'GET',
            "/commerce/seller/{$seller['uuid']}/payouts"
        ));

        self::assertSame(200, $response->getStatusCode());
        $row = $this->json($response)['data'][0];

        self::assertSame(2500, $row['amount']);
        self::assertSame('USD', $row['currency']);
        self::assertSame('ext-ref-surf-1', $row['external_ref']);
        self::assertSame('a manual note', $row['note']);
        self::assertSame('operatorSURFMN1', $row['created_by']);
        self::assertSame('paid', $row['status']);
        self::assertNull($row['provider']);
        self::assertNull($row['provider_ref']);
        self::assertNull($row['failure_code']);
        self::assertNull($row['failure_message']);

        self::assertSame(
            ['uuid', 'currency', 'amount', 'external_ref', 'note', 'created_by', 'created_at',
                'status', 'provider', 'provider_ref', 'failure_code', 'failure_message',
            ],
            array_keys($row),
            'the manual payout projection shape must stay exactly this, in this order.'
        );
    }

    public function testProviderRowRendersNullableExternalRefAndCreatedByWithoutCrashing(): void
    {
        $seller = $this->seedSeller('surf-null', 'ownerSURFNL01');

        // A scheduled-batch-shaped provider row (design spec §3.1): no human actor, no
        // caller-supplied external reference.
        $this->connection->table('commerce_payouts')->insert([
            'uuid' => 'payoutNULLABL',
            'tenant_uuid' => $this->tenant,
            'seller_uuid' => $seller['uuid'],
            'currency' => 'USD',
            'amount' => 300,
            'external_ref' => null,
            'note' => null,
            'created_by' => null,
            'idempotency_key' => 'payoutNULLABL',
            'status' => 'paid',
            'method' => 'provider',
            'provider' => self::PROVIDER,
            'provider_ref' => 'prov-null-ref',
            'destination_ref' => 'acct-null-ref',
            'failure_code' => null,
            'retryable' => false,
            'attempt_count' => 1,
        ]);

        $router = $this->freshRouter(new SurfaceFakePayoutCollector());
        $response = $this->dispatch($router, $this->requestAs(
            'ownerSURFNL01',
            'GET',
            "/commerce/seller/{$seller['uuid']}/payouts"
        ));

        self::assertSame(200, $response->getStatusCode());
        $row = $this->json($response)['data'][0];
        self::assertNull($row['external_ref']);
        self::assertNull($row['created_by']);
        self::assertSame('paid', $row['status']);
        self::assertSame(self::PROVIDER, $row['provider']);
        self::assertSame('prov-null-ref', $row['provider_ref']);
    }

    // -----------------------------------------------------------------
    // Cross-seller / unknown -- non-revealing 404.
    // -----------------------------------------------------------------

    public function testCrossSellerAndUnknownSellerPayoutAccountReadinessIs404NonRevealing(): void
    {
        $sellerA = $this->seedSeller('surf-cross-a', 'ownerSURFCA01');
        $sellerB = $this->seedSeller('surf-cross-b', 'ownerSURFCB01');
        $this->seedReadyAccount($sellerA['uuid'], self::PROVIDER, 'acct-cross-a');

        $router = $this->freshRouter(new SurfaceFakePayoutCollector());

        $ownRead = $this->dispatch($router, $this->requestAs(
            'ownerSURFCA01',
            'GET',
            "/commerce/seller/{$sellerA['uuid']}/payouts/accounts"
        ));
        $crossSeller = $this->dispatch($router, $this->requestAs(
            'ownerSURFCA01',
            'GET',
            "/commerce/seller/{$sellerB['uuid']}/payouts/accounts"
        ));
        $unknown = $this->dispatch($router, $this->requestAs(
            'ownerSURFCA01',
            'GET',
            '/commerce/seller/doesNotExist01/payouts/accounts'
        ));

        self::assertSame(200, $ownRead->getStatusCode());
        self::assertSame(404, $crossSeller->getStatusCode());
        self::assertSame(404, $unknown->getStatusCode());
        self::assertSame($this->json($unknown), $this->json($crossSeller));
    }

    // -----------------------------------------------------------------
    // Fixtures + helpers.
    // -----------------------------------------------------------------

    private function seedAvailable(string $sellerUuid, int $amount, string $currency = 'USD'): void
    {
        $this->ledger->post($this->context, $this->tenant, [
            'account_kind' => 'seller',
            'account_key' => LedgerRepository::accountKeyForSeller($sellerUuid),
            'seller_uuid' => $sellerUuid,
            'currency' => $currency,
            'entry_type' => 'sale_credit',
            'amount' => $amount,
            'order_uuid' => 'orderSURFSD' . substr($sellerUuid, -2),
            'idempotency_key' => Utils::generateNanoID() . ':' . $sellerUuid . ':sale_credit',
        ]);
    }

    private function seedReadyAccount(string $sellerUuid, string $provider, string $accountRef): void
    {
        $this->connection->table('commerce_seller_payout_accounts')->insert([
            'uuid' => Utils::generateNanoID(),
            'tenant_uuid' => $this->tenant,
            'seller_uuid' => $sellerUuid,
            'provider' => $provider,
            'account_ref' => $accountRef,
            'readiness_state' => 'ready',
            'last_synced_at' => gmdate('Y-m-d H:i:s'),
            'failure_code' => null,
        ]);
    }

    private function seedPendingAccount(string $sellerUuid, string $provider, string $accountRef): void
    {
        $this->connection->table('commerce_seller_payout_accounts')->insert([
            'uuid' => Utils::generateNanoID(),
            'tenant_uuid' => $this->tenant,
            'seller_uuid' => $sellerUuid,
            'provider' => $provider,
            'account_ref' => $accountRef,
            'readiness_state' => 'pending',
            'last_synced_at' => null,
            'failure_code' => null,
        ]);
    }

    /** Forces a timing column into the past so a CAS/due-select predicate treats it as due. */
    private function forcePastDue(string $payoutUuid, string $column): void
    {
        $this->connection->table('commerce_payouts')
            ->where('uuid', '=', $payoutUuid)
            ->update([$column => gmdate('Y-m-d H:i:s', time() - 3600)]);
    }

    private function payoutService(?PayoutCollector $collector): PayoutService
    {
        return new PayoutService(
            $this->payoutsRepo,
            $this->ledger,
            new LedgerAccountLock(),
            $this->balances,
            new SellerRepository(),
            null,
            $collector,
            new PayoutAccountService($this->payoutAccountsRepo, null, $collector)
        );
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
        $request->headers->set('X-Test-Scopes', 'commerce:write');

        return $request;
    }

    /**
     * Builds a fresh {@see Router} bound to a REAL {@see AdminPayoutController}/
     * {@see SellerFinancialController} pair sharing THIS test's own repositories, plus a fake
     * `auth` (sets BOTH the seller-side `user` array attribute AND the admin-side `auth.user`/
     * `api_key_scopes` attributes {@see \Glueful\Extensions\Commerce\Http\Admin\ResolvesActor}/
     * {@see RequireScopeMiddleware} read) and a REAL `require_scope` middleware -- this repo's
     * {@see CommerceRouterTestCase::freshRouter()} only ever faked the seller side, since no
     * prior suite dispatched a `commerce:write`-gated admin route through the real router.
     */
    protected function freshRouter(?PayoutCollector $collector = null): Router
    {
        $this->bind(ApplicationContext::class, $this->context);
        $this->bind('commerce_seller', $this->buildSellerMiddleware());
        $this->bind('auth', $this->buildScopedAuthMiddleware());
        $this->bind('require_scope', new RequireScopeMiddleware());

        $accountService = new PayoutAccountService($this->payoutAccountsRepo, null, $collector);
        $payoutService = new PayoutService(
            $this->payoutsRepo,
            $this->ledger,
            new LedgerAccountLock(),
            $this->balances,
            new SellerRepository(),
            null,
            $collector,
            $accountService
        );

        $this->bind(SellerFinancialController::class, new SellerFinancialController(
            $this->context,
            new SellerFinancialReportRepository(),
            $this->balances,
            $this->payoutsRepo,
            new MarketplaceMode(),
            $this->fixedTenant(),
            $this->payoutAccountsRepo
        ));

        $this->bind(AdminPayoutController::class, new AdminPayoutController(
            $this->context,
            $payoutService,
            new AdjustmentService($this->ledger, new LedgerAccountLock()),
            $this->fixedTenant(),
            $accountService
        ));

        $router = new Router($this->contextContainer());
        require __DIR__ . '/../../../routes.php';

        return $router;
    }

    /**
     * `X-Test-User` authenticates (seller convention: sets `user`); `X-Test-Scopes` (a
     * comma-separated list) sets `api_key_scopes` for {@see RequireScopeMiddleware} -- both may
     * be present on the SAME request (an operator request also resolves `actorUuid()` via
     * `auth.user`).
     */
    private function buildScopedAuthMiddleware(): RouteMiddleware
    {
        return new class implements RouteMiddleware {
            public function handle(Request $request, callable $next, mixed ...$params): mixed
            {
                $userUuid = $request->headers->get('X-Test-User');
                if ($userUuid === null || $userUuid === '') {
                    return \Glueful\Http\Response::unauthorized('Authentication required');
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

/**
 * Scripted fake payout collector for the surface suite -- distinct name from every other fake
 * in this namespace (e.g. {@see RetryReconcileFakeCollector}, {@see ReadinessFakeCollector}) so
 * multiple test files load in the same PHPUnit process without a class-name collision. Scripts
 * `transfer()` (a queue of PayoutResult|Throwable), `status()` (a queue of
 * PayoutStatusResult|Throwable, unused by this suite but implemented for interface completeness),
 * and `inspectDestination()` (a queue of DestinationStatus).
 */
final class SurfaceFakePayoutCollector implements PayoutCollector
{
    /** @var list<array{idempotencyKey: string}> */
    public array $transferCalls = [];

    /** @var list<array{provider: string, accountRef: string}> */
    public array $inspectCalls = [];

    /**
     * @param list<PayoutResult|\Throwable> $transferQueue
     * @param list<PayoutStatusResult|\Throwable> $statusQueue
     * @param list<DestinationStatus> $inspectQueue
     */
    public function __construct(
        private array $transferQueue = [],
        private array $statusQueue = [],
        private array $inspectQueue = [],
    ) {
    }

    public function transfer(
        ApplicationContext $context,
        PayoutDestination $destination,
        PayoutRequest $request
    ): PayoutResult {
        $this->transferCalls[] = ['idempotencyKey' => $request->idempotencyKey];

        if ($this->transferQueue === []) {
            throw new \RuntimeException('SurfaceFakePayoutCollector transfer queue exhausted.');
        }

        $next = array_shift($this->transferQueue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function status(
        ApplicationContext $context,
        PayoutDestination $destination,
        string $idempotencyKey
    ): PayoutStatusResult {
        if ($this->statusQueue === []) {
            throw new \RuntimeException('SurfaceFakePayoutCollector status queue exhausted.');
        }

        $next = array_shift($this->statusQueue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function inspectDestination(
        ApplicationContext $context,
        PayoutDestination $destination
    ): DestinationStatus {
        $this->inspectCalls[] = ['provider' => $destination->provider, 'accountRef' => $destination->accountRef];

        if ($this->inspectQueue === []) {
            throw new \RuntimeException('SurfaceFakePayoutCollector inspect queue exhausted.');
        }

        return array_shift($this->inspectQueue);
    }
}
