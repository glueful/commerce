<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\Admin\AdminReportController;
use Glueful\Extensions\Commerce\Http\DTOs\ReportWindowQuery;
use Glueful\Extensions\Commerce\Reports\SalesReportRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * `GET /commerce/admin/reports/sales` (design spec §4.1, Layer 5 decisions
 * 3-8): revenue-status gross/net/AOV, refund bucketing on `completed_at`
 * independent of the order's own window membership, PHP-side week/month
 * rollup + zero-fill via `ReportRollup`, and a summary whose additive fields
 * are summed from the series while `aov_minor` is recomputed (never summed).
 */
final class SalesReportEndpointTest extends CommerceTestCase
{
    private const TENANT = 'tenantsales1';

    public function testHandComputedTotalsAcrossDaysAndStatusExclusions(): void
    {
        // Day 2026-06-01: two revenue orders + one pending + one canceled.
        $this->seedOrder('salesordA001', 'paid', 1000, '2026-06-01 09:00:00', discountTotal: 100, shippingTotal: 50, taxTotal: 20);
        $this->seedOrder('salesordB001', 'fulfilled', 2000, '2026-06-01 15:00:00');
        $this->seedOrder('salesordC001', 'pending_payment', 500, '2026-06-01 08:00:00');
        $this->seedOrder('salesordD001', 'canceled', 300, '2026-06-01 07:00:00');

        // Day 2026-06-02: one revenue order, partially refunded the same day.
        $this->seedOrder('salesordE001', 'refunded', 800, '2026-06-02 10:00:00');
        $this->seedRefund('salesrefF001', 'salesordE001', 200, 'completed', '2026-06-02 12:00:00');

        $body = $this->call('2026-06-01', '2026-06-03');
        $series = $this->seriesByBucket($body);

        self::assertCount(3, $body['data']['series']);

        self::assertSame(3000, $series['2026-06-01']['gross_minor']);
        self::assertSame(2, $series['2026-06-01']['orders_count']);
        self::assertSame(0, $series['2026-06-01']['refunds_minor']);
        self::assertSame(3000, $series['2026-06-01']['net_minor']);
        self::assertSame(1500, $series['2026-06-01']['aov_minor']);

        self::assertSame(800, $series['2026-06-02']['gross_minor']);
        self::assertSame(1, $series['2026-06-02']['orders_count']);
        self::assertSame(200, $series['2026-06-02']['refunds_minor']);
        self::assertSame(600, $series['2026-06-02']['net_minor']);
        self::assertSame(800, $series['2026-06-02']['aov_minor']);

        self::assertSame(0, $series['2026-06-03']['gross_minor']);
        self::assertSame(0, $series['2026-06-03']['orders_count']);
        self::assertSame(0, $series['2026-06-03']['refunds_minor']);
        self::assertSame(0, $series['2026-06-03']['net_minor']);
        self::assertSame(0, $series['2026-06-03']['aov_minor']);

        $summary = $body['data']['summary'];
        self::assertSame(3800, $summary['gross_minor']);
        self::assertSame(200, $summary['refunds_minor']);
        self::assertSame(3600, $summary['net_minor']);
        self::assertSame(3, $summary['orders_count']);
        self::assertSame(1267, $summary['aov_minor']);
        self::assertSame(1, $summary['pending_orders']);
        self::assertSame(100, $summary['discount_minor']);
        self::assertSame(50, $summary['shipping_minor']);
        self::assertSame(20, $summary['tax_minor']);

        // Additive fields sum from the series to the summary...
        self::assertSame(
            $summary['gross_minor'],
            array_sum(array_column($body['data']['series'], 'gross_minor'))
        );
        self::assertSame(
            $summary['refunds_minor'],
            array_sum(array_column($body['data']['series'], 'refunds_minor'))
        );
        self::assertSame(
            $summary['orders_count'],
            array_sum(array_column($body['data']['series'], 'orders_count'))
        );
        // ...but AOV is recomputed, not summed -- summing the bucket AOVs (1500+800+0=2300)
        // would give a materially different (wrong) number than the correct window AOV.
        self::assertNotSame(
            array_sum(array_column($body['data']['series'], 'aov_minor')),
            $summary['aov_minor']
        );

        self::assertSame('USD', $body['data']['currency']);
        self::assertSame('2026-06-01', $body['data']['window']['from']);
        self::assertSame('2026-06-03', $body['data']['window']['to']);
        self::assertSame('day', $body['data']['window']['group']);
    }

