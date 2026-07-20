<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Support\UtcNowSql;
use Glueful\Helpers\Utils;
use Glueful\Queue\QueueManager;

/**
 * The MV5c-2 Task 4 transactional outbox (design spec §2.4/§2.9 -- SECURITY
 * CORE): {@see self::capture()} is called from INSIDE the authoritative
 * business transaction of every real v1 state-change (order placement,
 * payment, cancellation, fulfillment, refund, payout, direct stock
 * adjustment, product adoption/transfer) and, when a seller has a matching
 * ACTIVE endpoint, writes ONE durable `commerce_seller_webhook_events`
 * snapshot per participating seller plus ONE `commerce_seller_webhook_deliveries`
 * row per matched endpoint, all before that caller's transaction commits. An
 * outbox write failure therefore rolls back the WHOLE business transition --
 * there is no committed-state-without-outbox crash window (design spec §2.4).
 *
 * **Contract with callers -- the `$context['data']` shape.** `capture()`'s
 * OWN signature is fixed (design spec §Interfaces): `capture(ApplicationContext
 * $c, string $tenant, string $eventType, array $context): void`. Every call
 * site is responsible for pre-scoping `$context['data']` to EXACTLY:
 *
 * ```
 * [
 *     'data' => [
 *         $sellerUuid => [ ...this seller's OWN fields for $eventType, see
 *                          {@see SellerWebhookPayloadProjector} for the
 *                          per-event-type shape... ],
 *         // one entry per PARTICIPATING seller -- the array keys ARE the
 *         // participating-seller set this method resolves against; a
 *         // caller that has nothing to report for a seller simply omits it.
 *     ],
 *     'claimed_sellers' => [ $sellerUuid, ... ],   // OPTIONAL: sellers whose
 *         // revision the CALLER already claimed via SellerRepository::claimRevision()
 *         // earlier in this SAME transaction (design spec §2.4's "reuse a
 *         // caller-held claim through the shared claim helper" -- the shared
 *         // helper IS SellerRepository::claimRevision(), the SAME primitive
 *         // every seller-lifecycle/payout/checkout mutation already uses;
 *         // this key just tells capture() not to call it a second time for
 *         // sellers already serialized against a concurrent suspend/reactivate).
 *     'source_ref' => string|null,                  // OPTIONAL correlation ref
 *         // stored (never signed/sent) on the event snapshot for diagnostics.
 * ]
 * ```
 *
 * **Lock order (design spec §4/MV5b precedent, BINDING).** For every
 * participating seller NOT already in `claimed_sellers`, this method claims
 * {@see SellerRepository::claimRevision()} in ASCENDING seller_uuid order --
 * the EXACT primitive/ordering {@see SellerService::suspend()}/`reactivate()`
 * and {@see PayoutService::record()}/`reserve()` already claim for the SAME
 * `commerce_sellers` row, so a capture() and a concurrent suspend/reactivate
 * are strictly serialized (never interleaved), never a lost pause. Every
 * call site that ALSO claims a `LedgerAccountLock` in the SAME transaction
 * (order.paid via `LedgerPostingService::postSale()`, refund.completed via
 * `postRefund()`, the provider-payout finalizer via `applyPendingTransition()`)
 * MUST invoke `capture()` BEFORE that lock claim -- this class never claims
 * anything BUT a seller revision, so as long as the CALLER upholds that
 * ordering, the global "revision before account lock" order (design spec
 * §4, the MV5b payout-freeze precedent) is preserved end to end. This class
 * cannot enforce that from inside itself; it is a contract on every call
 * site, verified call site by call site (see each call site's own docblock).
 *
 * **Algorithm (design spec §2.4/§6, verbatim).** Master marketplace-off
 * (`MarketplaceMode::installEnabled()`, config-only) is a zero-query no-op.
 * Otherwise, for each participating seller in ascending order: claim/reuse
 * its revision, re-read its CURRENT lifecycle status, then run ONE bounded
 * indexed probe ({@see SellerWebhookEndpointRepository::activeForSeller()})
 * for its active endpoints, filtered in PHP to those subscribed to
 * `$eventType`. No match -> nothing is written for that seller. A match ->
 * one event snapshot (the exact bytes {@see SellerWebhookPayloadProjector::project()}
 * produces) plus one delivery per matched endpoint: an `active` seller's
 * deliveries are born `pending` (due immediately, `next_attempt_at =
 * DB_NOW`); a NON-active (suspended) seller's are born `paused` with
 * `pause_reason = 'seller_suspended'` and zero remaining delay -- this is
 * what makes both capture-vs-suspension race orderings safe (design spec
 * §2.4: suspension-first is already reflected by the fresh re-read;
 * capture-first commits rows a suspension transaction, serialized behind
 * this one on the SAME revision claim, will itself see and pause when it
 * runs its own pause sweep).
 *
 * Queue publication is registered `afterCommit()` for `pending` rows ONLY,
 * and is a pure wake-up HINT: a lost/failed enqueue is swallowed (logged,
 * never rethrown) because the durable `pending` row -- already committed --
 * is the authority; a later recovery sweep (Task 5) discovers and enqueues
 * it regardless.
 */
