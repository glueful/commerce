<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Extensions\Commerce\Tests\Support\CommerceRouterTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Seller-scoped member surfaces (design spec §2.8, MV1 Task 4): functional
 * behavior over REAL routes for "my sellers", the seller-scoped catalog
 * (list/show/create/update/variant-create, each reusing
 * {@see \Glueful\Extensions\Commerce\Catalog\CatalogService} with tenant+
 * seller predicates), seller-scoped inventory (read/adjust reusing
 * {@see \Glueful\Extensions\Commerce\Inventory\InventoryService}), and
 * seller membership CRUD (list/grant/changeRole/revoke via
 * {@see \Glueful\Extensions\Commerce\Marketplace\SellerMembershipService}).
 * The authorization matrix itself (capability x role, non-revealing 404s,
 * suspended/closed/inactive-workspace gating, middleware ordering) is
 * {@see SellerMiddlewareTest}'s job -- this file assumes a capability-
 * satisfying caller throughout and asserts the surface actually does what it
 * says.
 */
final class SellerSurfaceTest extends CommerceRouterTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableMarketplace();
        $this->activateWorkspace();
        $this->bindFakeAuth();
    }

    // -----------------------------------------------------------------
    // "My sellers"
    // -----------------------------------------------------------------

    public function testMineReturnsOnlyActiveMembershipsInTenant(): void
    {
        $sellerA = $this->seedSeller('mine-active-a', 'ownerUserX01');
        $sellerB = $this->seedSeller('mine-active-b', 'someOtherOwner');
        $this->seedMembership($sellerB['uuid'], 'ownerUserX01', 'seller_staff');
        $sellerC = $this->seedSeller('mine-revoked-c', 'someOtherOwner2');
        $this->seedMembership($sellerC['uuid'], 'ownerUserX01', 'seller_analyst');
        $this->membershipService()->revoke($this->context, $this->tenant, $sellerC['uuid'], 'ownerUserX01');

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs('ownerUserX01', 'GET', '/commerce/seller/mine'));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame(2, $body['total']);
        $sellerUuids = array_column($body['data'], 'seller_uuid');
        sort($sellerUuids);
        $expected = [$sellerA['uuid'], $sellerB['uuid']];
        sort($expected);
        self::assertSame($expected, $sellerUuids);
    }

    public function testMineNeverReturnsAnotherTenantsMembership(): void
    {
        $seller = $this->seedSeller('mine-this-tenant', 'ownerUserX02');

        // A membership row for the SAME user uuid, but scoped to a different
        // tenant -- must never surface through this tenant's "mine" read.
        $this->connection->table('commerce_sellers')->insert([
            'uuid' => 'otherTenantSlr',
            'tenant_uuid' => 'someOtherTenant1',
            'slug' => 'other-tenant-seller',
            'name' => 'Other Tenant Seller',
            'status' => 'active',
        ]);
        $this->connection->table('commerce_seller_memberships')->insert([
            'uuid' => 'otherTenantMbr',
            'tenant_uuid' => 'someOtherTenant1',
            'seller_uuid' => 'otherTenantSlr',
            'user_uuid' => 'ownerUserX02',
            'role' => 'seller_owner',
            'status' => 'active',
        ]);

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs('ownerUserX02', 'GET', '/commerce/seller/mine'));

        $body = $this->json($response);
        self::assertSame(1, $body['total']);
        self::assertSame([$seller['uuid']], array_column($body['data'], 'seller_uuid'));
    }

    // -----------------------------------------------------------------
    // Catalog
    // -----------------------------------------------------------------

    public function testIndexListsOnlyThisSellersProducts(): void
    {
        $sellerA = $this->seedSeller('catalog-list-a', 'ownerUserY01');
        $sellerB = $this->seedSeller('catalog-list-b', 'ownerUserY02');
        $this->seedProduct($sellerA['uuid'], 'catalog-list-a-p1');
        $this->seedProduct($sellerA['uuid'], 'catalog-list-a-p2');
        $this->seedProduct($sellerB['uuid'], 'catalog-list-b-p1');

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerUserY01',
            'GET',
            "/commerce/seller/{$sellerA['uuid']}/products"
        ));

        $body = $this->json($response);
        self::assertSame(2, $body['total']);
    }

    public function testStoreDerivesSellerUuidFromTheRouteAndReturns201(): void
    {
        $seller = $this->seedSeller('catalog-store', 'ownerUserY03');

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerUserY03',
            'POST',
            "/commerce/seller/{$seller['uuid']}/products",
            $this->productPayload('catalog-store-p')
        ));

        self::assertSame(201, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame($seller['uuid'], $body['data']['seller_uuid']);
        self::assertSame('catalog-store-p', $body['data']['slug']);
        self::assertNotEmpty($body['data']['variants']);
    }

    public function testShowReturnsProductWithVariants(): void
    {
        $seller = $this->seedSeller('catalog-show', 'ownerUserY04');
        $product = $this->seedProduct($seller['uuid'], 'catalog-show-p');

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerUserY04',
            'GET',
            "/commerce/seller/{$seller['uuid']}/products/{$product['uuid']}"
        ));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame($product['uuid'], $body['data']['uuid']);
        self::assertNotEmpty($body['data']['variants']);
    }

    public function testUpdateChangesFieldsAndSilentlyIgnoresAStraySellerUuid(): void
    {
        $seller = $this->seedSeller('catalog-update', 'ownerUserY05');
        $otherSeller = $this->seedSeller('catalog-update-other', 'ownerUserY06');
        $product = $this->seedProduct($seller['uuid'], 'catalog-update-p');

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerUserY05',
            'PATCH',
            "/commerce/seller/{$seller['uuid']}/products/{$product['uuid']}",
            ['name' => 'Updated Name', 'seller_uuid' => $otherSeller['uuid']]
        ));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame('Updated Name', $body['data']['name']);
        self::assertSame($seller['uuid'], $body['data']['seller_uuid'], 'seller_uuid must never move via update');
    }

    public function testUpdateOnAnotherSellersProductIsNotFound(): void
    {
        $sellerA = $this->seedSeller('catalog-update-x-a', 'ownerUserY07');
        $sellerB = $this->seedSeller('catalog-update-x-b', 'ownerUserY08');
        $productB = $this->seedProduct($sellerB['uuid'], 'catalog-update-x-b-p');

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerUserY07',
            'PATCH',
            "/commerce/seller/{$sellerA['uuid']}/products/{$productB['uuid']}",
            ['name' => 'Should Not Apply']
        ));

        self::assertSame(404, $response->getStatusCode());
        $row = $this->connection->table('commerce_products')->where('uuid', '=', $productB['uuid'])->first();
        self::assertSame('catalog-update-x-b-p', $row['name'], 'the wrong-seller update must not have applied');
    }

    public function testStoreVariantAttachesToTheProductRoot(): void
    {
        $seller = $this->seedSeller('catalog-variant', 'ownerUserY09');
        $product = $this->seedProduct($seller['uuid'], 'catalog-variant-p');

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerUserY09',
            'POST',
            "/commerce/seller/{$seller['uuid']}/products/{$product['uuid']}/variants",
            [
                'sku' => 'CATALOG-VARIANT-2',
                'option_values' => [],
                'price' => 500,
                'currency' => 'USD',
            ]
        ));

        self::assertSame(201, $response->getStatusCode());

        $count = $this->connection->table('commerce_variants')
            ->where('product_uuid', '=', $product['uuid'])
            ->count();
        self::assertSame(2, $count);
    }

    public function testStoreVariantOnAnotherSellersProductIsNotFound(): void
    {
        $sellerA = $this->seedSeller('catalog-variant-x-a', 'ownerUserY10');
        $sellerB = $this->seedSeller('catalog-variant-x-b', 'ownerUserY11');
        $productB = $this->seedProduct($sellerB['uuid'], 'catalog-variant-x-b-p');

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerUserY10',
            'POST',
            "/commerce/seller/{$sellerA['uuid']}/products/{$productB['uuid']}/variants",
            ['sku' => 'SHOULD-NOT-CREATE', 'option_values' => [], 'price' => 100, 'currency' => 'USD']
        ));

        self::assertSame(404, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Inventory
    // -----------------------------------------------------------------

    public function testInventoryShowReturnsCurrentQuantity(): void
    {
        $seller = $this->seedSeller('inventory-show', 'ownerUserZ01');
        $product = $this->seedProduct($seller['uuid'], 'inventory-show-p');
        $variantUuid = (string) $product['variants'][0]['uuid'];

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerUserZ01',
            'GET',
            "/commerce/seller/{$seller['uuid']}/variants/{$variantUuid}/stock"
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $this->json($response)['data']['quantity']);
    }

    public function testInventoryAdjustWritesTheLedgerAndTenantScopedQuantity(): void
    {
        $seller = $this->seedSeller('inventory-adjust', 'ownerUserZ02');
        $product = $this->seedProduct($seller['uuid'], 'inventory-adjust-p');
        $variantUuid = (string) $product['variants'][0]['uuid'];

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerUserZ02',
            'POST',
            "/commerce/seller/{$seller['uuid']}/variants/{$variantUuid}/stock/adjust",
            ['delta' => 7, 'reason' => 'restock']
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(7, $this->json($response)['data']['quantity']);

        $movement = $this->connection->table('commerce_stock_movements')
            ->where('variant_uuid', '=', $variantUuid)
            ->orderBy('id', 'DESC')
            ->first();
        self::assertSame(7, (int) $movement['delta']);
        self::assertSame('restock', $movement['reason']);
    }

    public function testInventoryAdjustOnAnotherSellersVariantIsNotFound(): void
    {
        $sellerA = $this->seedSeller('inventory-x-a', 'ownerUserZ03');
        $sellerB = $this->seedSeller('inventory-x-b', 'ownerUserZ04');
        $productB = $this->seedProduct($sellerB['uuid'], 'inventory-x-b-p');
        $variantUuid = (string) $productB['variants'][0]['uuid'];

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerUserZ03',
            'POST',
            "/commerce/seller/{$sellerA['uuid']}/variants/{$variantUuid}/stock/adjust",
            ['delta' => 5]
        ));

        self::assertSame(404, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Membership CRUD (owner-only, commerce.seller.members.manage)
    // -----------------------------------------------------------------

    public function testOwnerCanListGrantChangeRoleAndRevokeMembers(): void
    {
        $seller = $this->seedSeller('membership-crud', 'ownerUserW01');
        $router = $this->freshRouter();

        $grantResponse = $this->dispatch($router, $this->requestAs(
            'ownerUserW01',
            'POST',
            "/commerce/seller/{$seller['uuid']}/members",
            ['user_uuid' => 'newMemberW001', 'role' => 'seller_staff']
        ));
        self::assertSame(201, $grantResponse->getStatusCode());

        $listResponse = $this->dispatch($router, $this->requestAs(
            'ownerUserW01',
            'GET',
            "/commerce/seller/{$seller['uuid']}/members"
        ));
        self::assertSame(2, $this->json($listResponse)['total'], 'owner + the new staff member');

        $changeResponse = $this->dispatch($router, $this->requestAs(
            'ownerUserW01',
            'PATCH',
            "/commerce/seller/{$seller['uuid']}/members/newMemberW001",
            ['role' => 'seller_admin']
        ));
        self::assertSame(200, $changeResponse->getStatusCode());
        self::assertSame('seller_admin', $this->json($changeResponse)['data']['role']);

        $revokeResponse = $this->dispatch($router, $this->requestAs(
            'ownerUserW01',
            'DELETE',
            "/commerce/seller/{$seller['uuid']}/members/newMemberW001"
        ));
        self::assertSame(204, $revokeResponse->getStatusCode());

        $row = $this->connection->table('commerce_seller_memberships')
            ->where('seller_uuid', '=', $seller['uuid'])
            ->where('user_uuid', '=', 'newMemberW001')
            ->first();
        self::assertSame('revoked', $row['status']);
    }

    public function testRevokingTheLastOwnerThroughTheRouteIs409(): void
    {
        $seller = $this->seedSeller('membership-last-owner', 'ownerUserW02');

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerUserW02',
            'DELETE',
            "/commerce/seller/{$seller['uuid']}/members/ownerUserW02"
        ));

        self::assertSame(409, $response->getStatusCode());
    }

    public function testGrantWithAnUnrecognizedRoleIs422(): void
    {
        $seller = $this->seedSeller('membership-bad-role', 'ownerUserW03');

        $router = $this->freshRouter();
        $response = $this->dispatch($router, $this->requestAs(
            'ownerUserW03',
            'POST',
            "/commerce/seller/{$seller['uuid']}/members",
            ['user_uuid' => 'someUserW003', 'role' => 'not_a_real_role']
        ));

        self::assertSame(422, $response->getStatusCode());
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
