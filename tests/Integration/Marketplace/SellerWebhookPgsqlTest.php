<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Api\Webhooks\WebhookSignature;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Encryption\EncryptionService;
use Glueful\Extensions\Commerce\Database\Migrations\CreateSellerWebhookTables;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookDeliveryRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookEndpointRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookEventRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookSecretService;
use Glueful\Extensions\Commerce\Marketplace\SellerLifecycleEventRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerService;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Psr\Container\ContainerInterface;

/**
 * Real-PostgreSQL race lanes for Marketplace MV5c-2's seller-webhook
 * subsystem (design spec §2.4/§2.6/§2.7/§2.9; Task 8, GATES). Every case
 * here requires TRUE two-connection row-lock interleaving that SQLite -- a
 * single-process, single-connection engine in this test harness -- cannot
 * exercise at all; {@see SellerWebhookOutboxTest} already proves the
 * identical outcomes SEQUENTIALLY (one call fully committing before the
 * next begins, see its own `testCaptureFirstThenSuspensionOrdering...()`
 * docblock, which explicitly defers the genuinely-concurrent proof to this
 * file) -- this file proves the SAME outcomes hold when the two operations
 * genuinely CONTEND for the same row lock.
 *
 * Mirrors `SellerSuspensionPgsqlTest`/`SellerApiKeyPgsqlTest`
 * primitive-for-primitive: `skipUnlessPgsql()`, `pgConfig()`,
 * `migratedConnection()`, `pgsqlContext()`, `launchRaceChild()`/
 * `collectRaceChild()` (against the NEW dedicated
 * `fixtures/seller_webhook_race_child.php`, not the shared marketplace
 * fixture -- see that file's own docblock for why), and the fixture-width
 * discipline (every `uuid`/`tenant_uuid` literal here is 12 characters or
 * fewer). Connection A either manually replicates the claim-then-write
 * critical section of the real service under test directly via the
 * repositories (so it can pause mid-transaction and hold its claim open on
 * demand), or manually replicates a PRIVATE method's own critical section
 * when no public entry point reaches it (`claim()`/`finalize()` on
 * {@see \Glueful\Extensions\Commerce\Marketplace\SellerWebhookDeliveryService}
 * are both private -- see each such test's own docblock); connection B is
 * either a genuinely separate subprocess running the REAL public service end
 * to end, or -- ONLY for the one lane where finalize() genuinely has no
 * public form on EITHER side (§5 below) -- a genuinely separate subprocess
 * replicating the SAME repository-level primitives the private method
 * itself calls, so the row-lock contention is still fully real even then.
 *
 * Six lanes, BOTH commit orderings each, every one proving the design spec
 * §2.9 pinned lock order (`seller revision -> endpoint revision -> delivery
 * CAS`) is what genuinely serializes concurrent seller-webhook mutations:
 * (1) delivery-claim vs seller-suspension; (2) capture vs suspension (§2.4's
 * "capture-first is caught by suspension" direction, which
 * {@see SellerWebhookOutboxTest} explicitly deferred to here); (3)
 * rotate-secret vs in-flight delivery (§2.5's overlap-verifiability
 * invariant); (4) management mutation vs suspension; (5) expired-claim
 * reclaim vs stale-token finalize (§2.7's crash-safe lease CAS); (6)
 * auto-disable vs endpoint-enable (§2.7's reset-then-recount linearization).
 * Plus: migration 019 shape/indexes live on PostgreSQL, and rerunning it is
 * a no-op.
 */
final class SellerWebhookPgsqlTest extends CommerceTestCase
{
    private int $seq = 0;

    // =====================================================================
    // 1. delivery-claim vs seller-suspension (design spec §2.7/§2.9,
    //    seller -> endpoint -> delivery lock order), BOTH orderings.
    // =====================================================================

    /**
     * Ordering (a): the CLAIM commits first. Connection A manually
     * replicates {@see \Glueful\Extensions\Commerce\Marketplace\SellerWebhookDeliveryService::claim()}'s
     * critical section (claim seller revision -> re-read active -> claim
     * endpoint's ACTIVE revision -> re-read active -> CAS the delivery
     * pending -> delivering), holding it open. Connection B (subprocess, the
     * REAL `SellerService::suspend()`) blocks entirely on A's held
     * seller-revision claim; once A commits, B's own claim succeeds (suspend
     * carries no live-delivery guard) -- the delivery claimed BEFORE
     * suspension committed stays `delivering`, completely untouched by the
     * later suspension (design spec §2.9: "an in-flight ... delivery that
     * started before suspension ... may finish").
     */
    public function testDeliveryClaimCommitsFirstThenConcurrentSuspendAppliesAfterAndTheClaimedDeliveryStaysDeliveringOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'whpgclfs001';
        $this->cleanupTenant($connectionA, $tenant);

        $seller = $this->seedActiveSeller($contextA, $tenant, 'whpg-clfs-s1', 'ownerCLFS001');
        $endpointUuid = $this->seedEndpoint($connectionA, $tenant, $seller['uuid'], ['order.placed']);
        $this->mintSecret($contextA, $tenant, $endpointUuid);
        $eventUuid = $this->seedEvent($connectionA, $tenant, $seller['uuid'], ['order_uuid' => 'ordCLFS0001']);
        $deliveryUuid = $this->seedDelivery($connectionA, $tenant, $endpointUuid, $eventUuid, $seller['uuid'], 'pending');

        $connectionA->getTransactionManager()->begin();
        $held = $this->manuallyClaimDelivery($contextA, $tenant, $seller['uuid'], $endpointUuid, $deliveryUuid);

        $handle = $this->launchRaceChild($pgConfig, 'suspend', [
            'tenant' => $tenant,
            'sellerUuid' => $seller['uuid'],
            'reason' => 'Delivery-claim-first race probe.',
            'actor' => 'operatorWHR1',
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertTrue(
            $result['ok'] ?? false,
            'suspend must succeed once the in-flight claim has committed: ' . json_encode($result, JSON_THROW_ON_ERROR)
        );
        self::assertSame('suspended', $result['status']);

        $delivery = $connectionA->table('commerce_seller_webhook_deliveries')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $deliveryUuid)->first();
        self::assertSame('delivering', $delivery['status'], 'a claim committed BEFORE suspension must survive it');
        self::assertSame($held['claim_token'], $delivery['claim_token']);

        $this->cleanupTenant($connectionA, $tenant);
    }

    /**
     * Ordering (b): the SUSPEND commits first. Connection A manually
     * replicates `SellerService::suspend()`'s claim-then-update, committing
     * FIRST. Connection B (subprocess, the REAL
     * `SellerWebhookDeliveryService::deliver()`) attempts to claim the SAME
     * delivery -- its own seller-revision claim blocks entirely on A's held
     * claim; once unblocked, its fresh re-read observes `suspended` and
     * REFUSES -- the delivery is best-effort PAUSED (`pause_reason =
     * seller_suspended`), never claimed as `delivering` at all.
     */
    public function testSuspendCommitsFirstThenConcurrentDeliveryClaimRefusesAndPausesWithNoClaimOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'whpgclsf001';
        $this->cleanupTenant($connectionA, $tenant);

