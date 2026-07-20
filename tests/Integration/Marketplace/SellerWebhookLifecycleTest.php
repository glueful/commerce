<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Encryption\EncryptionService;
use Glueful\Extensions\Commerce\Marketplace\FixedSellerRoleAuthority;
use Glueful\Extensions\Commerce\Marketplace\SellerLifecycleEventRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerMembershipRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerService;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookDeliveryRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookDeliveryService;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookEndpointRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookEndpointService;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookEventRepository;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookException;
use Glueful\Extensions\Commerce\Marketplace\SellerWebhookSecretService;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Http\Client;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Security\SafeOutboundTargetResolver;
use Glueful\Queue\QueueManager;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Marketplace MV5c-2 Task 6 (design spec §2.8/§2.9): webhook seller/endpoint
 * lifecycle -- suspend-pause, reinstate-restore, close-cancel -- wired INTO
 * MV5b's {@see SellerService}, plus dead-letter replay, plus the T5-review
 * M1 fix (a CLOSED seller's claim/reclaim refusal terminally cancels,
 * never pauses).
 *
 * **Live pgsql orderings (Task 8, NOT reproducible on this single-connection
 * SQLite harness):** capture-first (a NEW pending row lands) racing a
 * suspension transaction that then claims the seller revision -- the SAME
 * ordering T5's review flagged as deferred to here/Task 8; suspend/close vs
 * a concurrent single-endpoint management mutation on the SAME seller (both
 * now claim every one of the seller's endpoint revisions, so a real
 * connection-level lock wait is the only way to observe true serialization,
 * not merely sequential calls on one connection).
 */
final class SellerWebhookLifecycleTest extends CommerceTestCase
{
    private const TENANT = 'tenantWHLC1';
    private const SAFE_HOST = 'whlifesafe.example.test';
    private const SAFE_IP = '1.1.1.1';

    private int $seq = 0;

    // -----------------------------------------------------------------
    // Suspend/reactivate: seller_suspended vs endpoint_disabled never
    // cross-touch; endpoint management refused while suspended; both
    // tracks resolve correctly and independently once reinstated.
    // -----------------------------------------------------------------

