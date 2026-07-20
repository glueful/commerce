<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Marketplace;

use Glueful\Extensions\Commerce\Tests\Support\CommerceRouterTestCase;
use Glueful\Http\Response;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;

/**
 * Marketplace MV5c-2 Task 7 (design spec §2.10/§5): the seller self-service
 * WEBHOOK-endpoint management HTTP surface (register/list/update/
 * rotate-secret/disable/enable/delete + delivery history + dead-letter
 * replay) -- over REAL routes, mirroring
 * {@see \Glueful\Extensions\Commerce\Tests\Integration\Marketplace\SellerApiKeySurfaceTest}'s
 * exact harness (JWT-session / API-key-simulated request helpers, route
 * order proof) for this sibling surface.
 *
 * This is a SECURITY surface: secret-once (never on list/read/deliveries),
 * JWT-interactive-only (an API key can NEVER reach this surface, even with
 * `webhooks.manage` in its declared scope), own-seller-only (including the
 * `{uuid}`/`{deliveryUuid}` chain -- see {@see \Glueful\Extensions\Commerce\Http\Seller\SellerWebhookController}'s
 * own docblock for why the delivery-history/replay routes need an EXTRA
 * ownership check the underlying services do not themselves perform), and
 * no leaked internal SSRF-resolved address anywhere in a response.
 */
