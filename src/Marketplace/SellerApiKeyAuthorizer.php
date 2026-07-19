<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Marketplace;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Helpers\Utils;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * The seller-API-key per-request authorizer (design spec §2.3/§2.4/§2.6/
 * §2.10, MV5c-1 Task 4) -- the SECURITY CORE run by
 * {@see \Glueful\Extensions\Commerce\Http\Middleware\SellerMemberMiddleware}
 * AFTER tenant/route-seller extraction but BEFORE its own seller/membership
 * lookup. A stolen or misused key must never reach another seller, escalate
 * scope, impersonate a different user, or carry a drifted framework scope
 * copy into a seller route.
 *
 * **Off-invariance (design spec §6):** {@see self::authorize()} returns
 * `null` -- "not a key request", ZERO key-table queries -- for every request
 * whose `auth_method` request attribute is not exactly `'api_key'`. A
 * session (JWT/etc.) request never touches
 * `commerce_seller_api_key_credentials`/`commerce_seller_api_keys` at all.
 * `null` is reserved EXCLUSIVELY for this off-invariant short-circuit --
 * every other outcome of an API-key request is either a `Response` (a
 * denial, returned directly so the middleware can short-circuit with it
 * unchanged) or a {@see SellerApiKeyAuthorizationContext} (every authorizer
 * check passed).
 *
 * **Per-request checks, in order (design spec §2.3):**
 * 1. Resolve the exact authenticated `api_key_uuid` through
 *    {@see SellerApiKeyRepository::findCredentialByFrameworkKeyUuid()} --
 *    the credential must be `current`, or a `predecessor` still inside its
 *    `grace_expires_at` window, AND its lineage must be `active`. Failing
 *    ANY part of this is treated as "no binding at all" -- the SAME
 *    non-revealing response {@see \Glueful\Extensions\Commerce\Http\Middleware\SellerMemberMiddleware}
 *    already uses for an unknown seller / no membership -- and is NEVER
 *    audited: there is no lineage to key a denial row on, and a random or
 *    non-Commerce framework key must never grow the audit table (design
 *    spec §2.10), even when its `user_id` happens to be an active seller
 *    member on the route seller.
 * 2. **Principal integrity:** the request's raw `user_id` attribute (set
 *    directly by `ApiKeyAuthenticationProvider`, never the `user` array)
 *    must equal the lineage's `subject_user_uuid`. This method never writes
 *    to the request's `user` attribute -- it validates the ALREADY
 *    authenticated principal, it never replaces it.
 * 3. **Exact scope match:** the request's `api_key_scopes` attribute,
 *    canonicalized (trim/dedupe/sort -- the SAME algorithm
 *    {@see SellerApiKeyScopeValidator} uses at CREATE time, intentionally
 *    duplicated rather than shared: create-time validation and read-time
 *    comparison are independent concerns, and this is ~10 lines), must
 *    equal the lineage's canonicalized `declared_scopes` EXACTLY. Any
 *    non-string/blank entry silently drops out of the comparison rather
 *    than throwing -- a malformed attribute must fail CLOSED via mismatch
 *    on this hot, security-critical path, never crash the request.
 * 4. **One-seller (design spec §2.6):** the route `{sellerUuid}` must equal
 *    the lineage's `seller_uuid` -- a key can NEVER reach a seller other
 *    than the one it is bound to, regardless of the acting subject's OTHER
 *    seller memberships.
 * 5. **Effective-scope gate (design spec §2.4):** the required capability
 *    must be in `declared_scopes ∩ SellerApiKeyCapabilityCatalog` -- the
 *    catalog intersection is defense-in-depth (declared scopes are already
 *    validated against the catalog at CREATE time), never trusted alone.
 *    The THIRD leg of the three-way intersection -- the subject's LIVE
 *    seller-role capabilities -- is enforced by
 *    {@see \Glueful\Extensions\Commerce\Http\Middleware\SellerMemberMiddleware}'s
 *    OWN pre-existing role check, which still runs (unconditionally, exactly
 *    as it does for a session request) once THIS method returns a context.
 *
 * Steps 2-4 return the SAME non-revealing `Response::notFound()` shape as
 * step 1 (never distinguishable from "unknown seller" from the outside);
 * step 5 returns `Response::forbidden()` (an authenticated, correctly
 * seller-bound key simply lacking a declared capability is not a secret
 * worth hiding, exactly like the middleware's own live-role 403).
 *
 * **Audit (design spec §2.10):** steps 2-4 call {@see self::recordDenied()}
 * themselves (they already hold the resolved context); the middleware calls
 * the SAME public method for its OWN post-context checks
 * (`membership_inactive`/`seller_inactive`/`capability_denied`) -- one
 * method, two call sites, never a duplicate audit for the same request.
 */
final class SellerApiKeyAuthorizer
{
    /** @var callable(): string */
    private $uuidGenerator;

    /**
     * @param (callable(): string)|null $uuidGenerator Injectable seam for tests forcing a
     *     denial-audit-uuid collision (mirrors {@see SellerApiKeyService}'s identical
     *     convention). Defaults to the house {@see Utils::generateNanoID()} generator.
     */
    public function __construct(
        private SellerApiKeyRepository $apiKeys,
        ?callable $uuidGenerator = null,
    ) {
        $this->uuidGenerator = $uuidGenerator ?? static fn (): string => Utils::generateNanoID();
    }

    public function authorize(
        ApplicationContext $context,
        Request $request,
        string $tenant,
        string $routeSellerUuid,
        string $requiredCapability
    ): SellerApiKeyAuthorizationContext|Response|null {
        if ((string) $request->attributes->get('auth_method', '') !== 'api_key') {
            return null;
        }

        $frameworkKeyUuid = trim((string) $request->attributes->get('api_key_uuid', ''));
        $credential = $frameworkKeyUuid !== ''
            ? $this->apiKeys->findCredentialByFrameworkKeyUuid($context, $tenant, $frameworkKeyUuid)
            : null;

        if ($credential === null || !$this->credentialIsUsable($credential)) {
            return $this->nonRevealingDenial();
        }

        $lineage = $this->apiKeys->findLineageByUuid($context, $tenant, (string) $credential['lineage_uuid']);
        if ($lineage === null || (string) ($lineage['status'] ?? '') !== 'active') {
            return $this->nonRevealingDenial();
        }

        $keyContext = new SellerApiKeyAuthorizationContext(
            tenant: $tenant,
            lineage: $lineage,
            credential: $credential,
            subjectUserUuid: (string) $lineage['subject_user_uuid'],
            sellerUuid: (string) $lineage['seller_uuid'],
        );

        $requestUserId = trim((string) $request->attributes->get('user_id', ''));
        if ($requestUserId === '' || $requestUserId !== $keyContext->subjectUserUuid) {
            $this->recordDenied($context, $keyContext, 'principal_mismatch');
            return $this->nonRevealingDenial();
        }

        $rawRequestScopes = $request->attributes->get('api_key_scopes', []);
        $requestScopes = $this->canonicalize(is_array($rawRequestScopes) ? $rawRequestScopes : []);
        $declaredScopesRaw = $lineage['declared_scopes'] ?? [];
        $declaredScopes = $this->canonicalize(is_array($declaredScopesRaw) ? $declaredScopesRaw : []);
        if ($requestScopes !== $declaredScopes) {
            $this->recordDenied($context, $keyContext, 'scope_drift');
            return $this->nonRevealingDenial();
        }

        if ($routeSellerUuid !== $keyContext->sellerUuid) {
            $this->recordDenied($context, $keyContext, 'seller_mismatch');
            return $this->nonRevealingDenial();
        }

        $effectiveScopes = array_intersect($declaredScopes, SellerApiKeyCapabilityCatalog::all());
        if (!in_array($requiredCapability, $effectiveScopes, true)) {
            $this->recordDenied($context, $keyContext, 'scope_missing');
            return Response::forbidden('Insufficient API key scope for this action.');
        }

        return $keyContext;
    }

    /**
     * The single denial-audit entry point (design spec §2.10): called by
     * THIS class internally for `principal_mismatch`/`scope_drift`/
     * `scope_missing`/`seller_mismatch`, and by
     * {@see \Glueful\Extensions\Commerce\Http\Middleware\SellerMemberMiddleware}
     * for `membership_inactive`/`seller_inactive`/`capability_denied` --
     * NEVER both for the same request. `$reason` is a closed vocabulary
     * value; the UTC-minute dedupe bucket is computed HERE (PHP wall clock,
     * not a DB round-trip -- unlike the CREATE-time expiry comparison, this
     * runs on every denied API-key request, a hot path where an extra query
     * per attempt is unjustified for a minute-granularity dedupe key).
     *
     * Delegates the actual write + duplicate-collision handling to
     * {@see SellerApiKeyRepository::recordAuthDenied()} (the house
     * "repository owns the DB-idiom/PDOException handling" convention --
     * see {@see \Glueful\Extensions\Commerce\Marketplace\LedgerRepository},
     * {@see \Glueful\Extensions\Commerce\Marketplace\ReserveRepository}).
     * That method NEVER throws -- an audit-write failure must never
     * propagate and risk being mishandled into an accidental allow; the
     * caller has already decided to deny access before calling this method
     * regardless of whether the write succeeds.
     */
    public function recordDenied(
        ApplicationContext $context,
        SellerApiKeyAuthorizationContext $keyContext,
        string $reason
    ): void {
        $this->apiKeys->recordAuthDenied($context, $keyContext->tenant, [
            'uuid' => ($this->uuidGenerator)(),
            'lineage_uuid' => $keyContext->lineageUuid(),
            'seller_uuid' => $keyContext->sellerUuid,
            'subject_user_uuid' => $keyContext->subjectUserUuid,
            'reason_code' => $reason,
            'bucket_start' => gmdate('Y-m-d H:i:00'),
        ]);
    }

    /**
     * "Usable" (design spec §2.3 step 1): `current`, or a `predecessor`
     * still strictly inside its recorded `grace_expires_at` -- ANY other
     * relationship (`revoked`, or a `predecessor` past grace / with no
     * grace recorded) is NOT usable. Compared against PHP's own UTC wall
     * clock, not DB-now: mirrors {@see self::recordDenied()}'s identical
     * "no extra DB round-trip on this hot path" reasoning -- a grace window
     * is measured in hours, so ordinary clock drift is immaterial here,
     * unlike a strict CREATE-time expiry boundary.
     *
     * @param array<string,mixed> $credential
     */
    private function credentialIsUsable(array $credential): bool
    {
        $relationship = (string) ($credential['relationship'] ?? '');
        if ($relationship === 'current') {
            return true;
        }
        if ($relationship === 'predecessor') {
            $graceExpiresAt = $credential['grace_expires_at'] ?? null;
            return is_string($graceExpiresAt)
                && $graceExpiresAt !== ''
                && $graceExpiresAt > gmdate('Y-m-d H:i:s');
        }

        return false;
    }

    private function nonRevealingDenial(): Response
    {
        return Response::notFound('Resource not found.');
    }

    /**
     * Canonicalization mirrors {@see SellerApiKeyScopeValidator}'s private
     * `canonicalize()` EXACTLY (trim, drop blanks, dedupe, `SORT_STRING`) --
     * deliberately duplicated rather than shared (see this class's own
     * docblock). Unlike the validator, a non-string entry is silently
     * DROPPED rather than thrown: this runs against a framework-controlled
     * request attribute on a security-critical per-request path, where
     * degrading to a scope MISMATCH (denial) is the correct fail-closed
     * response to malformed input, never an uncaught exception.
     *
     * @param list<mixed> $scopes
     * @return list<string>
     */
    private function canonicalize(array $scopes): array
    {
        $unique = [];
        foreach ($scopes as $scope) {
            if (!is_string($scope)) {
                continue;
            }
            $trimmed = trim($scope);
            if ($trimmed !== '') {
                $unique[$trimmed] = true;
            }
        }

        $canonical = array_keys($unique);
        sort($canonical, SORT_STRING);

        return $canonical;
    }
}
