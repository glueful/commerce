<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Integration\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\Admin\AdminReportController;
use Glueful\Extensions\Commerce\Http\DTOs\ReportWindowQuery;
use Glueful\Extensions\Commerce\Reports\CustomerReportRepository;
use Glueful\Extensions\Commerce\Reports\ProductSalesReportRepository;
use Glueful\Extensions\Commerce\Reports\ReportWindow;
use Glueful\Extensions\Commerce\Reports\SalesReportRepository;
use Glueful\Extensions\Commerce\Tests\Support\CommerceTestCase;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * `GET /commerce/admin/reports/customers` (design spec §4.3, decision 9): new
 * vs returning customer classification reusing
 * `CustomerAggregationRepository::KEY_EXPR` verbatim, DB-side bounded distinct
 * aggregates (never `(days x customers)`), and an independently-computed
 * window summary that is NOT derived by summing the per-bucket series.
 */
final class CustomersReportEndpointTest extends CommerceTestCase
{
    private const TENANT = 'tenantcust01';

    public function testKeyIsNewInExactlyOneBucketAcrossAMultiDayWindow(): void
    {
        // Same key (user) orders on day 1 (its first-ever order) and again on day 3.
        $this->seedOrder('custordA0001', 'paid', '2026-06-01 09:00:00', userUuid: 'useralpha001');
        $this->seedOrder('custordA0002', 'paid', '2026-06-03 09:00:00', userUuid: 'useralpha001');

        $body = $this->call('2026-06-01', '2026-06-03');
        $series = $this->seriesByBucket($body);

        self::assertSame(1, $series['2026-06-01']['new_customers']);
        self::assertSame(0, $series['2026-06-01']['returning_customers']);

        self::assertSame(0, $series['2026-06-02']['new_customers']);
        self::assertSame(0, $series['2026-06-02']['returning_customers']);

        self::assertSame(0, $series['2026-06-03']['new_customers']);
        self::assertSame(1, $series['2026-06-03']['returning_customers']);
    }

    public function testGuestEmailKeyDiffersFromUserUuidKeyForTheSameEmailAddress(): void
    {
        // Two orders share the exact same email string, but one is an
        // authenticated purchase (user_uuid set) and the other is a guest
        // checkout (user_uuid null) -- these are two DIFFERENT keys; the
        // user key wins over the email key whenever user_uuid is present.
        $this->seedOrder('custordB0001', 'paid', '2026-06-01 09:00:00', userUuid: 'userbeta0001', email: 'shared@example.com');
        $this->seedOrder('custordB0002', 'paid', '2026-06-01 10:00:00', userUuid: null, email: 'shared@example.com');

        $body = $this->call('2026-06-01', '2026-06-01');

        self::assertSame(2, $body['data']['summary']['new_customers']);
        self::assertSame(0, $body['data']['summary']['returning_customers']);
        self::assertSame(2, $body['data']['summary']['total_customers']);
    }

    public function testGuestEmailIsNormalizedByCaseAndSurroundingWhitespace(): void
    {
        // ' Bob@X.COM ' and 'bob@x.com' must resolve to the exact same key.
        $this->seedOrder('custordC0001', 'paid', '2026-06-01 09:00:00', userUuid: null, email: ' Bob@X.COM ');
        $this->seedOrder('custordC0002', 'paid', '2026-06-02 09:00:00', userUuid: null, email: 'bob@x.com');

        $body = $this->call('2026-06-01', '2026-06-02');
        $series = $this->seriesByBucket($body);

        self::assertSame(1, $series['2026-06-01']['new_customers']);
        self::assertSame(0, $series['2026-06-01']['returning_customers']);
        self::assertSame(0, $series['2026-06-02']['new_customers']);
        self::assertSame(1, $series['2026-06-02']['returning_customers']);

        self::assertSame(1, $body['data']['summary']['total_customers']);
    }

