<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Http\Admin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\DTOs\ProductsReportQuery;
use Glueful\Extensions\Commerce\Http\DTOs\ReportWindowQuery;
use Glueful\Extensions\Commerce\Http\DTOs\StockReportQuery;
use Glueful\Extensions\Commerce\Reports\CustomerReportRepository;
use Glueful\Extensions\Commerce\Reports\ProductSalesReportRepository;
use Glueful\Extensions\Commerce\Reports\ReportRollup;
use Glueful\Extensions\Commerce\Reports\ReportWindow;
use Glueful\Extensions\Commerce\Reports\SalesReportRepository;
use Glueful\Extensions\Commerce\Reports\StockReportRepository;
use Glueful\Extensions\Commerce\Reports\StockThreshold;
use Glueful\Extensions\Commerce\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Read-only admin report endpoints (design spec Layer 5, §4): `sales`,
 * `products`, `customers`, and `stock`.
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
 *
 * `products` is a ranked, house-paginated variant list -- no `group` (it
 * builds its window via `ReportWindow::fromDates($query->from, $query->to,
 * 'day')` directly, per {@see ProductsReportQuery}'s own docblock). It is a
 * thin pass-through to `ProductSalesReportRepository::paginate()`, which
 * already returns the exact response field shape; the controller only
 * resolves the tenant/window/pagination inputs and shapes the flat
 * `Response::paginated()` envelope.
 *
 * `customers` is a thin pass-through to
 * `CustomerReportRepository::bucketCounts()`, which already returns a fully
 * zero-filled, keyed `series` map and an independent `summary` row (design
 * spec §4.3) -- the controller only reshapes the keyed series map into the
 * response's chronologically-ordered list via `ReportWindow::bucketBounds()`.
 *
 * `stock` is point-in-time (no window/pagination `group`; house pagination
 * only) and resolves the effective threshold via `StockThreshold::resolve()`
 * -- the validated `?threshold=` override when present, else the validated
 * `config('commerce.reports.low_stock_threshold')` default (design spec §4.4,
 * decision 10). `StockThreshold::resolve()` throws a plain
 * `\InvalidArgumentException` for an out-of-range OVERRIDE (a client input
 * error) -- caught here and converted to a 422 `ValidationException` on the
 * `threshold` field, since an uncaught `InvalidArgumentException` would
 * otherwise surface as a 500. An out-of-range CONFIGURED value throws
 * `ReportConfigurationException` instead (a deployment error, not a client
 * one) and is deliberately left to propagate uncaught. `StockReportRepository
 * ::paginate()` returns no `threshold` field per item (its interface is
 * repository-only); the controller stamps the one resolved, effective
 * threshold onto every item before responding, per the spec §4.4 example.
 */
final class AdminReportController
{
    public function __construct(
        private ApplicationContext $context,
        private ?SalesReportRepository $sales = null,
        private ?CurrentTenantResolver $tenants = null,
        private ?ProductSalesReportRepository $products = null,
        private ?CustomerReportRepository $customers = null,
        private ?StockReportRepository $stock = null,
    ) {
        $this->sales ??= app($context, SalesReportRepository::class);
        $this->tenants ??= container($context)->has(CurrentTenantResolver::class)
            ? container($context)->get(CurrentTenantResolver::class)
            : new SentinelTenantResolver();
        // has()-checked, like $tenants above -- NOT a blind app() call like $sales:
        // ProductSalesReportRepository/CustomerReportRepository/StockReportRepository
        // have no dependencies, so a plain new() is a safe, side-effect-free
        // fallback when a caller (e.g. a lightweight test container) never
        // registered these bindings.
        $this->products ??= container($context)->has(ProductSalesReportRepository::class)
            ? container($context)->get(ProductSalesReportRepository::class)
            : new ProductSalesReportRepository();
        $this->customers ??= container($context)->has(CustomerReportRepository::class)
            ? container($context)->get(CustomerReportRepository::class)
            : new CustomerReportRepository();
        $this->stock ??= container($context)->has(StockReportRepository::class)
            ? container($context)->get(StockReportRepository::class)
            : new StockReportRepository();
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

    #[ApiOperation(
        summary: 'Products report: ranked variant sales with line-attributed refunds over a date window',
        tags: ['Commerce Admin']
    )]
    #[ApiResponse(200, description: 'Product report retrieved')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function products(ProductsReportQuery $query, Request $request): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $window = ReportWindow::fromDates($query->from, $query->to, 'day');

        $page = max(1, $query->page ?? 1);
        $perPage = max(1, min(100, $query->per_page ?? 24));
        $sort = $query->sort ?? 'revenue';

        $result = $this->products->paginate($this->context, $tenant, $window, $sort, $page, $perPage);

        return Response::paginated(
            $result['items'],
            $result['total'],
            $page,
            $perPage,
            null,
            'Product report retrieved'
        );
    }

    #[ApiOperation(
        summary: 'Customers report: new vs returning customer counts over a date window',
        tags: ['Commerce Admin']
    )]
    #[ApiResponse(200, description: 'Customer report retrieved')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function customers(ReportWindowQuery $query, Request $request): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);
        $window = ReportWindow::fromQuery($query);

        $counts = $this->customers->bucketCounts($this->context, $tenant, $window);

        $series = [];
        foreach ($window->bucketBounds() as $bound) {
            $bucket = $bound['bucket'];
            $bucketCounts = $counts['series'][$bucket] ?? ['new_customers' => 0, 'returning_customers' => 0];

            $series[] = [
                'bucket' => $bucket,
                'new_customers' => $bucketCounts['new_customers'],
                'returning_customers' => $bucketCounts['returning_customers'],
            ];
        }

        return Response::success([
            'window' => [
                'from' => $window->fromDate(),
                'to' => $window->toDate(),
                'group' => $window->group(),
            ],
            'summary' => $counts['summary'],
            'series' => $series,
        ], 'Customer report retrieved');
    }

    #[ApiOperation(
        summary: 'Stock report: point-in-time out-of-stock and low-stock variants',
        tags: ['Commerce Admin']
    )]
    #[ApiResponse(200, description: 'Stock report retrieved')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function stock(StockReportQuery $query, Request $request): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);

        try {
            $threshold = StockThreshold::resolve(
                $query->threshold,
                config($this->context, 'commerce.reports.low_stock_threshold')
            );
        } catch (\InvalidArgumentException $e) {
            // An out-of-range OVERRIDE is a client input error: convert to a 422
            // instead of letting the raw InvalidArgumentException surface as a 500.
            // A ReportConfigurationException (invalid CONFIGURED value) is NOT
            // caught here -- it is a deployment error and must propagate.
            throw ValidationException::forField('threshold', $e->getMessage());
        }

        $page = max(1, $query->page ?? 1);
        $perPage = max(1, min(100, $query->per_page ?? 24));

        $result = $this->stock->paginate($this->context, $tenant, $threshold, $query->status, $page, $perPage);

        $items = array_map(
            static fn (array $item): array => [...$item, 'threshold' => $threshold],
            $result['items']
        );

        return Response::paginated($items, $result['total'], $page, $perPage, null, 'Stock report retrieved');
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
