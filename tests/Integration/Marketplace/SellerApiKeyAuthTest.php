<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Extensions\Commerce\Marketplace\SellerApiKeyAuthorizer;
use Glueful\Extensions\Commerce\Marketplace\SellerApiKeyRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceRouterTestCase;
use Glueful\Helpers\Utils;
use Glueful\Http\Response;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;

/**
 * Marketplace MV5c-1 Task 4 (design spec §2.3/§2.4/§2.6/§2.10, SECURITY
 * CORE): {@see \Glueful\Extensions\Commerce\Marketplace\SellerApiKeyAuthorizer}
 * exercised at its real choke point --
 * {@see \Glueful\Extensions\Commerce\Http\Middleware\SellerMemberMiddleware}
 * over REAL routes (mirrors {@see SellerMiddlewareTest}'s
 * {@see CommerceRouterTestCase} convention) -- plus a handful of direct,
 * unit-level calls against the authorizer for assertions the HTTP layer
 * cannot observe (the authenticated `user` attribute is never overwritten;
 * a genuine audit-write failure still denies).
 *
 * Lineage/credential rows are seeded DIRECTLY via {@see SellerApiKeyRepository}
 * (never through `SellerApiKeyService::create()`, which needs the
 * framework's OWN `api_keys` table -- see {@see SellerApiKeyCreateTest}'s
 * docblock) -- this suite only cares about the READ-time authorization
 * contract, not CREATE.
 *
 * The fake `auth` binding below extends {@see CommerceRouterTestCase::bindFakeAuth()}'s
 * `X-Test-User` session convention with a SEPARATE API-key simulation:
 * `X-Test-Api-Key-Uuid` (-> `api_key_uuid`), `X-Test-Api-Key-Subject` (->
 * `user_id` AND the post-auth `user.uuid`, exactly mirroring how the REAL
 * `ApiKeyAuthenticationProvider`/`AuthMiddleware` keep those two attributes
 * consistent for a legitimately authenticated request -- see that
 * provider's own docblock), and `X-Test-Api-Key-Scopes` (-> `api_key_scopes`,
 * comma-separated). A request carrying NEITHER an `X-Test-Api-Key-Uuid` NOR
 * an `X-Test-User` header is a 401, fail-closed exactly like the real
 * middleware for a missing credential.
 */
