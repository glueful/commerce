<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Reports;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Customers\CustomerAggregationRepository;
use Glueful\Extensions\Commerce\Orders\OrderScope;
use Glueful\Extensions\Commerce\Support\DateBucketSql;
use Glueful\Extensions\Commerce\Support\ReportBoundarySql;

/**
 * Customers report's bounded, DB-side distinct-key aggregate (design spec
 * §4.3, decision 9). No dedicated customer table -- identity reuses
 * `CustomerAggregationRepository::KEY_EXPR` verbatim
 * (`CASE WHEN user_uuid IS NOT NULL THEN user_uuid ELSE LOWER(TRIM(email))
 * END`) so a key is always the same one the customers admin surface groups
 * by: a user's `user_uuid` when present, else the normalized
 * `LOWER(TRIM(email))` guest identity.
 *
 * "New in bucket/window" = the key's all-time first-ever revenue-status
 * order falls inside that bucket/window; "returning" = the key ordered in
 * the bucket/window but its all-time first order predates it. Both figures
 * are computed by joining each key's ALL-TIME `MIN(report_at)` (over the
 * unbounded two-branch `report_at` derivation, revenue statuses only, no
 * date range) against that key's in-window/in-bucket activity (the same
 * windowed two-branch `report_at` derivation used by
 * {@see SalesReportRepository}/{@see ProductSalesReportRepository}).
 *
 * Bounded-aggregate contract (never `(days x customers)`):
 * - `group=day`: ONE query groups the windowed activity by
 *   `DateBucketSql::dayExpression()` and classifies with
 *   `COUNT(DISTINCT CASE ...)` in SQL -- at most one row per day that
 *   actually has activity; PHP zero-fills the remaining requested days.
 * - `group=week|month`: a small literal "boundary table" built from
 *   `ReportWindow::bucketBounds()` rows via `ReportBoundarySql::rowExpression()`
 *   joined with `UNION ALL` (<= 54 week / <= 13 month rows), LEFT JOINed
 *   against the windowed activity so EVERY bucket -- including empty ones --
 *   returns exactly one row through the SQL shape itself (no PHP zero-fill
 *   needed, though `bucketCounts()` applies the same defaulting uniformly
 *   for both modes as a harmless no-op safety net).
 * - the window summary is a SEPARATE, independent single-row aggregate over
 *   the whole window (not the per-bucket boundary table) -- classifying each
 *   distinct in-window key exactly once. Series figures are intentionally
 *   non-additive (a key new in one bucket and returning in another is
 *   perfectly valid) and MUST NOT be summed to derive the summary; this
 *   class never does so.
 *
 * `rawSeriesRows()` is a deliberate observability/testing seam (Task 4
 * self-review note, not part of the plan-pinned `bucketCounts()` interface):
 * it exposes the RAW, pre-zero-fill per-bucket rows the day/week/month query
 * actually returns from the database, so the row-count bound (repository
 * result rows <= requested_bucket_count + 1) can be asserted directly
 * against the SQL layer -- independent of how many distinct customers exist
 * -- rather than only against `bucketCounts()`'s already-zero-filled output,
 * which is always exactly bucket-sized regardless of the underlying query
 * shape and therefore cannot by itself prove the bound.
 */
final class CustomerReportRepository
{
    private const REVENUE_STATUSES = "'paid','fulfilled','refunded'";

    /**
     * @return array{
     *     series: array<string, array{new_customers: int, returning_customers: int}>,
     *     summary: array{new_customers: int, returning_customers: int, total_customers: int}
     * }
     */
    public function bucketCounts(ApplicationContext $context, string $tenant, ReportWindow $window): array
    {
        $byBucket = [];
        foreach ($this->rawSeriesRows($context, $tenant, $window) as $row) {
            $byBucket[$row['bucket']] = [
                'new_customers' => $row['new_customers'],
                'returning_customers' => $row['returning_customers'],
            ];
        }

        $series = [];
        foreach ($window->bucketBounds() as $bound) {
            $series[$bound['bucket']] = $byBucket[$bound['bucket']] ?? [
                'new_customers' => 0,
                'returning_customers' => 0,
            ];
        }

        return [
            'series' => $series,
            'summary' => $this->summaryCounts($context, $tenant, $window),
        ];
    }

