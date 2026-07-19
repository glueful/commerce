<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Marketplace\Contracts\SellerRoleAuthority;
use Glueful\Extensions\Commerce\Support\UtcNowSql;
use Glueful\Helpers\Utils;
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

        $utcNowExpression = UtcNowSql::expression(db($c)->getDriverName());
        $row = db($c)->query()->executeRawFirst("SELECT {$utcNowExpression} AS now_utc");
        $dbNowRaw = $row !== null ? (string) ($row['now_utc'] ?? '') : '';
        if ($dbNowRaw === '') {
            throw new \RuntimeException('Unable to read database-now for expiry validation.');
        }
        $dbNow = new \DateTimeImmutable($dbNowRaw, new \DateTimeZone('UTC'));

        if ($parsed->getTimestamp() <= $dbNow->getTimestamp()) {
            throw ValidationException::forField(
                'expires_at',
                'expires_at must be strictly after the current time.'
            );
        }

        return $parsed->format('Y-m-d H:i:s');
    }
}
