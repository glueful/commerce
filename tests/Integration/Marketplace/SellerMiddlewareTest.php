<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Extensions\Commerce\Tests\Support\CommerceRouterTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * `commerce_seller:<capability>` middleware (design spec §2.5/§2.6/§4/§5,
 * MV1 Task 4): the full capability x role matrix over REAL routes, the
 * non-revealing 404 for unknown seller / no membership / cross-seller /
 * revoked membership (all four must be the SAME response, never
 * distinguishable from one another), body-supplied `seller_uuid` smuggling,
 * the suspended/closed mutation-409-but-read-OK posture, the workspace-
 * inactive 409, and the `auth -> tenant -> commerce_seller` (tenancy
 * enabled) / `auth -> commerce_seller` (sentinel) ordering.
 */
final class SellerMiddlewareTest extends CommerceRouterTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableMarketplace();
        $this->activateWorkspace();
        $this->bindFakeAuth();
    }

    // -----------------------------------------------------------------
    // Full capability x role matrix over real routes
    // -----------------------------------------------------------------

    public function testCatalogReadCapabilityAllowsAllFourRoles(): void
    {
        $seller = $this->seedSeller('matrix-catalog-read', 'ownerUser01');
        $this->seedMembership($seller['uuid'], 'adminUser01', 'seller_admin');
        $this->seedMembership($seller['uuid'], 'staffUser01', 'seller_staff');
        $this->seedMembership($seller['uuid'], 'analystUser1', 'seller_analyst');
        $this->seedProduct($seller['uuid'], 'matrix-catalog-read-p');

        $router = $this->freshRouter();
        foreach (['ownerUser01', 'adminUser01', 'staffUser01', 'analystUser1'] as $userUuid) {
            $response = $this->dispatch($router, $this->requestAs(
                $userUuid,
                'GET',
                "/commerce/seller/{$seller['uuid']}/products"
            ));

            self::assertSame(200, $response->getStatusCode(), "catalog.read must allow {$userUuid}");
        }
    }

    public function testCatalogWriteCapabilityAllowsOnlyOwnerAndAdmin(): void
    {
        $seller = $this->seedSeller('matrix-catalog-write', 'ownerUser02');
        $this->seedMembership($seller['uuid'], 'adminUser02', 'seller_admin');
        $this->seedMembership($seller['uuid'], 'staffUser02', 'seller_staff');
        $this->seedMembership($seller['uuid'], 'analystUser2', 'seller_analyst');

        $router = $this->freshRouter();
        $expected = [
            'ownerUser02' => 201,
            'adminUser02' => 201,
            'staffUser02' => 403,
            'analystUser2' => 403,
        ];
        foreach ($expected as $userUuid => $status) {
            $response = $this->dispatch($router, $this->requestAs(
                $userUuid,
                'POST',
                "/commerce/seller/{$seller['uuid']}/products",
                $this->productPayload('matrix-write-' . $userUuid)
            ));

            self::assertSame($status, $response->getStatusCode(), "catalog.write for {$userUuid}");
        }
    }

    public function testInventoryReadCapabilityAllowsAllFourRoles(): void
    {
        $seller = $this->seedSeller('matrix-inv-read', 'ownerUser03');
        $this->seedMembership($seller['uuid'], 'adminUser03', 'seller_admin');
        $this->seedMembership($seller['uuid'], 'staffUser03', 'seller_staff');
        $this->seedMembership($seller['uuid'], 'analystUser3', 'seller_analyst');
        $product = $this->seedProduct($seller['uuid'], 'matrix-inv-read-p');
        $variantUuid = (string) $product['variants'][0]['uuid'];

        $router = $this->freshRouter();
        foreach (['ownerUser03', 'adminUser03', 'staffUser03', 'analystUser3'] as $userUuid) {
            $response = $this->dispatch($router, $this->requestAs(
                $userUuid,
                'GET',
                "/commerce/seller/{$seller['uuid']}/variants/{$variantUuid}/stock"
            ));

            self::assertSame(200, $response->getStatusCode(), "inventory.read must allow {$userUuid}");
        }
    }

    public function testInventoryWriteCapabilityDeniesOnlyAnalyst(): void
    {
        $seller = $this->seedSeller('matrix-inv-write', 'ownerUser04');
        $this->seedMembership($seller['uuid'], 'adminUser04', 'seller_admin');
        $this->seedMembership($seller['uuid'], 'staffUser04', 'seller_staff');
        $this->seedMembership($seller['uuid'], 'analystUser4', 'seller_analyst');
        $product = $this->seedProduct($seller['uuid'], 'matrix-inv-write-p');
        $variantUuid = (string) $product['variants'][0]['uuid'];

        $router = $this->freshRouter();
        $expected = [
            'ownerUser04' => 200,
            'adminUser04' => 200,
            'staffUser04' => 200,
            'analystUser4' => 403,
        ];
        foreach ($expected as $userUuid => $status) {
            $response = $this->dispatch($router, $this->requestAs(
                $userUuid,
                'POST',
                "/commerce/seller/{$seller['uuid']}/variants/{$variantUuid}/stock/adjust",
                ['delta' => 1, 'reason' => 'matrix']
            ));

            self::assertSame($status, $response->getStatusCode(), "inventory.write for {$userUuid}");
        }
    }

    public function testMembersManageCapabilityAllowsOnlyOwner(): void
    {
        $seller = $this->seedSeller('matrix-members', 'ownerUser05');
        $this->seedMembership($seller['uuid'], 'adminUser05', 'seller_admin');
        $this->seedMembership($seller['uuid'], 'staffUser05', 'seller_staff');
        $this->seedMembership($seller['uuid'], 'analystUser5', 'seller_analyst');

        $router = $this->freshRouter();
        $expected = [
            'ownerUser05' => 200,
            'adminUser05' => 403,
            'staffUser05' => 403,
            'analystUser5' => 403,
        ];
        foreach ($expected as $userUuid => $status) {
            $response = $this->dispatch($router, $this->requestAs(
                $userUuid,
                'GET',
                "/commerce/seller/{$seller['uuid']}/members"
            ));

            self::assertSame($status, $response->getStatusCode(), "members.manage (list) for {$userUuid}");
        }
    }

    // -----------------------------------------------------------------
    // Non-revealing 404: unknown seller / no membership / cross-seller /
    // revoked membership must ALL be the exact same response.
    // -----------------------------------------------------------------

    public function testUnknownSellerIsNotFound(): void
    {
        $router = $this->freshRouter();

        $response = $this->dispatch(
            $router,
            $this->requestAs('anyUser0001', 'GET', '/commerce/seller/doesNotExist/products')
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function testNonMemberIsNotFoundIdenticallyToUnknownSeller(): void
    {
        $seller = $this->seedSeller('nonmember-seller', 'ownerUser06');
        $router = $this->freshRouter();

        $unknownResponse = $this->dispatch(
            $router,
            $this->requestAs('strangerUser1', 'GET', '/commerce/seller/doesNotExist/products')
        );
        $nonMemberResponse = $this->dispatch(
            $router,
            $this->requestAs('strangerUser1', 'GET', "/commerce/seller/{$seller['uuid']}/products")
        );

        self::assertSame(404, $nonMemberResponse->getStatusCode());
        self::assertSame($unknownResponse->getStatusCode(), $nonMemberResponse->getStatusCode());
        self::assertSame($this->json($unknownResponse), $this->json($nonMemberResponse));
    }

    public function testCrossSellerProductAccessIsNotFound(): void
    {
        $sellerA = $this->seedSeller('cross-seller-a', 'ownerUserA07');
        $sellerB = $this->seedSeller('cross-seller-b', 'ownerUserB07');
        $productB = $this->seedProduct($sellerB['uuid'], 'cross-seller-b-product');
        $this->seedMembership($sellerA['uuid'], 'memberUser07', 'seller_owner');

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'memberUser07',
            'GET',
            "/commerce/seller/{$sellerA['uuid']}/products/{$productB['uuid']}"
        ));

        // memberUser07 has no membership at all on sellerB -- the ROUTE
        // seller is sellerA, so this must resolve as sellerA's own
        // (non-existent) product, never leak sellerB's existence.
        self::assertSame(404, $response->getStatusCode());
    }

    public function testRevokedMembershipIsNotFound(): void
    {
        $seller = $this->seedSeller('revoked-membership', 'ownerUser08');
        $this->seedMembership($seller['uuid'], 'goneUser0008', 'seller_staff');
        $this->membershipService()->revoke($this->context, $this->tenant, $seller['uuid'], 'goneUser0008');

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'goneUser0008',
            'GET',
            "/commerce/seller/{$seller['uuid']}/products"
        ));

        self::assertSame(404, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Body-supplied seller_uuid is ignored -- the product always lands on
    // the ROUTE seller.
    // -----------------------------------------------------------------

    public function testBodySuppliedSellerUuidIsIgnoredProductLandsOnRouteSeller(): void
    {
        $routeSeller = $this->seedSeller('smuggle-route', 'ownerUser09');
        $otherSeller = $this->seedSeller('smuggle-other', 'ownerUser10');

        $router = $this->freshRouter();
        $payload = $this->productPayload('smuggled-product');
        $payload['seller_uuid'] = $otherSeller['uuid'];

        $response = $this->dispatch($router, $this->requestAs(
            'ownerUser09',
            'POST',
            "/commerce/seller/{$routeSeller['uuid']}/products",
            $payload
        ));

        self::assertSame(201, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame($routeSeller['uuid'], $body['data']['seller_uuid']);

        $row = $this->connection->table('commerce_products')
            ->where('uuid', '=', $body['data']['uuid'])
            ->first();
        self::assertSame($routeSeller['uuid'], $row['seller_uuid']);
    }

    // -----------------------------------------------------------------
    // Suspended / closed seller: mutations 409, reads OK.
    // -----------------------------------------------------------------

    public function testSuspendedSellerBlocksMutationsButAllowsReads(): void
    {
        $seller = $this->seedSeller('suspended-gate', 'ownerUser11');
        $product = $this->seedProduct($seller['uuid'], 'suspended-gate-p');
        $this->sellerService()->suspend($this->context, $this->tenant, $seller['uuid'], 'Under review.', 'operator01');

        $router = $this->freshRouter();

        $readResponse = $this->dispatch($router, $this->requestAs(
            'ownerUser11',
            'GET',
            "/commerce/seller/{$seller['uuid']}/products/{$product['uuid']}"
        ));
        self::assertSame(200, $readResponse->getStatusCode(), 'reads stay available while suspended');

        $writeResponse = $this->dispatch($router, $this->requestAs(
            'ownerUser11',
            'PATCH',
            "/commerce/seller/{$seller['uuid']}/products/{$product['uuid']}",
            ['name' => 'Should Not Apply']
        ));
        self::assertSame(409, $writeResponse->getStatusCode());
    }

    public function testClosedSellerWithAnActiveMembershipBlocksMutationsButAllowsReads(): void
    {
        // Seeded directly (mirrors the T2/T3 convention for isolating a
        // status edge case): a real close() ALSO revokes every membership,
        // which would independently 404 via the "no active membership"
        // branch. Flipping status alone isolates the suspended/closed
        // mutation gate itself.
        $seller = $this->seedSeller('closed-gate', 'ownerUser12');
        $product = $this->seedProduct($seller['uuid'], 'closed-gate-p');
        $this->connection->table('commerce_sellers')
            ->where('uuid', '=', $seller['uuid'])
            ->update(['status' => 'closed']);

        $router = $this->freshRouter();

        $readResponse = $this->dispatch($router, $this->requestAs(
            'ownerUser12',
            'GET',
            "/commerce/seller/{$seller['uuid']}/products/{$product['uuid']}"
        ));
        self::assertSame(200, $readResponse->getStatusCode());

        $writeResponse = $this->dispatch($router, $this->requestAs(
            'ownerUser12',
            'PATCH',
            "/commerce/seller/{$seller['uuid']}/products/{$product['uuid']}",
            ['name' => 'Should Not Apply']
        ));
        self::assertSame(409, $writeResponse->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Workspace marketplace inactive -> 409 on member surfaces.
    // -----------------------------------------------------------------

    public function testWorkspaceInactiveReturns409OnMemberSurfaces(): void
    {
        $this->connection->table('commerce_marketplace_settings')
            ->where('tenant_uuid', '=', $this->tenant)
            ->update(['status' => 'disabled']);
        $seller = $this->seedSeller('inactive-workspace', 'ownerUser13');

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerUser13',
            'GET',
            "/commerce/seller/{$seller['uuid']}/products"
        ));

        self::assertSame(409, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Middleware ordering.
    // -----------------------------------------------------------------

    public function testTenancyEnabledUserWithMembershipButDeniedByTenantMiddlewareIsRejectedBeforeCommerceSeller(): void
    {
        $this->enableTenancy();
        $this->bindFakeTenant();
        $seller = $this->seedSeller('tenant-denied', 'ownerUser14');

        $router = $this->freshRouter();
        $request = $this->requestAs('ownerUser14', 'GET', "/commerce/seller/{$seller['uuid']}/products");
        $request->headers->set('X-Test-Tenant-Denied', '1');

        $response = $this->dispatch($router, $request);

        // The TENANT denial (403, "Tenant membership required"), never a
        // marketplace 404/409 -- commerce_seller must never have run.
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Tenant membership required', $this->json($response)['message']);
    }

    public function testTenancyEnabledUserAllowedByTenantMiddlewareReachesCommerceSeller(): void
    {
        $this->enableTenancy();
        $this->bindFakeTenant();
        $seller = $this->seedSeller('tenant-allowed', 'ownerUser15');
        $this->seedProduct($seller['uuid'], 'tenant-allowed-p');

        $router = $this->freshRouter();
        $response = $this->dispatch(
            $router,
            $this->requestAs('ownerUser15', 'GET', "/commerce/seller/{$seller['uuid']}/products")
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function testSentinelModeOrderIsAuthThenCommerceSeller(): void
    {
        // No enableTenancy()/bindFakeTenant() -- single-store sentinel mode.
        // auth (X-Test-User) then commerce_seller must be sufficient; the
        // absence of a tenant hop must not break resolution.
        $seller = $this->seedSeller('sentinel-order', 'ownerUser16');
        $this->seedProduct($seller['uuid'], 'sentinel-order-p');

        $router = $this->freshRouter();
        $response = $this->dispatch(
            $router,
            $this->requestAs('ownerUser16', 'GET', "/commerce/seller/{$seller['uuid']}/products")
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function testUnauthenticatedRequestIsRejectedByAuthBeforeCommerceSeller(): void
    {
        $seller = $this->seedSeller('unauthenticated', 'ownerUser17');

        $router = $this->freshRouter();
        $response = $this->dispatch(
            $router,
            Request::create("/commerce/seller/{$seller['uuid']}/products", 'GET')
        );

        self::assertSame(401, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Master-off: zero new routes.
    // -----------------------------------------------------------------

    public function testMasterOffRegistersZeroSellerRoutes(): void
    {
        $this->context->overrideConfig('commerce.marketplace.enabled', false);

        $router = $this->freshRouter();

        $paths = [];
        foreach ($router->getAllRoutes() as $route) {
            if (str_starts_with((string) $route['path'], '/commerce/seller')) {
                $paths[] = (string) $route['path'];
            }
        }

        self::assertSame([], $paths);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function requestAs(string $userUuid, string $method, string $uri, array $body = []): Request
    {
        $content = $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR);
        $request = Request::create($uri, $method, [], [], [], [], $content);
        if ($content !== null) {
            $request->headers->set('Content-Type', 'application/json');
        }
        $request->headers->set('X-Test-User', $userUuid);

        return $request;
    }

    /** @return array<string,mixed> */
    private function productPayload(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => $slug,
            'type' => 'physical',
            'status' => 'active',
            'variants' => [[
                'sku' => strtoupper(str_replace('-', '', $slug)),
                'option_values' => [],
                'price' => 1000,
                'currency' => 'USD',
            ]],
        ];
    }
}