    public function testMayOrderJuneRefundLandsInJunesBucketNotMays(): void
    {
        // Order placed in May -- outside the June window entirely.
        $this->seedOrder('salesordG001', 'paid', 1000, '2026-05-15 08:00:00');
        // Its refund completes in June -- decision 5: refunds bucket on completed_at,
        // independent of the order's own report window membership.
        $this->seedRefund('salesrefG001', 'salesordG001', 300, 'completed', '2026-06-05 09:00:00');

        $body = $this->call('2026-06-01', '2026-06-30');
        $series = $this->seriesByBucket($body);

        self::assertSame(0, $series['2026-06-05']['gross_minor']);
        self::assertSame(0, $series['2026-06-05']['orders_count']);
        self::assertSame(300, $series['2026-06-05']['refunds_minor']);
        self::assertSame(-300, $series['2026-06-05']['net_minor']);

        $summary = $body['data']['summary'];
        self::assertSame(0, $summary['gross_minor']);
        self::assertSame(300, $summary['refunds_minor']);
        self::assertSame(-300, $summary['net_minor']);
        self::assertSame(0, $summary['orders_count']);
        self::assertSame(0, $summary['aov_minor']);
    }

    public function testLegacyOrderWithNullPlacedAtIsCountedViaTheCreatedAtBranch(): void
    {
        $this->seedOrder('salesordH001', 'paid', 400, null, createdAt: '2026-06-11 05:00:00');

        $body = $this->call('2026-06-10', '2026-06-12');
        $series = $this->seriesByBucket($body);

        self::assertSame(0, $series['2026-06-10']['gross_minor']);
        self::assertSame(400, $series['2026-06-11']['gross_minor']);
        self::assertSame(1, $series['2026-06-11']['orders_count']);
        self::assertSame(0, $series['2026-06-12']['gross_minor']);
    }

    public function testWeekGroupRollsUpDifferentDaysOfTheSameIsoWeek(): void
    {
        // 2026-06-15 (Monday) .. 2026-06-21 (Sunday) is exactly ISO week 2026-W25.
        $this->seedOrder('salesordI001', 'paid', 100, '2026-06-15 08:00:00');
        $this->seedOrder('salesordJ001', 'paid', 250, '2026-06-17 08:00:00');

        $body = $this->call('2026-06-15', '2026-06-21', 'week');

        self::assertCount(1, $body['data']['series']);
        $bucket = $body['data']['series'][0];
        self::assertSame('2026-W25', $bucket['bucket']);
        self::assertSame(350, $bucket['gross_minor']);
        self::assertSame(2, $bucket['orders_count']);
        self::assertSame(350, $bucket['net_minor']);
        self::assertSame(175, $bucket['aov_minor']);
        self::assertSame('week', $body['data']['window']['group']);
    }

    public function testMonthGroupRollsUpDifferentDaysOfTheSameCalendarMonth(): void
    {
        $this->seedOrder('salesordK001', 'paid', 500, '2026-07-05 08:00:00');
        $this->seedOrder('salesordL001', 'paid', 700, '2026-07-20 08:00:00');

        $body = $this->call('2026-07-01', '2026-07-31', 'month');

        self::assertCount(1, $body['data']['series']);
        $bucket = $body['data']['series'][0];
        self::assertSame('2026-07', $bucket['bucket']);
        self::assertSame(1200, $bucket['gross_minor']);
        self::assertSame(2, $bucket['orders_count']);
        self::assertSame(600, $bucket['aov_minor']);
        self::assertSame('month', $body['data']['window']['group']);
    }