final class SellerApiKeyAuthTest extends CommerceRouterTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableMarketplace();
        $this->activateWorkspace();
        $this->bindFakeAuthWithApiKeySupport();
    }

    // -----------------------------------------------------------------
    // Happy path: a valid, correctly bound key reaches its seller route.
    // -----------------------------------------------------------------

    public function testValidSellerKeyReachesItsSellerRouteHandlerRuns(): void
    {
        $seller = $this->seedSeller('key-happy-path', 'ownerKeyHap1');
        $this->seedProduct($seller['uuid'], 'key-happy-path-p');
        $this->seedKeyBinding(
            $seller['uuid'],
            'ownerKeyHap1',
            ['commerce.seller.catalog.read'],
            'fwKeyHappy01'
        );

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->apiKeyRequest(
            'ownerKeyHap1',
            'fwKeyHappy01',
            ['commerce.seller.catalog.read'],
            'GET',
            "/commerce/seller/{$seller['uuid']}/products"
        ));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame('key-happy-path-p', $body['data'][0]['slug'] ?? null);
    }

    // -----------------------------------------------------------------
    // One-seller (design spec §2.6): seller-A's key can never reach seller B.
    // -----------------------------------------------------------------

    public function testSellerAKeyOnSellerBRouteIsRefusedSellerMismatch(): void
    {
        $sellerA = $this->seedSeller('one-seller-a', 'ownerOneSlrA');
        $sellerB = $this->seedSeller('one-seller-b', 'ownerOneSlrB');
        $this->seedKeyBinding($sellerA['uuid'], 'ownerOneSlrA', ['commerce.seller.catalog.read'], 'fwKeyOneSlr1');

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->apiKeyRequest(
            'ownerOneSlrA',
            'fwKeyOneSlr1',
            ['commerce.seller.catalog.read'],
            'GET',
            "/commerce/seller/{$sellerB['uuid']}/products"
        ));

        self::assertSame(404, $response->getStatusCode());
        $this->assertExactlyOneDenialEvent($sellerA['uuid'], 'seller_mismatch');
    }

    // -----------------------------------------------------------------
    // Principal integrity (design spec §2.3 step 2): never overwritten,
    // always validated.
    // -----------------------------------------------------------------

    public function testPrincipalMismatchIsRefusedAndAudited(): void
    {
        $seller = $this->seedSeller('principal-mismatch', 'ownerPrinMis');
        $this->seedKeyBinding($seller['uuid'], 'ownerPrinMis', ['commerce.seller.catalog.read'], 'fwKeyPrinMi1');

        $router = $this->freshRouter();
        // The framework authenticated a DIFFERENT principal than the one
        // this key's lineage is bound to (e.g. a forged/reused token) --
        // the binding subject is 'ownerPrinMis', the request claims
        // 'attackerUser1'.
        $response = $this->dispatch($router, $this->apiKeyRequest(
            'attackerUser1',
            'fwKeyPrinMi1',
            ['commerce.seller.catalog.read'],
            'GET',
            "/commerce/seller/{$seller['uuid']}/products"
        ));

        self::assertSame(404, $response->getStatusCode());
        $this->assertExactlyOneDenialEvent($seller['uuid'], 'principal_mismatch');
    }

    public function testPrincipalMismatchDoesNotOverwriteTheAuthenticatedUserAttribute(): void
    {
        $seller = $this->seedSeller('principal-no-overwrite', 'ownerPrinNo1');
        $lineageUuid = $this->seedKeyBinding(
            $seller['uuid'],
            'ownerPrinNo1',
            ['commerce.seller.catalog.read'],
            'fwKeyPrinNo1'
        );

        $request = Request::create("/commerce/seller/{$seller['uuid']}/products", 'GET');
        $request->attributes->set('auth_method', 'api_key');
        $request->attributes->set('user_id', 'attackerUser2');
        $request->attributes->set('api_key_scopes', ['commerce.seller.catalog.read']);
        $request->attributes->set('api_key_uuid', 'fwKeyPrinNo1');
        // The ALREADY authenticated principal -- what AuthMiddleware set
        // before this authorizer ever ran.
        $request->attributes->set('user', ['uuid' => 'attackerUser2']);

        $authorizer = new SellerApiKeyAuthorizer(new SellerApiKeyRepository());
        $result = $authorizer->authorize($this->context, $request, $this->tenant, $seller['uuid'], 'commerce.seller.catalog.read');

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(
            ['uuid' => 'attackerUser2'],
            $request->attributes->get('user'),
            'the authorizer must VALIDATE the principal, never REPLACE the request user attribute'
        );
        self::assertNotSame('ownerPrinNo1', $request->attributes->get('user')['uuid']);
        $this->assertExactlyOneDenialEvent($seller['uuid'], 'principal_mismatch');
        self::assertNotSame('', $lineageUuid);
    }

    // -----------------------------------------------------------------
    // Exact scope match (design spec §2.3 step 3): the framework's OWN
    // scope copy must equal the binding's declared scopes exactly.
    // -----------------------------------------------------------------

    public function testFrameworkScopeCopyDriftingFromDeclaredScopesIsRefused(): void
    {
        $seller = $this->seedSeller('scope-drift', 'ownerScpDrft');
        $this->seedKeyBinding($seller['uuid'], 'ownerScpDrft', ['commerce.seller.orders.read'], 'fwKeyScpDrf1');

        $router = $this->freshRouter();
        // The framework's authenticated scope copy carries an EXTRA scope
        // the binding never declared -- e.g. the framework-side key row was
        // independently rotated/edited out of band.
        $response = $this->dispatch($router, $this->apiKeyRequest(
            'ownerScpDrft',
            'fwKeyScpDrf1',
            ['commerce.seller.orders.read', 'commerce.seller.orders.fulfill'],
            'GET',
            "/commerce/seller/{$seller['uuid']}/orders"
        ));

        self::assertSame(404, $response->getStatusCode());
        $this->assertExactlyOneDenialEvent($seller['uuid'], 'scope_drift');
    }

    // -----------------------------------------------------------------
    // Effective-scope gate (design spec §2.4/§2.5): a capability the key
    // never declared is a 403, never a 404.
    // -----------------------------------------------------------------

    public function testKeyDeclaringOrdersReadOnlyCallingAnOrdersFulfillRouteIsScopeMissing(): void
    {
        $seller = $this->seedSeller('scope-missing', 'ownerScpMiss');
        $this->seedKeyBinding($seller['uuid'], 'ownerScpMiss', ['commerce.seller.orders.read'], 'fwKeyScpMi1');

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->apiKeyRequest(
            'ownerScpMiss',
            'fwKeyScpMi1',
            ['commerce.seller.orders.read'],
            'POST',
            "/commerce/seller/{$seller['uuid']}/orders/irrelevantOrd1/fulfill",
            ['carrier' => 'UPS', 'tracking_number' => '1Z1', 'tracking_url' => null]
        ));

        self::assertSame(403, $response->getStatusCode());
        $this->assertExactlyOneDenialEvent($seller['uuid'], 'scope_missing');
    }

    // -----------------------------------------------------------------
    // Effective access = key ∩ LIVE role ∩ grantable (design spec §2.4):
    // a role reduced below a declared scope denies on the NEXT request --
    // membership/role are re-read LIVE by SellerMemberMiddleware, the key
    // itself carries no cached authority.
    // -----------------------------------------------------------------

    public function testRoleReducedBelowADeclaredScopeDeniesOnTheNextRequest(): void
    {
        $seller = $this->seedSeller('role-reduction', 'ownerRoleRed');
        $this->seedMembership($seller['uuid'], 'staffRoleRd1', 'seller_staff');
        $variant = $this->seedProduct($seller['uuid'], 'role-reduction-p')['variants'][0]['uuid'];
        $this->seedKeyBinding(
            $seller['uuid'],
            'staffRoleRd1',
            ['commerce.seller.inventory.write'],
            'fwKeyRoleRd1'
        );

        $router = $this->freshRouter();
        $firstRequest = fn () => $this->apiKeyRequest(
            'staffRoleRd1',
            'fwKeyRoleRd1',
            ['commerce.seller.inventory.write'],
            'POST',
            "/commerce/seller/{$seller['uuid']}/variants/{$variant}/stock/adjust",
            ['delta' => 1, 'reason' => 'role reduction test']
        );

        // seller_staff holds inventory.write -- the request reaches the handler.
        $before = $this->dispatch($router, $firstRequest());
        self::assertSame(200, $before->getStatusCode(), 'seller_staff holds inventory.write');

        // The membership's role is demoted to seller_analyst, which does
        // NOT hold inventory.write (FixedSellerRoleAuthority's matrix) --
        // the key's OWN declared scope is untouched, only the LIVE role
        // shrank.
        $this->connection->table('commerce_seller_memberships')
            ->where('seller_uuid', '=', $seller['uuid'])
            ->where('user_uuid', '=', 'staffRoleRd1')
            ->update(['role' => 'seller_analyst']);

        $after = $this->dispatch($router, $firstRequest());
        self::assertSame(403, $after->getStatusCode(), 'seller_analyst no longer holds inventory.write');
        $this->assertExactlyOneDenialEvent($seller['uuid'], 'capability_denied');
    }

    // -----------------------------------------------------------------
    // Unbound framework keys (design spec §2.3 step 1): a non-Commerce
    // framework key is denied even when its principal IS an active seller
    // member -- and writes NOTHING to the audit table (no lineage to key on).
    // -----------------------------------------------------------------

    public function testNonCommerceFrameworkKeyOwnedByAnActiveSellerMemberIsStillDenied(): void
    {
        $seller = $this->seedSeller('no-binding', 'ownerNoBind1');
        // 'ownerNoBind1' is a perfectly legitimate, ACTIVE seller_owner --
        // but 'someRandomFrameworkKey1' has no Commerce credential row at all.

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->apiKeyRequest(
            'ownerNoBind1',
            'someRandomFwKey1',
            ['commerce.seller.catalog.read'],
            'GET',
            "/commerce/seller/{$seller['uuid']}/products"
        ));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(
            0,
            $this->connection->table('commerce_seller_api_key_events')->count(),
            'a request with no Commerce binding must write ZERO audit rows -- there is no lineage to key one on'
        );
    }

    // -----------------------------------------------------------------
    // Credential resolution edge cases (design spec §2.3 step 1): revoked/
    // grace-expired/inactive-lineage all collapse into "no binding" too --
    // NOT individually distinguishable, NOT audited (no closed reason code
    // exists for any of them).
    // -----------------------------------------------------------------

    public function testRevokedCredentialIsDeniedAsNoBindingAndNotAudited(): void
    {
        $seller = $this->seedSeller('revoked-credential', 'ownerRevCred');
        $this->seedKeyBinding(
            $seller['uuid'],
            'ownerRevCred',
            ['commerce.seller.catalog.read'],
            'fwKeyRevCrd1',
            relationship: 'revoked'
        );

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->apiKeyRequest(
            'ownerRevCred',
            'fwKeyRevCrd1',
            ['commerce.seller.catalog.read'],
            'GET',
            "/commerce/seller/{$seller['uuid']}/products"
        ));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, $this->connection->table('commerce_seller_api_key_events')->count());
    }

    public function testPredecessorCredentialStillWithinGraceWindowSucceeds(): void
    {
        $seller = $this->seedSeller('grace-window-ok', 'ownerGraceOk');
        $this->seedProduct($seller['uuid'], 'grace-window-ok-p');
        $this->seedKeyBinding(
            $seller['uuid'],
            'ownerGraceOk',
            ['commerce.seller.catalog.read'],
            'fwKeyGraceOk1',
            relationship: 'predecessor',
            graceExpiresAt: gmdate('Y-m-d H:i:s', time() + 3600)
        );

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->apiKeyRequest(
            'ownerGraceOk',
            'fwKeyGraceOk1',
            ['commerce.seller.catalog.read'],
            'GET',
            "/commerce/seller/{$seller['uuid']}/products"
        ));

        self::assertSame(200, $response->getStatusCode(), 'a predecessor still inside its grace window must still work');
    }

    public function testPredecessorCredentialPastGraceWindowIsDeniedAsNoBinding(): void
    {
        $seller = $this->seedSeller('grace-window-expired', 'ownerGraceEx');
        $this->seedKeyBinding(
            $seller['uuid'],
            'ownerGraceEx',
            ['commerce.seller.catalog.read'],
            'fwKeyGraceEx1',
            relationship: 'predecessor',
            graceExpiresAt: gmdate('Y-m-d H:i:s', time() - 3600)
        );

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->apiKeyRequest(
            'ownerGraceEx',
            'fwKeyGraceEx1',
            ['commerce.seller.catalog.read'],
            'GET',
            "/commerce/seller/{$seller['uuid']}/products"
        ));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, $this->connection->table('commerce_seller_api_key_events')->count());
    }

    // -----------------------------------------------------------------
    // membership_inactive / seller_inactive / capability_denied: all three
    // flow through the SAME single context-audit path
    // ({@see SellerMemberMiddleware}'s own existing checks calling
    // {@see SellerApiKeyAuthorizer::recordDenied()} -- never a second,
    // duplicate audit inside the authorizer itself for these three reasons).
    // capability_denied is already proven above
    // (testRoleReducedBelowADeclaredScopeDeniesOnTheNextRequest); the other
    // two are proven here.
    // -----------------------------------------------------------------

    public function testMembershipRevokedAfterKeyIssuanceDeniesViaTheSingleContextAuditPath(): void
    {
        $seller = $this->seedSeller('membership-revoked', 'ownerMbrRevk');
        $this->seedKeyBinding($seller['uuid'], 'ownerMbrRevk', ['commerce.seller.catalog.read'], 'fwKeyMbrRv1');

        $this->connection->table('commerce_seller_memberships')
            ->where('seller_uuid', '=', $seller['uuid'])
            ->where('user_uuid', '=', 'ownerMbrRevk')
            ->update(['status' => 'revoked']);

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->apiKeyRequest(
            'ownerMbrRevk',
            'fwKeyMbrRv1',
            ['commerce.seller.catalog.read'],
            'GET',
            "/commerce/seller/{$seller['uuid']}/products"
        ));

        self::assertSame(404, $response->getStatusCode());
        $this->assertExactlyOneDenialEvent($seller['uuid'], 'membership_inactive');
    }

    public function testSuspendedSellerDeniesViaTheSingleContextAuditPath(): void
    {
        $seller = $this->seedSeller('seller-suspended-key', 'ownerSlrSusp');
        $this->seedKeyBinding($seller['uuid'], 'ownerSlrSusp', ['commerce.seller.catalog.read'], 'fwKeySlrSu1');
        $this->sellerService()->suspend($this->context, $this->tenant, $seller['uuid'], 'Under review.', 'operator01');

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->apiKeyRequest(
            'ownerSlrSusp',
            'fwKeySlrSu1',
            ['commerce.seller.catalog.read'],
            'GET',
            "/commerce/seller/{$seller['uuid']}/products"
        ));

        self::assertSame(409, $response->getStatusCode());
        $this->assertExactlyOneDenialEvent($seller['uuid'], 'seller_inactive');
    }

    // -----------------------------------------------------------------
    // Bounded audit dedupe (design spec §2.10): AT MOST one row per
    // (tenant, lineage, reason, UTC-minute) -- a repeat denial is an
    // idempotent no-op, never a second row.
    // -----------------------------------------------------------------

    public function testRepeatedIdenticalDenialsInTheSameMinuteWriteExactlyOneRow(): void
    {
        $sellerA = $this->seedSeller('dedupe-a', 'ownerDedupeA');
        $sellerB = $this->seedSeller('dedupe-b', 'ownerDedupeB');
        $this->seedKeyBinding($sellerA['uuid'], 'ownerDedupeA', ['commerce.seller.catalog.read'], 'fwKeyDedupe1');

        $router = $this->freshRouter();
        $request = fn () => $this->apiKeyRequest(
            'ownerDedupeA',
            'fwKeyDedupe1',
            ['commerce.seller.catalog.read'],
            'GET',
            "/commerce/seller/{$sellerB['uuid']}/products"
        );

        $first = $this->dispatch($router, $request());
        $second = $this->dispatch($router, $request());
        $third = $this->dispatch($router, $request());

        self::assertSame(404, $first->getStatusCode());
        self::assertSame(404, $second->getStatusCode());
        self::assertSame(404, $third->getStatusCode());
        $this->assertExactlyOneDenialEvent($sellerA['uuid'], 'seller_mismatch');
    }

    // -----------------------------------------------------------------
    // Fail-closed even when the audit write itself fails (design spec
    // §2.10): a genuine persistence failure NEVER opens access.
    // -----------------------------------------------------------------

    public function testAuditWriteFailureStillDeniesAccess(): void
    {
        $seller = $this->seedSeller('audit-write-fails', 'ownerAudFail');
        $this->seedKeyBinding($seller['uuid'], 'ownerAudFail', ['commerce.seller.catalog.read'], 'fwKeyAudFl1');
        $otherSeller = $this->seedSeller('audit-write-fails-other', 'ownerAudFlO1');

        // Pre-seed a row that collides on the events table's
        // (tenant_uuid, uuid) unique constraint under the EXACT uuid the
        // forced generator below will hand to the denial-audit insert, but
        // under a DIFFERENT lineage/reason/bucket -- so the repository's
        // own re-read (matching the ACTUAL attempted tuple) finds nothing,
        // and correctly treats this as a genuine write failure rather than
        // a confirmed dedupe collision.
        $this->connection->table('commerce_seller_api_key_events')->insert([
            'uuid' => 'collideAudit1',
            'tenant_uuid' => $this->tenant,
            'lineage_uuid' => 'someOtherLin',
            'seller_uuid' => $otherSeller['uuid'],
            'subject_user_uuid' => 'someoneElse1',
            'action' => 'created',
        ]);

        $request = Request::create("/commerce/seller/{$otherSeller['uuid']}/products", 'GET');
        $request->attributes->set('auth_method', 'api_key');
        $request->attributes->set('user_id', 'ownerAudFail');
        $request->attributes->set('api_key_scopes', ['commerce.seller.catalog.read']);
        $request->attributes->set('api_key_uuid', 'fwKeyAudFl1');
        $request->attributes->set('user', ['uuid' => 'ownerAudFail']);

        $authorizer = new SellerApiKeyAuthorizer(new SellerApiKeyRepository(), static fn (): string => 'collideAudit1');
        // Different seller than the binding's own -> seller_mismatch,
        // which the authorizer itself must audit (and whose write we've
        // forced to fail above).
        $result = $authorizer->authorize(
            $this->context,
            $request,
            $this->tenant,
            $otherSeller['uuid'],
            'commerce.seller.catalog.read'
        );

        self::assertInstanceOf(
            Response::class,
            $result,
            'access must STILL be denied even though the audit write itself failed'
        );
        self::assertSame(
            1,
            $this->connection->table('commerce_seller_api_key_events')->where('uuid', '=', 'collideAudit1')->count(),
            'no second row must have been forced through despite the failure'
        );
        self::assertSame(
            0,
            $this->connection->table('commerce_seller_api_key_events')
                ->where('reason_code', '=', 'seller_mismatch')
                ->count(),
            'the failed write must not have landed under a different uuid either'
        );
    }

    // -----------------------------------------------------------------
    // Off-invariance (design spec §6): a session request never touches a
    // key table -- zero queries against commerce_seller_api_key*.
    // -----------------------------------------------------------------

    public function testSessionRequestIssuesZeroKeyTableQueries(): void
    {
        $seller = $this->seedSeller('off-invariance', 'ownerOffInv1');
        $this->seedProduct($seller['uuid'], 'off-invariance-p');

        $router = $this->freshRouter();

        // Warm-up call so any one-time schema probes never leak into the
        // measured count below (mirrors StorefrontCatalogFilterTest's
        // identical warm-up convention).
        $this->dispatch(
            $router,
            $this->requestAsSession('ownerOffInv1', 'GET', "/commerce/seller/{$seller['uuid']}/products")
        );

        $pdo = $this->connection->getPDO();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [SellerApiKeyAuthTestQueryLog::class]);
        SellerApiKeyAuthTestQueryLog::$queries = [];

        $response = $this->dispatch(
            $router,
            $this->requestAsSession('ownerOffInv1', 'GET', "/commerce/seller/{$seller['uuid']}/products")
        );

        self::assertSame(200, $response->getStatusCode());
        $keyTableQueries = array_values(array_filter(
            SellerApiKeyAuthTestQueryLog::$queries,
            static fn (string $sql): bool => str_contains($sql, 'commerce_seller_api_key')
        ));
        self::assertSame(
            [],
            $keyTableQueries,
            'a session request must never issue a single query against a seller-API-key table'
        );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * `X-Test-Api-Key-Uuid` simulates a REQUEST that has already passed the
     * framework's OWN authentication (the SAME request-attribute shape
     * `ApiKeyAuthenticationProvider` sets: `auth_method`, `user_id`,
     * `api_key_scopes`, `api_key_uuid`, plus the post-auth `user` array
     * `AuthMiddleware` sets from the SAME identity) -- this suite tests the
     * COMMERCE authorization layer that runs AFTER that framework step, not
     * the framework's own key verification (that belongs to the framework's
     * own test suite).
     */
    protected function bindFakeAuthWithApiKeySupport(): void
    {
        $this->bind('auth', new class implements RouteMiddleware {
            public function handle(Request $request, callable $next, mixed ...$params): mixed
            {
                $apiKeyUuid = $request->headers->get('X-Test-Api-Key-Uuid');
                if ($apiKeyUuid !== null && $apiKeyUuid !== '') {
                    $subject = (string) $request->headers->get('X-Test-Api-Key-Subject', '');
                    $scopesHeader = (string) $request->headers->get('X-Test-Api-Key-Scopes', '');
                    $scopes = $scopesHeader === '' ? [] : explode(',', $scopesHeader);

                    $request->attributes->set('auth_method', 'api_key');
                    $request->attributes->set('user_id', $subject);
                    $request->attributes->set('api_key_scopes', $scopes);
                    $request->attributes->set('api_key_uuid', $apiKeyUuid);
                    $request->attributes->set('user', ['uuid' => $subject]);

                    return $next($request);
                }

                $userUuid = $request->headers->get('X-Test-User');
                if ($userUuid === null || $userUuid === '') {
                    return Response::unauthorized('Authentication required');
                }

                $request->attributes->set('user', ['uuid' => $userUuid]);

                return $next($request);
            }
        });
    }

    /** @param list<string> $scopes */
    private function apiKeyRequest(
        string $subjectUuid,
        string $frameworkKeyUuid,
        array $scopes,
        string $method,
        string $uri,
        array $body = []
    ): Request {
        $content = $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR);
        $request = Request::create($uri, $method, [], [], [], [], $content);
        if ($content !== null) {
            $request->headers->set('Content-Type', 'application/json');
        }
        $request->headers->set('X-Test-Api-Key-Uuid', $frameworkKeyUuid);
        $request->headers->set('X-Test-Api-Key-Subject', $subjectUuid);
        $request->headers->set('X-Test-Api-Key-Scopes', implode(',', $scopes));

        return $request;
    }

    private function requestAsSession(string $userUuid, string $method, string $uri): Request
    {
        $request = Request::create($uri, $method);
        $request->headers->set('X-Test-User', $userUuid);

        return $request;
    }

    /**
     * Seeds a lineage + its ONE credential row directly via
     * {@see SellerApiKeyRepository} (bypassing `SellerApiKeyService::create()`
     * -- see this file's class docblock). Returns the generated lineage
     * uuid.
     *
     * @param list<string> $declaredScopes
     */
    private function seedKeyBinding(
        string $sellerUuid,
        string $subjectUuid,
        array $declaredScopes,
        string $frameworkKeyUuid,
        string $relationship = 'current',
        ?string $graceExpiresAt = null,
        string $lineageStatus = 'active'
    ): string {
        $repo = new SellerApiKeyRepository();
        $lineageUuid = Utils::generateNanoID();
        $credentialUuid = Utils::generateNanoID();

        $repo->insertLineage($this->context, $this->tenant, [
            'uuid' => $lineageUuid,
            'seller_uuid' => $sellerUuid,
            'subject_user_uuid' => $subjectUuid,
            'declared_scopes' => $declaredScopes,
            'name' => 'Test key',
            'status' => $lineageStatus,
            'current_credential_uuid' => $credentialUuid,
            'expires_at' => null,
            'created_by' => $subjectUuid,
        ]);
        $repo->insertCredential($this->context, $this->tenant, [
            'uuid' => $credentialUuid,
            'lineage_uuid' => $lineageUuid,
            'framework_key_uuid' => $frameworkKeyUuid,
            'generation' => 1,
            'relationship' => $relationship,
            'grace_expires_at' => $graceExpiresAt,
        ]);

        return $lineageUuid;
    }

    private function assertExactlyOneDenialEvent(string $sellerUuid, string $reasonCode): void
    {
        $events = $this->connection->table('commerce_seller_api_key_events')
            ->where('seller_uuid', '=', $sellerUuid)
            ->where('action', '=', 'auth_denied')
            ->where('reason_code', '=', $reasonCode)
            ->get();

        self::assertCount(
            1,
            $events,
            "expected exactly one auth_denied({$reasonCode}) event for seller {$sellerUuid}"
        );
    }
}

/**
 * `PDOStatement` subclass capturing every executed query's SQL text --
 * installed via `PDO::ATTR_STATEMENT_CLASS` for
 * {@see SellerApiKeyAuthTest::testSessionRequestIssuesZeroKeyTableQueries()}
 * only (mirrors {@see \Glueful\Extensions\Commerce\Tests\Support\CountingPdoStatement}'s
 * identical installation convention, but records query TEXT rather than a
 * bare count, so the assertion can filter to key-table statements
 * specifically). Kept local to this file rather than added to
 * `tests/Support/` since nothing else in this suite needs it.
 */
final class SellerApiKeyAuthTestQueryLog extends \PDOStatement
{
    /** @var list<string> */
    public static array $queries = [];

    public function execute(?array $params = null): bool
    {
        self::$queries[] = $this->queryString;

        return parent::execute($params);
    }
}
