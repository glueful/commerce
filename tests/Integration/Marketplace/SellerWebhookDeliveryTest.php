<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Api\Webhooks\WebhookSignature;
use Glueful\Encryption\EncryptionService;
use Glueful\Extensions\Commerce\Console\SweepSellerWebhooksCommand;
use Glueful\Extensions\Commerce\Marketplace\FixedSellerRoleAuthority;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookDeliveryRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookDeliveryService;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookEndpointRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookEndpointService;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookEventRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookSecretService;
use Glueful\Extensions\Commerce\Queue\Jobs\DeliverSellerWebhookJob;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Http\Client;
use Glueful\Http\Security\SafeOutboundTargetResolver;
use Glueful\Queue\QueueManager;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Marketplace MV5c-2 Task 5 (design spec §2.5/§2.6/§2.7/§2.9 -- SECURITY
 * CORE): the signed, SSRF-safe webhook delivery worker -- crash-safe claim
 * lease, token-checked finalize, retry/backoff/dead-letter/auto-disable, and
 * the recovery sweep's per-row reclaim.
 *
 * **Live pgsql orderings (Task 8, NOT reproducible on this single-connection
 * SQLite harness):** delivery-claim vs seller suspension (suspension-first
 * commits before the claim transaction -> refused/paused; claim-commit-
 * first -> in flight, may finish); expired-claim reclaim vs a stale
 * worker's own finalize racing on TWO real connections (this suite proves
 * the token CAS sequentially instead, via a same-connection httpFactory
 * side-effect); auto-disable's endpoint claim vs a concurrent management
 * mutation (rotate-secret/disable/enable) on the SAME endpoint row.
 */
final class SellerWebhookDeliveryTest extends CommerceTestCase
{
    private const TENANT = '';
    private const SAFE_IP = '1.1.1.1';
    private const PRIVATE_IP = '10.0.0.5';
    private const SAFE_HOST = 'whsafe.example.test';

    private int $seq = 0;

    // -----------------------------------------------------------------
    // Claim protocol + ordering (seller -> endpoint -> delivery CAS)
    // -----------------------------------------------------------------

