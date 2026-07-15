<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\DTOs\ReportWindowQuery;
use Glueful\Extensions\Commerce\Reports\ReportRollup;
use Glueful\Extensions\Commerce\Reports\ReportWindow;
use Glueful\Extensions\Commerce\Reports\SalesReportRepository;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Read-only admin report endpoints (design spec Layer 5, §4). This task ships
 * the `sales` action only; `products`/`customers`/`stock` land in Group C.
 *
 * `sales` merges `SalesReportRepository::salesByDay()`'s day-grouped
 * money/order aggregates via `ReportRollup` (pure PHP day -> day/week/month
 * fold + zero-fill), then computes `net_minor`/`aov_minor` in PHP for both
 * the per-bucket series and the window summary. The summary's additive
 * fields (`gross_minor`/`refunds_minor`/`orders_count`) are summed from the
 * already-folded series -- the same day rows the series was built from, not
 * a third query -- while `aov_minor` is RECOMPUTED from the summary's own
 * gross/count (never summed from per-bucket AOVs, which would silently
 * produce a mathematically meaningless number). `discount_minor`/
 * `shipping_minor`/`tax_minor`/`pending_orders` are window-scoped scalars
 * straight from the repository, unrelated to any single bucket.
 */
final class AdminReportController
{
    public function __construct(
        private ApplicationContext $context,
        private ?SalesReportRepository $sales = null,
        private ?CurrentTenantResolver $tenants = null,
    ) {
        $this->sales ??= app($context, SalesReportRepository::class);
        $this->tenants ??= container($context)->has(CurrentTenantResolver::class)
            ? container($context)->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
    }

    #[ApiOperation(
        summary: 'Sales report: gross/net revenue, refunds, and AOV over a date window',
        tags: ['Commerce Admin']
    )]
    #[ApiResponse(200, description: 'Sales report retrieved')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function sales(ReportWindowQuery $query, Request $request): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $window = ReportWindow::fromQuery($query);

        $data = $this->sales->salesByDay($this->context, $tenant, $window);

        $dayRows = $this->mergeDayRows($data['orders'], $data['refunds']);
        /**
         * `ReportRollup::fold()` only guarantees a bare `{bucket: string}` shape when
         * `$dayRows` is completely empty (its docblock); every other bucket carries the
         * full merged field shape because `mergeDayRows()` always seeds all three fields.
         *
         * @var list<array{bucket: string, gross_minor?: int, refunds_minor?: int, orders_count?: int}> $folded
         */
        $folded = ReportRollup::fold($dayRows, $window);

        $series = [];
        foreach ($folded as $row) {
            $gross = (int) ($row['gross_minor'] ?? 0);
            $refundsMinor = (int) ($row['refunds_minor'] ?? 0);
            $ordersCount = (int) ($row['orders_count'] ?? 0);

            $series[] = [
                'bucket' => $row['bucket'],
                'gross_minor' => $gross,
                'refunds_minor' => $refundsMinor,
                'net_minor' => $gross - $refundsMinor,
                'orders_count' => $ordersCount,
                'aov_minor' => self::aov($gross, $ordersCount),
            ];
        }

        $summaryGross = (int) array_sum(array_column($series, 'gross_minor'));
        $summaryRefunds = (int) array_sum(array_column($series, 'refunds_minor'));
        $summaryOrdersCount = (int) array_sum(array_column($series, 'orders_count'));

        return Response::success([
            'currency' => (string) config($this->context, 'commerce.currency', 'USD'),
            'window' => [
                'from' => $window->fromDate(),
                'to' => $window->toDate(),
                'group' => $window->group(),
            ],
            'summary' => [
                'gross_minor' => $summaryGross,
                'refunds_minor' => $summaryRefunds,
                'net_minor' => $summaryGross - $summaryRefunds,
                'orders_count' => $summaryOrdersCount,
                'aov_minor' => self::aov($summaryGross, $summaryOrdersCount),
                'pending_orders' => $data['pending_orders'],
                'discount_minor' => $data['sums']['discount_minor'],
                'shipping_minor' => $data['sums']['shipping_minor'],
                'tax_minor' => $data['sums']['tax_minor'],
            ],
            'series' => $series,
        ], 'Sales report retrieved');
    }

    /**
     * Merges the repository's independent day-keyed `orders`/`refunds` maps
     * into one day-keyed row set shaped for `ReportRollup::fold()`, always
     * including all three additive fields (defaulted to zero) on every row
     * so the fold's zero-fill sees the full field shape regardless of which
     * of orders/refunds happened to have data on a given day.
     *
     * @param array<string, array{gross_minor: int, orders_count: int}> $orders
     * @param array<string, int> $refunds
     * @return array<string, array{gross_minor: int, orders_count: int, refunds_minor: int}>
     */
    private function mergeDayRows(array $orders, array $refunds): array
    {
        $days = [];

        foreach ($orders as $day => $aggregate) {
            $days[$day] ??= ['gross_minor' => 0, 'orders_count' => 0, 'refunds_minor' => 0];
            $days[$day]['gross_minor'] = $aggregate['gross_minor'];
            $days[$day]['orders_count'] = $aggregate['orders_count'];
        }

        foreach ($refunds as $day => $refundMinor) {
            $days[$day] ??= ['gross_minor' => 0, 'orders_count' => 0, 'refunds_minor' => 0];
            $days[$day]['refunds_minor'] = $refundMinor;
        }

        return $days;
    }

    /** House half-up integer division AOV (decision 7); `0` when `$ordersCount = 0`. */
    private static function aov(int $grossMinor, int $ordersCount): int
    {
        if ($ordersCount === 0) {
            return 0;
        }

        return intdiv(2 * $grossMinor + $ordersCount, 2 * $ordersCount);
    }
}