    public function testSuspendReactivateAndEndpointDisableEnableNeverCrossTouchEachOthersPauseReason(): void
    {
        $owner = 'ownerWHLC0001';
        $seeded = $this->seedActiveSellerWithEndpoint('sellerWHLC0001', $owner, 'a');
        $sellerUuid = $seeded['seller']['uuid'];
        $endpointA = $seeded['endpoint']['uuid'];

        $endpointB = $this->endpointService()->register(
            $this->context,
            self::TENANT,
            $sellerUuid,
            'https://' . self::SAFE_HOST . '/hook-b',
            ['order.placed'],
            $owner
        )['endpoint']['uuid'];

        $a1 = $this->seedDelivery($endpointA, $sellerUuid, ['status' => 'pending']);
        $b1 = $this->seedDelivery($endpointB, $sellerUuid, ['status' => 'pending']);

        // Endpoint A goes disabled FIRST (its own, independent track).
        $this->endpointService()->disable($this->context, self::TENANT, $sellerUuid, $endpointA, $owner);
        $rowA = $this->deliveryRow($a1);
        self::assertSame('paused', $rowA['status']);
        self::assertSame('endpoint_disabled', $rowA['pause_reason']);

        // Suspending the seller must pause ONLY endpoint B's still-pending
        // row -- endpoint A's ALREADY endpoint_disabled-paused row must be
        // completely untouched (same reason, same timestamps).
        $this->sellerService()->suspend($this->context, self::TENANT, $sellerUuid, 'Fraud review.', 'opWHLC001');

        $rowAAfterSuspend = $this->deliveryRow($a1);
        self::assertSame('paused', $rowAAfterSuspend['status']);
        self::assertSame('endpoint_disabled', $rowAAfterSuspend['pause_reason']);
        self::assertSame(
            $rowA['paused_at'],
            $rowAAfterSuspend['paused_at'],
            'endpoint_disabled pause must be untouched by suspend'
        );

        $rowB = $this->deliveryRow($b1);
        self::assertSame('paused', $rowB['status']);
        self::assertSame('seller_suspended', $rowB['pause_reason']);

        // Endpoint management is unavailable while the seller is suspended
        // (design spec §2.9) -- enable() on the disabled endpoint refuses.
        try {
            $this->endpointService()->enable($this->context, self::TENANT, $sellerUuid, $endpointA, $owner);
            self::fail('expected enable() to refuse while the seller is suspended');
        } catch (SellerWebhookException $e) {
            self::assertSame('seller_inactive', $e->errorCode);
        }

        // Reactivating must restore ONLY the seller_suspended row (B) --
        // endpoint A's endpoint_disabled row must STILL be untouched.
        $this->sellerService()->reactivate($this->context, self::TENANT, $sellerUuid, 'Cleared.', 'opWHLC002');

        $rowBAfterReactivate = $this->deliveryRow($b1);
        self::assertSame('pending', $rowBAfterReactivate['status']);
        self::assertNull($rowBAfterReactivate['pause_reason']);
        self::assertNull($rowBAfterReactivate['paused_remaining_seconds']);

        $rowAAfterReactivate = $this->deliveryRow($a1);
        self::assertSame(
            'paused',
            $rowAAfterReactivate['status'],
            'endpoint_disabled pause must survive a seller reactivate -- only explicit endpoint enable clears it'
        );
        self::assertSame('endpoint_disabled', $rowAAfterReactivate['pause_reason']);

        // NOW endpoint A can be explicitly enabled -- resuming ONLY its own
        // endpoint_disabled row, leaving B (already pending) untouched.
        $this->endpointService()->enable($this->context, self::TENANT, $sellerUuid, $endpointA, $owner);

        $rowAAfterEnable = $this->deliveryRow($a1);
        self::assertSame('pending', $rowAAfterEnable['status']);
        self::assertNull($rowAAfterEnable['pause_reason']);

        $rowBFinal = $this->deliveryRow($b1);
        self::assertSame('pending', $rowBFinal['status']);
    }

    // -----------------------------------------------------------------
    // Suspend/reactivate: remaining-delay freeze/restore arithmetic.
    // -----------------------------------------------------------------

    public function testReactivateReconstructsNextAttemptFromPersistedRemainingDelayNotElapsedWallClock(): void
    {
        $owner = 'ownerWHLC0003';
        $seeded = $this->seedActiveSellerWithEndpoint('sellerWHLC0003', $owner, 'a');
        $sellerUuid = $seeded['seller']['uuid'];

        // 40 seconds from due at the moment of suspension.
        $deliveryUuid = $this->seedDelivery($seeded['endpoint']['uuid'], $sellerUuid, [
            'status' => 'pending',
            'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + 40),
        ]);

        $this->sellerService()->suspend($this->context, self::TENANT, $sellerUuid, 'Under review.', 'opWHLC003');

        $paused = $this->deliveryRow($deliveryUuid);
        self::assertSame('paused', $paused['status']);
        self::assertSame('seller_suspended', $paused['pause_reason']);
        $remaining = (int) $paused['paused_remaining_seconds'];
        self::assertGreaterThanOrEqual(35, $remaining);
        self::assertLessThanOrEqual(40, $remaining);

        // Simulate a LOT of wall-clock time having passed since the pause --
        // if reinstatement wrongly derived the delay from elapsed real time
        // instead of the PERSISTED remaining-seconds snapshot, this would
        // make the row look either already-overdue or wildly wrong.
        $this->connection->table('commerce_seller_webhook_deliveries')
            ->where('uuid', '=', $deliveryUuid)
            ->update(['paused_at' => gmdate('Y-m-d H:i:s', time() - 1000)]);

        $this->sellerService()->reactivate($this->context, self::TENANT, $sellerUuid, 'Cleared.', 'opWHLC004');

        $resumed = $this->deliveryRow($deliveryUuid);
        self::assertSame('pending', $resumed['status']);
        self::assertNull($resumed['pause_reason']);
        self::assertNull($resumed['paused_remaining_seconds']);

        $delaySeconds = strtotime((string) $resumed['next_attempt_at']) - time();
        self::assertGreaterThanOrEqual(
            30,
            $delaySeconds,
            'must still be ~40s out -- reconstructed from DB-now + persisted remaining'
        );
        self::assertLessThanOrEqual(45, $delaySeconds);
    }

