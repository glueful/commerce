<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\Admin\AdminRefundController;
use Glueful\Extensions\Commerce\Http\DTOs\RefundListQuery;
use Glueful\Extensions\Commerce\Inventory\StockRepository;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundRepository;
use Glueful\Extensions\Commerce\Orders\Refunds\RefundService;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Cross-order admin refunds list (design spec Layer 6 §2 decision 4, new
 * `GET /refunds`) + show (`GET /refunds/{uuid}`). Filters: `status`, `order`
 * (order uuid), `from`/`to` half-open `Y-m-d` bounds on `completed_at`; pending/
 * failed rows carry no `completed_at` and therefore never match a
 * date-bounded request. Per-order refunds ({@see RefundEndpointTest}) are
 * unaffected -- this is the separate, unbounded, cross-order surface.
 */
final class RefundsListTest extends CommerceTestCase
{
    public function testListOnlyReturnsOwnTenantWithPaginationEnvelope(): void
    {
        $this->seedRefund('rfndlist0001', 'order00000001', 'completed', '2026-01-05 00:00:00');
        $this->seedRefund('rfndlist0002', 'order00000002', 'completed', '2026-01-06 00:00:00');
        $this->seedRefund('rfndlist0003', 'order00000003', 'completed', '2026-01-06 00:00:00', 'tenant-b');

        $response = $this->controller()->list(new RefundListQuery(), Request::create('/x'));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame(2, $body['total']);
        self::assertCount(2, $body['data']);
        self::assertSame(24, $body['per_page']);
    }

    public function testListFiltersByStatus(): void
    {
        $this->seedRefund('rfndlist0011', 'order00000011', 'completed', '2026-01-05 00:00:00');
        $this->seedRefund('rfndlist0012', 'order00000012', 'pending', null);

        $response = $this->controller()->list(new RefundListQuery(status: 'pending'), Request::create('/x'));

        $body = $this->json($response);
        self::assertSame(1, $body['total']);
        self::assertSame('rfndlist0012', $body['data'][0]['uuid']);
    }

    public function testListFiltersByOrderUuid(): void
    {
        $this->seedRefund('rfndlist0021', 'order00000021', 'completed', '2026-01-05 00:00:00');
        $this->seedRefund('rfndlist0022', 'order00000022', 'completed', '2026-01-05 00:00:00');

        $response = $this->controller()->list(
            new RefundListQuery(order: 'order00000021'),
            Request::create('/x')
        );

        $body = $this->json($response);
        self::assertSame(1, $body['total']);
        self::assertSame('rfndlist0021', $body['data'][0]['uuid']);
    }

    public function testListFromBoundIsInclusive(): void
    {
        $this->seedRefund('rfndlist0031', 'order00000031', 'completed', '2026-01-05 00:00:00');
        $this->seedRefund('rfndlist0032', 'order00000032', 'completed', '2026-01-04 23:59:59');

        $response = $this->controller()->list(new RefundListQuery(from: '2026-01-05'), Request::create('/x'));

        $body = $this->json($response);
        self::assertSame(1, $body['total']);
        self::assertSame('rfndlist0031', $body['data'][0]['uuid']);
    }

    public function testListToBoundIsInclusiveOfTheWholeDayHalfOpen(): void
    {
        $this->seedRefund('rfndlist0041', 'order00000041', 'completed', '2026-01-05 23:59:59');
        $this->seedRefund('rfndlist0042', 'order00000042', 'completed', '2026-01-06 00:00:00');

        $response = $this->controller()->list(new RefundListQuery(to: '2026-01-05'), Request::create('/x'));

        $body = $this->json($response);
        self::assertSame(1, $body['total']);
        self::assertSame('rfndlist0041', $body['data'][0]['uuid']);
    }

    public function testListFromAndToTogetherBoundARange(): void
    {
        $this->seedRefund('rfndlist0051', 'order00000051', 'completed', '2026-01-01 00:00:00');
        $this->seedRefund('rfndlist0052', 'order00000052', 'completed', '2026-01-05 00:00:00');
        $this->seedRefund('rfndlist0053', 'order00000053', 'completed', '2026-01-10 00:00:00');

        $response = $this->controller()->list(
            new RefundListQuery(from: '2026-01-02', to: '2026-01-06'),
            Request::create('/x')
        );

        $body = $this->json($response);
        self::assertSame(1, $body['total']);
        self::assertSame('rfndlist0052', $body['data'][0]['uuid']);
    }

