<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Marketplace\Contracts\SellerRoleAuthority;
use Glueful\Extensions\Commerce\Support\UtcNowSql;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Exceptions\HttpClientException;
use Glueful\Http\Security\SafeOutboundTargetResolver;
use Glueful\Validation\ValidationException;

/**
 * Seller-webhook endpoint management (design spec §2.2/§2.6/§2.9/§2.10,
 * MV5c-2 Task 3): register/update/rotate-secret/disable/enable/delete --
 * mirroring {@see SellerApiKeyService}'s exact
 * claim -> re-read -> authority -> validate -> encrypt -> persist -> audit
 * atomic idiom, one `db($c)->transaction()` per mutation.
 *
 * **Lock order (design spec §2.2/§2.10):** every mutation claims the
 * SELLER's revision first (refusing a `suspended`/`closed`/unknown seller),
 * THEN -- for every mutation against an EXISTING endpoint -- claims the
 * ENDPOINT's own revision (refusing an unknown/cross-seller/TOMBSTONED
 * endpoint identically, as a non-revealing 404), and ONLY THEN re-reads the
 * acting user's LIVE membership + role and requires
 * `commerce.seller.webhooks.manage`. This is a deliberate extension of
 * {@see SellerApiKeyService}'s own order (which re-derives authority BEFORE
 * its lineage-revision claim): claiming the endpoint FIRST means a
 * concurrent demotion/suspension that commits between this transaction's
 * endpoint claim and its authority re-read is still caught (the re-read is
 * fresh, inside the SAME transaction, regardless of where in the sequence
 * it runs) while every mutation's lock ACQUISITION order stays identical
 * and deterministic. {@see self::claimAndAuthorize()} implements this
 * shared sequence; {@see self::register()} (no existing endpoint to claim)
 * uses {@see self::claimAndRequireActiveSeller()} +
 * {@see self::requireActiveMembershipWithCapability()} directly.
 *
 * **SSRF-at-registration (design spec §2.6):** every URL a seller supplies
 * (register, and an update that changes the URL) is validated -- WITHOUT
 * issuing any HTTP request -- via the framework's
 * {@see SafeOutboundTargetResolver::resolveWebhook()}. Only the returned
 * `canonicalUrl` (ASCII/IDNA-canonicalized, no port/user-info) is persisted;
 * the resolved IP itself is discarded immediately (every actual DELIVERY
 * re-resolves and pins fresh, a later task's concern -- registration proves
 * safety at input time only, never reuses this resolution for a
 * connection). A safety-check failure never reaches the caller as the
 * framework's own exception type -- {@see self::resolveUrlOrFail()} always
 * re-wraps it as {@see SellerWebhookException::unsafeUrl()}, whose message
 * is the resolver's own already-generic text (see that factory's
 * docblock): no resolved internal address is ever exposed.
 *
 * **Tombstone (design spec §2.2/§2.9):** {@see self::delete()} NEVER
 * removes a row -- it sets `status = 'deleted'` + `deleted_at`, revokes
 * every live secret, and terminally cancels the endpoint's own
 * pending/paused deliveries, all in the SAME transaction as the endpoint's
 * OWN tombstone write. Because {@see SellerWebhookEndpointRepository::claimActiveRevision()}
 * refuses to claim a `status = 'deleted'` row, a SECOND `delete()` and any
 * `enable()` against an already-tombstoned endpoint both fail their claim
 * identically and surface the SAME non-revealing 404 as "never existed" --
 * a deleted endpoint can never be resurrected.
 */
final class SellerWebhookEndpointService
{
    /** @var callable(): string */
    private $uuidGenerator;

    /**
     * @param (callable(): string)|null $uuidGenerator Injectable seam for
     *     tests (mirrors {@see SellerApiKeyService}'s identical convention).
     *     Defaults to the house {@see Utils::generateNanoID()} generator.
     */
    public function __construct(
        private SellerRepository $sellers,
        private SellerMembershipRepository $memberships,
        private SellerWebhookEndpointRepository $endpoints,
        private SellerWebhookDeliveryRepository $deliveries,
        private SellerRoleAuthority $roles,
        private SellerWebhookSecretService $secrets,
        private SafeOutboundTargetResolver $urlResolver,
        ?callable $uuidGenerator = null,
    ) {
        $this->uuidGenerator = $uuidGenerator ?? static fn (): string => Utils::generateNanoID();
    }

