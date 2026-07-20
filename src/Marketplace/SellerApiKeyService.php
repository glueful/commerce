<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Auth\ApiKey\ApiKey;
use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Marketplace\Contracts\SellerRoleAuthority;
use Glueful\Extensions\Commerce\Support\UtcNowSql;
use Glueful\Helpers\Utils;
use Glueful\Http\Exceptions\Client\ConflictException;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Validation\ValidationException;

/**
 * Seller-API-key CREATE (design spec §2.1/§2.3/§2.5/§2.8, MV5c-1 Task 3):
 * self-service issuance of a scoped machine credential, reusing the
 * framework's {@see ApiKeyService} for all crypto (generation/hashing/
 * prefix/expiry) while Commerce owns only the seller binding + live
 * authorization.
 *
 * **No caller-supplied subject or role.** `create()`'s signature carries
 * only the ACTING SESSION USER (`$actor`) -- never a body-supplied subject
 * uuid or role. The subject bound to the lineage, and the role its
 * declared scopes are validated against, are ALWAYS derived from $actor's
 * OWN live seller membership, re-read fresh inside the transaction below --
 * a caller cannot mint a key "on behalf of" anyone else, and cannot inflate
 * a key's scopes beyond whatever role $actor currently, actually holds.
 *
 * **Global lock order (design spec §2.8/§2.9, mirroring
 * {@see SellerMembershipService::claimAndRequireMutableSeller()} /
 * {@see SellerService}'s claim-then-re-read discipline): seller revision ->
 * fresh actor membership/role/capability re-read -> framework key ->
 * lineage/credential/audit writes.** Everything from the seller-revision
 * claim through the audit-event insert runs inside ONE
 * `db($c)->transaction()` closure, on the SAME connection the framework's
 * {@see ApiKeyService::create()} model-level insert transparently joins (it
 * opens no transaction of its own) -- so a failure ANYWHERE after the claim
 * (an inactive seller, a lapsed membership, a role that no longer holds
 * `apikeys.manage`, an invalid scope, or a forced audit-uuid collision)
 * rolls back the framework `api_keys` row, the lineage row, and the
 * credential row together. There is no partially-bound framework key and no
 * half-written lineage.
 *
 * `current_credential_uuid` (NOT NULL on `commerce_seller_api_keys`, design
 * spec §3) is never patched in with a second UPDATE: the credential's uuid
 * is generated UP FRONT (before either insert), so the lineage INSERT
 * already carries its final `current_credential_uuid` value, and the
 * credential row inserted moments later in the SAME transaction reuses that
 * exact uuid. A chicken-and-egg two-step insert-then-update never happens.
 *
 * Basic input syntax -- `name` (non-empty, <=120 chars) and `expires_at`
 * (when provided: a parseable, EXPLICITLY UTC timestamp, strictly AFTER
 * database-now) -- is validated FIRST, before any claim, mirroring
 * {@see SellerService::guardReasonAndActor()}'s "422 before any row is
 * touched" convention. Declared-scope validation (empty/wildcard/
 * non-grantable/not-held-by-role) is deferred to AFTER the live role is
 * derived (see {@see SellerApiKeyScopeValidator}) -- it depends on that
 * live role and so cannot run any earlier.
 *
 * **Rotation/revocation (design spec §2.9, MV5c-1 Task 5):** {@see self::rotate()}
 * and {@see self::revoke()} extend the SAME global lock order one step
 * further -- **seller revision -> fresh actor membership/capability re-read
 * -> lineage revision -> framework/credential writes.** Both share
 * {@see self::requireLiveManagerAuthority()} for the first two stages
 * (byte-identical to `create()`'s own authority re-read, factored out here
 * so a demoted manager or a suspended seller refuses BOTH exactly like it
 * refuses a create) and {@see self::resolveLineageForMutation()} for the
 * lineage-revision claim: an affected-row-checked `UPDATE ... WHERE
 * status = 'active'` (mirrors {@see SellerRepository::claimRevision()}'s
 * idiom) that NEVER collapses a 0-row result into one outcome -- it
 * re-reads (never claims) to distinguish "unknown/cross-tenant/cross-seller"
 * (404, {@see NotFoundException}) from "found, this seller's lineage, but
 * already revoked" (409 on `rotate()` via {@see ConflictException}; a
 * stable, audit-free no-op on `revoke()`). Rotate and revoke SERIALIZE on
 * this SAME claim: whichever commits first decides the lineage's fate for
 * the one that was waiting -- a committed revoke always leaves the whole
 * lineage revoked, so NO active successor ever survives it, even one a
 * concurrent rotate just minted.
 *
 * `rotate()` never re-declares the successor's tenant/seller/subject/scopes/
 * expiry -- it hands the framework's OWN {@see ApiKey} model for the
 * CURRENT credential to {@see ApiKeyService::rotate()}, which copies those
 * fields off `$existing` itself. The binding therefore keeps its ORIGINAL
 * subject even when a DIFFERENT manager performs the rotation -- authority
 * to rotate is never authority to rebind. The predecessor's `grace_expires_at`
 * is carried verbatim from {@see ApiKeyService::rotate()}'s returned
 * `old_expires_at` (already the earlier of the predecessor's prior expiry
 * and the grace deadline, and already UTC `Y-m-d H:i:s` -- see that
 * method's own docblock), never recomputed here.
 *
 * `revoke()` enumerates EVERY credential row recorded for the lineage
 * (current, any grace predecessor, any already-revoked generation) via
 * {@see SellerApiKeyRepository::findCredentialsForLineage()} and revokes
 * each still-live framework key + credential row before marking the lineage
 * itself revoked -- whole-lineage revocation, never just the current
 * generation.
 */
