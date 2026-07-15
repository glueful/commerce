<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Downloads;

use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\Admin\AdminGrantController;
use Glueful\Extensions\Commerce\Orders\Downloads\DownloadGrantRepository;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Support\TokenHasher;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * `AdminGrantController` (carried forward from Task 5, design spec §8): revoke
 * and refund-access override set/clear. Every action is a single guarded
 * UPDATE; a repeat call against an already-in-target-state grant is a 409
 * (this codebase's house pattern for a one-shot transition, see
 * {@see \Glueful\Extensions\Commerce\Catalog\ReviewService}), never a silent
 * idempotent 200. Every successful mutation appends an actor-bearing,
 * internal-visibility order event with no token/hash/blob uuid.
 */
final class AdminGrantControllerTest extends CommerceTestCase
{
    // -----------------------------------------------------------------
    // Revoke
    // -----------------------------------------------------------------

    public function testRevokeSucceedsAndAppendsActorBearingInternalEventWithoutSecrets(): void
    {
        $this->seedOrder('ordergrantr1');
        $grantUuid = $this->seedGrant('ordergrantr1', 'grantr00001', 'blobr0000001', name: 'Ebook.pdf');

        $request = Request::create('/x', 'POST');
        $request->attributes->set('auth.user', new UserIdentity(uuid: 'actorrevoke1'));

        $response = $this->controller()->revoke($request, $grantUuid);
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($this->grantRow($grantUuid)['revoked_at']);
        self::assertArrayNotHasKey('token_hash', $body['data']);
        self::assertArrayNotHasKey('blob_uuid', $body['data']);

        $event = $this->eventFor('ordergrantr1', 'download.grant_revoked');
        self::assertNotNull($event);
        self::assertSame('actorrevoke1', $event['actor_uuid']);
        self::assertSame('internal', $event['visibility']);
        self::assertSame(['grant_uuid' => $grantUuid, 'name' => 'Ebook.pdf'], $event['payload']);
        self::assertStringNotContainsString('blobr0000001', (string) json_encode($event));
    }

    public function testRevokingAnAlreadyRevokedGrantReturns409AndDoesNotAppendASecondEvent(): void
    {
        $this->seedOrder('ordergrantr2');
        $grantUuid = $this->seedGrant('ordergrantr2', 'grantr00002', 'blobr0000002');

        $first = $this->controller()->revoke(Request::create('/x', 'POST'), $grantUuid);
        self::assertSame(200, $first->getStatusCode());

        $second = $this->controller()->revoke(Request::create('/x', 'POST'), $grantUuid);
        self::assertSame(409, $second->getStatusCode());

        $events = $this->connection->table('commerce_order_events')
            ->where('order_uuid', '=', 'ordergrantr2')
            ->where('type', '=', 'download.grant_revoked')
            ->get();
        self::assertCount(1, $events);
    }