    /**
     * Register a brand-new endpoint (design spec §2.2): URL SSRF-validated
     * and event set catalog-validated BEFORE any claim (basic input-shape
     * checks, mirroring {@see SellerApiKeyService::create()}'s "validated
     * first, before any claim" convention -- neither depends on the actor's
     * live role). Inserts the endpoint (revision 0, `active`) + its first
     * secret (`current`) + a `register` audit row, atomically.
     *
     * @param list<string> $events
     * @return array{endpoint: array<string,mixed>, secret: string}
     */
    public function register(
        ApplicationContext $c,
        string $tenant,
        string $sellerUuid,
        string $url,
        array $events,
        string $actor
    ): array {
        $actor = trim($actor);
        if ($actor === '') {
            throw ValidationException::forField('actor', 'actor is required.');
        }

        $canonicalEvents = $this->validateEvents($events);
        $canonicalUrl = $this->resolveUrlOrFail($url);

        return db($c)->transaction(function () use (
            $c,
            $tenant,
            $sellerUuid,
            $canonicalUrl,
            $canonicalEvents,
            $actor
        ): array {
            $this->claimAndRequireActiveSeller($c, $tenant, $sellerUuid);
            $this->requireActiveMembershipWithCapability($c, $tenant, $sellerUuid, $actor);

            $endpointUuid = ($this->uuidGenerator)();
            $this->endpoints->insert($c, $tenant, [
                'uuid' => $endpointUuid,
                'seller_uuid' => $sellerUuid,
                'url' => $canonicalUrl,
                'subscribed_events' => $canonicalEvents,
                'status' => 'active',
                'revision' => 0,
                'consecutive_failures' => 0,
                'created_by' => $actor,
            ]);

            $secretUuid = ($this->uuidGenerator)();
            $minted = $this->secrets->mint($tenant, $endpointUuid, $secretUuid);
            $this->endpoints->insertSecret($c, $tenant, [
                'uuid' => $secretUuid,
                'endpoint_uuid' => $endpointUuid,
                'secret_ciphertext' => $minted['ciphertext'],
                'secret_fingerprint' => $minted['fingerprint'],
                'relationship' => 'current',
            ]);

            // The audit write is LAST, on purpose (mirrors
            // SellerApiKeyService::create()): a forced uuid collision here
            // is this method's atomicity proof -- it must roll back the
            // endpoint + secret rows that "committed" moments earlier in
            // this SAME closure.
            $this->endpoints->insertEvent($c, $tenant, [
                'uuid' => ($this->uuidGenerator)(),
                'endpoint_uuid' => $endpointUuid,
                'seller_uuid' => $sellerUuid,
                'action' => 'register',
                'actor_uuid' => $actor,
            ]);

            $endpoint = $this->endpoints->findByUuid($c, $tenant, $endpointUuid);
            if ($endpoint === null) {
                throw new \RuntimeException('Registered seller webhook endpoint could not be reloaded.');
            }

            return ['endpoint' => $endpoint, 'secret' => $minted['plain']];
        });
    }

    /**
     * Update `url` and/or `subscribed_events` (design spec §2.2/§2.10). A
     * URL change RE-VALIDATES SSRF (same rules as `register()`); events, if
     * given, are re-validated against the catalog. NEVER returns a secret.
     * Both are validated BEFORE the claim, same reasoning as `register()`.
     *
     * @param list<string>|null $events null leaves subscribed_events untouched
     * @return array{endpoint: array<string,mixed>}
     */
    public function updateEndpoint(
        ApplicationContext $c,
        string $tenant,
        string $sellerUuid,
        string $endpointUuid,
        ?string $url,
        ?array $events,
        string $actor
    ): array {
        $actor = trim($actor);
        if ($actor === '') {
            throw ValidationException::forField('actor', 'actor is required.');
        }

        $canonicalUrl = $url !== null ? $this->resolveUrlOrFail($url) : null;
        $canonicalEvents = $events !== null ? $this->validateEvents($events) : null;

        return db($c)->transaction(function () use (
            $c,
            $tenant,
            $sellerUuid,
            $endpointUuid,
            $canonicalUrl,
            $canonicalEvents,
            $actor
        ): array {
            $endpoint = $this->claimAndAuthorize($c, $tenant, $sellerUuid, $endpointUuid, $actor);

            $changes = [];
            $changedFields = [];
            if ($canonicalUrl !== null && $canonicalUrl !== (string) $endpoint['url']) {
                $changes['url'] = $canonicalUrl;
                $changedFields[] = 'url';
            }
            if ($canonicalEvents !== null) {
                $changes['subscribed_events'] = $canonicalEvents;
                $changedFields[] = 'subscribed_events';
            }

            if ($changes !== []) {
                $this->endpoints->update($c, $tenant, $endpointUuid, $changes);

                // 'url_change' is the migration-019-pinned action slug for
                // ANY endpoint attribute update (design spec §3's fixed
                // audit-action vocabulary carries no separate "events
                // changed" slug); `detail` records which fields actually
                // changed.
                $this->endpoints->insertEvent($c, $tenant, [
                    'uuid' => ($this->uuidGenerator)(),
                    'endpoint_uuid' => $endpointUuid,
                    'seller_uuid' => $sellerUuid,
                    'action' => 'url_change',
                    'actor_uuid' => $actor,
                    'detail' => implode(',', $changedFields),
                ]);
            }

            $fresh = $this->endpoints->findByUuid($c, $tenant, $endpointUuid);
            if ($fresh === null) {
                throw new \RuntimeException('Updated seller webhook endpoint could not be reloaded.');
            }

            return ['endpoint' => $fresh];
        });
    }