    /**
     * Testing/observability seam -- see class docblock. Returns the raw,
     * pre-zero-fill per-bucket rows (at most one per day with activity in
     * day mode; exactly one per boundary in week/month mode).
     *
     * @internal not part of the supported contract; consume bucketCounts()
     * @return list<array{bucket: string, new_customers: int, returning_customers: int}>
     */
    public function rawSeriesRows(ApplicationContext $context, string $tenant, ReportWindow $window): array
    {
        return match ($window->group()) {
            'week', 'month' => $this->boundaryRows($context, $tenant, $window),
            default => $this->dayRows($context, $tenant, $window),
        };
    }

    /**
     * Day mode: one query, day-grouped, `COUNT(DISTINCT CASE ...)`
     * classification in SQL. A key is "new" on a day when its all-time
     * first-ever revenue-status order's own day equals the activity row's
     * day (both computed via the same `DateBucketSql::dayExpression()`, so
     * the comparison is portable across drivers); otherwise "returning".
     *
     * @return list<array{bucket: string, new_customers: int, returning_customers: int}>
     */
    private function dayRows(ApplicationContext $context, string $tenant, ReportWindow $window): array
    {
        $driver = db($context)->getDriverName();
        $from = $window->fromSql();
        $to = $window->toExclusiveSql();
        $keyExpr = CustomerAggregationRepository::KEY_EXPR;

        $activityDayExpr = DateBucketSql::dayExpression($driver, 'w.report_at');
        $firstOrderDayExpr = DateBucketSql::dayExpression($driver, 'fo.first_order_at');

        $sql = 'SELECT ' . $activityDayExpr . ' AS bucket, '
            . 'COUNT(DISTINCT CASE WHEN ' . $firstOrderDayExpr . ' = ' . $activityDayExpr
            . ' THEN ' . $keyExpr . ' END) AS new_customers, '
            . 'COUNT(DISTINCT CASE WHEN ' . $firstOrderDayExpr . ' <> ' . $activityDayExpr
            . ' THEN ' . $keyExpr . ' END) AS returning_customers '
            . 'FROM ' . $this->windowedOrdersDerivedTableSql() . ' AS w '
            . 'JOIN ' . $this->firstOrderSubquerySql() . ' AS fo ON ' . $keyExpr . ' = fo.customer_key '
            . 'WHERE w.status IN (' . self::REVENUE_STATUSES . ') '
            . 'GROUP BY ' . $activityDayExpr;

        // Binding order: windowedOrdersDerivedTableSql() [tenant, from, to, tenant, from, to],
        // then firstOrderSubquerySql() -> allTimeOrdersDerivedTableSql() [tenant, tenant].
        $bindings = [$tenant, $from, $to, $tenant, $from, $to, $tenant, $tenant];

        $rows = db($context)->table('commerce_orders')->executeRaw($sql, $bindings);

        return array_map(static fn (array $row): array => [
            'bucket' => (string) $row['bucket'],
            'new_customers' => (int) $row['new_customers'],
            'returning_customers' => (int) $row['returning_customers'],
        ], $rows);
    }