final class SellerWebhookOutboxPublisher
{
    /** @var callable(): string */
    private $uuidGenerator;

    /** @var callable(ApplicationContext,string,string):void */
    private $beforeWriteHook;

    /**
     * @param (callable(): string)|null $uuidGenerator injectable seam for tests, same
     *     convention as every other MV5c-2 service; defaults to {@see Utils::generateNanoID()}.
     * @param (callable(ApplicationContext,string,string):void)|null $beforeWriteHook test-only
     *     seam (same convention as {@see \Glueful\Extensions\Commerce\Orders\CheckoutService}'s
     *     `$afterOwnershipSnapshotHook` / {@see \Glueful\Extensions\Commerce\Orders\OrderPaymentService}'s
     *     `$afterPaidHook`): invoked with `(context, eventType, sellerUuid)` immediately BEFORE
     *     this seller's event snapshot is inserted -- still inside the caller's own open
     *     transaction. Tests use it to force a deterministic failure at that exact point and
     *     prove the WHOLE enclosing business transaction rolls back together (design spec
     *     §2.4's "an outbox write failure rolls back the business transition"). Defaults to a
     *     no-op.
     */
    public function __construct(
        private MarketplaceMode $marketplaceMode,
        private SellerRepository $sellers,
        private SellerWebhookEndpointRepository $endpoints,
        private SellerWebhookEventRepository $events,
        private SellerWebhookDeliveryRepository $deliveries,
        private SellerWebhookPayloadProjector $projector,
        ?callable $uuidGenerator = null,
        ?callable $beforeWriteHook = null,
    ) {
        $this->uuidGenerator = $uuidGenerator ?? static fn (): string => Utils::generateNanoID();
        $this->beforeWriteHook = $beforeWriteHook ?? static function (
            ApplicationContext $c,
            string $eventType,
            string $sellerUuid
        ): void {
        };
    }