final class SellerApiKeyService
{
    private const NAME_MAX_LENGTH = 120;

    /** @var callable(): string */
    private $uuidGenerator;

    /**
     * @param (callable(): string)|null $uuidGenerator Injectable seam for tests forcing
     *     a lineage/credential/audit-insert-time uuid collision (mirrors
     *     {@see SellerService}/{@see ReservePolicyService}'s identical convention) --
     *     proves the framework key + lineage + credential + audit writes are
     *     atomic. Defaults to the house {@see Utils::generateNanoID()} generator.
     */
    public function __construct(
        private SellerRepository $sellers,
        private SellerMembershipRepository $memberships,
        private SellerApiKeyRepository $apiKeys,
        private SellerRoleAuthority $roles,
        private SellerApiKeyScopeValidator $scopeValidator,
        ?callable $uuidGenerator = null,
    ) {
        $this->uuidGenerator = $uuidGenerator ?? static fn (): string => Utils::generateNanoID();
    }

    /**
     * @param list<string> $declaredScopes
     * @return array{lineage: array<string,mixed>, plain_key: string}
     */
    public function create(
        ApplicationContext $c,
        string $tenant,
        string $sellerUuid,
        string $name,
        array $declaredScopes,
        ?string $expiresAt,
        string $actor
    ): array {
        $name = trim($name);
        if ($name === '') {
            throw ValidationException::forField('name', 'name is required.');
        }
        if (strlen($name) > self::NAME_MAX_LENGTH) {
            throw ValidationException::forField(
                'name',
                'name must be at most ' . self::NAME_MAX_LENGTH . ' characters.'
            );
        }

        $actor = trim($actor);
        if ($actor === '') {
            throw ValidationException::forField('actor', 'actor is required.');
        }

        // Syntax + DB-now comparison happen BEFORE any claim (design spec
        // §2.8) -- see self::validateExpiry() for why this reads
        // database-now rather than PHP's wall clock.
        $normalizedExpiresAt = $this->validateExpiry($c, $expiresAt);

        return db($c)->transaction(function () use (
            $c,
            $tenant,
            $sellerUuid,
            $name,
            $declaredScopes,
            $normalizedExpiresAt,
            $actor
        ): array {
            if (!$this->sellers->claimRevision($c, $tenant, $sellerUuid)) {
                throw new NotFoundException('Resource not found.');
            }

            $seller = $this->sellers->findByUuid($c, $tenant, $sellerUuid);
            if ($seller === null) {
                throw new NotFoundException('Resource not found.');
            }
            if ((string) $seller['status'] !== 'active') {
                throw SellerApiKeyException::sellerInactive((string) $seller['status']);
            }

            // Live authority, re-read AFTER the seller-revision claim above
            // (design spec §2.8): a membership grant/role-change/revoke that
            // committed before this claim is what this re-read observes; one
            // that races AFTER it serializes behind this transaction.
            $membership = $this->memberships->findBySellerAndUser($c, $tenant, $sellerUuid, $actor);
            if ($membership === null || (string) $membership['status'] !== 'active') {
                throw SellerApiKeyException::membershipInactive();
            }

            $role = (string) $membership['role'];
            if (!$this->roles->allows($role, FixedSellerRoleAuthority::APIKEYS_MANAGE)) {
                throw SellerApiKeyException::capabilityDenied();
            }

            // Scope grantability is checked against THIS freshly-derived
            // role, never a caller-supplied or stale one.
            $canonicalScopes = $this->scopeValidator->validate($declaredScopes, $role);

            // Generated up front: the lineage insert below needs its final
            // current_credential_uuid value, and the framework key's OWN
            // uuid is independent (minted by ApiKeyService::create() itself).
            $lineageUuid = ($this->uuidGenerator)();
            $credentialUuid = ($this->uuidGenerator)();

            $created = ApiKeyService::create($c, [
                'user_uuid' => $actor,
                'name' => $name,
                'scopes' => $canonicalScopes,
                'expires_at' => $normalizedExpiresAt,
            ]);
            $frameworkKeyUuid = (string) $created['key']->uuid;

            $this->apiKeys->insertLineage($c, $tenant, [
                'uuid' => $lineageUuid,
                'seller_uuid' => $sellerUuid,
                'subject_user_uuid' => $actor,
                'declared_scopes' => $canonicalScopes,
                'name' => $name,
                'status' => 'active',
                'current_credential_uuid' => $credentialUuid,
                'expires_at' => $normalizedExpiresAt,
                'created_by' => $actor,
            ]);

            $this->apiKeys->insertCredential($c, $tenant, [
                'uuid' => $credentialUuid,
                'lineage_uuid' => $lineageUuid,
                'framework_key_uuid' => $frameworkKeyUuid,
                'generation' => 1,
                'relationship' => 'current',
            ]);

            // The audit write is LAST, on purpose (design spec §2.10): a
            // forced uuid collision here is this class's atomicity proof --
            // it must roll back the framework key + lineage + credential
            // rows that "committed" moments earlier in this SAME closure.
            $this->apiKeys->insertEvent($c, $tenant, [
                'uuid' => ($this->uuidGenerator)(),
                'lineage_uuid' => $lineageUuid,
                'seller_uuid' => $sellerUuid,
                'subject_user_uuid' => $actor,
                'action' => 'created',
                'actor_uuid' => $actor,
            ]);

            $lineage = $this->apiKeys->findLineageByUuid($c, $tenant, $lineageUuid);
            if ($lineage === null) {
                throw new \RuntimeException('Created seller API key lineage could not be reloaded.');
            }

            return [
                'lineage' => $lineage,
                // The raw secret -- returned exactly once, never persisted by Commerce.
                'plain_key' => $created['plain'],
            ];
        });
    }