final class SellerWebhookSurfaceTest extends CommerceRouterTestCase
{
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableMarketplace();
        $this->activateWorkspace();
        $this->bindFakeAuthWithProviderAndApiKeySupport();
    }

    // -----------------------------------------------------------------
    // Happy path: register -> list -> update -> rotate -> disable ->
    // enable -> delete. Secret returned exactly once on register + rotate,
    // NEVER on list/update.
    // -----------------------------------------------------------------

    public function testJwtSessionWithWebhooksManageCanRegisterListUpdateRotateDisableEnableAndDelete(): void
    {
        $seller = $this->seedSeller('webhook-lifecycle', 'ownerWebhookL1');

        $router = $this->freshRouter();

        $registerResponse = $this->dispatch($router, $this->jwtRequest(
            'ownerWebhookL1',
            'POST',
            "/commerce/seller/{$seller['uuid']}/webhooks",
            ['url' => 'https://whlifecycle.example.test/hook', 'events' => ['order.placed', 'order.paid']]
        ));
        self::assertSame(201, $registerResponse->getStatusCode());
        $registered = $this->json($registerResponse)['data'];
        self::assertArrayHasKey('secret', $registered);
        self::assertIsString($registered['secret']);
        self::assertNotSame('', $registered['secret']);
        self::assertSame('https://whlifecycle.example.test/hook', $registered['url']);
        // The service canonicalizes (de-duplicates + sorts) subscribed
        // events (design spec §2.2/§2.3) -- alphabetical, not input order.
        self::assertSame(['order.paid', 'order.placed'], $registered['events']);
        self::assertSame('active', $registered['status']);
        $endpointUuid = $registered['uuid'];
        $firstSecret = $registered['secret'];

        // LIST -- never leaks a secret.
        $listResponse = $this->dispatch($router, $this->jwtRequest(
            'ownerWebhookL1',
            'GET',
            "/commerce/seller/{$seller['uuid']}/webhooks"
        ));
        self::assertSame(200, $listResponse->getStatusCode());
        $items = $this->json($listResponse)['data'];
        self::assertCount(1, $items);
        self::assertSame($endpointUuid, $items[0]['uuid']);
        self::assertArrayNotHasKey('secret', $items[0]);

        // UPDATE (events only) -- never returns a secret.
        $updateResponse = $this->dispatch($router, $this->jwtRequest(
            'ownerWebhookL1',
            'PATCH',
            "/commerce/seller/{$seller['uuid']}/webhooks/{$endpointUuid}",
            ['events' => ['refund.completed']]
        ));
        self::assertSame(200, $updateResponse->getStatusCode());
        $updated = $this->json($updateResponse)['data'];
        self::assertSame(['refund.completed'], $updated['events']);
        self::assertSame('https://whlifecycle.example.test/hook', $updated['url'], 'url must be unchanged');
        self::assertArrayNotHasKey('secret', $updated);

        // ROTATE -- a NEW secret, returned exactly once.
        $rotateResponse = $this->dispatch($router, $this->jwtRequest(
            'ownerWebhookL1',
            'POST',
            "/commerce/seller/{$seller['uuid']}/webhooks/{$endpointUuid}/rotate-secret"
        ));
        self::assertSame(200, $rotateResponse->getStatusCode());
        $rotated = $this->json($rotateResponse)['data'];
        self::assertArrayHasKey('secret', $rotated);
        self::assertNotSame('', $rotated['secret']);
        self::assertNotSame($firstSecret, $rotated['secret'], 'rotation must mint a NEW secret');

        // LIST again -- still no secret leak.
        $listAfterRotate = $this->dispatch($router, $this->jwtRequest(
            'ownerWebhookL1',
            'GET',
            "/commerce/seller/{$seller['uuid']}/webhooks"
        ));
        self::assertArrayNotHasKey('secret', $this->json($listAfterRotate)['data'][0]);

        // DISABLE.
        $disableResponse = $this->dispatch($router, $this->jwtRequest(
            'ownerWebhookL1',
            'POST',
            "/commerce/seller/{$seller['uuid']}/webhooks/{$endpointUuid}/disable"
        ));
        self::assertSame(200, $disableResponse->getStatusCode());
        self::assertSame('disabled', $this->json($disableResponse)['data']['status']);

        // ENABLE -- SSRF-revalidated against the SAME (still-safe) stored URL.
        $enableResponse = $this->dispatch($router, $this->jwtRequest(
            'ownerWebhookL1',
            'POST',
            "/commerce/seller/{$seller['uuid']}/webhooks/{$endpointUuid}/enable"
        ));
        self::assertSame(200, $enableResponse->getStatusCode());
        self::assertSame('active', $this->json($enableResponse)['data']['status']);

        // DELETE -- tombstone.
        $deleteResponse = $this->dispatch($router, $this->jwtRequest(
            'ownerWebhookL1',
            'DELETE',
            "/commerce/seller/{$seller['uuid']}/webhooks/{$endpointUuid}"
        ));
        self::assertSame(200, $deleteResponse->getStatusCode());
        self::assertSame('deleted', $this->json($deleteResponse)['data']['status']);

        // LIST once more -- the deleted endpoint has disappeared.
        $listAfterDelete = $this->dispatch($router, $this->jwtRequest(
            'ownerWebhookL1',
            'GET',
            "/commerce/seller/{$seller['uuid']}/webhooks"
        ));
        self::assertSame([], $this->json($listAfterDelete)['data']);
    }

    // -----------------------------------------------------------------
    // enable() re-runs SSRF validation against the STORED url -- a DNS
    // rebind between registration and enable() is rejected, with NO
    // resolved internal address ever reaching the response.
    // -----------------------------------------------------------------

    public function testEnableRevalidatesSsrfAndRejectsADnsRebind(): void
    {
        $seller = $this->seedSeller('webhook-rebind', 'ownerWebhookR1');
        $this->webhookDnsMap = ['whrebind.example.test' => ['1.1.1.1']];

        $router = $this->freshRouter();
        $register = $this->dispatch($router, $this->jwtRequest(
            'ownerWebhookR1',
            'POST',
            "/commerce/seller/{$seller['uuid']}/webhooks",
            ['url' => 'https://whrebind.example.test/hook', 'events' => ['order.placed']]
        ));
        self::assertSame(201, $register->getStatusCode());
        $endpointUuid = $this->json($register)['data']['uuid'];

        // The host now resolves to a PRIVATE address (simulated DNS rebind)
        // -- a fresh router is required since the resolver closure captures
        // $this->webhookDnsMap at construction time.
        $this->webhookDnsMap['whrebind.example.test'] = ['10.0.0.5'];
        $router = $this->freshRouter();

        $disable = $this->dispatch($router, $this->jwtRequest(
            'ownerWebhookR1',
            'POST',
            "/commerce/seller/{$seller['uuid']}/webhooks/{$endpointUuid}/disable"
        ));
        self::assertSame(200, $disable->getStatusCode());

        $enable = $this->dispatch($router, $this->jwtRequest(
            'ownerWebhookR1',
            'POST',
            "/commerce/seller/{$seller['uuid']}/webhooks/{$endpointUuid}/enable"
        ));
        self::assertSame(422, $enable->getStatusCode());
        $this->assertNoInternalAddressLeak((string) $enable->getContent());

        // The endpoint stays disabled -- the failed SSRF re-check rolled
        // the claim back.
        $row = $this->endpointRow($endpointUuid);
        self::assertSame('disabled', $row['status']);
    }

    // -----------------------------------------------------------------
    // Delivery history + dead-letter replay: sanitized read (no secret, no
    // claim/lease internals), replay creates a NEW lineage without
    // mutating the original.
    // -----------------------------------------------------------------

    public function testDeliveriesListAndReplayADeadLetterDeliveryCreatesANewLineage(): void
    {
        $seller = $this->seedSeller('webhook-deliveries', 'ownerWebhookD1');
        $router = $this->freshRouter();

        $endpointUuid = $this->registerEndpointViaHttp(
            $router,
            $seller['uuid'],
            'ownerWebhookD1',
            'whdeliveries.example.test'
        );

        $deadLetter = $this->seedDelivery($endpointUuid, $seller['uuid'], 'dead_letter', [
            'attempts' => 6,
            'last_status_code' => 500,
            'last_error' => 'HTTP 500',
        ]);

        $listResponse = $this->dispatch($router, $this->jwtRequest(
            'ownerWebhookD1',
            'GET',
            "/commerce/seller/{$seller['uuid']}/webhooks/{$endpointUuid}/deliveries"
        ));
        self::assertSame(200, $listResponse->getStatusCode());
        $rows = $this->json($listResponse)['data'];
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame($deadLetter['delivery_uuid'], $row['uuid']);
        self::assertSame('dead_letter', $row['status']);
        self::assertSame(6, $row['attempts']);
        self::assertSame(500, $row['last_status_code']);
        foreach (['secret', 'claim_token', 'claim_expires_at', 'tenant_uuid', 'endpoint_uuid', 'seller_uuid',
            'webhook_event_uuid', 'id'] as $forbiddenKey) {
            self::assertArrayNotHasKey($forbiddenKey, $row, "delivery row must never expose '{$forbiddenKey}'");
        }
        $this->assertNoInternalAddressLeak((string) $listResponse->getContent());

        // REPLAY -- a NEW pending delivery lineage, original left untouched.
        $replayResponse = $this->dispatch($router, $this->jwtRequest(
            'ownerWebhookD1',
            'POST',
            "/commerce/seller/{$seller['uuid']}/webhooks/{$endpointUuid}/deliveries/"
                . "{$deadLetter['delivery_uuid']}/replay"
        ));
        self::assertSame(200, $replayResponse->getStatusCode());
        $replayed = $this->json($replayResponse)['data'];
        self::assertNotSame($deadLetter['delivery_uuid'], $replayed['uuid']);
        self::assertSame('pending', $replayed['status']);
        self::assertSame(0, $replayed['attempts']);
        self::assertSame($deadLetter['delivery_uuid'], $replayed['replay_of_uuid']);
        foreach (['secret', 'claim_token', 'claim_expires_at', 'tenant_uuid', 'endpoint_uuid', 'seller_uuid',
            'webhook_event_uuid', 'id'] as $forbiddenKey) {
            self::assertArrayNotHasKey($forbiddenKey, $replayed, "replay row must never expose '{$forbiddenKey}'");
        }

        // The original delivery's OWN row is never mutated by a replay.
        $originalRow = $this->deliveryRow($deadLetter['delivery_uuid']);
        self::assertSame('dead_letter', $originalRow['status']);
        self::assertSame(6, (int) $originalRow['attempts']);

        $listAfterReplay = $this->dispatch($router, $this->jwtRequest(
            'ownerWebhookD1',
            'GET',
            "/commerce/seller/{$seller['uuid']}/webhooks/{$endpointUuid}/deliveries"
        ));
        self::assertCount(2, $this->json($listAfterReplay)['data']);
    }

    public function testReplayRefusesNonDeadLetterDeliveriesWith409(): void
    {
        $seller = $this->seedSeller('webhook-replay-409', 'ownerWebhookP1');
        $router = $this->freshRouter();
        $endpointUuid = $this->registerEndpointViaHttp(
            $router,
            $seller['uuid'],
            'ownerWebhookP1',
            'whreplay409.example.test'
        );

        $pending = $this->seedDelivery($endpointUuid, $seller['uuid'], 'pending');
        $canceled = $this->seedDelivery($endpointUuid, $seller['uuid'], 'canceled');

        foreach ([$pending, $canceled] as $delivery) {
            $response = $this->dispatch($router, $this->jwtRequest(
                'ownerWebhookP1',
                'POST',
                "/commerce/seller/{$seller['uuid']}/webhooks/{$endpointUuid}/deliveries/"
                    . "{$delivery['delivery_uuid']}/replay"
            ));
            self::assertSame(409, $response->getStatusCode());
        }
    }

    public function testReplayIsRefusedWhenEndpointIsDisabledWith422(): void
    {
        $seller = $this->seedSeller('webhook-replay-422', 'ownerWebhookP2');
        $router = $this->freshRouter();
        $endpointUuid = $this->registerEndpointViaHttp(
            $router,
            $seller['uuid'],
            'ownerWebhookP2',
            'whreplay422.example.test'
        );
        $deadLetter = $this->seedDelivery($endpointUuid, $seller['uuid'], 'dead_letter');

        $disable = $this->dispatch($router, $this->jwtRequest(
            'ownerWebhookP2',
            'POST',
            "/commerce/seller/{$seller['uuid']}/webhooks/{$endpointUuid}/disable"
        ));
        self::assertSame(200, $disable->getStatusCode());

        $replay = $this->dispatch($router, $this->jwtRequest(
            'ownerWebhookP2',
            'POST',
            "/commerce/seller/{$seller['uuid']}/webhooks/{$endpointUuid}/deliveries/"
                . "{$deadLetter['delivery_uuid']}/replay"
        ));
        self::assertSame(422, $replay->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Tombstone: a deleted endpoint disappears from the list, a mutation on
    // it is a non-revealing 404, and its retained delivery history stays
    // internally coherent (still present in the database) even though the
    // JWT surface can no longer reach it.
    // -----------------------------------------------------------------

    public function testDeletedEndpointDisappearsFromListMutationIs404AndHistoryStaysCoherent(): void
    {
        $seller = $this->seedSeller('webhook-tombstone', 'ownerWebhookT1');
        $router = $this->freshRouter();
        $endpointUuid = $this->registerEndpointViaHttp(
            $router,
            $seller['uuid'],
            'ownerWebhookT1',
            'whtombstone.example.test'
        );
        $pending = $this->seedDelivery($endpointUuid, $seller['uuid'], 'pending');
        $deadLetter = $this->seedDelivery($endpointUuid, $seller['uuid'], 'dead_letter');

        $delete = $this->dispatch($router, $this->jwtRequest(
            'ownerWebhookT1',
            'DELETE',
            "/commerce/seller/{$seller['uuid']}/webhooks/{$endpointUuid}"
        ));
        self::assertSame(200, $delete->getStatusCode());

        $list = $this->dispatch($router, $this->jwtRequest(
            'ownerWebhookT1',
            'GET',
            "/commerce/seller/{$seller['uuid']}/webhooks"
        ));
        self::assertSame([], $this->json($list)['data'], 'a deleted endpoint must disappear from the list');

        foreach ($this->mutationRoutesForEndpoint($seller['uuid'], $endpointUuid, $deadLetter['delivery_uuid'])
            as $label => [$method, $uri, $body]) {
            $response = $this->dispatch($router, $this->jwtRequest('ownerWebhookT1', $method, $uri, $body));
            self::assertSame(404, $response->getStatusCode(), "{$label}: a tombstoned endpoint must 404");
        }

        // The retained history is untouched in the database -- both rows
        // still exist, the pending one now terminally canceled by the
        // tombstone sweep, the dead_letter one left exactly as it was.
        $pendingRow = $this->deliveryRow($pending['delivery_uuid']);
        self::assertSame('canceled', $pendingRow['status']);
        $deadLetterRow = $this->deliveryRow($deadLetter['delivery_uuid']);
        self::assertSame('dead_letter', $deadLetterRow['status']);
        self::assertSame(
            2,
            (int) $this->connection->table('commerce_seller_webhook_deliveries')
                ->where('endpoint_uuid', '=', $endpointUuid)
                ->count(),
            'the tombstone must never remove a delivery row'
        );
    }

    // -----------------------------------------------------------------
    // A key can NEVER manage webhooks: an API-key request is 403 on EVERY
    // route -- even one whose scope somehow includes webhooks.manage. This
    // ALSO proves route order: an unbound key would otherwise 404 through
    // `commerce_seller`, never 403.
    // -----------------------------------------------------------------

    public function testApiKeyRequestIsRefused403OnEveryWebhookRouteEvenWithWebhooksManageInScope(): void
    {
        $seller = $this->seedSeller('webhook-gate', 'ownerWebhookG1');
        $router = $this->freshRouter();
        $scopesIncludingManage = ['commerce.seller.webhooks.manage'];

        foreach ($this->allRoutes($seller['uuid'], 'anyEndpointU1', 'anyDeliveryU1') as $label => [$method, $uri, $body]) {
            $response = $this->dispatch($router, $this->apiKeyRequest(
                'ownerWebhookG1',
                'someFrameworkKeyU2',
                $scopesIncludingManage,
                $method,
                $uri,
                $body
            ));
            self::assertSame(403, $response->getStatusCode(), "{$label}: an API-key request must be refused");
        }
    }

    public function testNonJwtProviderIsRefused403OnEveryWebhookRoute(): void
    {
        $seller = $this->seedSeller('webhook-nonjwt', 'ownerWebhookN1');
        $router = $this->freshRouter();

        foreach ($this->allRoutes($seller['uuid'], 'anyEndpointU1', 'anyDeliveryU1') as $label => [$method, $uri, $body]) {
            $response = $this->dispatch(
                $router,
                $this->sessionRequest('ownerWebhookN1', 'saml', $method, $uri, $body)
            );
            self::assertSame(403, $response->getStatusCode(), "{$label}: a non-JWT provider must be refused");
        }
    }

    public function testJwtSessionWithoutWebhooksManageCapabilityIsRefused403(): void
    {
        $seller = $this->seedSeller('webhook-nocap', 'ownerWebhookC1');
        $this->seedMembership($seller['uuid'], 'staffWebhookC1', 'seller_staff');
        $this->seedMembership($seller['uuid'], 'analystWebhkC1', 'seller_analyst');

        $router = $this->freshRouter();

        foreach (['staffWebhookC1', 'analystWebhkC1'] as $userUuid) {
            $response = $this->dispatch($router, $this->jwtRequest(
                $userUuid,
                'POST',
                "/commerce/seller/{$seller['uuid']}/webhooks",
                ['url' => 'https://whnocap.example.test/hook', 'events' => ['order.placed']]
            ));
            self::assertSame(403, $response->getStatusCode(), "{$userUuid} does not hold webhooks.manage");
        }
    }

    // -----------------------------------------------------------------
    // Own-seller-only, including the {uuid}/{deliveryUuid} chain the
    // underlying services do not themselves verify (see the controller's
    // own docblock: SellerWebhookDeliveryService::replay() derives its
    // OWN seller/endpoint straight from the delivery row).
    // -----------------------------------------------------------------

    public function testCrossSellerEndpointAndDeliveryAreRefusedNonRevealing404(): void
    {
        $sellerA = $this->seedSeller('webhook-cross-a', 'ownerWebhookXA');
        $sellerB = $this->seedSeller('webhook-cross-b', 'ownerWebhookXB');

        $router = $this->freshRouter();
        $endpointA = $this->registerEndpointViaHttp($router, $sellerA['uuid'], 'ownerWebhookXA', 'whcrossa.example.test');
        $deliveryA = $this->seedDelivery($endpointA, $sellerA['uuid'], 'dead_letter');
        $endpointB = $this->registerEndpointViaHttp($router, $sellerB['uuid'], 'ownerWebhookXB', 'whcrossb.example.test');

        // Owner B genuinely holds webhooks.manage -- on seller B, not A.
        // Targeting seller A's endpoint through seller B's OWN route
        // resource must be refused exactly like an unknown endpoint.
        $crossSellerEndpoint = $this->dispatch($router, $this->jwtRequest(
            'ownerWebhookXB',
            'POST',
            "/commerce/seller/{$sellerB['uuid']}/webhooks/{$endpointA}/rotate-secret"
        ));
        self::assertSame(404, $crossSellerEndpoint->getStatusCode());

        $crossSellerDeliveries = $this->dispatch($router, $this->jwtRequest(
            'ownerWebhookXB',
            'GET',
            "/commerce/seller/{$sellerB['uuid']}/webhooks/{$endpointA}/deliveries"
        ));
        self::assertSame(404, $crossSellerDeliveries->getStatusCode());

        // Owner B's OWN endpoint, but seller A's delivery uuid: the
        // endpoint-ownership check alone would NOT catch this -- it is
        // exactly the delivery-to-endpoint chain check that must.
        $crossSellerReplayViaOwnEndpoint = $this->dispatch($router, $this->jwtRequest(
            'ownerWebhookXB',
            'POST',
            "/commerce/seller/{$sellerB['uuid']}/webhooks/{$endpointB}/deliveries/{$deliveryA['delivery_uuid']}/replay"
        ));
        self::assertSame(404, $crossSellerReplayViaOwnEndpoint->getStatusCode());

        // Owner A has no membership at all on seller B -- refused before
        // the handler even runs, the same non-revealing 404.
        $unrelatedSeller = $this->dispatch($router, $this->jwtRequest(
            'ownerWebhookXA',
            'GET',
            "/commerce/seller/{$sellerB['uuid']}/webhooks"
        ));
        self::assertSame(404, $unrelatedSeller->getStatusCode());
    }

    public function testCrossTenantSellerIsRefusedNonRevealing404(): void
    {
        $foreignTenant = 'otherTenant9';
        $sellerUuid = 'foreignSell1';
        $this->connection->table('commerce_sellers')->insert([
            'uuid' => $sellerUuid,
            'tenant_uuid' => $foreignTenant,
            'slug' => 'foreign-seller',
            'name' => 'Foreign Seller',
            'status' => 'active',
        ]);
        $this->connection->table('commerce_seller_memberships')->insert([
            'uuid' => 'mbrForeign01',
            'tenant_uuid' => $foreignTenant,
            'seller_uuid' => $sellerUuid,
            'user_uuid' => 'ownerForeign1',
            'role' => 'seller_owner',
            'status' => 'active',
        ]);

        $router = $this->freshRouter();

        // This test's own fixed tenant is $this->tenant -- the seller row
        // above genuinely exists, but under a DIFFERENT tenant.
        $response = $this->dispatch($router, $this->jwtRequest(
            'ownerForeign1',
            'GET',
            "/commerce/seller/{$sellerUuid}/webhooks"
        ));
        self::assertSame(404, $response->getStatusCode());
    }

    public function testSuspendedSellerRefusesManagementAndReplayWith409(): void
    {
        $seller = $this->seedSeller('webhook-suspended', 'ownerWebhookS1');
        $router = $this->freshRouter();
        $endpointUuid = $this->registerEndpointViaHttp(
            $router,
            $seller['uuid'],
            'ownerWebhookS1',
            'whsuspended.example.test'
        );
        $deadLetter = $this->seedDelivery($endpointUuid, $seller['uuid'], 'dead_letter');

        $this->connection->table('commerce_sellers')->where('uuid', '=', $seller['uuid'])
            ->update(['status' => 'suspended']);

        $register = $this->dispatch($router, $this->jwtRequest(
            'ownerWebhookS1',
            'POST',
            "/commerce/seller/{$seller['uuid']}/webhooks",
            ['url' => 'https://whsuspended2.example.test/hook', 'events' => ['order.placed']]
        ));
        self::assertSame(409, $register->getStatusCode(), 'management must be refused while suspended');

        $replay = $this->dispatch($router, $this->jwtRequest(
            'ownerWebhookS1',
            'POST',
            "/commerce/seller/{$seller['uuid']}/webhooks/{$endpointUuid}/deliveries/"
                . "{$deadLetter['delivery_uuid']}/replay"
        ));
        self::assertSame(409, $replay->getStatusCode(), 'replay must be refused while suspended');
    }

    public function testUnknownEndpointRoutesAreRefused404(): void
    {
        $seller = $this->seedSeller('webhook-unknown', 'ownerWebhookU1');
        $router = $this->freshRouter();

        foreach ($this->mutationRoutesForEndpoint($seller['uuid'], 'doesNotExist1', 'doesNotExist2')
            as $label => [$method, $uri, $body]) {
            $response = $this->dispatch($router, $this->jwtRequest('ownerWebhookU1', $method, $uri, $body));
            self::assertSame(404, $response->getStatusCode(), "{$label}: an unknown endpoint must 404");
        }
    }

    // -----------------------------------------------------------------
    // Fixtures + helpers.
    // -----------------------------------------------------------------

    private function registerEndpointViaHttp(
        \Glueful\Routing\Router $router,
        string $sellerUuid,
        string $ownerUuid,
        string $host
    ): string {
        $response = $this->dispatch($router, $this->jwtRequest(
            $ownerUuid,
            'POST',
            "/commerce/seller/{$sellerUuid}/webhooks",
            ['url' => "https://{$host}/hook", 'events' => ['order.placed']]
        ));
        self::assertSame(201, $response->getStatusCode());

        return $this->json($response)['data']['uuid'];
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array{delivery_uuid: string, event_uuid: string}
     */
    private function seedDelivery(
        string $endpointUuid,
        string $sellerUuid,
        string $status,
        array $overrides = []
    ): array {
        $eventUuid = $this->nextId('e');
        $deliveryUuid = $this->nextId('d');

        $this->connection->table('commerce_seller_webhook_events')->insert([
            'uuid' => $eventUuid,
            'tenant_uuid' => $this->tenant,
            'seller_uuid' => $sellerUuid,
            'event_type' => 'order.placed',
            'payload' => (string) json_encode(['order_uuid' => 'ordFixture1'], JSON_THROW_ON_ERROR),
            'occurred_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $row = array_merge([
            'uuid' => $deliveryUuid,
            'tenant_uuid' => $this->tenant,
            'endpoint_uuid' => $endpointUuid,
            'webhook_event_uuid' => $eventUuid,
            'seller_uuid' => $sellerUuid,
            'status' => $status,
            'attempts' => 0,
        ], $overrides);

        $this->connection->table('commerce_seller_webhook_deliveries')->insert($row);

        return ['delivery_uuid' => $deliveryUuid, 'event_uuid' => $eventUuid];
    }

    private function nextId(string $prefix): string
    {
        $this->seq++;

        return $prefix . str_pad((string) $this->seq, 11, '0', STR_PAD_LEFT);
    }

    /** @return array<string,mixed> */
    private function endpointRow(string $uuid): array
    {
        $row = $this->connection->table('commerce_seller_webhook_endpoints')->where('uuid', '=', $uuid)->first();
        self::assertNotNull($row);

        return $row;
    }

    /** @return array<string,mixed> */
    private function deliveryRow(string $uuid): array
    {
        $row = $this->connection->table('commerce_seller_webhook_deliveries')->where('uuid', '=', $uuid)->first();
        self::assertNotNull($row);

        return $row;
    }

    private function assertNoInternalAddressLeak(string $body): void
    {
        foreach (['10.0.0.', '169.254.', '127.0.0.1', '192.168.'] as $needle) {
            self::assertStringNotContainsString(
                $needle,
                $body,
                "response must never leak an internal address; got: {$body}"
            );
        }
    }

    /**
     * Every management + delivery-history + replay route this surface
     * exposes, keyed by a readable label -- shared by the API-key-refusal
     * and non-JWT-provider-refusal tests so every route is covered
     * identically (mirrors {@see SellerApiKeySurfaceTest::managementRoutes()}).
     *
     * @return array<string, array{0: string, 1: string, 2: array<string,mixed>}>
     */
    private function allRoutes(string $sellerUuid, string $endpointUuid, string $deliveryUuid): array
    {
        $body = ['url' => 'https://whgate.example.test/hook', 'events' => ['order.placed']];

        return [
            'register' => ['POST', "/commerce/seller/{$sellerUuid}/webhooks", $body],
            'list' => ['GET', "/commerce/seller/{$sellerUuid}/webhooks", []],
        ] + $this->mutationRoutesForEndpoint($sellerUuid, $endpointUuid, $deliveryUuid);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string,mixed>}>
     */
    private function mutationRoutesForEndpoint(string $sellerUuid, string $endpointUuid, string $deliveryUuid): array
    {
        $prefix = "/commerce/seller/{$sellerUuid}/webhooks/{$endpointUuid}";

        return [
            'update' => ['PATCH', $prefix, ['events' => ['order.paid']]],
            'rotate-secret' => ['POST', "{$prefix}/rotate-secret", []],
            'disable' => ['POST', "{$prefix}/disable", []],
            'enable' => ['POST', "{$prefix}/enable", []],
            'delete' => ['DELETE', $prefix, []],
            'deliveries' => ['GET', "{$prefix}/deliveries", []],
            'replay' => ['POST', "{$prefix}/deliveries/{$deliveryUuid}/replay", []],
        ];
    }

    /**
     * Extends {@see CommerceRouterTestCase::bindFakeAuth()}'s `X-Test-User`
     * session convention with the auth-PROVIDER signal this surface's gate
     * depends on -- mirrors {@see SellerApiKeySurfaceTest::bindFakeAuthWithProviderAndApiKeySupport()}
     * verbatim (same fake auth contract, a different surface).
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