    // -----------------------------------------------------------------
    // Suspend: only PENDING rows pause; a due PAUSED row never delivers.
    // -----------------------------------------------------------------

    public function testADuePausedRowNeverDelivers(): void
    {
        $seeded = $this->seedActiveSellerWithEndpoint('sellerWHLC0005', 'ownerWHLC0005', 'a');

        $deliveryUuid = $this->seedDelivery($seeded['endpoint']['uuid'], $seeded['seller']['uuid'], [
            'status' => 'paused',
            'pause_reason' => 'endpoint_disabled',
            'paused_at' => gmdate('Y-m-d H:i:s'),
            'paused_remaining_seconds' => 0,
            // Deliberately "due"-looking despite being paused.
            'next_attempt_at' => gmdate('Y-m-d H:i:s', time() - 3600),
        ]);

        $httpCalls = 0;
        $service = $this->deliveryService(function () use (&$httpCalls) {
            $httpCalls++;

            return new MockResponse('unused', ['http_code' => 200]);
        });

        $outcome = $service->deliver($this->context, self::TENANT, $deliveryUuid);

        self::assertSame('not_claimed', $outcome);
        self::assertSame(0, $httpCalls, 'a paused row must never reach HTTP delivery regardless of next_attempt_at');
        $row = $this->deliveryRow($deliveryUuid);
        self::assertSame('paused', $row['status'], 'a due-looking paused row must stay paused');
    }

    // -----------------------------------------------------------------
    // Close: cancels ALL pending/paused (any reason) + disables endpoints.
    // -----------------------------------------------------------------

    public function testCloseDisablesActiveEndpointsAndCancelsEveryPendingAndPausedDeliveryRegardlessOfReason(): void
    {
        $owner = 'ownerWHLC0006';
        $seeded = $this->seedActiveSellerWithEndpoint('sellerWHLC0006', $owner, 'a');
        $sellerUuid = $seeded['seller']['uuid'];
        $endpointA = $seeded['endpoint']['uuid'];

        $endpointB = $this->endpointService()->register(
            $this->context,
            self::TENANT,
            $sellerUuid,
            'https://' . self::SAFE_HOST . '/hook-b',
            ['order.placed'],
            $owner
        )['endpoint']['uuid'];

        // Endpoint B is disabled BEFORE close -- close must not re-disable
        // it (no duplicate audit row) but MUST still cancel its paused work.
        $this->endpointService()->disable($this->context, self::TENANT, $sellerUuid, $endpointB, $owner);

        $pendingOnA = $this->seedDelivery($endpointA, $sellerUuid, ['status' => 'pending']);
        $endpointDisabledOnB = $this->seedDelivery($endpointB, $sellerUuid, [
            'status' => 'paused',
            'pause_reason' => 'endpoint_disabled',
        ]);
        // A stray seller_suspended-paused row (any reason must be canceled).
        $sellerSuspendedOnA = $this->seedDelivery($endpointA, $sellerUuid, [
            'status' => 'paused',
            'pause_reason' => 'seller_suspended',
        ]);

        $closed = $this->sellerService()->close(
            $this->context,
            self::TENANT,
            $sellerUuid,
            'Shutting down.',
            'opWHLC005'
        );
        self::assertSame('closed', $closed['status']);

        $endpointARow = $this->connection->table('commerce_seller_webhook_endpoints')
            ->where('uuid', '=', $endpointA)->first();
        self::assertSame('disabled', $endpointARow['status']);
        self::assertSame('seller_closed', $endpointARow['disabled_reason']);

        $endpointBRow = $this->connection->table('commerce_seller_webhook_endpoints')
            ->where('uuid', '=', $endpointB)->first();
        self::assertSame('disabled', $endpointBRow['status']);

        // Exactly ONE 'disable' audit row for A (from close), and exactly
        // ONE for B (from the PRE-close manual disable -- close must not
        // add a second one for an already-disabled endpoint).
        $disableEventsA = $this->connection->table('commerce_seller_webhook_endpoint_events')
            ->where('endpoint_uuid', '=', $endpointA)->where('action', '=', 'disable')->count();
        self::assertSame(1, $disableEventsA);
        $disableEventsB = $this->connection->table('commerce_seller_webhook_endpoint_events')
            ->where('endpoint_uuid', '=', $endpointB)->where('action', '=', 'disable')->count();
        self::assertSame(1, $disableEventsB);

        foreach ([$pendingOnA, $endpointDisabledOnB, $sellerSuspendedOnA] as $uuid) {
            self::assertSame(
                'canceled',
                $this->deliveryRow($uuid)['status'],
                "delivery {$uuid} must be canceled by close()"
            );
        }
    }