    /**
     * Rotation (design spec §2.9): claims the seller revision, re-derives
     * live manager authority, claims the LINEAGE revision (refusing an
     * unknown/cross-tenant/cross-seller lineage with 404, and an
     * already-revoked one with 409), re-reads the current credential's
     * framework key (refusing a revoked/expired one with 409), delegates to
     * {@see ApiKeyService::rotate()} for the successor key, demotes the
     * predecessor credential, inserts the successor credential, advances the
     * lineage's pointer, and audits -- all in ONE transaction, so a failure
     * anywhere (including a forced audit-uuid collision) rolls back the
     * framework rotation itself along with every Commerce-side write. See
     * this class's own docblock for the full lock-order/subject-preservation
     * contract.
     *
     * @return array{lineage: array<string,mixed>, plain_key: string}
     */
    public function rotate(
        ApplicationContext $c,
        string $tenant,
        string $sellerUuid,
        string $lineageUuid,
        string $actor
    ): array {
        $actor = trim($actor);
        if ($actor === '') {
            throw ValidationException::forField('actor', 'actor is required.');
        }

        return db($c)->transaction(function () use ($c, $tenant, $sellerUuid, $lineageUuid, $actor): array {
            $this->requireLiveManagerAuthority($c, $tenant, $sellerUuid, $actor);

            $resolved = $this->resolveLineageForMutation($c, $tenant, $sellerUuid, $lineageUuid);
            if ($resolved['alreadyRevoked']) {
                throw new ConflictException('Seller API key lineage has already been revoked.');
            }
            $lineage = $resolved['lineage'];

            $credential = $this->apiKeys->findCredentialByUuid(
                $c,
                $tenant,
                (string) $lineage['current_credential_uuid']
            );
            if ($credential === null) {
                throw new \RuntimeException('Seller API key lineage has no resolvable current credential.');
            }

            $frameworkKey = $this->findFrameworkKey($c, (string) $credential['framework_key_uuid']);
            if ($frameworkKey === null) {
                throw new \RuntimeException('Seller API key credential has no resolvable framework key.');
            }
            if ($frameworkKey->isRevoked() || $frameworkKey->isExpired()) {
                throw new ConflictException('Seller API key credential is no longer rotatable.');
            }

            $rotated = ApiKeyService::rotate($c, $frameworkKey);
            $graceExpiresAt = (string) $rotated['old_expires_at'];

            $this->apiKeys->demoteCredentialToPredecessor(
                $c,
                $tenant,
                (string) $credential['uuid'],
                $graceExpiresAt
            );

            $newCredentialUuid = ($this->uuidGenerator)();
            $this->apiKeys->insertCredential($c, $tenant, [
                'uuid' => $newCredentialUuid,
                'lineage_uuid' => $lineageUuid,
                'framework_key_uuid' => $rotated['new_uuid'],
                'generation' => ((int) $credential['generation']) + 1,
                'relationship' => 'current',
            ]);

            $rotatedAt = $this->readDbNow($c)->format('Y-m-d H:i:s');
            $this->apiKeys->advanceLineageCurrentCredential($c, $tenant, $lineageUuid, $newCredentialUuid, $rotatedAt);

            // The audit write is LAST, on purpose (mirrors create()): a
            // forced uuid collision here proves the demote + successor
            // insert + pointer advance + the framework's OWN rotate() writes
            // all roll back together.
            $this->apiKeys->insertEvent($c, $tenant, [
                'uuid' => ($this->uuidGenerator)(),
                'lineage_uuid' => $lineageUuid,
                'seller_uuid' => $sellerUuid,
                'subject_user_uuid' => (string) $lineage['subject_user_uuid'],
                'action' => 'rotated',
                'actor_uuid' => $actor,
                'predecessor_key_uuid' => (string) $credential['framework_key_uuid'],
                'successor_key_uuid' => (string) $rotated['new_uuid'],
                'grace_expires_at' => $graceExpiresAt,
            ]);

            $freshLineage = $this->apiKeys->findLineageByUuid($c, $tenant, $lineageUuid);
            if ($freshLineage === null) {
                throw new \RuntimeException('Rotated seller API key lineage could not be reloaded.');
            }

            return [
                'lineage' => $freshLineage,
                // The raw secret -- returned exactly once, never persisted by Commerce.
                'plain_key' => (string) $rotated['new_plain'],
            ];
        });
    }