    public function testPreWindowFirstOrderMakesTheInWindowOrderReturningInSeriesAndSummary(): void
    {
        // First-ever order is in May, well before the queried June window.
        $this->seedOrder('custordD0001', 'paid', '2026-05-01 09:00:00', userUuid: 'userdelta001');
        // The only order inside the window -- must be classified "returning".
        $this->seedOrder('custordD0002', 'paid', '2026-06-05 09:00:00', userUuid: 'userdelta001');

        $body = $this->call('2026-06-01', '2026-06-30');
        $series = $this->seriesByBucket($body);

        self::assertSame(0, $series['2026-06-05']['new_customers']);
        self::assertSame(1, $series['2026-06-05']['returning_customers']);

        self::assertSame(0, $body['data']['summary']['new_customers']);
        self::assertSame(1, $body['data']['summary']['returning_customers']);
        self::assertSame(1, $body['data']['summary']['total_customers']);
    }

    public function testSameCustomerOrderingTwiceInOneWeekIsCountedOnceNeverBothNewAndReturning(): void
    {
        // 2026-06-15 (Monday) .. 2026-06-21 (Sunday) is exactly ISO week 2026-W25.
        // Both orders (and this key's all-time first order) fall inside this
        // single week -- the key must be "new" exactly once, never counted as
        // both new AND returning in the same bucket.
        $this->seedOrder('custordE0001', 'paid', '2026-06-15 08:00:00', userUuid: 'userecho0001');
        $this->seedOrder('custordE0002', 'paid', '2026-06-17 08:00:00', userUuid: 'userecho0001');

        $body = $this->call('2026-06-15', '2026-06-21', 'week');

        self::assertCount(1, $body['data']['series']);
        $bucket = $body['data']['series'][0];
        self::assertSame('2026-W25', $bucket['bucket']);
        self::assertSame(1, $bucket['new_customers']);
        self::assertSame(0, $bucket['returning_customers']);
    }

    public function testCraftedFixtureProvesSummaryIsIndependentAndNotASumOfTheSeries(): void
    {
        // Customer is new in week 1 (2026-06-15..21, "2026-W25") and orders
        // again in week 2 (2026-06-22..28, "2026-W26"). Series shows
        // new@W25 + returning@W26 (a naive sum would give new=1, returning=1,
        // total=2), but the independent window summary must show this
        // customer counted exactly ONCE: new=1, returning=0, total=1 (their
        // all-time first order falls inside the whole window).
        $this->seedOrder('custordF0001', 'paid', '2026-06-16 08:00:00', userUuid: 'userfoxtrot1');
        $this->seedOrder('custordF0002', 'paid', '2026-06-23 08:00:00', userUuid: 'userfoxtrot1');

        $body = $this->call('2026-06-15', '2026-06-28', 'week');
        $series = $this->seriesByBucket($body);

        self::assertSame(1, $series['2026-W25']['new_customers']);
        self::assertSame(0, $series['2026-W25']['returning_customers']);
        self::assertSame(0, $series['2026-W26']['new_customers']);
        self::assertSame(1, $series['2026-W26']['returning_customers']);

        $summary = $body['data']['summary'];
        self::assertSame(1, $summary['new_customers']);
        self::assertSame(0, $summary['returning_customers']);
        self::assertSame(1, $summary['total_customers']);

        // The naive "sum the series" computation would be wrong -- prove it
        // genuinely differs from the correct, independently-computed summary.
        $seriesReturningSum = array_sum(array_column($body['data']['series'], 'returning_customers'));
        self::assertNotSame($summary['returning_customers'], $seriesReturningSum);

        $seriesTotalSum = array_sum(array_column($body['data']['series'], 'new_customers'))
            + array_sum(array_column($body['data']['series'], 'returning_customers'));
        self::assertNotSame($summary['total_customers'], $seriesTotalSum);
    }