    public function testCloseIsANoOpForWebhooksWhenTheSellerNeverRegisteredAnEndpoint(): void
    {
        $seller = $this->sellerService()->create(
            $this->context,
            self::TENANT,
            'sellerWHLC0007',
            'No Webhooks',
            null,
            'ownerWHLC0007'
        );

        $closed = $this->sellerService()->close($this->context, self::TENANT, $seller['uuid'], 'Done.', 'opWHLC006');
        self::assertSame('closed', $closed['status']);
    }

    // -----------------------------------------------------------------
    // Endpoint delete: tombstone retains history, revokes secret, cancels
    // pending/paused, and is never replayable.
    // -----------------------------------------------------------------

    public function testDeleteTombstonesRevokesSecretCancelsPendingAndMakesReplayImpossible(): void
    {
        $owner = 'ownerWHLC0008';
        $seeded = $this->seedActiveSellerWithEndpoint('sellerWHLC0008', $owner, 'a');
        $sellerUuid = $seeded['seller']['uuid'];
        $endpointUuid = $seeded['endpoint']['uuid'];

        $eventUuid = $this->seedEvent($sellerUuid, ['x' => 1]);
        $pendingUuid = $this->seedDelivery($endpointUuid, $sellerUuid, [
            'status' => 'pending',
            'webhook_event_uuid' => $eventUuid,
        ]);
        $deadLetterUuid = $this->seedDelivery($endpointUuid, $sellerUuid, [
            'status' => 'dead_letter',
            'webhook_event_uuid' => $eventUuid,
            'attempts' => 5,
        ]);

        $this->endpointService()->delete($this->context, self::TENANT, $sellerUuid, $endpointUuid, $owner);

        $secrets = $this->connection->table('commerce_seller_webhook_secrets')
            ->where('endpoint_uuid', '=', $endpointUuid)->get();
        self::assertNotEmpty($secrets);
        foreach ($secrets as $secret) {
            self::assertNotNull($secret['revoked_at']);
        }

        self::assertSame('canceled', $this->deliveryRow($pendingUuid)['status']);
        // A dead_letter row is retained AS-IS (delete only sweeps pending/paused);
        // it becomes non-replayable through the tombstoned endpoint instead.
        self::assertSame('dead_letter', $this->deliveryRow($deadLetterUuid)['status']);

        $trashed = $this->connection->table('commerce_seller_webhook_endpoints')
            ->withTrashed()->where('uuid', '=', $endpointUuid)->first();
        self::assertSame('deleted', $trashed['status']);

        $this->expectException(NotFoundException::class);
        $this->deliveryService(fn () => new MockResponse('unused'))
            ->replay($this->context, self::TENANT, $deadLetterUuid);
    }

    // -----------------------------------------------------------------
    // Replay: new lineage, original untouched; canceled/ineligible refused.
    // -----------------------------------------------------------------