    public function testDeliverClaimsSellerThenEndpointThenSignsAndPostsTheExactStoredBytes(): void
    {
        $seeded = $this->registerActiveEndpoint('sellerDEL00001', 'ownerDEL0001');
        $payload = ['order_uuid' => 'orderDEL0001', 'seller_uuid' => 'sellerDEL00001'];
        $delivery = $this->seedPendingDelivery($seeded['endpoint']['uuid'], 'sellerDEL00001', $payload);
        $sellerRevisionBeforeDeliver = (int) $this->connection->table('commerce_sellers')
            ->where('uuid', '=', 'sellerDEL00001')->first()['revision'];

        $captured = null;
        $service = $this->deliveryService(function (string $method, string $url, array $options) use (&$captured) {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('ok', ['http_code' => 200]);
        });

        $outcome = $service->deliver($this->context, self::TENANT, $delivery['delivery_uuid']);

        self::assertSame('delivered', $outcome);
        self::assertNotNull($captured);
        self::assertSame('POST', $captured['method']);
        self::assertSame($delivery['payload_bytes'], $captured['options']['body']);

        // Seller AND endpoint revisions both advanced -- the claim chain ran
        // (once for the claim phase, again for the finalize phase -- design
        // spec §2.7's own permissive claim-then-finalize-claim protocol).
        $seller = $this->connection->table('commerce_sellers')->where('uuid', '=', 'sellerDEL00001')->first();
        self::assertGreaterThan($sellerRevisionBeforeDeliver, (int) $seller['revision']);
        $endpoint = $this->connection->table('commerce_seller_webhook_endpoints')
            ->where('uuid', '=', $seeded['endpoint']['uuid'])->first();
        self::assertGreaterThanOrEqual(1, (int) $endpoint['revision']);

        $row = $this->deliveryRow($delivery['delivery_uuid']);
        self::assertSame('delivered', $row['status']);
        self::assertNull($row['claim_token']);
        self::assertNull($row['claim_expires_at']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertSame(200, (int) $row['last_status_code']);

        // Exact stored bytes + CURRENT secret are what got signed.
        $headers = $this->parseCapturedHeaders($captured['options']['headers']);
        self::assertSame((string) $delivery['event_uuid'], $headers['X-Webhook-Event-Id']);
        $signature = $headers['X-Webhook-Signature'];
        self::assertTrue(WebhookSignature::verify($delivery['payload_bytes'], $signature, $seeded['secret']));
    }

    public function testSellerSuspendedBeforeClaimRefusesAndNeverReachesTheEndpointClaim(): void
    {
        $seeded = $this->registerActiveEndpoint('sellerDEL00002', 'ownerDEL0002');
        $delivery = $this->seedPendingDelivery(
            $seeded['endpoint']['uuid'],
            'sellerDEL00002',
            ['order_uuid' => 'orderDEL0002']
        );

        $this->connection->table('commerce_sellers')
            ->where('uuid', '=', 'sellerDEL00002')->update(['status' => 'suspended']);
        $endpointRevisionBefore = (int) $this->connection->table('commerce_seller_webhook_endpoints')
            ->where('uuid', '=', $seeded['endpoint']['uuid'])->first()['revision'];

        $httpCalls = 0;
        $service = $this->deliveryService(function () use (&$httpCalls) {
            $httpCalls++;

            return new MockResponse('unused', ['http_code' => 200]);
        });

        $outcome = $service->deliver($this->context, self::TENANT, $delivery['delivery_uuid']);

        self::assertSame('not_claimed', $outcome);
        self::assertSame(0, $httpCalls, 'a suspended seller must never reach HTTP delivery');

        $endpoint = $this->connection->table('commerce_seller_webhook_endpoints')
            ->where('uuid', '=', $seeded['endpoint']['uuid'])->first();
        self::assertSame(
            $endpointRevisionBefore,
            (int) $endpoint['revision'],
            'the endpoint must never be claimed once the seller check already refused'
        );

        $row = $this->deliveryRow($delivery['delivery_uuid']);
        self::assertSame('paused', $row['status']);
        self::assertSame('seller_suspended', $row['pause_reason']);
        self::assertSame(0, (int) $row['attempts'], 'a refused claim must never increment attempts');
    }

    public function testEndpointDisabledRefusesAfterTheSellerWasAlreadyClaimed(): void
    {
        $seeded = $this->registerActiveEndpoint('sellerDEL00003', 'ownerDEL0003');
        $delivery = $this->seedPendingDelivery(
            $seeded['endpoint']['uuid'],
            'sellerDEL00003',
            ['order_uuid' => 'orderDEL0003']
        );

        $this->connection->table('commerce_seller_webhook_endpoints')
            ->where('uuid', '=', $seeded['endpoint']['uuid'])->update(['status' => 'disabled']);

        $sellerRevisionBeforeDeliver = (int) $this->connection->table('commerce_sellers')
            ->where('uuid', '=', 'sellerDEL00003')->first()['revision'];

        $httpCalls = 0;
        $service = $this->deliveryService(function () use (&$httpCalls) {
            $httpCalls++;

            return new MockResponse('unused', ['http_code' => 200]);
        });

        $outcome = $service->deliver($this->context, self::TENANT, $delivery['delivery_uuid']);

        self::assertSame('not_claimed', $outcome);
        self::assertSame(0, $httpCalls);

        // The SELLER was still claimed on the way to the (refused) endpoint check.
        $seller = $this->connection->table('commerce_sellers')->where('uuid', '=', 'sellerDEL00003')->first();
        self::assertGreaterThan($sellerRevisionBeforeDeliver, (int) $seller['revision']);

        $row = $this->deliveryRow($delivery['delivery_uuid']);
        self::assertSame('paused', $row['status']);
        self::assertSame('endpoint_disabled', $row['pause_reason']);
    }

    public function testAnInFlightDeliveryThatClaimedBeforeSuspensionStillFinishesAndRecordsItsResult(): void
    {
        $seeded = $this->registerActiveEndpoint('sellerDEL00004', 'ownerDEL0004');
        $delivery = $this->seedPendingDelivery(
            $seeded['endpoint']['uuid'],
            'sellerDEL00004',
            ['order_uuid' => 'orderDEL0004']
        );

        // The claim (seller -> endpoint -> delivery CAS) commits BEFORE any I/O
        // (design spec §2.9): simulating "suspension commits AFTER the claim" means
        // suspending the seller from INSIDE the httpFactory callback, which only ever
        // runs once the claim transaction has already committed.
        $service = $this->deliveryService(function () {
            $this->connection->table('commerce_sellers')
                ->where('uuid', '=', 'sellerDEL00004')->update(['status' => 'suspended']);

            return new MockResponse('ok', ['http_code' => 200]);
        });

        $outcome = $service->deliver($this->context, self::TENANT, $delivery['delivery_uuid']);

        self::assertSame('delivered', $outcome, 'a claim that committed before suspension must be allowed to finish');
        $row = $this->deliveryRow($delivery['delivery_uuid']);
        self::assertSame('delivered', $row['status']);
    }

    // -----------------------------------------------------------------
    // Retry / backoff / dead-letter / Retry-After
    // -----------------------------------------------------------------

    public function testRetryableFiveHundredSchedulesBackoffPending(): void
    {
        $seeded = $this->registerActiveEndpoint('sellerDEL00005', 'ownerDEL0005');
        $delivery = $this->seedPendingDelivery($seeded['endpoint']['uuid'], 'sellerDEL00005', ['x' => 1]);

        $service = $this->deliveryService(fn () => new MockResponse('err', ['http_code' => 503]));
        $outcome = $service->deliver($this->context, self::TENANT, $delivery['delivery_uuid']);

        self::assertSame('retry_scheduled', $outcome);
        $row = $this->deliveryRow($delivery['delivery_uuid']);
        self::assertSame('pending', $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertSame(503, (int) $row['last_status_code']);
        self::assertNotNull($row['next_attempt_at']);
        self::assertGreaterThan(time(), strtotime((string) $row['next_attempt_at']) + 1);
    }

    public function testTerminalFourZeroFourDeadLettersImmediatelyRegardlessOfAttemptBudget(): void
    {
        $seeded = $this->registerActiveEndpoint('sellerDEL00006', 'ownerDEL0006');
        $delivery = $this->seedPendingDelivery($seeded['endpoint']['uuid'], 'sellerDEL00006', ['x' => 1]);

        $service = $this->deliveryService(fn () => new MockResponse('nope', ['http_code' => 404]));
        $outcome = $service->deliver($this->context, self::TENANT, $delivery['delivery_uuid']);

        self::assertSame('dead_letter', $outcome);
        $row = $this->deliveryRow($delivery['delivery_uuid']);
        self::assertSame('dead_letter', $row['status']);
        self::assertSame(404, (int) $row['last_status_code']);
        self::assertSame(1, (int) $row['attempts']);
    }

    public function testRetryableFailureDeadLettersOnceTheAttemptBudgetIsExhausted(): void
    {
        $this->context->overrideConfig('commerce.marketplace.webhooks.max_attempts', 2);
        $seeded = $this->registerActiveEndpoint('sellerDEL00007', 'ownerDEL0007');
        // Already made 1 attempt -- claiming this one becomes attempt #2 == max_attempts.
        $delivery = $this->seedPendingDelivery($seeded['endpoint']['uuid'], 'sellerDEL00007', ['x' => 1], attempts: 1);

        $service = $this->deliveryService(fn () => new MockResponse('err', ['http_code' => 500]));
        $outcome = $service->deliver($this->context, self::TENANT, $delivery['delivery_uuid']);

        self::assertSame('dead_letter', $outcome);
        $row = $this->deliveryRow($delivery['delivery_uuid']);
        self::assertSame('dead_letter', $row['status']);
        self::assertSame(2, (int) $row['attempts']);
    }

    public function testBoundedRetryAfterOverridesTheComputedBackoffDelay(): void
    {
        $this->context->overrideConfig('commerce.marketplace.webhooks.backoff_cap_seconds', 3600);
        $seeded = $this->registerActiveEndpoint('sellerDEL00008', 'ownerDEL0008');
        $delivery = $this->seedPendingDelivery($seeded['endpoint']['uuid'], 'sellerDEL00008', ['x' => 1]);

        $service = $this->deliveryService(fn () => new MockResponse(
            'slow down',
            ['http_code' => 429, 'response_headers' => ['Retry-After' => '120']]
        ));
        $outcome = $service->deliver($this->context, self::TENANT, $delivery['delivery_uuid']);

        self::assertSame('retry_scheduled', $outcome);
        $row = $this->deliveryRow($delivery['delivery_uuid']);
        $delaySeconds = strtotime((string) $row['next_attempt_at']) - time();
        self::assertGreaterThanOrEqual(115, $delaySeconds);
        self::assertLessThanOrEqual(125, $delaySeconds);
    }

    public function testBoundedRetryAfterIsClampedToTheBackoffCap(): void
    {
        $this->context->overrideConfig('commerce.marketplace.webhooks.backoff_cap_seconds', 60);
        $seeded = $this->registerActiveEndpoint('sellerDEL00009', 'ownerDEL0009');
        $delivery = $this->seedPendingDelivery($seeded['endpoint']['uuid'], 'sellerDEL00009', ['x' => 1]);

        $service = $this->deliveryService(fn () => new MockResponse(
            'slow down',
            ['http_code' => 503, 'response_headers' => ['Retry-After' => '99999']]
        ));
        $service->deliver($this->context, self::TENANT, $delivery['delivery_uuid']);

        $row = $this->deliveryRow($delivery['delivery_uuid']);
        $delaySeconds = strtotime((string) $row['next_attempt_at']) - time();
        self::assertLessThanOrEqual(61, $delaySeconds);
    }

    // -----------------------------------------------------------------
    // Strict SSRF: safeWebhookRequestAsync used; a safety failure is terminal.
    // -----------------------------------------------------------------

    public function testSsrfSafetyFailureAtDeliveryTimeIsTerminalNotRetryableAndNeverHitsTheNetwork(): void
    {
        // Registered against a SAFE dns answer; the DELIVERY-time resolver below
        // simulates a DNS rebind: the SAME host now resolves to a private address.
        $seeded = $this->registerActiveEndpoint('sellerDEL00010', 'ownerDEL0010');
        $delivery = $this->seedPendingDelivery($seeded['endpoint']['uuid'], 'sellerDEL00010', ['x' => 1]);

        $httpCalls = 0;
        $service = $this->deliveryService(
            function () use (&$httpCalls) {
                $httpCalls++;

                return new MockResponse('unused', ['http_code' => 200]);
            },
            dnsMap: [self::SAFE_HOST => [self::PRIVATE_IP]]
        );

        $outcome = $service->deliver($this->context, self::TENANT, $delivery['delivery_uuid']);

        self::assertSame('dead_letter', $outcome, 'an SSRF-safety failure is TERMINAL, never retried');
        self::assertSame(0, $httpCalls, 'a rejected resolution must never reach the mock transport');

        $row = $this->deliveryRow($delivery['delivery_uuid']);
        self::assertSame('dead_letter', $row['status']);
        self::assertStringNotContainsString(self::PRIVATE_IP, (string) $row['last_error']);
        self::assertStringNotContainsString('10.0.0.', (string) $row['last_error']);
    }

    public function testNetworkTransportFailureIsRetryableNotTerminal(): void
    {
        $seeded = $this->registerActiveEndpoint('sellerDEL00011', 'ownerDEL0011');
        $delivery = $this->seedPendingDelivery($seeded['endpoint']['uuid'], 'sellerDEL00011', ['x' => 1]);

        // The raw transport message can embed resolved addresses (curl-style
        // "Failed to connect to <ip> port 443") -- and `last_error` is
        // seller-visible through the deliveries read. Poison the message and
        // assert the persisted error is the bounded generic string.
        $service = $this->deliveryService(function () {
            throw new TransportException('Failed to connect to 10.66.77.88 port 443: internal-gw.corp timeout');
        });

        $outcome = $service->deliver($this->context, self::TENANT, $delivery['delivery_uuid']);

        self::assertSame('retry_scheduled', $outcome);
        $row = $this->deliveryRow($delivery['delivery_uuid']);
        self::assertSame('pending', $row['status']);
        self::assertNotNull($row['next_attempt_at']);
        self::assertSame(
            'Network error contacting the endpoint (connection failed or timed out).',
            (string) $row['last_error'],
            'transport detail must never reach the seller-visible last_error'
        );
        self::assertStringNotContainsString('10.66.77.88', (string) $row['last_error']);
        self::assertStringNotContainsString('internal-gw.corp', (string) $row['last_error']);
    }

    public function testUnexpectedInternalFailureStoresAGenericLastError(): void
    {
        $seeded = $this->registerActiveEndpoint('sellerDEL00026', 'ownerDEL0026');
        $delivery = $this->seedPendingDelivery($seeded['endpoint']['uuid'], 'sellerDEL00026', ['x' => 1]);

        // The \Throwable fail-safe branch: an unexpected internal exception's
        // message (paths, class names, hosts) must never reach the
        // seller-visible last_error either.
        $service = $this->deliveryService(function () {
            throw new \RuntimeException('PDO connection lost at db-internal-9.corp:5432 /var/app/secrets');
        });

        $outcome = $service->deliver($this->context, self::TENANT, $delivery['delivery_uuid']);

        self::assertSame('retry_scheduled', $outcome);
        $row = $this->deliveryRow($delivery['delivery_uuid']);
        self::assertSame('pending', $row['status']);
        self::assertSame(
            'Unexpected delivery error.',
            (string) $row['last_error'],
            'internal exception detail must never reach the seller-visible last_error'
        );
        self::assertStringNotContainsString('db-internal-9.corp', (string) $row['last_error']);
        self::assertStringNotContainsString('/var/app/secrets', (string) $row['last_error']);
    }

    // -----------------------------------------------------------------
    // Expired lease reclaim + stale-token finalizer no-op.
    // -----------------------------------------------------------------

    public function testSweepReclaimsAnExpiredDeliveringLeaseWithoutTouchingAttempts(): void
    {
        $seeded = $this->registerActiveEndpoint('sellerDEL00012', 'ownerDEL0012');
        $deliveryUuid = $this->seedDeliveringDelivery(
            $seeded['endpoint']['uuid'],
            'sellerDEL00012',
            claimToken: 'oldtoken000000001111',
            claimExpiresAt: gmdate('Y-m-d H:i:s', time() - 120),
            attempts: 1
        );

        $service = $this->deliveryService(fn () => new MockResponse('unused', ['http_code' => 200]));
        $outcome = $service->reclaimExpired($this->context, self::TENANT, $deliveryUuid);

        self::assertSame('reclaimed', $outcome);
        $row = $this->deliveryRow($deliveryUuid);
        self::assertSame('pending', $row['status']);
        self::assertNull($row['claim_token']);
        self::assertNull($row['claim_expires_at']);
        self::assertSame(1, (int) $row['attempts'], 'reclaim must never re-increment attempts');
        self::assertLessThanOrEqual(time() + 1, strtotime((string) $row['next_attempt_at']));
    }

    public function testAnOldTokenFinalizerCannotOverwriteADeliveryTheSweepAlreadyReclaimed(): void
    {
        $seeded = $this->registerActiveEndpoint('sellerDEL00013', 'ownerDEL0013');
        $delivery = $this->seedPendingDelivery($seeded['endpoint']['uuid'], 'sellerDEL00013', ['x' => 1]);

        // The httpFactory callback runs AFTER this worker's OWN claim already
        // committed (design spec §2.9's linearization point) -- simulating the
        // sweep reclaiming the SAME row (as if this worker's process had died)
        // from a "concurrent" vantage point before this worker gets to finalize.
        $service = $this->deliveryService(function () use ($delivery) {
            $this->connection->table('commerce_seller_webhook_deliveries')
                ->where('uuid', '=', $delivery['delivery_uuid'])
                ->update(['claim_expires_at' => gmdate('Y-m-d H:i:s', time() - 60)]);

            $sweepService = $this->deliveryService(fn () => new MockResponse('unused'));
            $reclaimOutcome = $sweepService->reclaimExpired($this->context, self::TENANT, $delivery['delivery_uuid']);
            self::assertSame('reclaimed', $reclaimOutcome, 'the sweep must win the reclaim race here');

            return new MockResponse('ok', ['http_code' => 200]);
        });

        $staleOutcome = $service->deliver($this->context, self::TENANT, $delivery['delivery_uuid']);

        self::assertSame('stale', $staleOutcome, 'the original worker\'s finalize must lose the token CAS');

        $row = $this->deliveryRow($delivery['delivery_uuid']);
        self::assertSame(
            'pending',
            $row['status'],
            'the row must reflect the RECLAIM outcome, never overwritten back to delivered'
        );
        self::assertSame(1, (int) $row['attempts'], 'the stale finalize must never double-increment attempts');
    }

    public function testRepositoryFinalizeIsANoOpAgainstAStaleClaimToken(): void
    {
        $seeded = $this->registerActiveEndpoint('sellerDEL00014', 'ownerDEL0014');
        $deliveryUuid = $this->seedDeliveringDelivery(
            $seeded['endpoint']['uuid'],
            'sellerDEL00014',
            claimToken: 'currenttoken00000001',
            claimExpiresAt: gmdate('Y-m-d H:i:s', time() + 60),
            attempts: 1
        );

        $repo = new SellerWebhookDeliveryRepository();
        $accepted = $repo->finalize($this->context, self::TENANT, $deliveryUuid, 'WRONG-STALE-TOKEN', [
            'status' => 'delivered',
            'last_status_code' => 200,
        ], gmdate('Y-m-d H:i:s'));

        self::assertFalse($accepted);
        $row = $this->deliveryRow($deliveryUuid);
        self::assertSame('delivering', $row['status'], 'a stale-token finalize must never mutate the row');
        self::assertSame('currenttoken00000001', $row['claim_token']);
    }

    public function testExpiredDeliveringLeaseWithExhaustedAttemptsDeadLettersOnReclaim(): void
    {
        $this->context->overrideConfig('commerce.marketplace.webhooks.max_attempts', 2);
        $seeded = $this->registerActiveEndpoint('sellerDEL00015', 'ownerDEL0015');
        $deliveryUuid = $this->seedDeliveringDelivery(
            $seeded['endpoint']['uuid'],
            'sellerDEL00015',
            claimToken: 'exhaustedtoken000001',
            claimExpiresAt: gmdate('Y-m-d H:i:s', time() - 30),
            attempts: 2
        );

        $service = $this->deliveryService(fn () => new MockResponse('unused'));
        $outcome = $service->reclaimExpired($this->context, self::TENANT, $deliveryUuid);

        self::assertSame('dead_letter', $outcome);
        $row = $this->deliveryRow($deliveryUuid);
        self::assertSame('dead_letter', $row['status']);
        self::assertSame(2, (int) $row['attempts']);
    }

    public function testExpiredDeliveringLeaseForASuspendedSellerIsPausedNotReturnedToPending(): void
    {
        $seeded = $this->registerActiveEndpoint('sellerDEL00016', 'ownerDEL0016');
        $deliveryUuid = $this->seedDeliveringDelivery(
            $seeded['endpoint']['uuid'],
            'sellerDEL00016',
            claimToken: 'suspendedtoken000001',
            claimExpiresAt: gmdate('Y-m-d H:i:s', time() - 30),
            attempts: 1
        );
        $this->connection->table('commerce_sellers')
            ->where('uuid', '=', 'sellerDEL00016')->update(['status' => 'suspended']);

        $service = $this->deliveryService(fn () => new MockResponse('unused'));
        $outcome = $service->reclaimExpired($this->context, self::TENANT, $deliveryUuid);

        self::assertSame('paused', $outcome);
        $row = $this->deliveryRow($deliveryUuid);
        self::assertSame('paused', $row['status']);
        self::assertSame('seller_suspended', $row['pause_reason']);
    }

    // -----------------------------------------------------------------
    // Auto-disable: isolated per endpoint.
    // -----------------------------------------------------------------

    public function testAutoDisableAtThresholdAuditsAndPausesSiblingRowsOfTheSameEndpointOnly(): void
    {
        $this->context->overrideConfig('commerce.marketplace.webhooks.consecutive_failure_disable_threshold', 2);

        $endpointA = $this->registerActiveEndpoint('sellerDEL00017', 'ownerDEL0017');
        $this->connection->table('commerce_seller_webhook_endpoints')
            ->where('uuid', '=', $endpointA['endpoint']['uuid'])
            ->update(['consecutive_failures' => 1]);

        $failing = $this->seedPendingDelivery($endpointA['endpoint']['uuid'], 'sellerDEL00017', ['x' => 1]);
        $sibling = $this->seedPendingDelivery($endpointA['endpoint']['uuid'], 'sellerDEL00017', ['x' => 2]);

        // A SEPARATE seller + endpoint B with its own pending delivery: must be
        // completely untouched by A's auto-disable.
        $endpointB = $this->registerActiveEndpoint('sellerDEL00018', 'ownerDEL0018');
        $otherSellerDelivery = $this->seedPendingDelivery($endpointB['endpoint']['uuid'], 'sellerDEL00018', ['x' => 3]);

        // A TERMINAL failure (404) so the failing delivery's own outcome is
        // unambiguous dead_letter -- keeps this test focused purely on
        // auto-disable's per-endpoint isolation, not the separate "does the
        // triggering delivery's own retry-scheduled row get excluded from
        // the auto-disable sweep" concern (covered by a dedicated test).
        $service = $this->deliveryService(fn () => new MockResponse('err', ['http_code' => 404]));
        $outcome = $service->deliver($this->context, self::TENANT, $failing['delivery_uuid']);

        self::assertSame('dead_letter', $outcome);

        $endpointARow = $this->connection->table('commerce_seller_webhook_endpoints')
            ->where('uuid', '=', $endpointA['endpoint']['uuid'])->first();
        self::assertSame('disabled', $endpointARow['status']);
        self::assertSame(2, (int) $endpointARow['consecutive_failures']);
        self::assertNotNull($endpointARow['disabled_at']);

        $siblingRow = $this->deliveryRow($sibling['delivery_uuid']);
        self::assertSame('paused', $siblingRow['status']);
        self::assertSame('endpoint_disabled', $siblingRow['pause_reason']);

        $audit = $this->connection->table('commerce_seller_webhook_endpoint_events')
            ->where('endpoint_uuid', '=', $endpointA['endpoint']['uuid'])
            ->where('action', '=', 'auto_disable')
            ->first();
        self::assertNotNull($audit);
        self::assertStringContainsString('consecutive_failures=2', (string) $audit['detail']);

        // Endpoint B and its delivery are COMPLETELY untouched.
        $endpointBRow = $this->connection->table('commerce_seller_webhook_endpoints')
            ->where('uuid', '=', $endpointB['endpoint']['uuid'])->first();
        self::assertSame('active', $endpointBRow['status']);
        self::assertSame(0, (int) $endpointBRow['consecutive_failures']);

        $otherRow = $this->deliveryRow($otherSellerDelivery['delivery_uuid']);
        self::assertSame('pending', $otherRow['status']);
        self::assertNull($otherRow['pause_reason']);
    }

    public function testSuccessResetsConsecutiveFailuresOnlyWhileTheEndpointRemainsActive(): void
    {
        $seeded = $this->registerActiveEndpoint('sellerDEL00019', 'ownerDEL0019');
        $this->connection->table('commerce_seller_webhook_endpoints')
            ->where('uuid', '=', $seeded['endpoint']['uuid'])->update(['consecutive_failures' => 5]);
        $delivery = $this->seedPendingDelivery($seeded['endpoint']['uuid'], 'sellerDEL00019', ['x' => 1]);

        $service = $this->deliveryService(fn () => new MockResponse('ok', ['http_code' => 200]));
        $outcome = $service->deliver($this->context, self::TENANT, $delivery['delivery_uuid']);

        self::assertSame('delivered', $outcome);
        $endpoint = $this->connection->table('commerce_seller_webhook_endpoints')
            ->where('uuid', '=', $seeded['endpoint']['uuid'])->first();
        self::assertSame(0, (int) $endpoint['consecutive_failures']);
    }

    // -----------------------------------------------------------------
    // Config invariant (T2 CARRY-FORWARD).
    // -----------------------------------------------------------------

    public function testClaimLeaseNotStrictlyGreaterThanDeliveryTimeoutThrows(): void
    {
        $this->context->overrideConfig('commerce.marketplace.webhooks.claim_lease_seconds', 10);
        $this->context->overrideConfig('commerce.marketplace.webhooks.delivery_timeout_seconds', 10);

        $seeded = $this->registerActiveEndpoint('sellerDEL00020', 'ownerDEL0020');
        $delivery = $this->seedPendingDelivery($seeded['endpoint']['uuid'], 'sellerDEL00020', ['x' => 1]);

        $service = $this->deliveryService(fn () => new MockResponse('unused'));

        $this->expectException(\RuntimeException::class);
        $service->deliver($this->context, self::TENANT, $delivery['delivery_uuid']);
    }

    public function testReclaimExpiredAlsoEnforcesTheClaimLeaseInvariant(): void
    {
        $this->context->overrideConfig('commerce.marketplace.webhooks.claim_lease_seconds', 5);
        $this->context->overrideConfig('commerce.marketplace.webhooks.delivery_timeout_seconds', 10);

        $seeded = $this->registerActiveEndpoint('sellerDEL00021', 'ownerDEL0021');
        $deliveryUuid = $this->seedDeliveringDelivery(
            $seeded['endpoint']['uuid'],
            'sellerDEL00021',
            claimToken: 'tok',
            claimExpiresAt: gmdate('Y-m-d H:i:s', time() - 30),
            attempts: 1
        );

        $service = $this->deliveryService(fn () => new MockResponse('unused'));

        $this->expectException(\RuntimeException::class);
        $service->reclaimExpired($this->context, self::TENANT, $deliveryUuid);
    }

    // -----------------------------------------------------------------
    // Recovery sweep: candidate discovery covers BOTH lost-pending-wakeup
    // and expired-claim; enqueueHint mirrors the outbox's swallow-and-log.
    // -----------------------------------------------------------------

    public function testDuePendingAndDueDeliveringDiscoverBothSweepCandidateKinds(): void
    {
        $seeded = $this->registerActiveEndpoint('sellerDEL00022', 'ownerDEL0022');

        $duePending = $this->seedPendingDelivery(
            $seeded['endpoint']['uuid'],
            'sellerDEL00022',
            ['x' => 1],
            nextAttemptAt: gmdate('Y-m-d H:i:s', time() - 30)
        );
        $notYetDue = $this->seedPendingDelivery(
            $seeded['endpoint']['uuid'],
            'sellerDEL00022',
            ['x' => 2],
            nextAttemptAt: gmdate('Y-m-d H:i:s', time() + 3600)
        );
        $expiredDelivering = $this->seedDeliveringDelivery(
            $seeded['endpoint']['uuid'],
            'sellerDEL00022',
            claimToken: 'sweeptoken0000000001',
            claimExpiresAt: gmdate('Y-m-d H:i:s', time() - 30),
            attempts: 1
        );

        $repo = new SellerWebhookDeliveryRepository();
        $duePendingRows = $repo->duePending($this->context, self::TENANT, 100);
        $dueDeliveringRows = $repo->dueDelivering($this->context, self::TENANT, 100);

        $duePendingUuids = array_column($duePendingRows, 'uuid');
        self::assertContains($duePending['delivery_uuid'], $duePendingUuids);
        self::assertNotContains($notYetDue['delivery_uuid'], $duePendingUuids);

        $dueDeliveringUuids = array_column($dueDeliveringRows, 'uuid');
        self::assertContains($expiredDelivering, $dueDeliveringUuids);
    }

    public function testEnqueueHintPushesTheDeliverJobAndSwallowsAQueueFailure(): void
    {
        $pushed = [];
        $this->bind(QueueManager::class, new class ($pushed) extends QueueManager {
            /** @param array<int,array{0:string,1:array<string,mixed>}> $pushed */
            public function __construct(private array &$pushed)
            {
            }

            public function push(
                string $job,
                array $data = [],
                ?string $queue = null,
                ?string $connection = null
            ): string {
                $this->pushed[] = [$job, $data];

                return 'job-uuid';
            }
        });

        $service = $this->deliveryService(fn () => new MockResponse('unused'));
        $service->enqueueHint($this->context, self::TENANT, 'someDeliveryId');

        self::assertCount(1, $pushed);
        self::assertSame(DeliverSellerWebhookJob::class, $pushed[0][0]);
        self::assertSame('someDeliveryId', $pushed[0][1]['delivery_uuid']);
        self::assertArrayHasKey('tenant_uuid', $pushed[0][1], 'hint must carry the tenant for pair resolution');
        self::assertSame(self::TENANT, $pushed[0][1]['tenant_uuid']);
    }

    public function testEnqueueHintSwallowsAThrowingQueueManager(): void
    {
        $this->bind(QueueManager::class, new class extends QueueManager {
            public function __construct()
            {
            }

            public function push(
                string $job,
                array $data = [],
                ?string $queue = null,
                ?string $connection = null
            ): string {
                throw new \RuntimeException('queue unavailable');
            }
        });

        $service = $this->deliveryService(fn () => new MockResponse('unused'));
        // Must not throw -- a lost hint never threatens correctness.
        $service->enqueueHint($this->context, self::TENANT, 'someDeliveryId');
        self::assertTrue(true);
    }

    // -----------------------------------------------------------------
    // Job glue: (tenant, uuid) pair resolution, with the legacy
    // tenant-less-payload fallback for in-flight pre-fix hints.
    // -----------------------------------------------------------------

    public function testJobResolvesTenantFromTheDeliveryRowAndDelegatesToTheService(): void
    {
        // LEGACY payload shape (no tenant_uuid): a hint enqueued by a
        // pre-fix install with jobs still in flight at upgrade time must
        // still resolve via the unscoped fallback and deliver.
        $seeded = $this->registerActiveEndpoint('sellerDEL00023', 'ownerDEL0023');
        $delivery = $this->seedPendingDelivery($seeded['endpoint']['uuid'], 'sellerDEL00023', ['x' => 1]);

        $service = $this->deliveryService(fn () => new MockResponse('ok', ['http_code' => 200]));
        $this->bind(SellerWebhookDeliveryRepository::class, new SellerWebhookDeliveryRepository());
        $this->bind(SellerWebhookDeliveryService::class, $service);

        $job = new DeliverSellerWebhookJob(['delivery_uuid' => $delivery['delivery_uuid']], $this->context);
        $job->handle();

        $row = $this->deliveryRow($delivery['delivery_uuid']);
        self::assertSame('delivered', $row['status']);
    }

    public function testJobResolvesByTenantPairAndNeverPicksACrossTenantUuidCollision(): void
    {
        // Regression (delivery uuids carry no cross-tenant uniqueness,
        // migration 019): seed a DECOY row under another tenant with the
        // SAME uuid, inserted FIRST so an unscoped `->first()` would find
        // it. The tenant-carrying payload must resolve OUR tenant's row --
        // deliver it -- and leave the decoy untouched.
        $seeded = $this->registerActiveEndpoint('sellerDEL00025', 'ownerDEL0025');
        $delivery = $this->seedPendingDelivery($seeded['endpoint']['uuid'], 'sellerDEL00025', ['x' => 1]);
        $collidingUuid = (string) $delivery['delivery_uuid'];

        // Move the real row's id ABOVE the decoy's by re-inserting the decoy
        // with a lower ordering guarantee: delete + reinsert our row after
        // the decoy so the decoy holds the lower autoincrement id.
        $ourRow = $this->deliveryRow($collidingUuid);
        $this->connection->table('commerce_seller_webhook_deliveries')
            ->where('tenant_uuid', '=', self::TENANT)
            ->where('uuid', '=', $collidingUuid)
            ->delete();
        $decoy = $ourRow;
        unset($decoy['id']);
        $decoy['tenant_uuid'] = 'tenantDECOY1';
        $this->connection->table('commerce_seller_webhook_deliveries')->insert($decoy);
        $ours = $ourRow;
        unset($ours['id']);
        $this->connection->table('commerce_seller_webhook_deliveries')->insert($ours);

        $service = $this->deliveryService(fn () => new MockResponse('ok', ['http_code' => 200]));
        $this->bind(SellerWebhookDeliveryRepository::class, new SellerWebhookDeliveryRepository());
        $this->bind(SellerWebhookDeliveryService::class, $service);

        $job = new DeliverSellerWebhookJob([
            'delivery_uuid' => $collidingUuid,
            'tenant_uuid' => self::TENANT,
        ], $this->context);
        $job->handle();

        $ourAfter = $this->connection->table('commerce_seller_webhook_deliveries')
            ->where('tenant_uuid', '=', self::TENANT)
            ->where('uuid', '=', $collidingUuid)
            ->first();
        self::assertNotNull($ourAfter);
        self::assertSame('delivered', $ourAfter['status'], 'our tenant\'s row must be the one delivered');

        $decoyAfter = $this->connection->table('commerce_seller_webhook_deliveries')
            ->where('tenant_uuid', '=', 'tenantDECOY1')
            ->where('uuid', '=', $collidingUuid)
            ->first();
        self::assertNotNull($decoyAfter);
        self::assertSame('pending', $decoyAfter['status'], 'the cross-tenant decoy must be untouched');
    }

    public function testJobIsANoOpForAnAlreadyGoneDeliveryUuid(): void
    {
        $this->bind(SellerWebhookDeliveryRepository::class, new SellerWebhookDeliveryRepository());
        $this->bind(
            SellerWebhookDeliveryService::class,
            $this->deliveryService(fn () => new MockResponse('unused'))
        );

        $job = new DeliverSellerWebhookJob(['delivery_uuid' => 'doesNotExist'], $this->context);
        $job->handle();
        self::assertTrue(true, 'must not throw for an unknown delivery uuid');
    }

    // -----------------------------------------------------------------
    // Auto-disable: the delivery whose own failure trips the threshold is
    // excluded from the "other pending deliveries" sweep.
    // -----------------------------------------------------------------

    public function testARetryableFailureThatAlsoTripsAutoDisableKeepsItsOwnBackoffPendingRow(): void
    {
        $this->context->overrideConfig('commerce.marketplace.webhooks.consecutive_failure_disable_threshold', 1);

        $seeded = $this->registerActiveEndpoint('sellerDEL00024', 'ownerDEL0024');
        $delivery = $this->seedPendingDelivery($seeded['endpoint']['uuid'], 'sellerDEL00024', ['x' => 1]);

        $service = $this->deliveryService(fn () => new MockResponse('err', ['http_code' => 500]));
        $outcome = $service->deliver($this->context, self::TENANT, $delivery['delivery_uuid']);

        self::assertSame('retry_scheduled', $outcome);

        $endpoint = $this->connection->table('commerce_seller_webhook_endpoints')
            ->where('uuid', '=', $seeded['endpoint']['uuid'])->first();
        self::assertSame('disabled', $endpoint['status']);

        $row = $this->deliveryRow($delivery['delivery_uuid']);
        self::assertSame(
            'pending',
            $row['status'],
            'the delivery whose OWN failure tripped auto-disable keeps its computed backoff, never re-paused by the '
                . 'SAME finalize\'s sweep'
        );
        self::assertNotNull($row['next_attempt_at']);
    }

    // -----------------------------------------------------------------
    // The recovery-sweep console command: both candidate kinds, per-tenant.
    // -----------------------------------------------------------------

    public function testSweepCommandReclaimsExpiredLeasesAndReEnqueuesDuePendingRows(): void
    {
        $seeded = $this->registerActiveEndpoint('sellerDEL00025', 'ownerDEL0025');

        $duePending = $this->seedPendingDelivery(
            $seeded['endpoint']['uuid'],
            'sellerDEL00025',
            ['x' => 1],
            nextAttemptAt: gmdate('Y-m-d H:i:s', time() - 30)
        );
        $expiredDelivering = $this->seedDeliveringDelivery(
            $seeded['endpoint']['uuid'],
            'sellerDEL00025',
            claimToken: 'cmdsweeptoken000001',
            claimExpiresAt: gmdate('Y-m-d H:i:s', time() - 30),
            attempts: 1
        );

        $pushed = [];
        $this->bind(QueueManager::class, new class ($pushed) extends QueueManager {
            /** @param array<int,string> $pushed */
            public function __construct(private array &$pushed)
            {
            }

            public function push(
                string $job,
                array $data = [],
                ?string $queue = null,
                ?string $connection = null
            ): string {
                $this->pushed[] = (string) ($data['delivery_uuid'] ?? '');

                return 'job-uuid';
            }
        });
        $this->bind(SellerWebhookDeliveryRepository::class, new SellerWebhookDeliveryRepository());
        $this->bind(
            SellerWebhookDeliveryService::class,
            $this->deliveryService(fn () => new MockResponse('unused'))
        );

        $command = new SweepSellerWebhooksCommand($this->context->getContainer(), $this->context);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $reclaimedRow = $this->deliveryRow($expiredDelivering);
        self::assertSame('pending', $reclaimedRow['status']);
        self::assertNull($reclaimedRow['claim_token']);

        self::assertContains($duePending['delivery_uuid'], $pushed, 'the due pending row must be re-enqueued');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /** @return array{endpoint: array<string,mixed>, secret: string} */
    private function registerActiveEndpoint(string $sellerUuid, string $ownerUuid): array
    {
        $this->connection->table('commerce_sellers')->insert([
            'uuid' => $sellerUuid,
            'tenant_uuid' => self::TENANT,
            'slug' => strtolower($sellerUuid),
            'name' => $sellerUuid,
            'status' => 'active',
        ]);
        $this->connection->table('commerce_seller_memberships')->insert([
            'uuid' => 'mbr' . substr(md5($sellerUuid . $ownerUuid), 0, 9),
            'tenant_uuid' => self::TENANT,
            'seller_uuid' => $sellerUuid,
            'user_uuid' => $ownerUuid,
            'role' => 'seller_owner',
            'status' => 'active',
        ]);

        $resolver = new SafeOutboundTargetResolver(
            fn (string $host): array => [self::SAFE_HOST => [self::SAFE_IP]][$host] ?? [self::SAFE_IP]
        );
        $endpoints = $this->endpointsRepo();
        $service = new SellerWebhookEndpointService(
            new SellerRepository(),
            new SellerMembershipRepository(),
            $endpoints,
            new SellerWebhookDeliveryRepository(),
            new FixedSellerRoleAuthority(),
            $this->secretService(),
            $resolver
        );

        return $service->register(
            $this->context,
            self::TENANT,
            $sellerUuid,
            'https://' . self::SAFE_HOST . '/hook',
            ['order.placed'],
            $ownerUuid
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{delivery_uuid: string, event_uuid: string, payload_bytes: string}
     */
    private function seedPendingDelivery(
        string $endpointUuid,
        string $sellerUuid,
        array $payload,
        string $eventType = 'order.placed',
        int $attempts = 0,
        ?string $nextAttemptAt = null
    ): array {
        $eventUuid = $this->nextId('e');
        $deliveryUuid = $this->nextId('d');
        $payloadBytes = (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->connection->table('commerce_seller_webhook_events')->insert([
            'uuid' => $eventUuid,
            'tenant_uuid' => self::TENANT,
            'seller_uuid' => $sellerUuid,
            'event_type' => $eventType,
            'payload' => $payloadBytes,
            'occurred_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $this->connection->table('commerce_seller_webhook_deliveries')->insert([
            'uuid' => $deliveryUuid,
            'tenant_uuid' => self::TENANT,
            'endpoint_uuid' => $endpointUuid,
            'webhook_event_uuid' => $eventUuid,
            'seller_uuid' => $sellerUuid,
            'status' => 'pending',
            'attempts' => $attempts,
            'next_attempt_at' => $nextAttemptAt ?? gmdate('Y-m-d H:i:s', time() - 5),
        ]);

        return ['delivery_uuid' => $deliveryUuid, 'event_uuid' => $eventUuid, 'payload_bytes' => $payloadBytes];
    }

    private function seedDeliveringDelivery(
        string $endpointUuid,
        string $sellerUuid,
        string $claimToken,
        string $claimExpiresAt,
        int $attempts
    ): string {
        $eventUuid = $this->nextId('e');
        $deliveryUuid = $this->nextId('d');

        $this->connection->table('commerce_seller_webhook_events')->insert([
            'uuid' => $eventUuid,
            'tenant_uuid' => self::TENANT,
            'seller_uuid' => $sellerUuid,
            'event_type' => 'order.placed',
            'payload' => '{"x":1}',
            'occurred_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $this->connection->table('commerce_seller_webhook_deliveries')->insert([
            'uuid' => $deliveryUuid,
            'tenant_uuid' => self::TENANT,
            'endpoint_uuid' => $endpointUuid,
            'webhook_event_uuid' => $eventUuid,
            'seller_uuid' => $sellerUuid,
            'status' => 'delivering',
            'attempts' => $attempts,
            'claim_token' => $claimToken,
            'claim_expires_at' => $claimExpiresAt,
            'last_attempt_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return $deliveryUuid;
    }

    /** @return array<string,mixed> */
    private function deliveryRow(string $uuid): array
    {
        $row = $this->connection->table('commerce_seller_webhook_deliveries')->where('uuid', '=', $uuid)->first();
        self::assertNotNull($row);

        return $row;
    }

    /**
     * Symfony's HttpClient normalizes an associative `headers` option into a
     * flat `list<string>` of `"Name: value"` lines before a MockHttpClient
     * factory ever sees it -- this reconstructs a name => value map for
     * assertions.
     *
     * @param list<string> $rawHeaders
     * @return array<string,string>
     */
    private function parseCapturedHeaders(array $rawHeaders): array
    {
        $map = [];
        foreach ($rawHeaders as $line) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $map[trim($name)] = trim($value);
        }

        return $map;
    }

    private function nextId(string $prefix): string
    {
        $this->seq++;

        return $prefix . str_pad((string) $this->seq, 11, '0', STR_PAD_LEFT);
    }

    /**
     * @param array<string,list<string>> $dnsMap host => list of IPs (delivery-time
     *     resolver -- may deliberately diverge from registration-time to simulate
     *     a DNS rebind, mirroring {@see SellerWebhookEndpointTest::service()}).
     */
    private function deliveryService(
        callable $httpFactory,
        array $dnsMap = [],
        ?callable $claimTokenGenerator = null
    ): SellerWebhookDeliveryService {
        $map = array_merge([self::SAFE_HOST => [self::SAFE_IP]], $dnsMap);
        $resolver = new SafeOutboundTargetResolver(
            static fn (string $host): array => $map[$host] ?? [self::SAFE_IP]
        );

        $httpClient = new MockHttpClient($httpFactory);
        $client = new Client($httpClient, new NullLogger(), $this->context, $resolver);

        return new SellerWebhookDeliveryService(
            new SellerRepository(),
            $this->endpointsRepo(),
            new SellerWebhookDeliveryRepository(),
            new SellerWebhookEventRepository(),
            $this->secretService(),
            $client,
            $claimTokenGenerator
        );
    }

    private function endpointsRepo(): SellerWebhookEndpointRepository
    {
        static $repo = null;
        if ($repo === null) {
            $repo = new SellerWebhookEndpointRepository();
        }

        return $repo;
    }

    private function secretService(): SellerWebhookSecretService
    {
        static $service = null;
        if ($service === null) {
            $service = new SellerWebhookSecretService($this->endpointsRepo(), $this->encryptionService());
        }

        return $service;
    }

    private function encryptionService(): EncryptionService
    {
        static $encryption = null;
        if ($encryption === null) {
            $this->context->overrideConfig('encryption.key', 'base64:' . base64_encode(str_repeat('k', 32)));
            $encryption = new EncryptionService($this->context);
        }

        return $encryption;
    }
}