    /**
     * Week/month mode: a bound literal boundary table (`ReportBoundarySql`
     * rows joined by `UNION ALL`) LEFT JOINed against the windowed activity
     * (and that against each key's all-time first order), then grouped by
     * boundary -- guaranteeing every requested bucket, including empty ones,
     * returns exactly one row through the LEFT JOIN shape itself.
     *
     * @return list<array{bucket: string, new_customers: int, returning_customers: int}>
     */
    private function boundaryRows(ApplicationContext $context, string $tenant, ReportWindow $window): array
    {
        $driver = db($context)->getDriverName();
        $from = $window->fromSql();
        $to = $window->toExclusiveSql();
        $bounds = $window->bucketBounds();
        $keyExpr = CustomerAggregationRepository::KEY_EXPR;

        $boundaryBindings = [];
        foreach ($bounds as $bound) {
            $boundaryBindings[] = $bound['bucket'];
            $boundaryBindings[] = $bound['from'];
            $boundaryBindings[] = $bound['to'];
        }

        $sql = 'SELECT b.bucket AS bucket, '
            . 'COUNT(DISTINCT CASE WHEN fo.first_order_at >= b.from_at AND fo.first_order_at < b.to_at '
            . 'THEN ' . $keyExpr . ' END) AS new_customers, '
            . 'COUNT(DISTINCT CASE WHEN fo.first_order_at < b.from_at '
            . 'THEN ' . $keyExpr . ' END) AS returning_customers '
            . 'FROM ' . $this->boundaryTableSql($driver, count($bounds)) . ' AS b '
            . 'LEFT JOIN ' . $this->windowedOrdersDerivedTableSql() . ' AS w '
            . 'ON w.report_at >= b.from_at AND w.report_at < b.to_at '
            . 'AND w.status IN (' . self::REVENUE_STATUSES . ') '
            . 'LEFT JOIN ' . $this->firstOrderSubquerySql() . ' AS fo ON ' . $keyExpr . ' = fo.customer_key '
            . 'GROUP BY b.bucket';

        // Binding order: boundaryTableSql() [bucket, from, to] x N bounds,
        // then windowedOrdersDerivedTableSql() [tenant, from, to, tenant, from, to],
        // then firstOrderSubquerySql() -> allTimeOrdersDerivedTableSql() [tenant, tenant].
        $bindings = [...$boundaryBindings, $tenant, $from, $to, $tenant, $from, $to, $tenant, $tenant];

        $rows = db($context)->table('commerce_orders')->executeRaw($sql, $bindings);

        return array_map(static fn (array $row): array => [
            'bucket' => (string) $row['bucket'],
            'new_customers' => (int) $row['new_customers'],
            'returning_customers' => (int) $row['returning_customers'],
        ], $rows);
    }

    /**
     * The independent window-wide summary aggregate (design spec §4.3): one
     * row, classifying each distinct in-window key exactly once by comparing
     * its all-time first order against the WHOLE window's bounds (not any
     * single bucket's). `new_customers + returning_customers = total_customers`
     * by construction -- every key present in the windowed activity has a
     * first-ever order that is either inside `[from, to)` or predates `from`
     * (it cannot fall after `to`, since the key already has an order inside
     * the window and the all-time first order can only be earlier or equal).
     *
     * @return array{new_customers: int, returning_customers: int, total_customers: int}
     */
    private function summaryCounts(ApplicationContext $context, string $tenant, ReportWindow $window): array
    {
        $from = $window->fromSql();
        $to = $window->toExclusiveSql();
        $keyExpr = CustomerAggregationRepository::KEY_EXPR;

        $sql = 'SELECT '
            . 'COUNT(DISTINCT CASE WHEN fo.first_order_at >= ? AND fo.first_order_at < ? '
            . 'THEN ' . $keyExpr . ' END) AS new_customers, '
            . 'COUNT(DISTINCT CASE WHEN fo.first_order_at < ? '
            . 'THEN ' . $keyExpr . ' END) AS returning_customers, '
            . 'COUNT(DISTINCT ' . $keyExpr . ') AS total_customers '
            . 'FROM ' . $this->windowedOrdersDerivedTableSql() . ' AS w '
            . 'JOIN ' . $this->firstOrderSubquerySql() . ' AS fo ON ' . $keyExpr . ' = fo.customer_key '
            . 'WHERE w.status IN (' . self::REVENUE_STATUSES . ')';

        // Binding order: the SELECT-list CASE conditions [from, to, from],
        // then windowedOrdersDerivedTableSql() [tenant, from, to, tenant, from, to],
        // then firstOrderSubquerySql() -> allTimeOrdersDerivedTableSql() [tenant, tenant].
        $bindings = [
            $from, $to, $from,
            $tenant, $from, $to, $tenant, $from, $to,
            $tenant, $tenant,
        ];

        $row = db($context)->table('commerce_orders')->executeRawFirst($sql, $bindings);

        return [
            'new_customers' => (int) ($row['new_customers'] ?? 0),
            'returning_customers' => (int) ($row['returning_customers'] ?? 0),
            'total_customers' => (int) ($row['total_customers'] ?? 0),
        ];
    }