    public function testReplayADeadLetterDeliveryCreatesANewLineageWithoutMutatingTheOriginal(): void
    {
        $owner = 'ownerWHLC0009';
        $seeded = $this->seedActiveSellerWithEndpoint('sellerWHLC0009', $owner, 'a');
        $sellerUuid = $seeded['seller']['uuid'];
        $endpointUuid = $seeded['endpoint']['uuid'];

        $eventUuid = $this->seedEvent($sellerUuid, ['order_uuid' => 'orderWHLC001']);
        $originalUuid = $this->seedDelivery($endpointUuid, $sellerUuid, [
            'status' => 'dead_letter',
            'webhook_event_uuid' => $eventUuid,
            'attempts' => 7,
            'last_status_code' => 500,
            'last_error' => 'gave up',
        ]);
        $originalBefore = $this->deliveryRow($originalUuid);

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

        $service = $this->deliveryService(fn () => new MockResponse('unused'));
        $replay = $service->replay($this->context, self::TENANT, $originalUuid);

        self::assertSame('pending', $replay['status']);
        self::assertSame(0, (int) $replay['attempts']);
        self::assertSame($originalUuid, $replay['replay_of_uuid']);
        self::assertSame($eventUuid, $replay['webhook_event_uuid'], 'replay references the SAME event snapshot');
        self::assertNotSame($originalUuid, $replay['uuid']);
        self::assertNotNull($replay['next_attempt_at']);
        self::assertLessThanOrEqual(time() + 1, strtotime((string) $replay['next_attempt_at']));

        // The original's own history is untouched.
        $originalAfter = $this->deliveryRow($originalUuid);
        self::assertSame($originalBefore, $originalAfter, "replay must never mutate the original's row");

        // Enqueue hint fired for the NEW row (after commit).
        self::assertContains($replay['uuid'], $pushed);
    }

    public function testReplayRefusesACanceledDeliveryAndCreatesNothing(): void
    {
        $seeded = $this->seedActiveSellerWithEndpoint('sellerWHLC0010', 'ownerWHLC0010', 'a');
        $eventUuid = $this->seedEvent($seeded['seller']['uuid'], ['x' => 1]);
        $canceledUuid = $this->seedDelivery($seeded['endpoint']['uuid'], $seeded['seller']['uuid'], [
            'status' => 'canceled',
            'webhook_event_uuid' => $eventUuid,
        ]);

        $countBefore = $this->connection->table('commerce_seller_webhook_deliveries')
            ->where('webhook_event_uuid', '=', $eventUuid)->count();

        try {
            $this->deliveryService(fn () => new MockResponse('unused'))
                ->replay($this->context, self::TENANT, $canceledUuid);
            self::fail('expected a canceled delivery to refuse replay');
        } catch (SellerWebhookException $e) {
            self::assertSame('delivery_not_replayable', $e->errorCode);
        }

        self::assertSame('canceled', $this->deliveryRow($canceledUuid)['status']);
        self::assertSame(
            $countBefore,
            $this->connection->table('commerce_seller_webhook_deliveries')
                ->where('webhook_event_uuid', '=', $eventUuid)->count(),
            'a refused replay must never insert a row'
        );
    }

    public function testReplayRefusesWhenTheOwningSellerIsNotActive(): void
    {
        $seeded = $this->seedActiveSellerWithEndpoint('sellerWHLC0011', 'ownerWHLC0011', 'a');
        $eventUuid = $this->seedEvent($seeded['seller']['uuid'], ['x' => 1]);
        $deadLetterUuid = $this->seedDelivery($seeded['endpoint']['uuid'], $seeded['seller']['uuid'], [
            'status' => 'dead_letter',
            'webhook_event_uuid' => $eventUuid,
        ]);

        $this->connection->table('commerce_sellers')
            ->where('uuid', '=', $seeded['seller']['uuid'])->update(['status' => 'suspended']);

        try {
            $this->deliveryService(fn () => new MockResponse('unused'))
                ->replay($this->context, self::TENANT, $deadLetterUuid);
            self::fail('expected a suspended seller to refuse replay');
        } catch (SellerWebhookException $e) {
            self::assertSame('seller_inactive', $e->errorCode);
        }

        self::assertSame('dead_letter', $this->deliveryRow($deadLetterUuid)['status']);
    }