    public function testRepositoryResultRowsStayBoundedUnderAHighCustomerFixture(): void
    {
        // 30 distinct customers, each ordering across 5 distinct days inside
        // a 10-day window -- a naive (days x customers) implementation would
        // return up to 150 raw rows; the bounded aggregate must return at
        // most one row per day actually ordered on (<= 10), independent of
        // how many distinct customers contributed to those days.
        for ($customer = 1; $customer <= 30; $customer++) {
            $userUuid = sprintf('userbulk%04d', $customer);
            for ($day = 1; $day <= 5; $day++) {
                $this->seedOrder(
                    sprintf('custbulk%02d%02d', $customer, $day),
                    'paid',
                    sprintf('2026-06-%02d 09:00:00', $day),
                    userUuid: $userUuid
                );
            }
        }

        $window = ReportWindow::fromDates('2026-06-01', '2026-06-10', 'day');
        $repository = new CustomerReportRepository();
        $rawRows = $repository->rawSeriesRows($this->context, self::TENANT, $window);

        self::assertLessThanOrEqual(
            count($window->bucketBounds()),
            count($rawRows),
            'repository must return at most one row per bucket, never (days x customers)'
        );
        self::assertLessThanOrEqual(10, count($rawRows));

        // Sanity: the aggregate is still correct despite being bounded --
        // every one of the 5 seeded days has all 30 customers "new" (each
        // customer's first-ever order is on their first seeded day).
        $body = $this->call('2026-06-01', '2026-06-10');
        self::assertSame(30, $body['data']['summary']['new_customers']);
        self::assertSame(0, $body['data']['summary']['returning_customers']);
        self::assertSame(30, $body['data']['summary']['total_customers']);
    }

    public function testZeroFilledEmptyBucketsInDayGrouping(): void
    {
        $body = $this->call('2026-08-01', '2026-08-03');

        self::assertCount(3, $body['data']['series']);
        foreach ($body['data']['series'] as $bucket) {
            self::assertSame(0, $bucket['new_customers']);
            self::assertSame(0, $bucket['returning_customers']);
        }

        $summary = $body['data']['summary'];
        self::assertSame(0, $summary['new_customers']);
        self::assertSame(0, $summary['returning_customers']);
        self::assertSame(0, $summary['total_customers']);
    }

    public function testZeroFilledEmptyBucketsInWeekGrouping(): void
    {
        $body = $this->call('2026-08-03', '2026-08-16', 'week');

        self::assertCount(2, $body['data']['series']);
        foreach ($body['data']['series'] as $bucket) {
            self::assertSame(0, $bucket['new_customers']);
            self::assertSame(0, $bucket['returning_customers']);
        }
    }

    public function testZeroFilledEmptyBucketsInMonthGrouping(): void
    {
        $body = $this->call('2026-08-01', '2026-09-30', 'month');

        self::assertCount(2, $body['data']['series']);
        foreach ($body['data']['series'] as $bucket) {
            self::assertSame(0, $bucket['new_customers']);
            self::assertSame(0, $bucket['returning_customers']);
        }
    }

    public function testTenantIsolationReturnsDisjointResults(): void
    {
        $this->seedOrder('custordG0001', 'paid', '2026-06-01 08:00:00', userUuid: 'usergolf0001', tenant: 'tenantcustA1');
        $this->seedOrder('custordH0001', 'paid', '2026-06-01 08:00:00', userUuid: 'userhotel001', tenant: 'tenantcustB1');

        $body = $this->call('2026-06-01', '2026-06-01', tenant: 'tenantcustA1');

        self::assertSame(1, $body['data']['summary']['total_customers']);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /** @return array<string,mixed> */
    private function call(string $from, string $to, string $group = 'day', string $tenant = self::TENANT): array
    {
        $response = $this->controller($tenant)->customers(
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
            $this->tenantResolver($tenant),
            new ProductSalesReportRepository(),
            new CustomerReportRepository()
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
        ?string $placedAt,
        ?string $createdAt = null,
        ?string $userUuid = null,
        string $email = 'buyer@example.com',
        string $tenant = self::TENANT,
    ): void {
        $this->connection->table('commerce_orders')->insert([
            'uuid' => $uuid,
            'tenant_uuid' => $tenant,
            'order_number' => 'ORD-' . $uuid,
            'status' => $status,
            'email' => $email,
            'user_uuid' => $userUuid,
            'guest_token_hash' => str_repeat('a', 64),
            'currency' => 'USD',
            'subtotal' => 0,
            'grand_total' => 0,
            'placed_at' => $placedAt,
            'created_at' => $createdAt ?? ($placedAt ?? '2026-01-01 00:00:00'),
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