    /**
     * Each key's ALL-TIME first-ever revenue-status order timestamp
     * (`MIN(report_at)`), grouped by the shared `KEY_EXPR`. This is the
     * classification anchor for both the per-bucket series and the
     * independent summary -- built over the UNBOUNDED derived table (no date
     * range at all), never the windowed one.
     */
    private function firstOrderSubquerySql(): string
    {
        $keyExpr = CustomerAggregationRepository::KEY_EXPR;

        return '('
            . 'SELECT ' . $keyExpr . ' AS customer_key, MIN(report_at) AS first_order_at '
            . 'FROM ' . $this->allTimeOrdersDerivedTableSql() . ' AS all_orders '
            . 'WHERE status IN (' . self::REVENUE_STATUSES . ') '
            . 'GROUP BY ' . $keyExpr
            . ')';
    }

    /**
     * The two-branch `report_at` derived table (spec §2.11), windowed on
     * `[from, to)`, mirroring {@see SalesReportRepository}'s construction:
     * branch 1 uses `placed_at` when present and ranged; branch 2 falls back
     * to `created_at` only when `placed_at IS NULL`, ranged on `created_at`.
     * Both branches start `tenant_uuid = ?`; NEVER `WHERE COALESCE(...)`.
     * Projects `user_uuid`/`email` (in addition to `report_at`/`status`) so
     * `KEY_EXPR` can be evaluated directly against this table's rows.
     *
     * Binding order per use: `[tenant, from, to, tenant, from, to]`.
     */
    private function windowedOrdersDerivedTableSql(): string
    {
        $notDraft = OrderScope::excludeDraftsSql();

        return '('
            . 'SELECT placed_at AS report_at, status, user_uuid, email '
            . 'FROM commerce_orders '
            . 'WHERE ' . $notDraft
            . ' AND tenant_uuid = ? AND placed_at IS NOT NULL AND placed_at >= ? AND placed_at < ? '
            . 'UNION ALL '
            . 'SELECT created_at AS report_at, status, user_uuid, email '
            . 'FROM commerce_orders '
            . 'WHERE ' . $notDraft
            . ' AND tenant_uuid = ? AND placed_at IS NULL AND created_at >= ? AND created_at < ?'
            . ')';
    }

    /**
     * The all-time variant of {@see self::windowedOrdersDerivedTableSql()}
     * used for the first-order derivation: same two-branch construction
     * (`placed_at` when present, else `created_at`), but with NO window
     * bounds at all -- both branches are unbounded (statuses only, filtered
     * by the consuming query), still projecting `report_at`.
     *
     * Binding order per use: `[tenant, tenant]`.
     */
    private function allTimeOrdersDerivedTableSql(): string
    {
        $notDraft = OrderScope::excludeDraftsSql();

        return '('
            . 'SELECT placed_at AS report_at, status, user_uuid, email '
            . 'FROM commerce_orders '
            . 'WHERE ' . $notDraft . ' AND tenant_uuid = ? AND placed_at IS NOT NULL '
            . 'UNION ALL '
            . 'SELECT created_at AS report_at, status, user_uuid, email '
            . 'FROM commerce_orders '
            . 'WHERE ' . $notDraft . ' AND tenant_uuid = ? AND placed_at IS NULL'
            . ')';
    }

    /**
     * A small literal "boundary table": `$count` `ReportBoundarySql::rowExpression()`
     * rows joined with `UNION ALL`, one per `ReportWindow::bucketBounds()`
     * entry. Binding order per use: `[bucket, from, to]` repeated `$count`
     * times, in `bucketBounds()`'s own chronological order.
     */
    private function boundaryTableSql(string $driver, int $count): string
    {
        $rowSql = ReportBoundarySql::rowExpression($driver);

        return '(' . implode(' UNION ALL ', array_fill(0, $count, $rowSql)) . ')';
    }
}