    public function testReplayRefusesWhenTheEndpointIsDisabled(): void
    {
        $owner = 'ownerWHLC0012';
        $seeded = $this->seedActiveSellerWithEndpoint('sellerWHLC0012', $owner, 'a');
        $sellerUuid = $seeded['seller']['uuid'];
        $endpointUuid = $seeded['endpoint']['uuid'];
        $eventUuid = $this->seedEvent($sellerUuid, ['x' => 1]);
        $deadLetterUuid = $this->seedDelivery($endpointUuid, $sellerUuid, [
            'status' => 'dead_letter',
            'webhook_event_uuid' => $eventUuid,
        ]);

        $this->endpointService()->disable($this->context, self::TENANT, $sellerUuid, $endpointUuid, $owner);

        try {
            $this->deliveryService(fn () => new MockResponse('unused'))
                ->replay($this->context, self::TENANT, $deadLetterUuid);
            self::fail('expected a disabled endpoint to refuse replay');
        } catch (SellerWebhookException $e) {
            self::assertSame('endpoint_inactive', $e->errorCode);
        }
    }

    // -----------------------------------------------------------------
    // M1 (T5-review CARRY-FORWARD): a CLOSED seller's due/expired delivery
    // ends canceled, never paused -- distinct from a merely suspended one.
    // -----------------------------------------------------------------

    public function testAClosedSellersDuePendingDeliveryEndsCanceledNotPausedOnClaim(): void
    {
        $seeded = $this->seedActiveSellerWithEndpoint('sellerWHLC0013', 'ownerWHLC0013', 'a');
        $sellerUuid = $seeded['seller']['uuid'];
        $deliveryUuid = $this->seedDelivery($seeded['endpoint']['uuid'], $sellerUuid, ['status' => 'pending']);

        // Raw status flip -- isolates the DELIVERY SERVICE's own claim-time
        // fix from SellerService::close()'s own (already-eager) sweep.
        $this->connection->table('commerce_sellers')->where('uuid', '=', $sellerUuid)->update(['status' => 'closed']);
        $endpointRevisionBefore = (int) $this->connection->table('commerce_seller_webhook_endpoints')
            ->where('uuid', '=', $seeded['endpoint']['uuid'])->first()['revision'];

        $httpCalls = 0;
        $service = $this->deliveryService(function () use (&$httpCalls) {
            $httpCalls++;

            return new MockResponse('unused', ['http_code' => 200]);
        });

        $outcome = $service->deliver($this->context, self::TENANT, $deliveryUuid);

        self::assertSame('not_claimed', $outcome);
        self::assertSame(0, $httpCalls);

        $row = $this->deliveryRow($deliveryUuid);
        self::assertSame('canceled', $row['status'], 'a closed seller\'s refused claim must cancel, never pause');
        self::assertNull($row['pause_reason']);
        self::assertSame(0, (int) $row['attempts'], 'a refused claim must never increment attempts');

        $endpoint = $this->connection->table('commerce_seller_webhook_endpoints')
            ->where('uuid', '=', $seeded['endpoint']['uuid'])->first();
        self::assertSame(
            $endpointRevisionBefore,
            (int) $endpoint['revision'],
            'the endpoint must never be claimed once the seller check already refused'
        );
    }