        $seller = $this->seedActiveSeller($contextA, $tenant, 'whpg-clsf-s1', 'ownerCLSF001');
        $endpointUuid = $this->seedEndpoint($connectionA, $tenant, $seller['uuid'], ['order.placed']);
        $this->mintSecret($contextA, $tenant, $endpointUuid);
        $eventUuid = $this->seedEvent($connectionA, $tenant, $seller['uuid'], ['order_uuid' => 'ordCLSF0001']);
        $deliveryUuid = $this->seedDelivery($connectionA, $tenant, $endpointUuid, $eventUuid, $seller['uuid'], 'pending');

        $sellers = new SellerRepository();
        $connectionA->getTransactionManager()->begin();
        self::assertTrue($sellers->claimRevision($contextA, $tenant, $seller['uuid']));
        $sellers->update($contextA, $tenant, $seller['uuid'], ['status' => 'suspended']);

        $handle = $this->launchRaceChild($pgConfig, 'webhookDeliver', [
            'tenant' => $tenant,
            'deliveryUuid' => $deliveryUuid,
            'statusCode' => 200,
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertTrue($result['ok'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame('not_claimed', $result['outcome']);

        $delivery = $connectionA->table('commerce_seller_webhook_deliveries')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $deliveryUuid)->first();
        self::assertSame('paused', $delivery['status']);
        self::assertSame('seller_suspended', $delivery['pause_reason']);
        self::assertNull($delivery['claim_token'], 'a refused claim must never touch the claim token');

        $this->cleanupTenant($connectionA, $tenant);
    }

    // =====================================================================
    // 2. capture vs suspension (design spec §2.4/§2.9) -- the "capture-first
    //    is caught by suspension" direction `SellerWebhookOutboxTest`
    //    explicitly defers to a live pgsql lane. BOTH orderings.
    // =====================================================================

    /**
     * Ordering (a): the CAPTURE commits first. Connection A manually
     * replicates {@see \Glueful\Extensions\Commerce\Marketplace\SellerWebhookOutboxPublisher::capture()}'s
     * critical section for ONE seller/endpoint (claim seller revision ->
     * re-read active -> insert event snapshot + `pending` delivery), holding
     * it open. Connection B (subprocess, the REAL `SellerService::suspend()`)
     * blocks entirely on A's held seller-revision claim; once A commits, B's
     * own claim succeeds -- the `pending` delivery committed BEFORE
     * suspension survives it untouched.
     */
    public function testCaptureCommitsFirstThenConcurrentSuspendAppliesAfterAndThePendingDeliverySurvivesOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'whpgcpfs001';
        $this->cleanupTenant($connectionA, $tenant);

        $seller = $this->seedActiveSeller($contextA, $tenant, 'whpg-cpfs-s1', 'ownerCPFS001');
        $endpointUuid = $this->seedEndpoint($connectionA, $tenant, $seller['uuid'], ['order.placed']);

        $connectionA->getTransactionManager()->begin();
        $captured = $this->manuallyCapture($contextA, $tenant, $seller['uuid'], $endpointUuid, 'ordCPFS0001');

        $handle = $this->launchRaceChild($pgConfig, 'suspend', [
            'tenant' => $tenant,
            'sellerUuid' => $seller['uuid'],
            'reason' => 'Capture-first race probe.',
            'actor' => 'operatorWHR2',
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertTrue($result['ok'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame('suspended', $result['status']);

        $delivery = $connectionA->table('commerce_seller_webhook_deliveries')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $captured['delivery_uuid'])->first();
        self::assertSame('pending', $delivery['status'], 'a capture committed BEFORE suspension must survive it');

        $this->cleanupTenant($connectionA, $tenant);
    }

    /**
     * Ordering (b): the SUSPEND commits first -- the case
     * {@see SellerWebhookOutboxTest}'s own sequential test explicitly could
     * not exercise under TRUE contention. Connection A manually replicates
     * `SellerService::suspend()`'s claim-then-update, committing FIRST.
     * Connection B (subprocess, the REAL
     * `SellerWebhookOutboxPublisher::capture()`) blocks entirely on A's held
     * seller-revision claim; once unblocked, its OWN fresh re-read of the
     * seller's status observes `suspended` -- it still WRITES the event
     * snapshot (design spec §2.9: "events generated while suspended are
     * STILL written"), but the delivery is born `paused` with
     * `pause_reason = seller_suspended`, never `pending`.
     */
    public function testSuspendCommitsFirstThenConcurrentCaptureWritesAPausedDeliveryOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'whpgcpsf001';
        $this->cleanupTenant($connectionA, $tenant);

        $seller = $this->seedActiveSeller($contextA, $tenant, 'whpg-cpsf-s1', 'ownerCPSF001');
        $this->seedEndpoint($connectionA, $tenant, $seller['uuid'], ['order.placed']);

        $sellers = new SellerRepository();
        $connectionA->getTransactionManager()->begin();
        self::assertTrue($sellers->claimRevision($contextA, $tenant, $seller['uuid']));
        $sellers->update($contextA, $tenant, $seller['uuid'], ['status' => 'suspended']);

        $handle = $this->launchRaceChild($pgConfig, 'webhookCapture', [
            'tenant' => $tenant,
            'sellerUuid' => $seller['uuid'],
            'orderUuid' => 'ordCPSF0001',
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertTrue($result['ok'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame(
            'paused',
            $result['deliveryStatus'],
            'capture must observe the just-committed suspension and write a PAUSED delivery, never pending'
        );
        self::assertSame('seller_suspended', $result['pauseReason']);

        self::assertSame(
            1,
            $connectionA->table('commerce_seller_webhook_events')
                ->where('tenant_uuid', '=', $tenant)->where('seller_uuid', '=', $seller['uuid'])->count(),
            'the event snapshot is STILL written even while suspended (design spec §2.9)'
        );

        $this->cleanupTenant($connectionA, $tenant);
    }

    // =====================================================================
    // 3. rotate-secret vs in-flight delivery (design spec §2.2/§2.5,
    //    endpoint-revision lock -- the overlap-verifiability invariant),
    //    BOTH orderings.
    // =====================================================================

    /**
     * Ordering (a): the delivery CLAIM commits first. Connection A first
     * decrypts the endpoint's pre-rotation `current` secret plaintext (so it
     * can assert overlap verifiability afterward), then manually replicates
     * `claim()`'s critical section (see lane 1's identical helper), holding
     * it open. Connection B (subprocess, the REAL `rotateSecret()`) blocks
     * entirely on A's held ENDPOINT-revision claim (claim() claims it too);
     * once A commits, B's rotate succeeds -- demoting the OLD (pre-claim)
     * secret to `previous` with an overlap window. The delivery, already
     * `delivering` under the OLD secret, signs against it during its own
     * (separate, unlocked) attempt step; this test proves the load-bearing
     * PRECONDITION for that signature to stay verifiable: the exact
     * pre-rotation plaintext is STILL present, decryptable, and marked
     * `previous` (not simply gone) immediately after rotation commits.
     */
    public function testDeliveryClaimCommitsFirstThenConcurrentRotateSecretAppliesAfterAndThePreRotationSecretStaysVerifiableAsPreviousOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'whpgrtcl001';
        $this->cleanupTenant($connectionA, $tenant);

        $seller = $this->seedActiveSeller($contextA, $tenant, 'whpg-rtcl-s1', 'ownerRTCL001');
        $endpointUuid = $this->seedEndpoint($connectionA, $tenant, $seller['uuid'], ['order.placed']);
        $preRotation = $this->mintSecret($contextA, $tenant, $endpointUuid);
        $eventUuid = $this->seedEvent($connectionA, $tenant, $seller['uuid'], ['order_uuid' => 'ordRTCL0001']);
        $deliveryUuid = $this->seedDelivery($connectionA, $tenant, $endpointUuid, $eventUuid, $seller['uuid'], 'pending');

        $preRotationPlain = $this->currentSecretPlain($contextA, $tenant, $endpointUuid);
        self::assertSame($preRotation['plain'], $preRotationPlain, 'sanity: minted plaintext round-trips');

        $connectionA->getTransactionManager()->begin();
        $this->manuallyClaimDelivery($contextA, $tenant, $seller['uuid'], $endpointUuid, $deliveryUuid);

        $handle = $this->launchRaceChild($pgConfig, 'webhookRotateSecret', [
            'tenant' => $tenant,
            'sellerUuid' => $seller['uuid'],
            'endpointUuid' => $endpointUuid,
            'actor' => 'ownerRTCL001',
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertTrue(
            $result['ok'] ?? false,
            'rotate must succeed once the in-flight claim has committed: ' . json_encode($result, JSON_THROW_ON_ERROR)
        );
        $newSecretPlain = (string) $result['secret'];
        self::assertNotSame($preRotationPlain, $newSecretPlain);

        $previousRow = $connectionA->table('commerce_seller_webhook_secrets')
            ->where('tenant_uuid', '=', $tenant)
            ->where('endpoint_uuid', '=', $endpointUuid)
            ->where('relationship', '=', 'previous')
            ->first();
        self::assertNotNull($previousRow, 'the pre-rotation secret must survive as `previous`, not be deleted');
        self::assertNotNull($previousRow['overlap_expires_at']);
        $decryptedPrevious = (new EncryptionService($contextA))->decrypt(
            (string) $previousRow['secret_ciphertext'],
            "{$tenant}:{$endpointUuid}:{$previousRow['uuid']}"
        );
        self::assertSame(
            $preRotationPlain,
            $decryptedPrevious,
            'overlap must keep the EXACT pre-rotation plaintext verifiable, not a re-derived value'
        );

        // The in-flight claim itself (committed before rotation) is untouched.
        $delivery = $connectionA->table('commerce_seller_webhook_deliveries')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $deliveryUuid)->first();
        self::assertSame('delivering', $delivery['status']);

        $this->cleanupTenant($connectionA, $tenant);
    }

    /**
     * Ordering (b): the ROTATE commits first. Connection A manually
     * replicates `rotateSecret()`'s critical section (claim seller + endpoint
     * revision, retire any stale previous, demote current -> previous with
     * overlap, mint + insert a NEW current secret), holding it open and
     * KEEPING the new plaintext for its own later assertion. Connection B
     * (subprocess, the REAL `deliver()`) attempts to claim + sign + POST the
     * SAME pending delivery -- its own claim blocks entirely on A's held
     * ENDPOINT-revision claim; once unblocked, the claim succeeds (endpoint
     * still active) and its (separate, unlocked) sign step reads whatever is
     * NOW current -- the brand-new secret A just rotated in. The resulting
     * signature verifies ONLY against the NEW secret, never the old one.
     */
    public function testRotateSecretCommitsFirstThenConcurrentDeliveryClaimSignsWithTheNewCurrentSecretOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'whpgrtrt001';
        $this->cleanupTenant($connectionA, $tenant);

        $seller = $this->seedActiveSeller($contextA, $tenant, 'whpg-rtrt-s1', 'ownerRTRT001');
        $endpointUuid = $this->seedEndpoint($connectionA, $tenant, $seller['uuid'], ['order.placed']);
        $oldSecret = $this->mintSecret($contextA, $tenant, $endpointUuid);
        $eventUuid = $this->seedEvent($connectionA, $tenant, $seller['uuid'], ['order_uuid' => 'ordRTRT0001']);
        $deliveryUuid = $this->seedDelivery($connectionA, $tenant, $endpointUuid, $eventUuid, $seller['uuid'], 'pending');

        $connectionA->getTransactionManager()->begin();
        $newSecretPlain = $this->manuallyRotateSecret($contextA, $tenant, $seller['uuid'], $endpointUuid);

        $handle = $this->launchRaceChild($pgConfig, 'webhookDeliver', [
            'tenant' => $tenant,
            'deliveryUuid' => $deliveryUuid,
            'statusCode' => 200,
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertTrue($result['ok'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame('delivered', $result['outcome']);

        $event = $connectionA->table('commerce_seller_webhook_events')
            ->where('tenant_uuid', '=', $tenant)->where('uuid', '=', $eventUuid)->first();
        $payloadBytes = (string) $event['payload'];
        $signatureHeader = (string) $result['signatureHeader'];

        self::assertTrue(
            WebhookSignature::verify($payloadBytes, $signatureHeader, $newSecretPlain),
            'a claim starting AFTER rotation committed must sign with the NEW current secret'
        );
        self::assertFalse(
            WebhookSignature::verify($payloadBytes, $signatureHeader, $oldSecret['plain']),
            'the same signature must NEVER verify against the old (now-previous) secret'
        );

        $this->cleanupTenant($connectionA, $tenant);
    }

    // =====================================================================
    // 4. management mutation vs suspension (design spec §2.2/§2.9, seller
    //    revision), BOTH orderings.
    // =====================================================================

    /**
     * Ordering (a): the DISABLE commits first. Connection A manually
     * replicates {@see \Glueful\Extensions\Commerce\Marketplace\SellerWebhookEndpointService::disable()}'s
     * critical section (claim seller -> re-read active -> claim endpoint ->
     * markDisabled), holding it open. Connection B (subprocess, the REAL
     * `SellerService::suspend()`) blocks entirely on A's held seller-revision
     * claim; once A commits, B's own claim succeeds (suspend carries no
     * live-endpoint guard) -- the disablement committed BEFORE suspension
     * survives it.
     */
    public function testDisableCommitsFirstThenConcurrentSuspendAppliesAfterOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'whpgmgfs001';
        $this->cleanupTenant($connectionA, $tenant);

        $seller = $this->seedActiveSeller($contextA, $tenant, 'whpg-mgfs-s1', 'ownerMGFS001');
        $endpointUuid = $this->seedEndpoint($connectionA, $tenant, $seller['uuid'], ['order.placed']);

        $sellers = new SellerRepository();
        $endpoints = new SellerWebhookEndpointRepository();
        $connectionA->getTransactionManager()->begin();
        self::assertTrue($sellers->claimRevision($contextA, $tenant, $seller['uuid']));
        $sellerRow = $sellers->findByUuid($contextA, $tenant, $seller['uuid']);
        self::assertSame('active', $sellerRow['status']);
        self::assertTrue($endpoints->claimActiveRevision($contextA, $tenant, $endpointUuid));
        $endpoints->markDisabled($contextA, $tenant, $endpointUuid, 'Management-first race probe.', gmdate('Y-m-d H:i:s'));

        $handle = $this->launchRaceChild($pgConfig, 'suspend', [
            'tenant' => $tenant,
            'sellerUuid' => $seller['uuid'],
            'reason' => 'Management-first race probe.',
            'actor' => 'operatorWHR4',
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertTrue($result['ok'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame('suspended', $result['status']);

        $endpointRow = $connectionA->table('commerce_seller_webhook_endpoints')
            ->where('tenant_uuid', '=', $tenant)->where('uuid', '=', $endpointUuid)->first();
        self::assertSame('disabled', $endpointRow['status'], 'a disable committed BEFORE suspension must survive it');

        $this->cleanupTenant($connectionA, $tenant);
    }

    /**
     * Ordering (b): the SUSPEND commits first. Connection A manually
     * replicates `SellerService::suspend()`'s claim-then-update, committing
     * FIRST. Connection B (subprocess, the REAL `disable()`) attempts to
     * disable the same endpoint -- its own seller-revision claim blocks
     * entirely on A's held claim; once unblocked, its fresh seller re-read
     * observes `suspended` and REFUSES with `seller_inactive` -- the
     * endpoint's own revision is never even claimed, `status` stays `active`.
     */
    public function testSuspendCommitsFirstThenConcurrentDisableRefusesWithSellerInactiveOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'whpgmgsf001';
        $this->cleanupTenant($connectionA, $tenant);

        $seller = $this->seedActiveSeller($contextA, $tenant, 'whpg-mgsf-s1', 'ownerMGSF001');
        $endpointUuid = $this->seedEndpoint($connectionA, $tenant, $seller['uuid'], ['order.placed']);

        $sellers = new SellerRepository();
        $connectionA->getTransactionManager()->begin();
        self::assertTrue($sellers->claimRevision($contextA, $tenant, $seller['uuid']));
        $sellers->update($contextA, $tenant, $seller['uuid'], ['status' => 'suspended']);

        $handle = $this->launchRaceChild($pgConfig, 'webhookDisable', [
            'tenant' => $tenant,
            'sellerUuid' => $seller['uuid'],
            'endpointUuid' => $endpointUuid,
            'actor' => 'ownerMGSF001',
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertFalse($result['ok'] ?? true, 'a disable against a just-suspended seller must be refused');
        self::assertSame('seller_inactive', $result['errorCode'] ?? null);

        $endpointRow = $connectionA->table('commerce_seller_webhook_endpoints')
            ->where('tenant_uuid', '=', $tenant)->where('uuid', '=', $endpointUuid)->first();
        self::assertSame('active', $endpointRow['status'], 'a refused disable must never touch the endpoint');

        $this->cleanupTenant($connectionA, $tenant);
    }

    // =====================================================================
    // 5. expired-claim reclaim vs stale-token finalize (design spec §2.7 --
    //    the crash-safe lease CAS, "the zombie: stale token must no-op"),
    //    BOTH orderings.
    //
    //    `finalize()` on {@see \Glueful\Extensions\Commerce\Marketplace\SellerWebhookDeliveryService}
    //    is PRIVATE -- there is no public entry point that resumes an
    //    ALREADY-`delivering` row's finalize using a previously-captured
    //    token (the public `deliver()` always starts its OWN fresh claim
    //    from `status = pending`). Both sides below therefore replicate the
    //    EXACT SAME repository-level primitives ({@see SellerRepository::claimRevision()},
    //    {@see SellerWebhookEndpointRepository::claimRevision()} (permissive),
    //    {@see SellerWebhookDeliveryRepository::finalize()}) `finalize()`
    //    itself calls -- for the ONE side that is the "stale finalize" in
    //    each ordering. The OTHER side, the sweep's reclaim, IS reachable
    //    publicly ({@see \Glueful\Extensions\Commerce\Marketplace\SellerWebhookDeliveryService::reclaimExpired()})
    //    and is what the REAL subprocess runs whenever it is that ordering's
    //    "second" actor. The row-lock contention itself is fully genuine
    //    either way: two separate connections/processes racing the SAME
    //    seller-revision claim, then the SAME delivery-row CAS.
    // =====================================================================

    /**
     * Ordering (a): the STALE FINALIZE commits first. Connection A manually
     * replicates the finalize critical section (permissive seller + endpoint
     * claim, `SellerWebhookDeliveryRepository::finalize()` CAS with the
     * ORIGINAL claim token -> `delivered`), holding it open. Connection B
     * (subprocess, the REAL `reclaimExpired()`) blocks entirely on A's held
     * seller-revision claim; once A commits, B's own claim succeeds, but its
     * delivery-row CAS (`status = delivering AND claim_token = ?`) now finds
     * the row already `delivered` -- 0 rows affected, outcome `stale`. The
     * finalize that legitimately committed first wins; the sweep correctly
     * detects staleness and touches nothing.
     */
    public function testStaleFinalizeCommitsFirstThenConcurrentReclaimExpiredLosesTheCasAndStaysDeliveredOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'whpgrcfs001';
        $this->cleanupTenant($connectionA, $tenant);

        $seller = $this->seedActiveSeller($contextA, $tenant, 'whpg-rcfs-s1', 'ownerRCFS001');
        $endpointUuid = $this->seedEndpoint($connectionA, $tenant, $seller['uuid'], ['order.placed']);
        $this->mintSecret($contextA, $tenant, $endpointUuid);
        $eventUuid = $this->seedEvent($connectionA, $tenant, $seller['uuid'], ['order_uuid' => 'ordRCFS0001']);
        $claimToken = bin2hex(random_bytes(16));
        $deliveryUuid = $this->seedDelivery($connectionA, $tenant, $endpointUuid, $eventUuid, $seller['uuid'], 'delivering', [
            'attempts' => 1,
            'claim_token' => $claimToken,
            'claim_expires_at' => gmdate('Y-m-d H:i:s', time() - 60),
        ]);

        $sellers = new SellerRepository();
        $endpoints = new SellerWebhookEndpointRepository();
        $deliveries = new SellerWebhookDeliveryRepository();
        $connectionA->getTransactionManager()->begin();
        $sellers->claimRevision($contextA, $tenant, $seller['uuid']);
        $endpoints->claimRevision($contextA, $tenant, $endpointUuid);
        self::assertTrue($deliveries->finalize($contextA, $tenant, $deliveryUuid, $claimToken, [
            'status' => 'delivered',
            'last_status_code' => 200,
            'last_error' => null,
        ], gmdate('Y-m-d H:i:s')));

        $handle = $this->launchRaceChild($pgConfig, 'webhookReclaimExpired', [
            'tenant' => $tenant,
            'deliveryUuid' => $deliveryUuid,
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertTrue($result['ok'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame('stale', $result['outcome'], 'the sweep must lose the CAS once finalize already committed');

        $delivery = $connectionA->table('commerce_seller_webhook_deliveries')
            ->where('tenant_uuid', '=', $tenant)->where('uuid', '=', $deliveryUuid)->first();
        self::assertSame('delivered', $delivery['status']);
        self::assertNull($delivery['claim_token']);

        $this->cleanupTenant($connectionA, $tenant);
    }

    /**
     * Ordering (b): the RECLAIM commits first. Connection A manually
     * replicates {@see \Glueful\Extensions\Commerce\Marketplace\SellerWebhookDeliveryService::reclaimExpired()}'s
     * critical section (permissive seller + endpoint claim,
     * `SellerWebhookDeliveryRepository::reclaimExpired()` CAS with the
     * ORIGINAL claim token -> due `pending`), holding it open. Connection B
     * (subprocess, a faithful standalone replica of the private
     * `finalize()`'s OWN critical section -- see this section's own
     * docblock for why -- using the SAME stale token) blocks entirely on A's
     * held seller-revision claim; once A commits, B's own claim succeeds,
     * but its delivery-row CAS (`status = delivering AND claim_token = ?`)
     * now finds the row already `pending` with a CLEARED token -- 0 rows
     * affected. The zombie's stale token genuinely no-ops.
     */
    public function testReclaimExpiredCommitsFirstThenConcurrentStaleFinalizeLosesTheCasAndStaysPendingOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'whpgrcsf001';
        $this->cleanupTenant($connectionA, $tenant);

        $seller = $this->seedActiveSeller($contextA, $tenant, 'whpg-rcsf-s1', 'ownerRCSF001');
        $endpointUuid = $this->seedEndpoint($connectionA, $tenant, $seller['uuid'], ['order.placed']);
        $this->mintSecret($contextA, $tenant, $endpointUuid);
        $eventUuid = $this->seedEvent($connectionA, $tenant, $seller['uuid'], ['order_uuid' => 'ordRCSF0001']);
        $claimToken = bin2hex(random_bytes(16));
        $deliveryUuid = $this->seedDelivery($connectionA, $tenant, $endpointUuid, $eventUuid, $seller['uuid'], 'delivering', [
            'attempts' => 1,
            'claim_token' => $claimToken,
            'claim_expires_at' => gmdate('Y-m-d H:i:s', time() - 60),
        ]);

        $sellers = new SellerRepository();
        $endpoints = new SellerWebhookEndpointRepository();
        $deliveries = new SellerWebhookDeliveryRepository();
        $connectionA->getTransactionManager()->begin();
        $sellers->claimRevision($contextA, $tenant, $seller['uuid']);
        $endpoints->claimRevision($contextA, $tenant, $endpointUuid);
        $nowStr = gmdate('Y-m-d H:i:s');
        self::assertTrue($deliveries->reclaimExpired($contextA, $tenant, $deliveryUuid, $claimToken, [
            'status' => 'pending',
            'next_attempt_at' => $nowStr,
            'last_error' => 'Claim lease expired (worker unresponsive).',
        ], $nowStr));

        $handle = $this->launchRaceChild($pgConfig, 'webhookStaleFinalize', [
            'tenant' => $tenant,
            'sellerUuid' => $seller['uuid'],
            'endpointUuid' => $endpointUuid,
            'deliveryUuid' => $deliveryUuid,
            'claimToken' => $claimToken,
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertTrue($result['ok'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertFalse($result['affected'], 'the stale finalize must lose the CAS once the sweep already reclaimed it');

        $delivery = $connectionA->table('commerce_seller_webhook_deliveries')
            ->where('tenant_uuid', '=', $tenant)->where('uuid', '=', $deliveryUuid)->first();
        self::assertSame('pending', $delivery['status']);
        self::assertNull($delivery['claim_token']);

        $this->cleanupTenant($connectionA, $tenant);
    }

    // =====================================================================
    // 6. auto-disable vs endpoint-enable (design spec §2.7, endpoint
    //    revision -- the reset-then-recount linearization), BOTH orderings.
    // =====================================================================

    /**
     * Ordering (a): AUTO-DISABLE commits first. The endpoint starts `active`
     * with `consecutive_failures` one below the configured threshold (20 by
     * default) and TWO `pending` deliveries. Connection A manually
     * replicates the failure-finalize's auto-disable critical section
     * (permissive seller + endpoint claim, finalize the FIRST delivery
     * terminally, then -- mirroring {@see \Glueful\Extensions\Commerce\Marketplace\SellerWebhookDeliveryService::applyFailureAndMaybeAutoDisable()}
     * exactly -- increment `consecutive_failures` to the threshold, flip the
     * endpoint `disabled`, pause the SECOND delivery, audit `auto_disable`),
     * holding it open. Connection B (subprocess, the REAL `enable()`) blocks
     * entirely on A's held ENDPOINT-revision claim; once A commits, B's own
     * claim succeeds -- SSRF-revalidates, flips back `active`, resets the
     * counter to 0, and resumes the paused second delivery.
     */
    public function testAutoDisableCommitsFirstThenConcurrentEnableAppliesAfterAndResumesThePausedDeliveryOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'whpgadfs001';
        $this->cleanupTenant($connectionA, $tenant);

        $threshold = (int) config($contextA, 'commerce.marketplace.webhooks.consecutive_failure_disable_threshold', 20);

        $seller = $this->seedActiveSeller($contextA, $tenant, 'whpg-adfs-s1', 'ownerADFS001');
        $endpointUuid = $this->seedEndpoint(
            $connectionA,
            $tenant,
            $seller['uuid'],
            ['order.placed'],
            'active',
            $threshold - 1
        );
        $this->mintSecret($contextA, $tenant, $endpointUuid);
        $eventUuid = $this->seedEvent($connectionA, $tenant, $seller['uuid'], ['order_uuid' => 'ordADFS0001']);
        $failingDelivery = $this->seedDelivery($connectionA, $tenant, $endpointUuid, $eventUuid, $seller['uuid'], 'delivering', [
            'attempts' => 1,
            'claim_token' => 'tokADFS0001',
            'claim_expires_at' => gmdate('Y-m-d H:i:s', time() + 30),
        ]);
        $survivingDelivery = $this->seedDelivery($connectionA, $tenant, $endpointUuid, $eventUuid, $seller['uuid'], 'pending');

        $sellers = new SellerRepository();
        $endpoints = new SellerWebhookEndpointRepository();
        $deliveries = new SellerWebhookDeliveryRepository();
        $connectionA->getTransactionManager()->begin();
        $sellers->claimRevision($contextA, $tenant, $seller['uuid']);
        $endpoints->claimRevision($contextA, $tenant, $endpointUuid);
        $nowStr = gmdate('Y-m-d H:i:s');
        self::assertTrue($deliveries->finalize($contextA, $tenant, $failingDelivery, 'tokADFS0001', [
            'status' => 'dead_letter',
            'last_status_code' => 500,
            'last_error' => 'HTTP 500',
        ], $nowStr));
        $endpoints->update($contextA, $tenant, $endpointUuid, [
            'status' => 'disabled',
            'consecutive_failures' => $threshold,
            'disabled_at' => $nowStr,
            'disabled_reason' => 'auto_disabled: consecutive failure threshold reached',
            'updated_at' => $nowStr,
        ]);
        $deliveries->pauseOne($contextA, $tenant, $survivingDelivery, 'endpoint_disabled', $nowStr, 0);
        $endpoints->insertEvent($contextA, $tenant, [
            'uuid' => 'whaewevt0001',
            'endpoint_uuid' => $endpointUuid,
            'seller_uuid' => $seller['uuid'],
            'action' => 'auto_disable',
            'reason' => 'consecutive_failure_threshold_reached',
        ]);

        $handle = $this->launchRaceChild($pgConfig, 'webhookEnable', [
            'tenant' => $tenant,
            'sellerUuid' => $seller['uuid'],
            'endpointUuid' => $endpointUuid,
            'actor' => 'ownerADFS001',
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertTrue(
            $result['ok'] ?? false,
            'enable must succeed once the auto-disable has committed: ' . json_encode($result, JSON_THROW_ON_ERROR)
        );
        self::assertSame('active', $result['status']);
        self::assertSame(0, $result['consecutiveFailures']);

        $survivingRow = $connectionA->table('commerce_seller_webhook_deliveries')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $survivingDelivery)->first();
        self::assertSame('pending', $survivingRow['status'], 'enable must resume the endpoint_disabled-paused delivery');

        $this->cleanupTenant($connectionA, $tenant);
    }

    /**
     * Ordering (b): ENABLE commits first. The endpoint starts already
     * `disabled` (a PRIOR auto-disable event, seeded directly). The delivery
     * itself is seeded ALREADY `pending` (a deliberately constructed edge
     * state, purely to exercise this exact race -- {@see \Glueful\Extensions\Commerce\Marketplace\SellerWebhookDeliveryService::claim()}'s
     * OWN first step is an UNLOCKED pre-check on the delivery's `pending`
     * status, so if this row started `paused` instead, connection B's claim
     * would short-circuit on that unlocked read the instant it runs -- often
     * before connection A's held lock is even reachable -- never genuinely
     * blocking at all). Connection A manually replicates `enable()`'s
     * critical section (claim seller + endpoint, markEnabled resetting
     * `consecutive_failures` to 0), holding it open. Connection B
     * (subprocess, the REAL `deliver()`, scripted to receive an HTTP 500)
     * passes its own unlocked pre-check immediately, then blocks entirely on
     * A's held ENDPOINT-revision claim inside its OWN transaction; once A
     * commits, B's own claim succeeds (endpoint now active), the delivery
     * fails (retryable), and its failure-finalize reads the endpoint FRESH
     * -- the just-reset counter -- incrementing it to 1, NEVER re-disabling:
     * a claim that genuinely starts AFTER `enable()` commits must see the
     * fresh reset, never a stale pre-reset value.
     */
    public function testEnableCommitsFirstThenConcurrentDeliveryFailureIncrementsFromTheFreshResetCounterWithoutReDisablingOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $pgConfig = $this->pgConfig();
        $connectionA = $this->migratedConnection($pgConfig);
        $contextA = $this->pgsqlContext($connectionA);
        $tenant = 'whpgadsf001';
        $this->cleanupTenant($connectionA, $tenant);

        $threshold = (int) config($contextA, 'commerce.marketplace.webhooks.consecutive_failure_disable_threshold', 20);

        $seller = $this->seedActiveSeller($contextA, $tenant, 'whpg-adsf-s1', 'ownerADSF001');
        $endpointUuid = $this->seedEndpoint(
            $connectionA,
            $tenant,
            $seller['uuid'],
            ['order.placed'],
            'disabled',
            $threshold
        );
        $this->mintSecret($contextA, $tenant, $endpointUuid);
        $eventUuid = $this->seedEvent($connectionA, $tenant, $seller['uuid'], ['order_uuid' => 'ordADSF0001']);
        $deliveryUuid = $this->seedDelivery($connectionA, $tenant, $endpointUuid, $eventUuid, $seller['uuid'], 'pending');

        $sellers = new SellerRepository();
        $endpoints = new SellerWebhookEndpointRepository();
        $connectionA->getTransactionManager()->begin();
        self::assertTrue($sellers->claimRevision($contextA, $tenant, $seller['uuid']));
        self::assertTrue($endpoints->claimActiveRevision($contextA, $tenant, $endpointUuid));
        $nowStr = gmdate('Y-m-d H:i:s');
        $endpoints->markEnabled($contextA, $tenant, $endpointUuid, $nowStr);

        $handle = $this->launchRaceChild($pgConfig, 'webhookDeliver', [
            'tenant' => $tenant,
            'deliveryUuid' => $deliveryUuid,
            'statusCode' => 500,
        ]);

        usleep(300_000);

        $connectionA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertTrue($result['ok'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame('retry_scheduled', $result['outcome']);

        $endpointRow = $connectionA->table('commerce_seller_webhook_endpoints')
            ->where('tenant_uuid', '=', $tenant)->where('uuid', '=', $endpointUuid)->first();
        self::assertSame('active', $endpointRow['status'], 'a single failure right after reset must never re-disable');
        self::assertSame(1, (int) $endpointRow['consecutive_failures'], 'must increment from the FRESH reset (0), never a stale pre-reset count');

        $this->cleanupTenant($connectionA, $tenant);
    }

    // =====================================================================
    // 7. Migration-shape live (design spec §3): columns/uniques/indexes via
    //    pg_indexes, re-run 019 no-op.
    // =====================================================================

    public function testMigration019ConvergesWithColumnsUniquesAndIndexesViaPgIndexesAndRerunningIsANoOpOnRealPostgres(): void
    {
        $this->skipUnlessPgsql();

        $connection = $this->migratedConnection($this->pgConfig());
        $schema = $connection->getSchemaBuilder();

        foreach (
            [
                'commerce_seller_webhook_endpoints',
                'commerce_seller_webhook_secrets',
                'commerce_seller_webhook_events',
                'commerce_seller_webhook_deliveries',
                'commerce_seller_webhook_endpoint_events',
            ] as $table
        ) {
            self::assertTrue($schema->hasTable($table), "missing {$table} on PostgreSQL");
        }

        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_webhook_endpoints',
            'commerce_seller_webhook_endpoints_tenant_uuid_unique',
            ['tenant_uuid', 'uuid']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_webhook_endpoints',
            'commerce_seller_webhook_endpoints_seller_status_index',
            ['tenant_uuid', 'seller_uuid', 'status']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_webhook_secrets',
            'commerce_seller_webhook_secrets_tenant_uuid_unique',
            ['tenant_uuid', 'uuid']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_webhook_secrets',
            'commerce_seller_webhook_secrets_endpoint_rel_index',
            ['tenant_uuid', 'endpoint_uuid', 'relationship']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_webhook_events',
            'commerce_seller_webhook_events_tenant_uuid_unique',
            ['tenant_uuid', 'uuid']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_webhook_events',
            'commerce_seller_webhook_events_seller_created_index',
            ['tenant_uuid', 'seller_uuid', 'created_at']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_webhook_deliveries',
            'commerce_seller_webhook_deliveries_status_next_index',
            ['tenant_uuid', 'status', 'next_attempt_at']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_webhook_deliveries',
            'commerce_seller_webhook_deliveries_status_claim_index',
            ['tenant_uuid', 'status', 'claim_expires_at']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_webhook_deliveries',
            'commerce_seller_webhook_deliveries_endpoint_status_index',
            ['tenant_uuid', 'endpoint_uuid', 'status']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_webhook_deliveries',
            'commerce_seller_webhook_deliveries_event_index',
            ['tenant_uuid', 'webhook_event_uuid']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_webhook_endpoint_events',
            'commerce_seller_webhook_endpoint_events_tenant_uuid_unique',
            ['tenant_uuid', 'uuid']
        );
        $this->assertPgIndexExists(
            $connection,
            'commerce_seller_webhook_endpoint_events',
            'commerce_seller_webhook_endpoint_events_endpoint_created_index',
            ['tenant_uuid', 'endpoint_uuid', 'created_at']
        );

        // migratedConnection() already ran every migration (including 019) once;
        // re-running up() again must be a no-op guarded by hasTable().
        (new CreateSellerWebhookTables())->up($schema);
        (new CreateSellerWebhookTables())->up($schema);

        self::assertTrue($schema->hasTable('commerce_seller_webhook_deliveries'));
        self::assertTrue($schema->hasColumn('commerce_seller_webhook_deliveries', 'claim_token'));
    }

    // -----------------------------------------------------------------
    // Helpers: manual critical-section replicas for connection A.
    // -----------------------------------------------------------------

    /**
     * Mirrors {@see \Glueful\Extensions\Commerce\Marketplace\SellerWebhookDeliveryService::claim()}'s
     * critical section exactly (minus its own event-snapshot lookup,
     * irrelevant to holding the transaction open): claim the seller
     * revision, re-read active, claim the endpoint's ACTIVE revision,
     * re-read active, CAS the delivery pending -> delivering with a fresh
     * token. Left OPEN for the caller to commit/rollback.
     *
     * @return array{claim_token: string}
     */
    private function manuallyClaimDelivery(
        ApplicationContext $contextA,
        string $tenant,
        string $sellerUuid,
        string $endpointUuid,
        string $deliveryUuid
    ): array {
        $sellers = new SellerRepository();
        $endpoints = new SellerWebhookEndpointRepository();
        $deliveries = new SellerWebhookDeliveryRepository();

        self::assertTrue($sellers->claimRevision($contextA, $tenant, $sellerUuid));
        $seller = $sellers->findByUuid($contextA, $tenant, $sellerUuid);
        self::assertSame('active', $seller['status']);

        self::assertTrue($endpoints->claimActiveRevision($contextA, $tenant, $endpointUuid));
        $endpoint = $endpoints->findByUuid($contextA, $tenant, $endpointUuid);
        self::assertSame('active', $endpoint['status']);

        $claimToken = bin2hex(random_bytes(16));
        $claimExpiresAt = gmdate('Y-m-d H:i:s', time() + 30);
        self::assertTrue($deliveries->claimForDelivery(
            $contextA,
            $tenant,
            $deliveryUuid,
            $claimToken,
            $claimExpiresAt,
            gmdate('Y-m-d H:i:s')
        ));

        return ['claim_token' => $claimToken];
    }

    /**
     * Mirrors {@see \Glueful\Extensions\Commerce\Marketplace\SellerWebhookOutboxPublisher::capture()}'s
     * critical section for exactly ONE participating seller/endpoint (no
     * catalog projector needed -- a minimal hand-built payload is enough for
     * every race assertion in this file). Left OPEN for the caller to
     * commit/rollback.
     *
     * @return array{event_uuid: string, delivery_uuid: string}
     */
    private function manuallyCapture(
        ApplicationContext $contextA,
        string $tenant,
        string $sellerUuid,
        string $endpointUuid,
        string $orderUuid
    ): array {
        $sellers = new SellerRepository();
        $events = new SellerWebhookEventRepository();
        $deliveries = new SellerWebhookDeliveryRepository();

        self::assertTrue($sellers->claimRevision($contextA, $tenant, $sellerUuid));
        $seller = $sellers->findByUuid($contextA, $tenant, $sellerUuid);
        self::assertSame('active', $seller['status']);

        $eventUuid = $this->nextUuid('whev');
        $events->insert($contextA, $tenant, [
            'uuid' => $eventUuid,
            'seller_uuid' => $sellerUuid,
            'event_type' => 'order.placed',
            'payload' => json_encode(['order_uuid' => $orderUuid], JSON_THROW_ON_ERROR),
            'occurred_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $deliveryUuid = $this->nextUuid('whdl');
        $deliveries->insertPending($contextA, $tenant, [
            'uuid' => $deliveryUuid,
            'endpoint_uuid' => $endpointUuid,
            'webhook_event_uuid' => $eventUuid,
            'seller_uuid' => $sellerUuid,
            'next_attempt_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return ['event_uuid' => $eventUuid, 'delivery_uuid' => $deliveryUuid];
    }

    /**
     * Mirrors {@see \Glueful\Extensions\Commerce\Marketplace\SellerWebhookEndpointService::rotateSecret()}'s
     * critical section exactly: claim seller + endpoint revision, retire any
     * stale previous (none in this file's scenarios), demote current ->
     * previous with an overlap window, mint + insert a NEW current secret.
     * Left OPEN for the caller to commit/rollback.
     */
    private function manuallyRotateSecret(
        ApplicationContext $contextA,
        string $tenant,
        string $sellerUuid,
        string $endpointUuid
    ): string {
        $sellers = new SellerRepository();
        $endpoints = new SellerWebhookEndpointRepository();
        $secretService = new SellerWebhookSecretService($endpoints, new EncryptionService($contextA));

        self::assertTrue($sellers->claimRevision($contextA, $tenant, $sellerUuid));
        self::assertTrue($endpoints->claimActiveRevision($contextA, $tenant, $endpointUuid));

        $current = $endpoints->findCurrentSecret($contextA, $tenant, $endpointUuid);
        self::assertNotNull($current);
        $nowStr = gmdate('Y-m-d H:i:s');
        $overlapExpiresAt = gmdate('Y-m-d H:i:s', time() + (24 * 3600));
        $endpoints->demoteCurrentSecretToPrevious($contextA, $tenant, (string) $current['uuid'], $overlapExpiresAt);

        $newSecretUuid = $this->nextUuid('whsc');
        $minted = $secretService->mint($tenant, $endpointUuid, $newSecretUuid);
        $endpoints->insertSecret($contextA, $tenant, [
            'uuid' => $newSecretUuid,
            'endpoint_uuid' => $endpointUuid,
            'secret_ciphertext' => $minted['ciphertext'],
            'secret_fingerprint' => $minted['fingerprint'],
            'relationship' => 'current',
        ]);

        return $minted['plain'];
    }

    // -----------------------------------------------------------------
    // Helpers: seeding.
    // -----------------------------------------------------------------

    /** @return array<string,mixed> */
    private function seedActiveSeller(
        ApplicationContext $context,
        string $tenant,
        string $slug,
        string $ownerUserUuid
    ): array {
        return (new SellerService(
            new SellerRepository(),
            new SellerMembershipRepository(),
            new SellerLifecycleEventRepository()
        ))->create(
            $context,
            $tenant,
            $slug,
            ucfirst(str_replace('-', ' ', $slug)),
            null,
            $ownerUserUuid
        );
    }

    /** @param list<string> $events */
    private function seedEndpoint(
        Connection $connection,
        string $tenant,
        string $sellerUuid,
        array $events,
        string $status = 'active',
        int $consecutiveFailures = 0
    ): string {
        $uuid = $this->nextUuid('whep');
        $connection->table('commerce_seller_webhook_endpoints')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'seller_uuid' => $sellerUuid,
            'url' => 'https://race.example.test/hook',
            'subscribed_events' => json_encode($events, JSON_THROW_ON_ERROR),
            'status' => $status,
            'revision' => 0,
            'consecutive_failures' => $consecutiveFailures,
            'created_by' => 'creatorWHR01',
        ]);

        return $uuid;
    }

    /** @return array{uuid: string, plain: string} */
    private function mintSecret(ApplicationContext $context, string $tenant, string $endpointUuid): array
    {
        $endpoints = new SellerWebhookEndpointRepository();
        $secretService = new SellerWebhookSecretService($endpoints, new EncryptionService($context));
        $secretUuid = $this->nextUuid('whsc');
        $minted = $secretService->mint($tenant, $endpointUuid, $secretUuid);
        $endpoints->insertSecret($context, $tenant, [
            'uuid' => $secretUuid,
            'endpoint_uuid' => $endpointUuid,
            'secret_ciphertext' => $minted['ciphertext'],
            'secret_fingerprint' => $minted['fingerprint'],
            'relationship' => 'current',
        ]);

        return ['uuid' => $secretUuid, 'plain' => $minted['plain']];
    }

    private function currentSecretPlain(ApplicationContext $context, string $tenant, string $endpointUuid): string
    {
        $endpoints = new SellerWebhookEndpointRepository();
        $secretService = new SellerWebhookSecretService($endpoints, new EncryptionService($context));
        $endpoint = $endpoints->findByUuid($context, $tenant, $endpointUuid);
        self::assertNotNull($endpoint);

        return $secretService->currentSecretPlain($context, $tenant, $endpoint);
    }

    /** @param array<string,mixed> $payload */
    private function seedEvent(Connection $connection, string $tenant, string $sellerUuid, array $payload): string
    {
        $uuid = $this->nextUuid('whev');
        $connection->table('commerce_seller_webhook_events')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'seller_uuid' => $sellerUuid,
            'event_type' => 'order.placed',
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'occurred_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return $uuid;
    }

    /** @param array<string,mixed> $overrides */
    private function seedDelivery(
        Connection $connection,
        string $tenant,
        string $endpointUuid,
        string $eventUuid,
        string $sellerUuid,
        string $status,
        array $overrides = []
    ): string {
        $uuid = $this->nextUuid('whdl');
        $connection->table('commerce_seller_webhook_deliveries')->insert(array_merge([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'endpoint_uuid' => $endpointUuid,
            'webhook_event_uuid' => $eventUuid,
            'seller_uuid' => $sellerUuid,
            'status' => $status,
            'attempts' => 0,
            'next_attempt_at' => gmdate('Y-m-d H:i:s'),
        ], $overrides));

        return $uuid;
    }

    private function nextUuid(string $prefix): string
    {
        $this->seq++;

        return $prefix . str_pad((string) $this->seq, 8, '0', STR_PAD_LEFT);
    }

    // -----------------------------------------------------------------
    // Helpers: harness plumbing (mirrors SellerSuspensionPgsqlTest exactly).
    // -----------------------------------------------------------------

    private function skipUnlessPgsql(): void
    {
        if (getenv('COMMERCE_TEST_DB_DRIVER') !== 'pgsql') {
            self::markTestSkipped('Requires a PostgreSQL test lane for true two-connection row-lock interleaving.');
        }
    }

    private function cleanupTenant(Connection $connection, string $tenant): void
    {
        $connection->table('commerce_seller_webhook_endpoint_events')->where('tenant_uuid', '=', $tenant)->delete();
        $connection->table('commerce_seller_webhook_deliveries')->where('tenant_uuid', '=', $tenant)->delete();
        $connection->table('commerce_seller_webhook_events')->where('tenant_uuid', '=', $tenant)->delete();
        $connection->table('commerce_seller_webhook_secrets')->where('tenant_uuid', '=', $tenant)->delete();
        $connection->table('commerce_seller_webhook_endpoints')->withTrashed()->where('tenant_uuid', '=', $tenant)->forceDelete();
        $connection->table('commerce_seller_memberships')->where('tenant_uuid', '=', $tenant)->delete();
        $connection->table('commerce_sellers')->where('tenant_uuid', '=', $tenant)->delete();
    }

    /**
     * @param array<string,mixed> $pgConfig
     * @param array<string,mixed> $args
     * @return array{0: resource, 1: array<int,resource>}
     */
    private function launchRaceChild(array $pgConfig, string $action, array $args): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/fixtures/seller_webhook_race_child.php',
                json_encode($pgConfig, JSON_THROW_ON_ERROR),
                $action,
                json_encode($args, JSON_THROW_ON_ERROR),
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        return [$process, $pipes];
    }

    /**
     * @param array{0: resource, 1: array<int,resource>} $handle
     * @return array<string,mixed>
     */
    private function collectRaceChild(array $handle): array
    {
        [$process, $pipes] = $handle;
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $result = json_decode(trim((string) $stdout), true);
        self::assertIsArray($result, "subprocess produced no parseable result. stderr: {$stderr}");

        return $result;
    }

    /**
     * `pg_indexes.indexdef` looks like `CREATE INDEX name ON public.table
     * USING btree (col_a, col_b)` (or `CREATE UNIQUE INDEX ...` for a named
     * unique constraint) -- the column list (in order) is the content of the
     * LAST parenthesized group. Mirrors `SellerSuspensionPgsqlTest`'s/
     * `SellerApiKeyPgsqlTest`'s identical helper exactly.
     *
     * @param list<string> $expectedColumns ordered, leading column first
     */
    private function assertPgIndexExists(
        Connection $connection,
        string $table,
        string $indexName,
        array $expectedColumns
    ): void {
        $pdo = $connection->getPDO();
        $stmt = $pdo->prepare('SELECT indexdef FROM pg_indexes WHERE tablename = ? AND indexname = ?');
        $stmt->execute([$table, $indexName]);
        $indexDef = $stmt->fetchColumn();

        self::assertIsString($indexDef, "missing index {$indexName} on {$table} (pg_indexes)");
        self::assertMatchesRegularExpression('/\(([^()]+)\)\s*$/', $indexDef, "unparseable indexdef: {$indexDef}");
        preg_match('/\(([^()]+)\)\s*$/', $indexDef, $matches);
        $actualColumns = array_map('trim', explode(',', $matches[1]));

        self::assertSame($expectedColumns, $actualColumns, "unexpected column set/order for {$indexName}");
    }

    /** @return array<string,mixed> */
    private function pgConfig(): array
    {
        return [
            'engine' => 'pgsql',
            'pgsql' => [
                'host' => getenv('DB_PGSQL_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('DB_PGSQL_PORT') ?: 5432),
                'db' => getenv('DB_PGSQL_DATABASE') ?: 'glueful_test',
                'user' => getenv('DB_PGSQL_USERNAME') ?: 'postgres',
                'pass' => getenv('DB_PGSQL_PASSWORD') ?: '',
                'schema' => getenv('DB_PGSQL_SCHEMA') ?: 'public',
            ],
            'pooling' => ['enabled' => false],
        ];
    }

    /** @param array<string,mixed> $pgConfig */
    private function migratedConnection(array $pgConfig): Connection
    {
        $connection = new Connection($pgConfig);
        $schema = $connection->getSchemaBuilder();
        foreach (static::MIGRATIONS as $migration) {
            (new $migration())->up($schema);
        }

        return $connection;
    }

    private function pgsqlContext(Connection $connection): ApplicationContext
    {
        $container = new class ($connection) implements ContainerInterface {
            public function __construct(private Connection $connection)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === 'database' || $id === Connection::class) {
                    return $this->connection;
                }

                throw new \RuntimeException("Unknown service: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === 'database' || $id === Connection::class;
            }
        };

        $context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
        $context->setContainer($container);
        $context->mergeConfigDefaults('commerce', require __DIR__ . '/../../../config/commerce.php');
        $context->mergeConfigDefaults('commerce', ['marketplace' => ['enabled' => true]]);
        // Mirrors fixtures/seller_webhook_race_child.php's OWN identical
        // fixed-key override -- a secret minted by ONE process must be
        // decryptable by the OTHER (see this class's own docblock).
        $context->overrideConfig('encryption.key', 'base64:' . base64_encode(str_repeat('k', 32)));

        return $context;
    }
}