    /**
     * Rotate the signing secret (design spec §2.2): retires any stale
     * `previous` secret, demotes the CURRENT secret to `previous` with
     * `overlap_expires_at = now + secret_overlap_hours` (config), mints a
     * fresh `current` secret, and audits -- all as ONE endpoint-revision
     * transaction. The new raw secret is returned exactly once.
     *
     * @return array{endpoint: array<string,mixed>, secret: string}
     */
    public function rotateSecret(
        ApplicationContext $c,
        string $tenant,
        string $sellerUuid,
        string $endpointUuid,
        string $actor
    ): array {
        $actor = trim($actor);
        if ($actor === '') {
            throw ValidationException::forField('actor', 'actor is required.');
        }

        return db($c)->transaction(function () use ($c, $tenant, $sellerUuid, $endpointUuid, $actor): array {
            $this->claimAndAuthorize($c, $tenant, $sellerUuid, $endpointUuid, $actor);

            $now = $this->readDbNow($c);
            $nowStr = $now->format('Y-m-d H:i:s');

            $current = $this->endpoints->findCurrentSecret($c, $tenant, $endpointUuid);
            if ($current === null) {
                throw SellerWebhookException::noCurrentSecret();
            }

            // Retire any stale `previous` secret BEFORE demoting the
            // current one, so the "at most one unexpired previous"
            // invariant never sees two live `previous` rows at once.
            $olderPrevious = $this->endpoints->findPreviousSecret($c, $tenant, $endpointUuid);
            if ($olderPrevious !== null) {
                $this->endpoints->retireSecret($c, $tenant, (string) $olderPrevious['uuid'], $nowStr);
            }

            $overlapHours = (int) config($c, 'commerce.marketplace.webhooks.secret_overlap_hours', 24);
            $overlapExpiresAt = $now->modify("+{$overlapHours} hours")->format('Y-m-d H:i:s');
            $this->endpoints->demoteCurrentSecretToPrevious(
                $c,
                $tenant,
                (string) $current['uuid'],
                $overlapExpiresAt
            );

            $secretUuid = ($this->uuidGenerator)();
            $minted = $this->secrets->mint($tenant, $endpointUuid, $secretUuid);
            $this->endpoints->insertSecret($c, $tenant, [
                'uuid' => $secretUuid,
                'endpoint_uuid' => $endpointUuid,
                'secret_ciphertext' => $minted['ciphertext'],
                'secret_fingerprint' => $minted['fingerprint'],
                'relationship' => 'current',
            ]);

            $this->endpoints->insertEvent($c, $tenant, [
                'uuid' => ($this->uuidGenerator)(),
                'endpoint_uuid' => $endpointUuid,
                'seller_uuid' => $sellerUuid,
                'action' => 'secret_rotate',
                'actor_uuid' => $actor,
            ]);

            $fresh = $this->endpoints->findByUuid($c, $tenant, $endpointUuid);
            if ($fresh === null) {
                throw new \RuntimeException('Rotated seller webhook endpoint could not be reloaded.');
            }

            return ['endpoint' => $fresh, 'secret' => $minted['plain']];
        });
    }

