<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Extensions\Commerce\Tests\Support\CommerceRouterTestCase;
use Glueful\Http\Response;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;

/**
 * Marketplace MV5c-1 Task 6 (design spec §2.8/§5): the seller self-service
 * API-key MANAGEMENT HTTP surface (create/list/rotate/revoke) -- over REAL
 * routes, exactly like {@see SellerFinancialSurfaceTest}/{@see SellerApiKeyAuthTest}
 * do for their own surfaces.
 *
 * **Route order proof (design spec §2.8: `auth -> tenant (when enabled) ->
 * interactive_session -> commerce_seller:...apikeys.manage`):** the fake
 * `auth` binding below never seeds a `commerce_seller_api_key_credentials`
 * row for the API-key-simulated requests -- meaning if `commerce_seller`
 * (specifically {@see \Glueful\Extensions\Commerce\Marketplace\SellerApiKeyAuthorizer})
 * ran BEFORE `interactive_session`, an unbound API-key request would surface
 * as a NON-REVEALING `404` (its "no Commerce binding" outcome), never a
 * `403`. Every API-key-request assertion in this file expects `403`
 * specifically -- which is only reachable if
 * {@see \Glueful\Extensions\Commerce\Http\Middleware\InteractiveSessionMiddleware}
 * runs FIRST and short-circuits before `commerce_seller` (and therefore the
 * key authorizer/lineage lookup) ever executes.
 *
 * Creates the FRAMEWORK's own `api_keys` table inline (in {@see self::setUp()}),
 * mirroring {@see SellerApiKeyCreateTest}/{@see SellerApiKeyRotationTest}'s
 * identical convention -- the real {@see \Glueful\Extensions\Commerce\Marketplace\SellerApiKeyService}
 * wired into this surface calls the framework's OWN
 * `Glueful\Auth\ApiKey\ApiKeyService`, which persists/mutates `ApiKey` ORM
 * rows on that table.
 */