    public function testTenantIsolationReturnsDisjointResults(): void
    {
        $this->seedOrder('salesordM001', 'paid', 100, '2026-06-01 08:00:00', tenant: 'tenantsalesA');
        $this->seedOrder('salesordN001', 'paid', 999, '2026-06-01 08:00:00', tenant: 'tenantsalesB');

        $body = $this->call('2026-06-01', '2026-06-01', tenant: 'tenantsalesA');

        self::assertSame(100, $body['data']['summary']['gross_minor']);
        self::assertSame(1, $body['data']['summary']['orders_count']);
    }

    public function testEmptyWindowReturnsAllZeroZeroFilledSeries(): void
    {
        $body = $this->call('2026-08-01', '2026-08-02');

        self::assertCount(2, $body['data']['series']);
        foreach ($body['data']['series'] as $bucket) {
            self::assertSame(0, $bucket['gross_minor']);
            self::assertSame(0, $bucket['refunds_minor']);
            self::assertSame(0, $bucket['net_minor']);
            self::assertSame(0, $bucket['orders_count']);
            self::assertSame(0, $bucket['aov_minor']);
        }

        $summary = $body['data']['summary'];
        self::assertSame(0, $summary['gross_minor']);
        self::assertSame(0, $summary['refunds_minor']);
        self::assertSame(0, $summary['net_minor']);
        self::assertSame(0, $summary['orders_count']);
        self::assertSame(0, $summary['aov_minor']);
        self::assertSame(0, $summary['pending_orders']);
        self::assertSame(0, $summary['discount_minor']);
        self::assertSame(0, $summary['shipping_minor']);
        self::assertSame(0, $summary['tax_minor']);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /** @return array<string,mixed> */
    private function call(string $from, string $to, string $group = 'day', string $tenant = self::TENANT): array
    {
        $response = $this->controller($tenant)->sales(
            new ReportWindowQuery(from: $from, to: $to, group: $group),
            Request::create('/x', 'GET')
        );

        self::assertSame(200, $response->getStatusCode());

        return $this->json($response);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string, array<string,mixed>>
     */
    private function seriesByBucket(array $body): array
    {
        $indexed = [];
        foreach ($body['data']['series'] as $bucket) {
            $indexed[$bucket['bucket']] = $bucket;
        }

        return $indexed;
    }

    private function controller(string $tenant = self::TENANT): AdminReportController
    {
        return new AdminReportController(
            $this->context,
            new SalesReportRepository(),
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

    private function seedOrder(
        string $uuid,
        string $status,
        int $grandTotal,
        ?string $placedAt,
        ?string $createdAt = null,
        int $discountTotal = 0,
        int $shippingTotal = 0,
        int $taxTotal = 0,
        string $tenant = self::TENANT,
    ): void {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_number' => 'ORD-' . $uuid,
            'status' => $status,
            'email' => 'buyer@example.com',
            'user_uuid' => null,
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => $grandTotal,
            'discount_total' => $discountTotal,
            'shipping_total' => $shippingTotal,
            'tax_total' => $taxTotal,
            'grand_total' => $grandTotal,
            'placed_at' => $placedAt,
            'created_at' => $createdAt ?? ($placedAt ?? '2026-01-01 00:00:00'),
        ]);
    }

    private function seedRefund(
        string $uuid,
        string $orderUuid,
        int $amount,
        string $status,
        ?string $completedAt,
        string $tenant = self::TENANT,
    ): void {
        $this->connection->table('commerce_refunds')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_uuid' => $orderUuid,
            'idempotency_key' => 'idem-' . $uuid,
            'request_fingerprint' => str_repeat('f', 40),
            'amount' => $amount,
            'currency' => 'USD',
            'method' => 'manual',
            'status' => $status,
            'completed_at' => $completedAt,
        ]);
    }

    /** @return array<string,mixed> */
    private function json(HttpResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