    /**
     * Manual disablement (design spec §2.2/§2.7): flips `status =
     * 'disabled'`, PAUSES the endpoint's own `pending` deliveries with
     * `pause_reason = endpoint_disabled` (persisting each row's remaining
     * retry delay), and audits.
     *
     * @return array{endpoint: array<string,mixed>}
     */
    public function disable(
        ApplicationContext $c,
        string $tenant,
        string $sellerUuid,
        string $endpointUuid,
        string $actor,
        ?string $reason = null
    ): array {
        $actor = trim($actor);
        if ($actor === '') {
            throw ValidationException::forField('actor', 'actor is required.');
        }

        return db($c)->transaction(function () use ($c, $tenant, $sellerUuid, $endpointUuid, $actor, $reason): array {
            $this->claimAndAuthorize($c, $tenant, $sellerUuid, $endpointUuid, $actor);

            $now = $this->readDbNow($c);
            $nowStr = $now->format('Y-m-d H:i:s');

            $this->endpoints->markDisabled($c, $tenant, $endpointUuid, $reason, $nowStr);

            foreach ($this->deliveries->findByEndpointAndStatus($c, $tenant, $endpointUuid, 'pending') as $row) {
                $remaining = $this->remainingSeconds(
                    isset($row['next_attempt_at']) ? (string) $row['next_attempt_at'] : null,
                    $now
                );
                $this->deliveries->pauseOne(
                    $c,
                    $tenant,
                    (string) $row['uuid'],
                    'endpoint_disabled',
                    $nowStr,
                    $remaining
                );
            }

            $this->endpoints->insertEvent($c, $tenant, [
                'uuid' => ($this->uuidGenerator)(),
                'endpoint_uuid' => $endpointUuid,
                'seller_uuid' => $sellerUuid,
                'action' => 'disable',
                'actor_uuid' => $actor,
                'reason' => $reason,
            ]);

            $fresh = $this->endpoints->findByUuid($c, $tenant, $endpointUuid);
            if ($fresh === null) {
                throw new \RuntimeException('Disabled seller webhook endpoint could not be reloaded.');
            }

            return ['endpoint' => $fresh];
        });
    }

    /**
     * Re-activation (design spec §2.2/§2.7): RE-VALIDATES SSRF against the
     * STORED url (a throw here rolls back the claim, leaving the endpoint
     * untouched -- still `disabled`), resets `consecutive_failures`, flips
     * `status = 'active'`, and resumes ONLY this endpoint's
     * `endpoint_disabled`-paused deliveries (never a `seller_suspended` one
     * -- see {@see SellerWebhookDeliveryRepository::findByEndpointStatusAndPauseReason()}).
     *
     * @return array{endpoint: array<string,mixed>}
     */
    public function enable(
        ApplicationContext $c,
        string $tenant,
        string $sellerUuid,
        string $endpointUuid,
        string $actor
    ): array {
        $actor = trim($actor);
        if ($actor === '') {
            throw ValidationException::forField('actor', 'actor is required.');
        }

        return db($c)->transaction(function () use ($c, $tenant, $sellerUuid, $endpointUuid, $actor): array {
            $endpoint = $this->claimAndAuthorize($c, $tenant, $sellerUuid, $endpointUuid, $actor);

            // Re-validated INSIDE the transaction, after the claim: a
            // safety failure here throws out of this closure, rolling the
            // claim back and leaving the endpoint exactly as it was.
            $this->resolveUrlOrFail((string) $endpoint['url']);

            $now = $this->readDbNow($c);
            $nowStr = $now->format('Y-m-d H:i:s');

            $this->endpoints->markEnabled($c, $tenant, $endpointUuid, $nowStr);

            $paused = $this->deliveries->findByEndpointStatusAndPauseReason(
                $c,
                $tenant,
                $endpointUuid,
                'paused',
                'endpoint_disabled'
            );
            foreach ($paused as $row) {
                $remaining = max(0, (int) ($row['paused_remaining_seconds'] ?? 0));
                $nextAttemptAt = $now->modify("+{$remaining} seconds")->format('Y-m-d H:i:s');
                $this->deliveries->resumeOne(
                    $c,
                    $tenant,
                    (string) $row['uuid'],
                    'endpoint_disabled',
                    $nextAttemptAt,
                    $nowStr
                );
            }

            $this->endpoints->insertEvent($c, $tenant, [
                'uuid' => ($this->uuidGenerator)(),
                'endpoint_uuid' => $endpointUuid,
                'seller_uuid' => $sellerUuid,
                'action' => 'enable',
                'actor_uuid' => $actor,
            ]);

            $fresh = $this->endpoints->findByUuid($c, $tenant, $endpointUuid);
            if ($fresh === null) {
                throw new \RuntimeException('Enabled seller webhook endpoint could not be reloaded.');
            }

            return ['endpoint' => $fresh];
        });
    }