    public function testAClosedSellersExpiredDeliveringLeaseEndsCanceledNotPausedOnReclaim(): void
    {
        $seeded = $this->seedActiveSellerWithEndpoint('sellerWHLC0014', 'ownerWHLC0014', 'a');
        $sellerUuid = $seeded['seller']['uuid'];
        $deliveryUuid = $this->seedDelivery($seeded['endpoint']['uuid'], $sellerUuid, [
            'status' => 'delivering',
            'attempts' => 1,
            'claim_token' => 'closedtoken000000001',
            'claim_expires_at' => gmdate('Y-m-d H:i:s', time() - 60),
            'last_attempt_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $this->connection->table('commerce_sellers')->where('uuid', '=', $sellerUuid)->update(['status' => 'closed']);

        $service = $this->deliveryService(fn () => new MockResponse('unused'));
        $outcome = $service->reclaimExpired($this->context, self::TENANT, $deliveryUuid);

        self::assertSame('canceled', $outcome);
        $row = $this->deliveryRow($deliveryUuid);
        self::assertSame('canceled', $row['status']);
        self::assertNull($row['claim_token']);
        self::assertNull($row['claim_expires_at']);
        self::assertSame(1, (int) $row['attempts'], 'reclaim must never touch attempts');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /** @return array{seller: array<string,mixed>, endpoint: array<string,mixed>, secret: string} */
    private function seedActiveSellerWithEndpoint(string $slug, string $ownerUuid, string $suffix): array
    {
        $seller = $this->sellerService()->create($this->context, self::TENANT, $slug, $slug, null, $ownerUuid);

        $registered = $this->endpointService()->register(
            $this->context,
            self::TENANT,
            $seller['uuid'],
            'https://' . self::SAFE_HOST . '/hook-' . $suffix,
            ['order.placed'],
            $ownerUuid
        );

        return ['seller' => $seller, 'endpoint' => $registered['endpoint'], 'secret' => $registered['secret']];
    }

    private function seedEvent(string $sellerUuid, array $payload): string
    {
        $eventUuid = $this->nextId('e');
        $this->connection->table('commerce_seller_webhook_events')->insert([
            'uuid' => $eventUuid,
            'tenant_uuid' => self::TENANT,
            'seller_uuid' => $sellerUuid,
            'event_type' => 'order.placed',
            'payload' => (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'occurred_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return $eventUuid;
    }

    /** @param array<string,mixed> $overrides */
    private function seedDelivery(string $endpointUuid, string $sellerUuid, array $overrides = []): string
    {
        $uuid = $this->nextId('d');
        $this->connection->table('commerce_seller_webhook_deliveries')->insert(array_merge([
            'uuid' => $uuid,
            'tenant_uuid' => self::TENANT,
            'endpoint_uuid' => $endpointUuid,
            'webhook_event_uuid' => 'wheventseed01',
            'seller_uuid' => $sellerUuid,
            'next_attempt_at' => gmdate('Y-m-d H:i:s', time() - 5),
        ], $overrides));

        return $uuid;
    }

    /** @return array<string,mixed> */
    private function deliveryRow(string $uuid): array
    {
        $row = $this->connection->table('commerce_seller_webhook_deliveries')->where('uuid', '=', $uuid)->first();
        self::assertNotNull($row);

        return $row;
    }

    private function nextId(string $prefix): string
    {
        $this->seq++;

        return $prefix . str_pad((string) $this->seq, 11, '0', STR_PAD_LEFT);
    }

    private function sellerService(): SellerService
    {
        return new SellerService(
            new SellerRepository(),
            new SellerMembershipRepository(),
            new SellerLifecycleEventRepository(),
            null,
            null,
            $this->endpointsRepo(),
            $this->deliveriesRepo()
        );
    }

    private function endpointService(?callable $uuidGenerator = null): SellerWebhookEndpointService
    {
        $resolver = new SafeOutboundTargetResolver(
            static fn (string $host): array => [self::SAFE_HOST => [self::SAFE_IP]][$host] ?? [self::SAFE_IP]
        );

        return new SellerWebhookEndpointService(
            new SellerRepository(),
            new SellerMembershipRepository(),
            $this->endpointsRepo(),
            $this->deliveriesRepo(),
            new FixedSellerRoleAuthority(),
            $this->secretService(),
            $resolver,
            $uuidGenerator
        );
    }

    private function deliveryService(callable $httpFactory): SellerWebhookDeliveryService
    {
        $resolver = new SafeOutboundTargetResolver(
            static fn (string $host): array => [self::SAFE_HOST => [self::SAFE_IP]][$host] ?? [self::SAFE_IP]
        );
        $httpClient = new MockHttpClient($httpFactory);
        $client = new Client($httpClient, new NullLogger(), $this->context, $resolver);

        return new SellerWebhookDeliveryService(
            new SellerRepository(),
            $this->endpointsRepo(),
            $this->deliveriesRepo(),
            new SellerWebhookEventRepository(),
            $this->secretService(),
            $client
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

    private function deliveriesRepo(): SellerWebhookDeliveryRepository
    {
        static $repo = null;
        if ($repo === null) {
            $repo = new SellerWebhookDeliveryRepository();
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