    /**
     * @param array<string,mixed> $context see this class's own docblock for the exact
     *     `data`/`claimed_sellers`/`source_ref` shape
     */
    public function capture(ApplicationContext $c, string $tenant, string $eventType, array $context): void
    {
        // Off-invariance (design spec §6): marketplace master-off is a config-only,
        // zero-database-query no-op -- checked FIRST, before touching $context at all.
        if (!$this->marketplaceMode->installEnabled($c)) {
            return;
        }

        $data = is_array($context['data'] ?? null) ? $context['data'] : [];
        if ($data === []) {
            return;
        }

        $sellerUuids = array_keys($data);
        sort($sellerUuids, SORT_STRING);

        $alreadyClaimed = is_array($context['claimed_sellers'] ?? null) ? $context['claimed_sellers'] : [];
        $sourceRef = isset($context['source_ref']) ? (string) $context['source_ref'] : null;
        $now = $this->readDbNow($c);

        $pendingDeliveryUuids = [];

        foreach ($sellerUuids as $sellerUuid) {
            // Claim/reuse (design spec §2.4): a caller-held claim from THIS same
            // transaction is reused via SellerRepository::claimRevision() -- the shared
            // primitive -- never re-acquired; every other participating seller is claimed
            // here, in this loop's own ascending order.
            if (!in_array($sellerUuid, $alreadyClaimed, true)) {
                $this->sellers->claimRevision($c, $tenant, $sellerUuid);
            }

            // A seller_uuid with no commerce_sellers row is untracked by the marketplace
            // lifecycle (same convention as PayoutService's freeze gate) -- treated as
            // active so an otherwise-legitimate event is never silently swallowed.
            $seller = $this->sellers->findByUuid($c, $tenant, $sellerUuid);
            $sellerStatus = $seller !== null ? (string) $seller['status'] : 'active';

            $matchedEndpoints = array_values(array_filter(
                $this->endpoints->activeForSeller($c, $tenant, $sellerUuid),
                static function (array $endpoint) use ($eventType): bool {
                    $subscribed = $endpoint['subscribed_events'] ?? [];

                    return is_array($subscribed) && in_array($eventType, $subscribed, true);
                }
            ));
            if ($matchedEndpoints === []) {
                continue;
            }

            ($this->beforeWriteHook)($c, $eventType, $sellerUuid);

            $sellerSlice = is_array($data[$sellerUuid] ?? null) ? $data[$sellerUuid] : [];
            $payload = $this->projector->project($eventType, $sellerUuid, $sellerSlice);
            $payloadBytes = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            $eventUuid = ($this->uuidGenerator)();
            $this->events->insert($c, $tenant, [
                'uuid' => $eventUuid,
                'seller_uuid' => $sellerUuid,
                'event_type' => $eventType,
                'payload' => $payloadBytes,
                'occurred_at' => $now,
                'source_ref' => $sourceRef,
            ]);

            foreach ($matchedEndpoints as $endpoint) {
                $deliveryUuid = ($this->uuidGenerator)();
                $endpointUuid = (string) $endpoint['uuid'];

                if ($sellerStatus === 'active') {
                    $this->deliveries->insertPending($c, $tenant, [
                        'uuid' => $deliveryUuid,
                        'endpoint_uuid' => $endpointUuid,
                        'webhook_event_uuid' => $eventUuid,
                        'seller_uuid' => $sellerUuid,
                        'next_attempt_at' => $now,
                    ]);
                    $pendingDeliveryUuids[] = $deliveryUuid;
                } else {
                    $this->deliveries->insertPaused($c, $tenant, [
                        'uuid' => $deliveryUuid,
                        'endpoint_uuid' => $endpointUuid,
                        'webhook_event_uuid' => $eventUuid,
                        'seller_uuid' => $sellerUuid,
                    ], $now);
                }
            }
        }

        if ($pendingDeliveryUuids !== []) {
            db($c)->afterCommit(function () use ($c, $pendingDeliveryUuids): void {
                $this->pushQueueHints($c, $pendingDeliveryUuids);
            });
        }
    }

    /**
     * Wake-up hint only (design spec §2.4): a swallowed failure here NEVER
     * threatens correctness -- the `pending` rows this hints for are already
     * durably committed by the time this runs, and Task 5's recovery sweep
     * discovers/enqueues any row whose hint was lost, dropped, or never
     * ran (process crash between commit and this callback).
     *
     * @param list<string> $deliveryUuids
     */
    private function pushQueueHints(ApplicationContext $c, array $deliveryUuids): void
    {
        $container = container($c);
        if (!$container->has(QueueManager::class)) {
            return;
        }

        $queue = $container->get(QueueManager::class);
        if (!$queue instanceof QueueManager) {
            return;
        }

        foreach ($deliveryUuids as $deliveryUuid) {
            try {
                // Glueful\Extensions\Commerce\Queue\Jobs\DeliverSellerWebhookJob (Task 5);
                // push() only persists this string, it is never autoloaded/instantiated here.
                $queue->push('Glueful\\Extensions\\Commerce\\Queue\\Jobs\\DeliverSellerWebhookJob', [
                    'delivery_uuid' => $deliveryUuid,
                ]);
            } catch (\Throwable $e) {
                error_log(
                    '[Commerce][SellerWebhookOutboxPublisher] Failed to enqueue delivery hint '
                        . "'{$deliveryUuid}': " . $e->getMessage()
                );
            }
        }
    }

    /** The SAME driver-pinned-UTC "database is now" primitive every MV5c-2 service uses. */
    private function readDbNow(ApplicationContext $c): string
    {
        $utcNowExpression = UtcNowSql::expression(db($c)->getDriverName());
        $row = db($c)->query()->executeRawFirst("SELECT {$utcNowExpression} AS now_utc");
        $dbNowRaw = $row !== null ? (string) ($row['now_utc'] ?? '') : '';
        if ($dbNowRaw === '') {
            throw new \RuntimeException('Unable to read database-now for seller webhook outbox capture.');
        }

        return $dbNowRaw;
    }
}
