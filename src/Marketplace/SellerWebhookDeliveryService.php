<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Api\Webhooks\WebhookSignature;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Support\UtcNowSql;
use Glueful\Helpers\Utils;
use Glueful\Http\Client;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Exceptions\HttpClientException;
use Glueful\Queue\QueueManager;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The MV5c-2 Task 5 signed, SSRF-safe webhook delivery worker (design spec
 * §2.5/§2.6/§2.7/§2.9 -- SECURITY CORE): claims ONE due `pending` delivery
 * through the crash-safe lease protocol, signs + POSTs the exact stored
 * event-snapshot bytes via the framework's strict webhook client, and
 * token-checks every finalize so a stale worker can NEVER corrupt a
 * reclaimed/newer attempt. Also drives the recovery sweep's per-row reclaim.
 *
 * **Claim protocol (design spec §2.7/§2.9, the in-flight linearization
 * point).** {@see self::deliver()} first reads the candidate row UNLOCKED
 * (no claim yet), then in ONE short transaction: claims the seller's
 * revision ({@see SellerRepository::claimRevision()}, the SAME shared
 * primitive every other seller-scoped mutation in this subsystem claims)
 * and re-reads its CURRENT status; claims the endpoint's revision
 * ({@see SellerWebhookEndpointRepository::claimActiveRevision()} -- refuses
 * a tombstoned endpoint, a brand-new delivery attempt must never start
 * against one) and re-reads its CURRENT status; ONLY if both are `active`
 * does it CAS the delivery itself
 * ({@see SellerWebhookDeliveryRepository::claimForDelivery()}: `pending` ->
 * `delivering`, a fresh random `claim_token`, `claim_expires_at = now +
 * claim_lease_seconds`, `attempts + 1`, `last_attempt_at`) and commit. A
 * seller/endpoint that is NOT active at that fresh re-read is refused: the
 * row is best-effort paused ({@see SellerWebhookDeliveryRepository::pauseOne()},
 * itself a `WHERE status = 'pending'`-guarded no-op if the row already moved
 * on) with the matching reason -- this is the ONLY mechanism (design spec
 * §2.9's own "Worker discipline" note) that ever catches a `pending` row
 * that predates a seller suspension: {@see SellerWebhookOutboxPublisher::capture()}
 * pauses new rows at capture time, but a suspension itself never sweeps
 * ALREADY-pending rows -- that happens lazily, here, the next time a worker
 * tries to claim one.
 *
 * A lifecycle transition (suspend/disable/close) that COMMITS BEFORE this
 * claim transaction is what the fresh re-read observes, so the claim is
 * refused/paused. One that commits AFTER this claim's own commit has no
 * effect on THIS delivery -- design spec §2.9: "an in-flight HTTP delivery
 * that started before suspension/closure may finish and record its result".
 *
 * **Sign + POST (design spec §2.5/§2.6).** The delivery signs the EXACT
 * stored `commerce_seller_webhook_events.payload` bytes (never a re-encode)
 * with the endpoint's CURRENT secret (decrypted via
 * {@see SellerWebhookSecretService::currentSecretPlain()}, AAD-bound, never
 * logged) and a fresh unix timestamp via {@see WebhookSignature::generate()}.
 * The POST itself goes through the locked webhook client's
 * {@see Client::safeWebhookRequestAsync()} -- the strict SSRF resolver,
 * pinned exactly once immediately before the request, no redirects, the
 * configured connect/read timeout. A safety-check failure
 * ({@see HttpClientException}, thrown synchronously before any network
 * attempt) is classified TERMINAL. A genuine network/timeout failure
 * ({@see TransportExceptionInterface}, surfaced lazily on
 * `getStatusCode()`/`getHeaders()` for a real transport) is classified
 * RETRYABLE. Both classifications are decided purely by EXCEPTION TYPE, not
 * by which call happened to throw it, so the same classification holds
 * whether the underlying HTTP client fails eagerly (as this suite's
 * `MockHttpClient` stand-in does) or lazily (as a real transport does).
 *
 * **Token-checked finalize (design spec §2.7/§2.9, the correctness core).**
 * Every accepted outcome write goes through
 * {@see SellerWebhookDeliveryRepository::finalize()}, whose own WHERE clause
 * is `status = 'delivering' AND claim_token = ?` -- the EXACT token minted
 * by THIS claim. If the sweep's {@see self::reclaimExpired()} already
 * reclaimed this delivery's lease (clearing `claim_token`, moving `status`
 * away from `delivering`) before this worker gets around to finalizing, that
 * UPDATE affects 0 rows and {@see self::finalize()} returns immediately
 * WITHOUT touching endpoint counters, consecutive-failure state, or
 * auto-disable -- a stale finalizer can never overwrite a reclaimed/newer
 * attempt.
 *
 * **Auto-disable (design spec §2.7).** An ACCEPTED failure finalize re-claims
 * the seller and endpoint revisions (the permissive
 * {@see SellerWebhookEndpointRepository::claimRevision()}, NOT
 * `claimActiveRevision()` -- a finalize must still be able to record its
 * result even if the endpoint was deleted mid-flight) inside the SAME
 * transaction as the delivery CAS, increments `consecutive_failures`, and --
 * only while the endpoint is STILL `active` -- flips it `disabled` +
 * durably audits (`auto_disable`) + pauses EVERY other `pending` row for
 * THAT endpoint the moment the configured threshold is reached. Because this
 * pause sweep is scoped by `endpoint_uuid`
 * ({@see SellerWebhookDeliveryRepository::findByEndpointAndStatus()}), one
 * endpoint's failures can never pause or disable another endpoint's work. A
 * SUCCESS finalize resets the counter to zero ONLY while the endpoint
 * remains active (a disabled endpoint's counter is left alone -- `enable()`,
 * Task 3, is what resets it on re-activation).
 *
 * **Recovery sweep (design spec §2.7, `SweepSellerWebhooksCommand`).**
 * {@see self::reclaimExpired()} is the per-row reclaim a sweep drives for
 * every `delivering` row whose `claim_expires_at` has passed: it re-claims
 * seller -> endpoint (permissively) -> the delivery's token+expiry CAS
 * ({@see SellerWebhookDeliveryRepository::reclaimExpired()}), NEVER touches
 * `attempts` (already incremented at claim time -- "an expired claim counts
 * as the already-incremented attempt"), and returns the row to due `pending`
 * (or `paused`/`canceled` if the seller/endpoint lifecycle no longer permits
 * delivery). {@see self::enqueueHint()} is the sibling primitive a sweep
 * uses for due `pending` rows whose queue wake-up was simply lost -- a pure
 * re-enqueue, no state transition, mirroring
 * {@see SellerWebhookOutboxPublisher::pushQueueHints()}'s exact swallow-and-
 * log idiom (a lost/failed push here never threatens correctness -- the
 * durable `pending` row is the authority; the NEXT sweep tries again).
 */
final class SellerWebhookDeliveryService
{
    private const RETRYABLE_STATUS_CODES = [408, 425, 429];

    /** @var callable(): string */
    private $claimTokenGenerator;

    /** @var callable(): string */
    private $uuidGenerator;

    /**
     * @param (callable(): string)|null $claimTokenGenerator Injectable seam for tests;
     *     defaults to a cryptographically random 32-hex-char token (fits the
     *     `claim_token varchar(32)` column exactly).
     * @param (callable(): string)|null $uuidGenerator Injectable seam for tests (MV5c-2
     *     Task 6, {@see self::replay()}'s new delivery-row uuid) -- the SAME convention
     *     every other MV5c-2 service uses; defaults to {@see Utils::generateNanoID()}.
     */
    public function __construct(
        private SellerRepository $sellers,
        private SellerWebhookEndpointRepository $endpoints,
        private SellerWebhookDeliveryRepository $deliveries,
        private SellerWebhookEventRepository $events,
        private SellerWebhookSecretService $secrets,
        private Client $httpClient,
        ?callable $claimTokenGenerator = null,
        ?callable $uuidGenerator = null,
    ) {
        $this->claimTokenGenerator = $claimTokenGenerator ?? static fn (): string => bin2hex(random_bytes(16));
        $this->uuidGenerator = $uuidGenerator ?? static fn (): string => Utils::generateNanoID();
    }

    /**
     * The worker entry point (used by {@see \Glueful\Extensions\Commerce\Queue\Jobs\DeliverSellerWebhookJob}):
     * claim -> sign+POST -> finalize. Returns a short outcome tag for
     * logging/tests: `not_claimed` (nothing to do -- already gone, not
     * pending, lost the claim race, or refused/paused due to lifecycle),
     * `delivered`, `retry_scheduled`, `dead_letter`, or `stale` (an accepted
     * claim whose finalize lost the token CAS -- reclaimed by the sweep
     * meanwhile).
     */
    public function deliver(ApplicationContext $c, string $tenant, string $deliveryUuid): string
    {
        $this->assertLeaseInvariant($c);

        $claimed = $this->claim($c, $tenant, $deliveryUuid);
        if ($claimed === null) {
            return 'not_claimed';
        }

        $result = $this->attempt($c, $tenant, $claimed);

        return $this->finalize($c, $tenant, $claimed, $result);
    }

    /**
     * The sweep's per-row reclaim (design spec §2.7): expired `delivering`
     * lease -> due `pending` (or `paused`/`canceled` per current lifecycle).
     * Returns a short outcome tag mirroring {@see self::deliver()}'s
     * convention: `reclaimed`, `paused`, `canceled`, `dead_letter` (attempt
     * budget already exhausted when the crash was discovered), `stale` (lost
     * the reclaim CAS -- already finalized or reclaimed by another sweep
     * run), or `not_claimed` (the row is no longer `delivering` at all by
     * the time this runs).
     */
    public function reclaimExpired(ApplicationContext $c, string $tenant, string $deliveryUuid): string
    {
        $this->assertLeaseInvariant($c);

        $delivery = $this->deliveries->findByUuid($c, $tenant, $deliveryUuid);
        if ($delivery === null || (string) $delivery['status'] !== 'delivering') {
            return 'not_claimed';
        }

        $sellerUuid = (string) $delivery['seller_uuid'];
        $endpointUuid = (string) $delivery['endpoint_uuid'];
        $oldToken = (string) $delivery['claim_token'];
        $attempts = (int) $delivery['attempts'];

        return db($c)->transaction(function () use (
            $c,
            $tenant,
            $sellerUuid,
            $endpointUuid,
            $deliveryUuid,
            $oldToken,
            $attempts
        ): string {
            $this->sellers->claimRevision($c, $tenant, $sellerUuid);
            $seller = $this->sellers->findByUuid($c, $tenant, $sellerUuid);
            $sellerStatus = $seller !== null ? (string) $seller['status'] : 'active';

            $this->endpoints->claimRevision($c, $tenant, $endpointUuid);
            $endpoint = $this->endpoints->findByUuidIncludingDeleted($c, $tenant, $endpointUuid);
            $endpointStatus = $endpoint !== null ? (string) $endpoint['status'] : 'deleted';

            $now = $this->readDbNow($c);
            $nowStr = $now->format('Y-m-d H:i:s');

            // T5-review M1 fix (design spec §2.9): the SAME suspended-vs-closed
            // distinction {@see self::claim()} now makes -- an expired lease
            // whose seller is CLOSED terminally cancels, never pauses.
            if ($sellerStatus === 'closed') {
                $accepted = $this->deliveries->reclaimExpired($c, $tenant, $deliveryUuid, $oldToken, [
                    'status' => 'canceled',
                ], $nowStr);

                return $accepted ? 'canceled' : 'stale';
            }
            if ($sellerStatus !== 'active') {
                $accepted = $this->deliveries->reclaimExpired($c, $tenant, $deliveryUuid, $oldToken, [
                    'status' => 'paused',
                    'pause_reason' => 'seller_suspended',
                    'paused_at' => $nowStr,
                    'paused_remaining_seconds' => 0,
                ], $nowStr);

                return $accepted ? 'paused' : 'stale';
            }

            if ($endpointStatus === 'deleted') {
                $accepted = $this->deliveries->reclaimExpired($c, $tenant, $deliveryUuid, $oldToken, [
                    'status' => 'canceled',
                ], $nowStr);

                return $accepted ? 'canceled' : 'stale';
            }

            if ($endpointStatus !== 'active') {
                $accepted = $this->deliveries->reclaimExpired($c, $tenant, $deliveryUuid, $oldToken, [
                    'status' => 'paused',
                    'pause_reason' => 'endpoint_disabled',
                    'paused_at' => $nowStr,
                    'paused_remaining_seconds' => 0,
                ], $nowStr);

                return $accepted ? 'paused' : 'stale';
            }

            $maxAttempts = (int) config($c, 'commerce.marketplace.webhooks.max_attempts', 10);
            if ($attempts >= $maxAttempts) {
                $accepted = $this->deliveries->reclaimExpired($c, $tenant, $deliveryUuid, $oldToken, [
                    'status' => 'dead_letter',
                    'last_error' => 'Claim lease expired (worker unresponsive); attempt budget exhausted.',
                ], $nowStr);

                return $accepted ? 'dead_letter' : 'stale';
            }

            // Design spec §2.7: "an expired claim counts as the already-
            // incremented attempt" -- attempts is left untouched; the row is
            // simply due again right away.
            $accepted = $this->deliveries->reclaimExpired($c, $tenant, $deliveryUuid, $oldToken, [
                'status' => 'pending',
                'next_attempt_at' => $nowStr,
                'last_error' => 'Claim lease expired (worker unresponsive).',
            ], $nowStr);

            return $accepted ? 'reclaimed' : 'stale';
        });
    }

    /**
     * Replay a `dead_letter` delivery (design spec §2.8, MV5c-2 Task 6): a
     * NEW `pending` delivery ATTEMPT LINEAGE referencing the SAME event
     * snapshot (`webhook_event_uuid` unchanged -- never a re-projected
     * payload), `replay_of_uuid` pointing back at `$deliveryUuid`, zero
     * attempts. The original row's own history is NEVER mutated -- this
     * method only ever INSERTs.
     *
     * Eligibility (claim-then-fresh-re-read, the SAME "seller revision ->
     * endpoint revision -> ..." lock order every other mutation in this
     * subsystem claims): the owning seller must be freshly `active`
     * ({@see SellerWebhookException::sellerNotActive()} otherwise -- a
     * `closed` seller's deliveries are `canceled`, never replayable, and a
     * merely `suspended` one is refused too, matching design spec §2.9's
     * "Management is unavailable while suspended"), the endpoint must claim
     * ({@see SellerWebhookEndpointRepository::claimActiveRevision()} --
     * refuses a TOMBSTONED endpoint identically to every other mutation,
     * surfacing a non-revealing {@see NotFoundException}) and be freshly
     * `active` ({@see SellerWebhookException::endpointNotActive()}
     * otherwise -- a `disabled` endpoint refuses too), and the delivery
     * itself must be freshly `dead_letter`
     * ({@see SellerWebhookException::deliveryNotReplayable()} for anything
     * else, INCLUDING `canceled` -- design spec §2.8: "a canceled ...
     * delivery is NOT replayable").
     *
     * The JWT-interactive-only actor/`webhooks.manage`-capability HTTP gate
     * is a later task's concern (this method's own docblock, and the design
     * spec §2.8 CARRY-FORWARD note); this is the mechanical service-layer
     * primitive that gate will call once claimed.
     *
     * @return array<string,mixed> the newly-inserted replay delivery row
     */
    public function replay(ApplicationContext $c, string $tenant, string $deliveryUuid): array
    {
        $original = $this->deliveries->findByUuid($c, $tenant, $deliveryUuid);
        if ($original === null) {
            throw new NotFoundException('Resource not found.');
        }

        $sellerUuid = (string) $original['seller_uuid'];
        $endpointUuid = (string) $original['endpoint_uuid'];
        $eventUuid = (string) $original['webhook_event_uuid'];

        $replay = db($c)->transaction(function () use (
            $c,
            $tenant,
            $sellerUuid,
            $endpointUuid,
            $eventUuid,
            $deliveryUuid
        ): array {
            $this->sellers->claimRevision($c, $tenant, $sellerUuid);
            $seller = $this->sellers->findByUuid($c, $tenant, $sellerUuid);
            $sellerStatus = $seller !== null ? (string) $seller['status'] : 'active';
            if ($sellerStatus !== 'active') {
                throw SellerWebhookException::sellerNotActive($sellerStatus);
            }

            if (!$this->endpoints->claimActiveRevision($c, $tenant, $endpointUuid)) {
                throw new NotFoundException('Resource not found.');
            }
            $endpoint = $this->endpoints->findByUuid($c, $tenant, $endpointUuid);
            if ($endpoint === null) {
                throw new NotFoundException('Resource not found.');
            }
            if ((string) $endpoint['status'] !== 'active') {
                throw SellerWebhookException::endpointNotActive((string) $endpoint['status']);
            }

            // Re-read the delivery FRESH under claim -- its status may have
            // moved since the unlocked read above (e.g. a concurrent close()
            // canceling it, or a second replay racing this one).
            $fresh = $this->deliveries->findByUuid($c, $tenant, $deliveryUuid);
            if ($fresh === null) {
                throw new NotFoundException('Resource not found.');
            }
            $status = (string) $fresh['status'];
            if ($status !== 'dead_letter') {
                throw SellerWebhookException::deliveryNotReplayable($status);
            }

            $nowStr = $this->readDbNow($c)->format('Y-m-d H:i:s');
            $replayUuid = ($this->uuidGenerator)();

            $this->deliveries->insertReplay($c, $tenant, [
                'uuid' => $replayUuid,
                'endpoint_uuid' => $endpointUuid,
                'webhook_event_uuid' => $eventUuid,
                'seller_uuid' => $sellerUuid,
                'replay_of_uuid' => $deliveryUuid,
                'next_attempt_at' => $nowStr,
            ]);

            $inserted = $this->deliveries->findByUuid($c, $tenant, $replayUuid);
            if ($inserted === null) {
                throw new \RuntimeException('Replayed seller webhook delivery could not be reloaded.');
            }

            return $inserted;
        });

        // Wake-up hint only, AFTER commit (design spec §2.4's own convention
        // for every `pending` row this class or the outbox ever creates) --
        // the durable `pending` row committed above is the authority
        // regardless of whether this hint is ever delivered.
        $replayUuid = (string) $replay['uuid'];
        db($c)->afterCommit(function () use ($c, $replayUuid): void {
            $this->enqueueHint($c, $replayUuid);
        });

        return $replay;
    }

    /**
     * A pure wake-up hint (design spec §2.4/§2.7), mirroring
     * {@see SellerWebhookOutboxPublisher::pushQueueHints()}'s exact
     * swallow-and-log idiom: a lost/failed push here NEVER threatens
     * correctness -- the durable `pending` row is the authority regardless.
     */
    public function enqueueHint(ApplicationContext $c, string $deliveryUuid): void
    {
        $container = container($c);
        if (!$container->has(QueueManager::class)) {
            return;
        }

        $queue = $container->get(QueueManager::class);
        if (!$queue instanceof QueueManager) {
            return;
        }

        try {
            $queue->push('Glueful\\Extensions\\Commerce\\Queue\\Jobs\\DeliverSellerWebhookJob', [
                'delivery_uuid' => $deliveryUuid,
            ]);
        } catch (\Throwable $e) {
            error_log(
                '[Commerce][SellerWebhookDeliveryService] Failed to enqueue delivery hint '
                    . "'{$deliveryUuid}': " . $e->getMessage()
            );
        }
    }

    // -----------------------------------------------------------------
    // Claim
    // -----------------------------------------------------------------

    /**
     * @return array{
     *     delivery_uuid: string,
     *     seller_uuid: string,
     *     endpoint: array<string,mixed>,
     *     event: array<string,mixed>,
     *     claim_token: string,
     *     attempts: int
     * }|null
     */
    private function claim(ApplicationContext $c, string $tenant, string $deliveryUuid): ?array
    {
        $delivery = $this->deliveries->findByUuid($c, $tenant, $deliveryUuid);
        if ($delivery === null || (string) $delivery['status'] !== 'pending') {
            return null;
        }

        $sellerUuid = (string) $delivery['seller_uuid'];
        $endpointUuid = (string) $delivery['endpoint_uuid'];
        $eventUuid = (string) $delivery['webhook_event_uuid'];

        return db($c)->transaction(function () use (
            $c,
            $tenant,
            $sellerUuid,
            $endpointUuid,
            $eventUuid,
            $deliveryUuid
        ): ?array {
            $this->sellers->claimRevision($c, $tenant, $sellerUuid);
            $seller = $this->sellers->findByUuid($c, $tenant, $sellerUuid);
            $sellerStatus = $seller !== null ? (string) $seller['status'] : 'active';

            $now = $this->readDbNow($c);
            $nowStr = $now->format('Y-m-d H:i:s');

            // T5-review M1 fix (design spec §2.9): a CLOSED seller's refused
            // claim terminally cancels -- never pauses -- so it can neither
            // be resumed nor escape the retention purge as a stale `paused`
            // row. A `suspended` (or any other non-active, non-closed) seller
            // keeps the original pause-with-remaining-delay behavior.
            if ($sellerStatus === 'closed') {
                $this->deliveries->cancelOne($c, $tenant, $deliveryUuid, $nowStr);

                return null;
            }
            if ($sellerStatus !== 'active') {
                $this->refuseAndPause($c, $tenant, $deliveryUuid, 'seller_suspended', $nowStr);

                return null;
            }

            $endpointClaimed = $this->endpoints->claimActiveRevision($c, $tenant, $endpointUuid);
            if (!$endpointClaimed) {
                // Tombstoned (or otherwise unclaimable) endpoint -- best-effort
                // pause; a no-op if delete()'s own sweep already canceled it.
                $this->refuseAndPause($c, $tenant, $deliveryUuid, 'endpoint_disabled', $nowStr);

                return null;
            }

            $endpoint = $this->endpoints->findByUuid($c, $tenant, $endpointUuid);
            if ($endpoint === null || (string) $endpoint['status'] !== 'active') {
                $this->refuseAndPause($c, $tenant, $deliveryUuid, 'endpoint_disabled', $nowStr);

                return null;
            }

            $leaseSeconds = (int) config($c, 'commerce.marketplace.webhooks.claim_lease_seconds', 30);
            $claimToken = ($this->claimTokenGenerator)();
            $claimExpiresAt = $now->modify("+{$leaseSeconds} seconds")->format('Y-m-d H:i:s');

            $claimed = $this->deliveries->claimForDelivery(
                $c,
                $tenant,
                $deliveryUuid,
                $claimToken,
                $claimExpiresAt,
                $nowStr
            );
            if (!$claimed) {
                return null;
            }

            $event = $this->events->findByUuid($c, $tenant, $eventUuid);
            if ($event === null) {
                throw new \RuntimeException("Seller webhook event snapshot '{$eventUuid}' is missing.");
            }

            $fresh = $this->deliveries->findByUuid($c, $tenant, $deliveryUuid);
            $attempts = $fresh !== null ? (int) $fresh['attempts'] : 1;

            return [
                'delivery_uuid' => $deliveryUuid,
                'seller_uuid' => $sellerUuid,
                'endpoint' => $endpoint,
                'event' => $event,
                'claim_token' => $claimToken,
                'attempts' => $attempts,
            ];
        });
    }

    /**
     * Best-effort refusal pause (design spec §2.9's "Worker discipline"): a
     * no-op ({@see SellerWebhookDeliveryRepository::pauseOne()}'s own `WHERE
     * status = 'pending'` guard) when the row already moved on by some other
     * path.
     */
    private function refuseAndPause(
        ApplicationContext $c,
        string $tenant,
        string $deliveryUuid,
        string $pauseReason,
        string $nowStr
    ): void {
        $delivery = $this->deliveries->findByUuid($c, $tenant, $deliveryUuid);
        $remaining = 0;
        if ($delivery !== null) {
            $nextAttemptAt = isset($delivery['next_attempt_at']) ? (string) $delivery['next_attempt_at'] : null;
            $remaining = $this->remainingSeconds($nextAttemptAt, $nowStr);
        }

        $this->deliveries->pauseOne($c, $tenant, $deliveryUuid, $pauseReason, $nowStr, $remaining);
    }

    // -----------------------------------------------------------------
    // Sign + POST
    // -----------------------------------------------------------------

    /**
     * @param array{
     *     delivery_uuid: string,
     *     seller_uuid: string,
     *     endpoint: array<string,mixed>,
     *     event: array<string,mixed>,
     *     claim_token: string,
     *     attempts: int
     * } $claimed
     * @return array{kind: string, status_code: int|null, error: string|null, retry_after_seconds: int|null}
     */
    private function attempt(ApplicationContext $c, string $tenant, array $claimed): array
    {
        $endpoint = $claimed['endpoint'];
        $event = $claimed['event'];
        $payloadBytes = (string) $event['payload'];

        try {
            $secretPlain = $this->secrets->currentSecretPlain($c, $tenant, $endpoint);
        } catch (SellerWebhookException $e) {
            return $this->outcome('terminal', null, 'No current signing secret is available.', null);
        }

        $timestamp = time();
        $signature = WebhookSignature::generate($payloadBytes, $secretPlain, $timestamp);

        $timeoutSeconds = max(1, (int) config($c, 'commerce.marketplace.webhooks.delivery_timeout_seconds', 10));
        $maxResponseBytes = max(0, (int) config($c, 'commerce.marketplace.webhooks.max_response_bytes', 65536));

        $headers = [
            'Content-Type' => 'application/json',
            'X-Webhook-Delivery' => $claimed['delivery_uuid'],
            'X-Webhook-Event-Id' => (string) $event['uuid'],
            'X-Webhook-Event-Type' => (string) $event['event_type'],
            'X-Webhook-Schema-Version' => '1',
            'X-Webhook-Signature' => $signature,
        ];

        try {
            $response = $this->httpClient->safeWebhookRequestAsync('POST', (string) $endpoint['url'], [
                'headers' => $headers,
                'body' => $payloadBytes,
                'timeout' => $timeoutSeconds,
                'max_duration' => $timeoutSeconds,
            ]);

            $statusCode = $response->getStatusCode();
            $responseHeaders = $response->getHeaders(false);
        } catch (HttpClientException $e) {
            // Thrown synchronously by resolveWebhook() before any network
            // attempt (design spec §2.6): a safety-check failure is ALWAYS
            // terminal. The resolver's own message is already generic and
            // never embeds a resolved internal address.
            return $this->outcome('terminal', null, 'Webhook delivery blocked by safety validation.', null);
        } catch (TransportExceptionInterface $e) {
            // A genuine network/timeout failure -- retryable.
            return $this->outcome('retryable', null, 'Network error: ' . $e->getMessage(), null);
        } catch (\Throwable $e) {
            // Fail-safe: never let an unexpected exception crash the worker;
            // treat as retryable so the attempt budget still governs it.
            return $this->outcome('retryable', null, 'Unexpected delivery error: ' . $e->getMessage(), null);
        }

        $this->consumeBounded($response, $maxResponseBytes);

        if ($statusCode >= 200 && $statusCode < 300) {
            return $this->outcome('success', $statusCode, null, null);
        }

        $isRetryable = in_array($statusCode, self::RETRYABLE_STATUS_CODES, true)
            || ($statusCode >= 500 && $statusCode <= 599);

        $retryAfterSeconds = null;
        if (in_array($statusCode, [429, 503], true)) {
            $retryAfterSeconds = $this->parseRetryAfter($responseHeaders);
        }

        return $this->outcome(
            $isRetryable ? 'retryable' : 'terminal',
            $statusCode,
            "HTTP {$statusCode}",
            $retryAfterSeconds
        );
    }

    /**
     * @return array{kind: string, status_code: int|null, error: string|null, retry_after_seconds: int|null}
     */
    private function outcome(string $kind, ?int $statusCode, ?string $error, ?int $retryAfterSeconds): array
    {
        return [
            'kind' => $kind,
            'status_code' => $statusCode,
            'error' => $error,
            'retry_after_seconds' => $retryAfterSeconds,
        ];
    }

    /**
     * Bounded body consumption (design spec §2.6: "consume at most
     * max_response_bytes"). Reads incrementally via the underlying Symfony
     * client's chunked stream and cancels the response the moment the cap is
     * reached, so a hostile/huge response body can never be pulled fully
     * into memory. Best-effort only -- classification is already decided
     * from the status code/headers; a read failure here never changes the
     * outcome.
     */
    private function consumeBounded(ResponseInterface $response, int $maxBytes): void
    {
        if ($maxBytes <= 0) {
            return;
        }

        try {
            $consumed = 0;
            foreach ($this->httpClient->getHttpClient()->stream($response) as $chunk) {
                if ($chunk->isTimeout()) {
                    continue;
                }
                if ($chunk->isLast()) {
                    break;
                }

                $consumed += strlen($chunk->getContent());
                if ($consumed >= $maxBytes) {
                    $response->cancel();
                    break;
                }
            }
        } catch (\Throwable) {
            // Best-effort only.
        }
    }

    /**
     * @param array<string,list<string>> $headers keyed lowercase (Symfony contract)
     */
    private function parseRetryAfter(array $headers): ?int
    {
        $values = $headers['retry-after'] ?? [];
        if (!is_array($values) || $values === []) {
            return null;
        }

        $raw = trim((string) $values[0]);
        if ($raw === '') {
            return null;
        }

        if (ctype_digit($raw)) {
            return max(0, (int) $raw);
        }

        $timestamp = strtotime($raw);
        if ($timestamp === false) {
            return null;
        }

        return max(0, $timestamp - time());
    }

    // -----------------------------------------------------------------
    // Finalize (token-checked) + auto-disable
    // -----------------------------------------------------------------

    /**
     * @param array{
     *     delivery_uuid: string,
     *     seller_uuid: string,
     *     endpoint: array<string,mixed>,
     *     event: array<string,mixed>,
     *     claim_token: string,
     *     attempts: int
     * } $claimed
     * @param array{kind: string, status_code: int|null, error: string|null, retry_after_seconds: int|null} $result
     */
    private function finalize(ApplicationContext $c, string $tenant, array $claimed, array $result): string
    {
        $sellerUuid = $claimed['seller_uuid'];
        $endpointUuid = (string) $claimed['endpoint']['uuid'];
        $deliveryUuid = $claimed['delivery_uuid'];
        $claimToken = $claimed['claim_token'];
        $attempts = $claimed['attempts'];

        return db($c)->transaction(function () use (
            $c,
            $tenant,
            $sellerUuid,
            $endpointUuid,
            $deliveryUuid,
            $claimToken,
            $attempts,
            $result
        ): string {
            // Permissive claims (design spec §2.7): a finalize must still be
            // able to record an already-in-flight attempt's result even if
            // the seller/endpoint lifecycle changed meanwhile.
            $this->sellers->claimRevision($c, $tenant, $sellerUuid);
            $this->endpoints->claimRevision($c, $tenant, $endpointUuid);

            $now = $this->readDbNow($c);
            $nowStr = $now->format('Y-m-d H:i:s');

            if ($result['kind'] === 'success') {
                $accepted = $this->deliveries->finalize($c, $tenant, $deliveryUuid, $claimToken, [
                    'status' => 'delivered',
                    'last_status_code' => $result['status_code'],
                    'last_error' => null,
                ], $nowStr);

                if (!$accepted) {
                    // Stale finalizer (design spec §2.7/§2.9): the token CAS
                    // above already lost -- NEVER touch endpoint counters.
                    return 'stale';
                }

                $endpoint = $this->endpoints->findByUuid($c, $tenant, $endpointUuid);
                if ($endpoint !== null && (string) $endpoint['status'] === 'active') {
                    $this->endpoints->update($c, $tenant, $endpointUuid, [
                        'consecutive_failures' => 0,
                        'updated_at' => $nowStr,
                    ]);
                }

                return 'delivered';
            }

            $maxAttempts = (int) config($c, 'commerce.marketplace.webhooks.max_attempts', 10);
            $isTerminal = $result['kind'] === 'terminal';
            $exhausted = $attempts >= $maxAttempts;
            $error = $this->truncateError($result['error']);

            if ($isTerminal || $exhausted) {
                $accepted = $this->deliveries->finalize($c, $tenant, $deliveryUuid, $claimToken, [
                    'status' => 'dead_letter',
                    'last_status_code' => $result['status_code'],
                    'last_error' => $error,
                ], $nowStr);
            } else {
                $nextAttemptAt = $this->computeNextAttempt($c, $now, $attempts, $result['retry_after_seconds']);
                $accepted = $this->deliveries->finalize($c, $tenant, $deliveryUuid, $claimToken, [
                    'status' => 'pending',
                    'next_attempt_at' => $nextAttemptAt,
                    'last_status_code' => $result['status_code'],
                    'last_error' => $error,
                ], $nowStr);
            }

            if (!$accepted) {
                // Stale finalizer (design spec §2.7/§2.9): NEVER touch
                // consecutive_failures / auto-disable on a lost token CAS.
                return 'stale';
            }

            $this->applyFailureAndMaybeAutoDisable(
                $c,
                $tenant,
                $endpointUuid,
                $sellerUuid,
                $deliveryUuid,
                $now,
                $nowStr
            );

            return $isTerminal || $exhausted ? 'dead_letter' : 'retry_scheduled';
        });
    }

    /**
     * Design spec §2.7 (auto-disable, isolated per endpoint): runs ONLY
     * after an accepted failure finalize, inside the SAME transaction. Reads
     * the endpoint fresh (post- {@see SellerWebhookEndpointRepository::claimRevision()}),
     * increments `consecutive_failures` ONLY while it remains `active`, and
     * -- exactly at the configured threshold -- flips it `disabled`, audits
     * `auto_disable`, and pauses every OTHER `pending` row scoped to THIS
     * `endpoint_uuid` alone (the SAME per-endpoint sweep
     * {@see SellerWebhookEndpointService::disable()} runs, Task 3) -- never
     * another endpoint's. `$justFinalizedDeliveryUuid` -- the delivery whose
     * OWN failure just triggered this disable -- is excluded from the sweep
     * (design spec: "pauses its OTHER pending deliveries"): it keeps
     * whatever the finalize CAS above already gave it (its own computed
     * backoff `pending` row); the NEXT worker that tries to claim it lazily
     * discovers the now-disabled endpoint and pauses it then, the SAME
     * "worker discipline" mechanism {@see self::claim()} uses for a seller
     * suspension.
     */
    private function applyFailureAndMaybeAutoDisable(
        ApplicationContext $c,
        string $tenant,
        string $endpointUuid,
        string $sellerUuid,
        string $justFinalizedDeliveryUuid,
        \DateTimeImmutable $now,
        string $nowStr
    ): void {
        $endpoint = $this->endpoints->findByUuid($c, $tenant, $endpointUuid);
        if ($endpoint === null || (string) $endpoint['status'] !== 'active') {
            return;
        }

        $newFailures = (int) $endpoint['consecutive_failures'] + 1;
        $threshold = (int) config($c, 'commerce.marketplace.webhooks.consecutive_failure_disable_threshold', 20);

        if ($newFailures < $threshold) {
            $this->endpoints->update($c, $tenant, $endpointUuid, [
                'consecutive_failures' => $newFailures,
                'updated_at' => $nowStr,
            ]);

            return;
        }

        $this->endpoints->update($c, $tenant, $endpointUuid, [
            'status' => 'disabled',
            'consecutive_failures' => $newFailures,
            'disabled_at' => $nowStr,
            'disabled_reason' => 'auto_disabled: consecutive failure threshold reached',
            'updated_at' => $nowStr,
        ]);

        foreach ($this->deliveries->findByEndpointAndStatus($c, $tenant, $endpointUuid, 'pending') as $row) {
            if ((string) $row['uuid'] === $justFinalizedDeliveryUuid) {
                continue;
            }

            $remaining = $this->remainingSeconds(
                isset($row['next_attempt_at']) ? (string) $row['next_attempt_at'] : null,
                $nowStr
            );
            $this->deliveries->pauseOne($c, $tenant, (string) $row['uuid'], 'endpoint_disabled', $nowStr, $remaining);
        }

        $this->endpoints->insertEvent($c, $tenant, [
            'uuid' => Utils::generateNanoID(),
            'endpoint_uuid' => $endpointUuid,
            'seller_uuid' => $sellerUuid,
            'action' => 'auto_disable',
            'reason' => 'consecutive_failure_threshold_reached',
            'detail' => "consecutive_failures={$newFailures}",
        ]);
    }

    /**
     * Bounded exponential backoff + jitter (design spec §2.7): `attempts`
     * already reflects THIS attempt (incremented at claim time), so the
     * first failed attempt backs off by `backoff_base_seconds`, doubling
     * each attempt thereafter, capped at `backoff_cap_seconds`. A bounded
     * `Retry-After` (429/503) REPLACES the computed delay outright (honoring
     * the server's explicit instruction) rather than merely flooring it,
     * still clamped to the same cap.
     */
    private function computeNextAttempt(
        ApplicationContext $c,
        \DateTimeImmutable $now,
        int $attempts,
        ?int $retryAfterSeconds
    ): string {
        $cap = max(1, (int) config($c, 'commerce.marketplace.webhooks.backoff_cap_seconds', 3600));

        if ($retryAfterSeconds !== null) {
            $delay = min($cap, max(0, $retryAfterSeconds));
        } else {
            $base = max(1, (int) config($c, 'commerce.marketplace.webhooks.backoff_base_seconds', 30));
            $jitterFactor = max(0.0, (float) config($c, 'commerce.marketplace.webhooks.jitter', 0.2));

            $exponent = max(0, $attempts - 1);
            $delay = min($cap, (int) ($base * (2 ** $exponent)));

            if ($jitterFactor > 0.0) {
                $jitterRange = (int) round($delay * $jitterFactor);
                if ($jitterRange > 0) {
                    $delay += random_int(0, $jitterRange);
                }
            }
        }

        $delay = max(1, min($cap, $delay));

        return $now->modify("+{$delay} seconds")->format('Y-m-d H:i:s');
    }

    private function truncateError(?string $error): ?string
    {
        if ($error === null) {
            return null;
        }

        return strlen($error) > 255 ? substr($error, 0, 255) : $error;
    }

    // -----------------------------------------------------------------
    // Config invariant + time helpers
    // -----------------------------------------------------------------

    /**
     * T2 CARRY-FORWARD (binding): `claim_lease_seconds` MUST stay strictly
     * greater than `delivery_timeout_seconds` -- otherwise a healthy
     * in-flight HTTP attempt could be reclaimed by the sweep out from under
     * itself before it can even time out on its own. Validated at the top
     * of every entry point (design spec: "at service construction/use").
     */
    private function assertLeaseInvariant(ApplicationContext $c): void
    {
        $lease = (int) config($c, 'commerce.marketplace.webhooks.claim_lease_seconds', 30);
        $timeout = (int) config($c, 'commerce.marketplace.webhooks.delivery_timeout_seconds', 10);

        if ($lease <= $timeout) {
            throw new \RuntimeException(
                "commerce.marketplace.webhooks.claim_lease_seconds ({$lease}s) must be strictly greater than "
                    . "delivery_timeout_seconds ({$timeout}s); otherwise the recovery sweep could reclaim a still-"
                    . 'healthy in-flight delivery attempt out from under itself.'
            );
        }
    }

    /**
     * The remaining delay until `$nextAttemptAt`, clamped to >= 0 -- mirrors
     * {@see SellerWebhookEndpointService::remainingSeconds()}'s identical
     * pause-persistence semantics (design spec §2.9).
     */
    private function remainingSeconds(?string $nextAttemptAt, string $nowStr): int
    {
        if ($nextAttemptAt === null || $nextAttemptAt === '') {
            return 0;
        }

        $next = new \DateTimeImmutable($nextAttemptAt, new \DateTimeZone('UTC'));
        $now = new \DateTimeImmutable($nowStr, new \DateTimeZone('UTC'));

        return max(0, $next->getTimestamp() - $now->getTimestamp());
    }

    /**
     * The SAME driver-pinned-UTC "database is now" primitive every MV5c-2
     * service uses (e.g. {@see SellerWebhookEndpointService::readDbNow()}).
     */
    private function readDbNow(ApplicationContext $c): \DateTimeImmutable
    {
        $utcNowExpression = UtcNowSql::expression(db($c)->getDriverName());
        $row = db($c)->query()->executeRawFirst("SELECT {$utcNowExpression} AS now_utc");
        $dbNowRaw = $row !== null ? (string) ($row['now_utc'] ?? '') : '';
        if ($dbNowRaw === '') {
            throw new \RuntimeException('Unable to read database-now for seller webhook delivery.');
        }

        return new \DateTimeImmutable($dbNowRaw, new \DateTimeZone('UTC'));
    }
}