final class SellerApiKeySurfaceTest extends CommerceRouterTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableMarketplace();
        $this->activateWorkspace();
        $this->bindFakeAuthWithProviderAndApiKeySupport();

        $schema = $this->connection->getSchemaBuilder();
        if (!$schema->hasTable('api_keys')) {
            $schema->createTable('api_keys', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('uuid', 12);
                $table->string('user_uuid', 12);
                $table->string('name', 255);
                $table->string('key_prefix', 24);
                $table->string('key_hash', 64);
                $table->text('scopes')->nullable();
                $table->text('allowed_ips')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->bigInteger('rotated_from_id')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');

                $table->unique('uuid');
                $table->unique('key_hash');
                $table->index('user_uuid');
                $table->index('key_prefix');
            });
        }
    }

    // -----------------------------------------------------------------
    // Happy path: a JWT interactive session with apikeys.manage can
    // create / list / rotate / revoke. Secret returned exactly once on
    // create + rotate, NEVER on list.
    // -----------------------------------------------------------------

    public function testJwtInteractiveSessionWithApikeysManageCanCreateListRotateAndRevoke(): void
    {
        $seller = $this->seedSeller('apikey-lifecycle', 'ownerApiKeyL1');

        $router = $this->freshRouter();

        $createResponse = $this->dispatch($router, $this->jwtRequest(
            'ownerApiKeyL1',
            'POST',
            "/commerce/seller/{$seller['uuid']}/api-keys",
            ['name' => 'CI integration key', 'declared_scopes' => ['commerce.seller.orders.read']]
        ));

        self::assertSame(201, $createResponse->getStatusCode());
        $created = $this->json($createResponse)['data'];
        self::assertArrayHasKey('secret', $created);
        self::assertIsString($created['secret']);
        self::assertNotSame('', $created['secret']);
        self::assertSame('CI integration key', $created['name']);
        self::assertSame(['commerce.seller.orders.read'], $created['declared_scopes']);
        self::assertSame('active', $created['status']);
        $lineageUuid = $created['uuid'];

        // LIST -- never leaks a secret.
        $listResponse = $this->dispatch($router, $this->jwtRequest(
            'ownerApiKeyL1',
            'GET',
            "/commerce/seller/{$seller['uuid']}/api-keys"
        ));
        self::assertSame(200, $listResponse->getStatusCode());
        $items = $this->json($listResponse)['data'];
        self::assertCount(1, $items);
        self::assertSame($lineageUuid, $items[0]['uuid']);
        self::assertArrayNotHasKey('secret', $items[0]);

        // ROTATE -- a NEW secret, returned exactly once.
        $rotateResponse = $this->dispatch($router, $this->jwtRequest(
            'ownerApiKeyL1',
            'POST',
            "/commerce/seller/{$seller['uuid']}/api-keys/{$lineageUuid}/rotate"
        ));
        self::assertSame(200, $rotateResponse->getStatusCode());
        $rotated = $this->json($rotateResponse)['data'];
        self::assertArrayHasKey('secret', $rotated);
        self::assertNotSame('', $rotated['secret']);
        self::assertNotSame($created['secret'], $rotated['secret'], 'rotation must mint a NEW secret');
        self::assertNotNull($rotated['last_rotated_at']);

        // LIST again -- still no secret leak, and reflects the rotation.
        $listAfterRotate = $this->dispatch($router, $this->jwtRequest(
            'ownerApiKeyL1',
            'GET',
            "/commerce/seller/{$seller['uuid']}/api-keys"
        ));
        $itemsAfterRotate = $this->json($listAfterRotate)['data'];
        self::assertCount(1, $itemsAfterRotate);
        self::assertArrayNotHasKey('secret', $itemsAfterRotate[0]);
        self::assertNotNull($itemsAfterRotate[0]['last_rotated_at']);

        // REVOKE -- whole lineage.
        $revokeResponse = $this->dispatch($router, $this->jwtRequest(
            'ownerApiKeyL1',
            'POST',
            "/commerce/seller/{$seller['uuid']}/api-keys/{$lineageUuid}/revoke"
        ));
        self::assertSame(200, $revokeResponse->getStatusCode());
        self::assertSame('revoked', $this->json($revokeResponse)['data']['status']);

        // Rotate-on-revoked -> 409 (the framework's own ConflictException, auto-mapped).
        $rotateAfterRevoke = $this->dispatch($router, $this->jwtRequest(
            'ownerApiKeyL1',
            'POST',
            "/commerce/seller/{$seller['uuid']}/api-keys/{$lineageUuid}/rotate"
        ));
        self::assertSame(409, $rotateAfterRevoke->getStatusCode());
    }

    public function testRotateOrRevokeOnAnUnknownLineageIs404(): void
    {
        $seller = $this->seedSeller('apikey-unknown-lineage', 'ownerApiKeyU1');

        $router = $this->freshRouter();

        $rotate = $this->dispatch($router, $this->jwtRequest(
            'ownerApiKeyU1',
            'POST',
            "/commerce/seller/{$seller['uuid']}/api-keys/doesNotExist01/rotate"
        ));
        self::assertSame(404, $rotate->getStatusCode());

        $revoke = $this->dispatch($router, $this->jwtRequest(
            'ownerApiKeyU1',
            'POST',
            "/commerce/seller/{$seller['uuid']}/api-keys/doesNotExist01/revoke"
        ));
        self::assertSame(404, $revoke->getStatusCode());
    }

    // -----------------------------------------------------------------
    // A key can NEVER manage keys: an API-key request is 403 on EVERY
    // management route -- even one whose scope somehow includes
    // apikeys.manage (the catalog never grants it; this gate must refuse
    // regardless of scope). This ALSO proves route order (see class
    // docblock): an unbound key would otherwise 404 through
    // `commerce_seller`, never 403.
    // -----------------------------------------------------------------

    public function testApiKeyRequestIsRefused403OnEveryManagementRouteEvenWithApikeysManageInScope(): void
    {
        $seller = $this->seedSeller('apikey-gate', 'ownerApiKeyG1');

        $router = $this->freshRouter();
        $scopesIncludingManage = ['commerce.seller.apikeys.manage'];

        foreach ($this->managementRoutes($seller['uuid']) as $label => [$method, $uri, $body]) {
            $response = $this->dispatch($router, $this->apiKeyRequest(
                'ownerApiKeyG1',
                'someFrameworkKeyU1',
                $scopesIncludingManage,
                $method,
                $uri,
                $body
            ));
            self::assertSame(403, $response->getStatusCode(), "{$label}: an API-key request must be refused");
        }
    }

    // -----------------------------------------------------------------
    // A non-JWT provider (e.g. a hypothetical future SAML/LDAP bearer) is
    // 403 on every management route too -- "not an API key" alone is an
    // insufficient predicate (design spec §2.8).
    // -----------------------------------------------------------------

    public function testNonJwtProviderIsRefused403OnEveryManagementRoute(): void
    {
        $seller = $this->seedSeller('apikey-nonjwt', 'ownerApiKeyN1');

        $router = $this->freshRouter();

        foreach ($this->managementRoutes($seller['uuid']) as $label => [$method, $uri, $body]) {
            $response = $this->dispatch(
                $router,
                $this->sessionRequest('ownerApiKeyN1', 'saml', $method, $uri, $body)
            );
            self::assertSame(403, $response->getStatusCode(), "{$label}: a non-JWT provider must be refused");
        }
    }

    // -----------------------------------------------------------------
    // apikeys.manage absent (e.g. a staff/analyst role) -> 403, from
    // commerce_seller's own capability check -- reached only AFTER
    // interactive_session already passed for this JWT session.
    // -----------------------------------------------------------------

    public function testJwtSessionWithoutApikeysManageCapabilityIsRefused(): void
    {
        $seller = $this->seedSeller('apikey-nocap', 'ownerApiKeyC1');
        $this->seedMembership($seller['uuid'], 'staffApiKeyC1', 'seller_staff');
        $this->seedMembership($seller['uuid'], 'analystApiKyC', 'seller_analyst');

        $router = $this->freshRouter();

        foreach (['staffApiKeyC1', 'analystApiKyC'] as $userUuid) {
            $response = $this->dispatch($router, $this->jwtRequest(
                $userUuid,
                'POST',
                "/commerce/seller/{$seller['uuid']}/api-keys",
                ['name' => 'x', 'declared_scopes' => ['commerce.seller.orders.read']]
            ));
            self::assertSame(403, $response->getStatusCode(), "{$userUuid} does not hold apikeys.manage");
        }
    }

    // -----------------------------------------------------------------
    // Own-seller-only: neither a cross-seller lineage target nor an
    // unrelated seller route is ever reachable.
    // -----------------------------------------------------------------

    public function testCrossSellerLineageTargetAndUnrelatedSellerAreRefusedNonRevealing404(): void
    {
        $sellerA = $this->seedSeller('apikey-cross-a', 'ownerApiKeyXA');
        $sellerB = $this->seedSeller('apikey-cross-b', 'ownerApiKeyXB');

        $router = $this->freshRouter();

        $createResponse = $this->dispatch($router, $this->jwtRequest(
            'ownerApiKeyXA',
            'POST',
            "/commerce/seller/{$sellerA['uuid']}/api-keys",
            ['name' => 'A key', 'declared_scopes' => ['commerce.seller.orders.read']]
        ));
        self::assertSame(201, $createResponse->getStatusCode());
        $lineageUuid = $this->json($createResponse)['data']['uuid'];

        // Owner B genuinely holds apikeys.manage -- on seller B, not seller A.
        // Targeting seller A's lineage through seller B's OWN route resource
        // must be refused exactly like an unknown lineage.
        $crossSellerLineage = $this->dispatch($router, $this->jwtRequest(
            'ownerApiKeyXB',
            'POST',
            "/commerce/seller/{$sellerB['uuid']}/api-keys/{$lineageUuid}/rotate"
        ));
        self::assertSame(404, $crossSellerLineage->getStatusCode());

        // Owner A has no membership at all on seller B -- refused before the
        // handler, the SAME non-revealing 404 every other seller surface uses.
        $unrelatedSeller = $this->dispatch($router, $this->jwtRequest(
            'ownerApiKeyXA',
            'GET',
            "/commerce/seller/{$sellerB['uuid']}/api-keys"
        ));
        self::assertSame(404, $unrelatedSeller->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Absolute-expiry DTO rejection (Task 6 carry-forward, commit-1
    // review Minor 1): a relative expression never reaches the service's
    // own UTC>DB-now check; an absolute future UTC timestamp is accepted.
    // -----------------------------------------------------------------

    public function testCreateRejectsARelativeExpiresAtButAcceptsAnAbsoluteFutureUtcTimestamp(): void
    {
        $seller = $this->seedSeller('apikey-expiry', 'ownerApiKeyE1');

        $router = $this->freshRouter();

        foreach (['+1 day', 'now', 'tomorrow'] as $relative) {
            $response = $this->dispatch($router, $this->jwtRequest(
                'ownerApiKeyE1',
                'POST',
                "/commerce/seller/{$seller['uuid']}/api-keys",
                [
                    'name' => 'relative expiry',
                    'declared_scopes' => ['commerce.seller.orders.read'],
                    'expires_at' => $relative,
                ]
            ));
            self::assertSame(422, $response->getStatusCode(), "expires_at={$relative} must be rejected");
            self::assertArrayHasKey(
                'expires_at',
                $this->json($response)['errors'] ?? [],
                "expires_at={$relative} must report a field error"
            );
        }

        self::assertSame(
            0,
            $this->connection->table('commerce_seller_api_keys')->count(),
            'no lineage may have been created for any rejected relative expiry'
        );

        $future = gmdate('Y-m-d H:i:s', time() + 3600);
        $absolute = $this->dispatch($router, $this->jwtRequest(
            'ownerApiKeyE1',
            'POST',
            "/commerce/seller/{$seller['uuid']}/api-keys",
            [
                'name' => 'absolute expiry',
                'declared_scopes' => ['commerce.seller.orders.read'],
                'expires_at' => $future,
            ]
        ));
        self::assertSame(201, $absolute->getStatusCode());
        self::assertSame($future, $this->json($absolute)['data']['expires_at']);
    }

    // -----------------------------------------------------------------
    // Fixtures + helpers.
    // -----------------------------------------------------------------

    /**
     * Every management route this surface exposes, keyed by a readable
     * label for assertion messages -- shared by the API-key-refusal and
     * non-JWT-provider-refusal tests so every route is covered identically.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string,mixed>}>
     */
    private function managementRoutes(string $sellerUuid): array
    {
        $body = ['name' => 'x', 'declared_scopes' => ['commerce.seller.orders.read']];

        return [
            'create' => ['POST', "/commerce/seller/{$sellerUuid}/api-keys", $body],
            'list' => ['GET', "/commerce/seller/{$sellerUuid}/api-keys", []],
            'rotate' => ['POST', "/commerce/seller/{$sellerUuid}/api-keys/anyLineageUu1/rotate", []],
            'revoke' => ['POST', "/commerce/seller/{$sellerUuid}/api-keys/anyLineageUu1/revoke", []],
        ];
    }

    /**
     * Extends {@see CommerceRouterTestCase::bindFakeAuth()}'s `X-Test-User`
     * session convention with the auth-PROVIDER signal this surface's gate
     * depends on (`X-Test-Auth-Provider` -> `auth_provider`, defaulting to
     * `jwt` for a bare session request) and
     * {@see \Glueful\Extensions\Commerce\Tests\Integration\Marketplace\SellerApiKeyAuthTest}'s
     * OWN API-key simulation (`X-Test-Api-Key-*` -> `auth_method`/
     * `api_key_uuid`/`api_key_scopes`/`user_id`) -- with `auth_provider` ALSO
     * set to `api_key` for that path, mirroring exactly what the real
     * `AuthMiddleware::authenticateRequest()` fallback does for a credential
     * that does not look like a JWT (see
     * {@see \Glueful\Extensions\Commerce\Http\Middleware\InteractiveSessionMiddleware}'s
     * own docblock for the verified framework source this mirrors).
     */
    private function bindFakeAuthWithProviderAndApiKeySupport(): void
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
                    $request->attributes->set('auth_provider', 'api_key');
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

                $provider = (string) $request->headers->get('X-Test-Auth-Provider', 'jwt');
                $request->attributes->set('auth_provider', $provider);
                $request->attributes->set('user', ['uuid' => $userUuid]);

                return $next($request);
            }
        });
    }

    /** @param array<string,mixed> $body */
    private function jwtRequest(string $userUuid, string $method, string $uri, array $body = []): Request
    {
        return $this->sessionRequest($userUuid, 'jwt', $method, $uri, $body);
    }

    /** @param array<string,mixed> $body */
    private function sessionRequest(
        string $userUuid,
        string $provider,
        string $method,
        string $uri,
        array $body = []
    ): Request {
        $content = $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR);
        $request = Request::create($uri, $method, [], [], [], [], $content);
        if ($content !== null) {
            $request->headers->set('Content-Type', 'application/json');
        }
        $request->headers->set('X-Test-User', $userUuid);
        $request->headers->set('X-Test-Auth-Provider', $provider);

        return $request;
    }

    /**
     * @param list<string> $scopes
     * @param array<string,mixed> $body
     */
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
}