    /**
     * Tombstone delete (design spec §2.2/§2.9): `status = 'deleted'` +
     * `deleted_at`, revokes EVERY live secret, terminally CANCELS
     * pending/paused deliveries, and audits -- retaining every row (never a
     * removal). A second `delete()` (or any `enable()`) against the SAME
     * endpoint afterward fails its claim and surfaces a plain, non-revealing
     * 404 -- see this class's own docblock.
     *
     * @return array{endpoint: array<string,mixed>}
     */
    public function delete(
        ApplicationContext $c,
        string $tenant,
        string $sellerUuid,
        string $endpointUuid,
        string $actor,
        ?string $reason = null
    ): array {
        $actor = trim($actor);
        if ($actor === '') {
            throw ValidationException::forField('actor', 'actor is required.');
        }

        return db($c)->transaction(function () use ($c, $tenant, $sellerUuid, $endpointUuid, $actor, $reason): array {
            $this->claimAndAuthorize($c, $tenant, $sellerUuid, $endpointUuid, $actor);

            $now = $this->readDbNow($c);
            $nowStr = $now->format('Y-m-d H:i:s');

            $this->endpoints->markDeleted($c, $tenant, $endpointUuid, $nowStr);
            $this->endpoints->revokeAllSecretsForEndpoint($c, $tenant, $endpointUuid, $nowStr);
            $this->deliveries->cancelPendingAndPausedForEndpoint($c, $tenant, $endpointUuid, $nowStr);

            $this->endpoints->insertEvent($c, $tenant, [
                'uuid' => ($this->uuidGenerator)(),
                'endpoint_uuid' => $endpointUuid,
                'seller_uuid' => $sellerUuid,
                'action' => 'delete',
                'actor_uuid' => $actor,
                'reason' => $reason,
            ]);

            $fresh = $this->endpoints->findByUuidIncludingDeleted($c, $tenant, $endpointUuid);
            if ($fresh === null) {
                throw new \RuntimeException('Deleted seller webhook endpoint could not be reloaded.');
            }

            return ['endpoint' => $fresh];
        });
    }

    // -----------------------------------------------------------------
    // Shared claim/authority helpers
    // -----------------------------------------------------------------

    /**
     * Stage 1 of the lock order, shared by EVERY mutation (including
     * `register()`, which has no endpoint to claim yet): claim the seller's
     * revision and require it `active` -- refusing `suspended`/`closed`
     * identically (design spec §2.2/§2.9: "Management is unavailable while
     * suspended").
     *
     * @return array<string,mixed>
     */
    private function claimAndRequireActiveSeller(ApplicationContext $c, string $tenant, string $sellerUuid): array
    {
        if (!$this->sellers->claimRevision($c, $tenant, $sellerUuid)) {
            throw new NotFoundException('Resource not found.');
        }

        $seller = $this->sellers->findByUuid($c, $tenant, $sellerUuid);
        if ($seller === null) {
            throw new NotFoundException('Resource not found.');
        }
        if ((string) $seller['status'] !== 'active') {
            throw SellerWebhookException::sellerNotActive((string) $seller['status']);
        }

        return $seller;
    }

    /**
     * The final authority stage, shared by every mutation: a FRESH read of
     * the actor's live membership + role, requiring
     * `commerce.seller.webhooks.manage`.
     *
     * @return array<string,mixed>
     */
    private function requireActiveMembershipWithCapability(
        ApplicationContext $c,
        string $tenant,
        string $sellerUuid,
        string $actor
    ): array {
        $membership = $this->memberships->findBySellerAndUser($c, $tenant, $sellerUuid, $actor);
        if ($membership === null || (string) $membership['status'] !== 'active') {
            throw SellerWebhookException::membershipInactive();
        }

        $role = (string) $membership['role'];
        if (!$this->roles->allows($role, FixedSellerRoleAuthority::WEBHOOKS_MANAGE)) {
            throw SellerWebhookException::capabilityDenied();
        }

        return $membership;
    }