    /**
     * Whole-lineage revocation (design spec §2.9): claims the seller
     * revision, re-derives live manager authority, claims the LINEAGE
     * revision (refusing an unknown/cross-tenant/cross-seller lineage with
     * 404), then -- unless the lineage was ALREADY revoked, in which case
     * this is a stable, audit-free no-op -- revokes EVERY recorded
     * credential's framework key, marks every credential row and the
     * lineage itself revoked, and audits. All in one transaction.
     *
     * @return array{lineage: array<string,mixed>}
     */
    public function revoke(
        ApplicationContext $c,
        string $tenant,
        string $sellerUuid,
        string $lineageUuid,
        string $actor
    ): array {
        $actor = trim($actor);
        if ($actor === '') {
            throw ValidationException::forField('actor', 'actor is required.');
        }

        return db($c)->transaction(function () use ($c, $tenant, $sellerUuid, $lineageUuid, $actor): array {
            $this->requireLiveManagerAuthority($c, $tenant, $sellerUuid, $actor);

            $resolved = $this->resolveLineageForMutation($c, $tenant, $sellerUuid, $lineageUuid);
            if ($resolved['alreadyRevoked']) {
                // Stable no-op (design spec §2.9): re-revoke touches nothing
                // and writes NO second audit event.
                return ['lineage' => $resolved['lineage']];
            }
            $lineage = $resolved['lineage'];

            $revokedAt = $this->readDbNow($c)->format('Y-m-d H:i:s');

            $credentials = $this->apiKeys->findCredentialsForLineage($c, $tenant, $lineageUuid);
            foreach ($credentials as $credential) {
                if ((string) $credential['relationship'] === 'revoked') {
                    continue;
                }

                $frameworkKey = $this->findFrameworkKey($c, (string) $credential['framework_key_uuid']);
                if ($frameworkKey !== null && !$frameworkKey->isRevoked()) {
                    ApiKeyService::revoke($c, $frameworkKey);
                }

                $this->apiKeys->markCredentialRevoked($c, $tenant, (string) $credential['uuid'], $revokedAt);
            }

            $this->apiKeys->markLineageRevoked($c, $tenant, $lineageUuid, $revokedAt);

            $this->apiKeys->insertEvent($c, $tenant, [
                'uuid' => ($this->uuidGenerator)(),
                'lineage_uuid' => $lineageUuid,
                'seller_uuid' => $sellerUuid,
                'subject_user_uuid' => (string) $lineage['subject_user_uuid'],
                'action' => 'revoked',
                'actor_uuid' => $actor,
            ]);

            $freshLineage = $this->apiKeys->findLineageByUuid($c, $tenant, $lineageUuid);
            if ($freshLineage === null) {
                throw new \RuntimeException('Revoked seller API key lineage could not be reloaded.');
            }

            return ['lineage' => $freshLineage];
        });
    }