    public function testPendingAndFailedRefundsNeverMatchADateBoundedRequestBecauseTheyLackCompletedAt(): void
    {
        $this->seedRefund('rfndlist0061', 'order00000061', 'pending', null);
        $this->seedRefund('rfndlist0062', 'order00000062', 'failed', null);
        $this->seedRefund('rfndlist0063', 'order00000063', 'completed', '2026-01-05 00:00:00');

        $response = $this->controller()->list(
            new RefundListQuery(from: '2000-01-01', to: '2100-01-01'),
            Request::create('/x')
        );

        $body = $this->json($response);
        self::assertSame(1, $body['total']);
        self::assertSame('rfndlist0063', $body['data'][0]['uuid']);
    }

    public function testListOrdersByCreatedAtDescWithStableUuidTieBreak(): void
    {
        $tiedAt = '2026-01-01 00:00:00';
        $this->seedRefund('rfndtie00002', 'order00000071', 'completed', $tiedAt, '', $tiedAt);
        $this->seedRefund('rfndtie00001', 'order00000072', 'completed', $tiedAt, '', $tiedAt);

        $response = $this->controller()->list(new RefundListQuery(), Request::create('/x'));
        $uuids = array_column($this->json($response)['data'], 'uuid');

        self::assertSame(['rfndtie00001', 'rfndtie00002'], $uuids);
    }

    public function testListPaginatesWithClamp(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->seedRefund('rfndpage000' . $i, 'order0000008' . $i, 'completed', '2026-01-0' . ($i + 1) . ' 00:00:00');
        }

        $response = $this->controller()->list(
            new RefundListQuery(page: 1, per_page: 2),
            Request::create('/x')
        );

        $body = $this->json($response);
        self::assertSame(3, $body['total']);
        self::assertCount(2, $body['data']);
    }

    // === show ==================================================================

    public function testShowReturnsRefund(): void
    {
        $uuid = $this->seedRefund('rfndshow0001', 'order00000091', 'completed', '2026-01-05 00:00:00');

        $response = $this->controller()->show(Request::create('/x'), $uuid);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('order00000091', $this->json($response)['data']['order_uuid']);
    }

    public function testShowUnknownRefundThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->show(Request::create('/x'), 'no-such-refund');
    }

    public function testShowCrossTenantRefundThrowsNotFound(): void
    {
        $uuid = $this->seedRefund('rfndshow0002', 'order00000092', 'completed', '2026-01-05 00:00:00', 'tenant-b');

        $this->expectException(NotFoundException::class);
        $this->controller()->show(Request::create('/x'), $uuid);
    }

    // === Helpers ===============================================================

    private function seedRefund(
        string $uuid,
        string $orderUuid,
        string $status,
        ?string $completedAt,
        string $tenant = '',
        ?string $createdAt = null
    ): string {
        $row = [
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_uuid' => $orderUuid,
            'idempotency_key' => 'idem-' . $uuid,
            'request_fingerprint' => 'fp-' . $uuid,
            'amount' => 1000,
            'currency' => 'USD',
            'method' => 'manual',
            'status' => $status,
            'restocked' => false,
            'completed_at' => $completedAt,
        ];
        if ($createdAt !== null) {
            $row['created_at'] = $createdAt;
        }
        $this->connection->table('commerce_refunds')->insert($row);

        return $uuid;
    }

    private function controller(string $tenant = ''): AdminRefundController
    {
        $tenants = $tenant === '' ? new SentinelTenantResolver() : $this->fixedTenant($tenant);

        return new AdminRefundController(
            $this->context,
            new OrderRepository(),
            new RefundRepository(),
            new RefundService(new OrderRepository(), new RefundRepository(), new StockRepository(), $tenants),
            $tenants
        );
    }

    private function fixedTenant(string $tenant): CurrentTenantResolver
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

    /** @return array<string,mixed> */
    private function json(HttpResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