    /**
     * Stages 1-3 of the lock order for every mutation against an EXISTING
     * endpoint (design spec §2.2/§2.10): claim seller revision -> claim
     * endpoint revision (refusing unknown/cross-seller/tombstoned
     * identically as 404) -> re-read actor membership + capability.
     *
     * @return array<string,mixed> the freshly-claimed endpoint row
     */
    private function claimAndAuthorize(
        ApplicationContext $c,
        string $tenant,
        string $sellerUuid,
        string $endpointUuid,
        string $actor
    ): array {
        $this->claimAndRequireActiveSeller($c, $tenant, $sellerUuid);

        if (!$this->endpoints->claimActiveRevision($c, $tenant, $endpointUuid)) {
            throw new NotFoundException('Resource not found.');
        }

        $endpoint = $this->endpoints->findByUuid($c, $tenant, $endpointUuid);
        if ($endpoint === null || (string) $endpoint['seller_uuid'] !== $sellerUuid) {
            throw new NotFoundException('Resource not found.');
        }

        $this->requireActiveMembershipWithCapability($c, $tenant, $sellerUuid, $actor);

        return $endpoint;
    }

    // -----------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------

    /**
     * Design spec §2.2/§2.6: validates via the framework
     * {@see SafeOutboundTargetResolver::resolveWebhook()} and returns ONLY
     * the canonical URL -- the resolved IP is discarded immediately, never
     * reused for a connection (registration proves safety at input time
     * only).
     */
    private function resolveUrlOrFail(string $url): string
    {
        try {
            $resolved = $this->urlResolver->resolveWebhook($url);
        } catch (HttpClientException $e) {
            throw SellerWebhookException::unsafeUrl($e->getMessage());
        }

        return $resolved->canonicalUrl;
    }

    /**
     * Design spec §2.2/§2.3: non-empty, catalog-only subset -- canonicalized
     * (trimmed, de-duplicated, sorted) mirroring
     * {@see SellerApiKeyScopeValidator}'s identical convention. Does NOT
     * depend on the actor's live role (unlike declared-scope validation),
     * so it is always safe to run BEFORE any claim.
     *
     * @param list<string> $events
     * @return list<string>
     */
    private function validateEvents(array $events): array
    {
        $unique = [];
        foreach ($events as $event) {
            if (!is_string($event)) {
                throw ValidationException::forField('events', 'Every event must be a string.');
            }
            $trimmed = trim($event);
            if ($trimmed !== '') {
                $unique[$trimmed] = true;
            }
        }

        $canonical = array_keys($unique);
        sort($canonical, SORT_STRING);

        if ($canonical === []) {
            throw ValidationException::forField('events', 'At least one event is required.');
        }

        foreach ($canonical as $event) {
            if (!SellerWebhookEventCatalog::contains($event)) {
                throw ValidationException::forField(
                    'events',
                    "Event '{$event}' is not a supported webhook event type."
                );
            }
        }

        return $canonical;
    }

    // -----------------------------------------------------------------
    // Time helpers
    // -----------------------------------------------------------------

    /**
     * The remaining delay until `$nextAttemptAt`, clamped to >= 0 (design
     * spec §2.9's pause-persistence semantics, reused here for
     * `endpoint_disabled` pauses): a not-yet-scheduled row (`next_attempt_at`
     * still null -- e.g. a fresh, zero-attempt pending delivery) is treated
     * as immediately due, mirroring the spec's own "new suspended events use
     * zero" rule for the sibling `seller_suspended` pause reason.
     */
    private function remainingSeconds(?string $nextAttemptAt, \DateTimeImmutable $now): int
    {
        if ($nextAttemptAt === null || $nextAttemptAt === '') {
            return 0;
        }

        $next = new \DateTimeImmutable($nextAttemptAt, new \DateTimeZone('UTC'));

        return max(0, $next->getTimestamp() - $now->getTimestamp());
    }

    /**
     * The SAME driver-pinned-UTC "database is the single source of truth
     * for now" primitive {@see SellerApiKeyService::readDbNow()} uses,
     * reused here for pause/resume/rotation timestamps.
     */
    private function readDbNow(ApplicationContext $c): \DateTimeImmutable
    {
        $utcNowExpression = UtcNowSql::expression(db($c)->getDriverName());
        $row = db($c)->query()->executeRawFirst("SELECT {$utcNowExpression} AS now_utc");
        $dbNowRaw = $row !== null ? (string) ($row['now_utc'] ?? '') : '';
        if ($dbNowRaw === '') {
            throw new \RuntimeException('Unable to read database-now for seller webhook endpoint mutation.');
        }

        return new \DateTimeImmutable($dbNowRaw, new \DateTimeZone('UTC'));
    }
}