    /**
     * Stage 1-2 of the design spec §2.8/§2.9 lock order, shared verbatim by
     * `create()` (inlined there), `rotate()`, and `revoke()`: claim the
     * seller revision, require it `active`, then re-read the ACTOR's live
     * membership + role and require it currently holds `apikeys.manage`.
     * Throws {@see NotFoundException} for an unknown seller, else
     * {@see SellerApiKeyException} for every live-authority refusal.
     */
    private function requireLiveManagerAuthority(
        ApplicationContext $c,
        string $tenant,
        string $sellerUuid,
        string $actor
    ): void {
        if (!$this->sellers->claimRevision($c, $tenant, $sellerUuid)) {
            throw new NotFoundException('Resource not found.');
        }

        $seller = $this->sellers->findByUuid($c, $tenant, $sellerUuid);
        if ($seller === null) {
            throw new NotFoundException('Resource not found.');
        }
        if ((string) $seller['status'] !== 'active') {
            throw SellerApiKeyException::sellerInactive((string) $seller['status']);
        }

        $membership = $this->memberships->findBySellerAndUser($c, $tenant, $sellerUuid, $actor);
        if ($membership === null || (string) $membership['status'] !== 'active') {
            throw SellerApiKeyException::membershipInactive();
        }

        $role = (string) $membership['role'];
        if (!$this->roles->allows($role, FixedSellerRoleAuthority::APIKEYS_MANAGE)) {
            throw SellerApiKeyException::capabilityDenied();
        }
    }

    /**
     * Stage 3 of the design spec §2.9 lock order, shared by `rotate()` and
     * `revoke()`: claims the lineage's revision via
     * {@see SellerApiKeyRepository::claimActiveLineageRevision()} -- which
     * only ever claims an `active` lineage -- and NEVER collapses a 0-row
     * result into one outcome:
     *
     * - The claim succeeds (1 row): re-reads the freshly-claimed lineage. A
     *   seller mismatch (cross-seller) throws the SAME non-revealing 404 as
     *   "unknown" -- the claim increment rolls back with the rest of the
     *   transaction, so this probe leaves no trace either way.
     * - The claim affects 0 rows: re-reads (never claims) to classify
     *   WITHOUT touching the row. Not found, or found under a different
     *   seller, is 404. Found, correct seller, but not `active` means
     *   already revoked -- returned as `alreadyRevoked: true` so `rotate()`
     *   and `revoke()` can each apply their OWN distinct outcome (409 vs a
     *   stable no-op) rather than this shared helper picking one for both.
     *
     * @return array{lineage: array<string,mixed>, alreadyRevoked: bool}
     */
    private function resolveLineageForMutation(
        ApplicationContext $c,
        string $tenant,
        string $sellerUuid,
        string $lineageUuid
    ): array {
        $claimed = $this->apiKeys->claimActiveLineageRevision($c, $tenant, $lineageUuid);
        if ($claimed) {
            $lineage = $this->apiKeys->findLineageByUuid($c, $tenant, $lineageUuid);
            if ($lineage === null) {
                throw new \RuntimeException('Claimed seller API key lineage could not be reloaded.');
            }
            if ((string) $lineage['seller_uuid'] !== $sellerUuid) {
                throw new NotFoundException('Resource not found.');
            }

            return ['lineage' => $lineage, 'alreadyRevoked' => false];
        }

        $existing = $this->apiKeys->findLineageByUuid($c, $tenant, $lineageUuid);
        if ($existing === null || (string) $existing['seller_uuid'] !== $sellerUuid) {
            throw new NotFoundException('Resource not found.');
        }

        return ['lineage' => $existing, 'alreadyRevoked' => true];
    }