    public function testRevokeUnknownGrantReturns404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->revoke(Request::create('/x', 'POST'), 'no-such-grant');
    }

    public function testRevokeCrossTenantGrantReturns404NonRevealing(): void
    {
        $this->seedOrder('ordergrantr3', tenant: 'tenantAAAA01');
        $grantUuid = $this->seedGrant('ordergrantr3', 'grantr00003', 'blobr0000003', tenant: 'tenantAAAA01');

        $this->expectException(NotFoundException::class);
        $this->controller('tenantBBBB02')->revoke(Request::create('/x', 'POST'), $grantUuid);
    }

    // -----------------------------------------------------------------
    // Refund-access override: set
    // -----------------------------------------------------------------

    public function testSetOverrideSucceedsRecordsActorAndAppendsEvent(): void
    {
        $this->seedOrder('ordergranto1');
        $grantUuid = $this->seedGrant('ordergranto1', 'granto00001', 'bloboo000001', name: 'Video.mp4');

        $request = Request::create('/x', 'PUT');
        $request->attributes->set('auth.user', new UserIdentity(uuid: 'actoroverride'));

        $response = $this->controller()->setOverride($request, $grantUuid);

        self::assertSame(200, $response->getStatusCode());
        $grant = $this->grantRow($grantUuid);
        self::assertNotNull($grant['refund_access_override_at']);
        self::assertSame('actoroverride', $grant['refund_access_override_by']);

        $event = $this->eventFor('ordergranto1', 'download.override_set');
        self::assertNotNull($event);
        self::assertSame('actoroverride', $event['actor_uuid']);
        self::assertSame('internal', $event['visibility']);
        self::assertSame(['grant_uuid' => $grantUuid, 'name' => 'Video.mp4'], $event['payload']);
    }

    public function testSettingOverrideTwiceReturns409(): void
    {
        $this->seedOrder('ordergranto2');
        $grantUuid = $this->seedGrant('ordergranto2', 'granto00002', 'bloboo000002');

        $first = $this->controller()->setOverride(Request::create('/x', 'PUT'), $grantUuid);
        self::assertSame(200, $first->getStatusCode());

        $second = $this->controller()->setOverride(Request::create('/x', 'PUT'), $grantUuid);
        self::assertSame(409, $second->getStatusCode());
    }

    public function testSetOverrideUnknownGrantReturns404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->setOverride(Request::create('/x', 'PUT'), 'no-such-grant');
    }

    // -----------------------------------------------------------------
    // Refund-access override: clear
    // -----------------------------------------------------------------

    public function testClearOverrideSucceedsAndAppendsEvent(): void
    {
        $this->seedOrder('ordergrantc1');
        $grantUuid = $this->seedGrant(
            'ordergrantc1',
            'grantc00001',
            'blobcc000001',
            name: 'Album.zip',
            overrideAt: '2026-01-01 00:00:00',
            overrideBy: 'previousactor'
        );

        $response = $this->controller()->clearOverride(Request::create('/x', 'DELETE'), $grantUuid);

        self::assertSame(200, $response->getStatusCode());
        $grant = $this->grantRow($grantUuid);
        self::assertNull($grant['refund_access_override_at']);
        self::assertNull($grant['refund_access_override_by']);

        $event = $this->eventFor('ordergrantc1', 'download.override_cleared');
        self::assertNotNull($event);
        self::assertSame(['grant_uuid' => $grantUuid, 'name' => 'Album.zip'], $event['payload']);
    }

    public function testClearingAnAlreadyClearedOverrideReturns409(): void
    {
        $this->seedOrder('ordergrantc2');
        $grantUuid = $this->seedGrant('ordergrantc2', 'grantc00002', 'blobcc000002');

        $response = $this->controller()->clearOverride(Request::create('/x', 'DELETE'), $grantUuid);

        self::assertSame(409, $response->getStatusCode());
    }

    public function testClearOverrideUnknownGrantReturns404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->clearOverride(Request::create('/x', 'DELETE'), 'no-such-grant');
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function controller(string $tenant = ''): AdminGrantController
    {
        return new AdminGrantController(
            $this->context,
            new DownloadGrantRepository(),
            new OrderRepository(),
            $this->tenantResolver($tenant)
        );
    }

    private function tenantResolver(string $tenant): CurrentTenantResolver
    {
        return new class ($tenant) implements CurrentTenantResolver {
            public function __construct(private string $tenant)
            {
            }

            public function tenantUuid(ApplicationContext $context): string
            {
                return $this->tenant;
            }
        };
    }

    private function seedOrder(string $uuid, string $tenant = ''): void
    {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_number' => 'ORD-' . $uuid,
            'status' => 'paid',
            'email' => 'buyer@example.com',
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => 1000,
            'grand_total' => 1000,
        ]);
    }

    private function seedGrant(
        string $orderUuid,
        string $uuid,
        string $blobUuid,
        string $tenant = '',
        string $name = 'File.pdf',
        ?string $overrideAt = null,
        ?string $overrideBy = null,
    ): string {
        $this->connection->table('commerce_download_grants')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_uuid' => $orderUuid,
            'download_uuid' => 'dl' . substr($uuid, 0, 10),
            'blob_uuid' => $blobUuid,
            'name' => $name,
            'token_hash' => TokenHasher::generate()['hash'],
            'remaining' => 5,
            'refund_access_override_at' => $overrideAt,
            'refund_access_override_by' => $overrideBy,
        ]);

        return $uuid;
    }

    /** @return array<string,mixed> */
    private function grantRow(string $uuid, string $tenant = ''): array
    {
        $row = $this->connection->table('commerce_download_grants')
            ->where('tenant_uuid', '=', $tenant)
            ->where('uuid', '=', $uuid)
            ->first();
        self::assertNotNull($row);

        return $row;
    }

    /** @return array<string,mixed>|null */
    private function eventFor(string $orderUuid, string $type): ?array
    {
        $row = $this->connection->table('commerce_order_events')
            ->where('order_uuid', '=', $orderUuid)
            ->where('type', '=', $type)
            ->first();

        if ($row === null) {
            return null;
        }

        $decoded = json_decode((string) $row['payload'], true);
        $row['payload'] = is_array($decoded) ? $decoded : null;

        return $row;
    }

    /** @return array<string,mixed> */
    private function json(HttpResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