    /**
     * Design spec §2.8: an optional `expires_at` must be a PARSEABLE,
     * EXPLICITLY UTC timestamp, STRICTLY AFTER database-now. Compared
     * against DATABASE time -- via {@see UtcNowSql::expression()}, the SAME
     * driver-pinned-UTC primitive every other expiry/claim comparison in
     * this codebase uses (design spec rationale: PHP-process wall clock and
     * the DB server's clock can drift; the DB is the single source of
     * truth for "now"). "Explicitly UTC" means the parsed offset must be
     * exactly zero: a bare timestamp (no offset -- interpreted as UTC by
     * construction below), a trailing `Z`, or an explicit `+00:00` are all
     * accepted; any OTHER explicit offset (e.g. `+02:00`) is rejected even
     * though it is technically unambiguous, because a seller key's expiry
     * must never depend on an implicit timezone conversion.
     */
    private function validateExpiry(ApplicationContext $c, ?string $expiresAt): ?string
    {
        if ($expiresAt === null || trim($expiresAt) === '') {
            return null;
        }

        try {
            $parsed = new \DateTimeImmutable(trim($expiresAt), new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw ValidationException::forField('expires_at', 'expires_at must be a parseable UTC timestamp.');
        }

        if ($parsed->getOffset() !== 0) {
            throw ValidationException::forField('expires_at', 'expires_at must be expressed in UTC.');
        }

        $dbNow = $this->readDbNow($c);

        if ($parsed->getTimestamp() <= $dbNow->getTimestamp()) {
            throw ValidationException::forField(
                'expires_at',
                'expires_at must be strictly after the current time.'
            );
        }

        return $parsed->format('Y-m-d H:i:s');
    }

    /**
     * The SAME driver-pinned-UTC "database is the single source of truth
     * for now" primitive {@see self::validateExpiry()} already used inline
     * -- factored out so {@see self::rotate()}/{@see self::revoke()} can
     * reuse it for `last_rotated_at`/`revoked_at` without duplicating the
     * `UtcNowSql`/`executeRawFirst()` idiom a third time.
     */
    private function readDbNow(ApplicationContext $c): \DateTimeImmutable
    {
        $utcNowExpression = UtcNowSql::expression(db($c)->getDriverName());
        $row = db($c)->query()->executeRawFirst("SELECT {$utcNowExpression} AS now_utc");
        $dbNowRaw = $row !== null ? (string) ($row['now_utc'] ?? '') : '';
        if ($dbNowRaw === '') {
            throw new \RuntimeException('Unable to read database-now for seller API key mutation.');
        }

        return new \DateTimeImmutable($dbNowRaw, new \DateTimeZone('UTC'));
    }

    /**
     * Resolves the framework's OWN {@see ApiKey} model by its exact uuid.
     * `Builder::first()` is typed to return the base `?Model` (see that
     * class's own docblock) regardless of which model class built the
     * query, so this narrows via `instanceof` -- the SAME idiom
     * {@see ApiKeyService::verify()}/{@see ApiKeyService::forUser()} already
     * use for an identical reason -- rather than an unchecked cast.
     */
    private function findFrameworkKey(ApplicationContext $c, string $frameworkKeyUuid): ?ApiKey
    {
        $model = ApiKey::query($c)->where('uuid', '=', $frameworkKeyUuid)->first();

        return $model instanceof ApiKey ? $model : null;
    }
}
