<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Reports;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Support\DateBucketSql;

/**
 * Sales report's raw-SQL day-grouped aggregates (design spec §4.1, decisions
 * 3-8): two day-grouped queries (revenue-status orders; completed refunds)
 * plus two window-scoped scalar queries (discount/shipping/tax sums;
 * pending-order visibility count) -- never `(days x anything)`. Grouping by
 * day only (never week/month) mirrors `DateBucketSql`'s docblock: PHP-side
 * `ReportRollup` folds day rows into the requested grouping; this class never
 * invokes a database week/month function.
 *
 * Revenue timestamp semantics (decision 3) are the two-branch `report_at`
 * derived table (decision 11), never `WHERE COALESCE(placed_at, created_at)`
 * (which would defeat the folded `(tenant_uuid, placed_at)` /
 * `(tenant_uuid, created_at)` indexes): branch 1 selects `placed_at AS
 * report_at` when `placed_at IS NOT NULL` and ranged; branch 2 falls back to
 * `created_at AS report_at` only when `placed_at IS NULL`, ranged on
 * `created_at`. Both branches start `tenant_uuid = ?` and are combined with
 * `UNION ALL`.
 *
 * Revenue statuses are exactly `('paid','fulfilled','refunded')` (decision 4);
 * `pending_payment`/`canceled` orders never contribute to `gross_minor`,
 * `orders_count`, or the window sums -- `pending_payment` is surfaced
 * separately as `pending_orders`, a window-scoped visibility count, never a
 * revenue metric.
 *
 * Refunds are windowed/bucketed on `completed_at`, `status = 'completed'`
 * only (decision 5) -- a June refund against a May order buckets in June
 * regardless of the order's own window membership. `orders.refunded_total`
 * is never read here (decision 6); `net_minor` is derived by the controller
 * as `gross_minor - refunds_minor`.
 */
final class SalesReportRepository
{
    private const REVENUE_STATUSES = "'paid','fulfilled','refunded'";

    /**
     * @return array{
     *     orders: array<string, array{gross_minor: int, orders_count: int}>,
     *     refunds: array<string, int>,
     *     sums: array{discount_minor: int, shipping_minor: int, tax_minor: int},
     *     pending_orders: int
     * }
     */
    public function salesByDay(ApplicationContext $context, string $tenant, ReportWindow $window): array
    {
        $driver = db($context)->getDriverName();
        $from = $window->fromSql();
        $to = $window->toExclusiveSql();
        $windowBindings = [$tenant, $from, $to, $tenant, $from, $to];

        $reportAtDayExpr = DateBucketSql::dayExpression($driver, 'report_at');
        $derivedTable = $this->reportAtDerivedTableSql();

        $orderRows = db($context)->table('commerce_orders')->executeRaw(
            'SELECT ' . $reportAtDayExpr . ' AS bucket, '
                . 'SUM(grand_total) AS gross_minor, '
                . 'COUNT(*) AS orders_count '
                . 'FROM ' . $derivedTable . ' AS report_orders '
                . 'WHERE status IN (' . self::REVENUE_STATUSES . ') '
                . 'GROUP BY ' . $reportAtDayExpr,
            $windowBindings
        );

        $sumsRow = db($context)->table('commerce_orders')->executeRawFirst(
            'SELECT SUM(discount_total) AS discount_minor, '
                . 'SUM(shipping_total) AS shipping_minor, '
                . 'SUM(tax_total) AS tax_minor '
                . 'FROM ' . $derivedTable . ' AS report_orders '
                . 'WHERE status IN (' . self::REVENUE_STATUSES . ')',
            $windowBindings
        );

        $pendingRow = db($context)->table('commerce_orders')->executeRawFirst(
            'SELECT COUNT(*) AS pending_orders '
                . 'FROM ' . $derivedTable . ' AS report_orders '
                . "WHERE status = 'pending_payment'",
            $windowBindings
        );

        $refundDayExpr = DateBucketSql::dayExpression($driver, 'completed_at');
        $refundRows = db($context)->table('commerce_refunds')->executeRaw(
            'SELECT ' . $refundDayExpr . ' AS bucket, SUM(amount) AS refund_minor '
                . 'FROM commerce_refunds '
                . "WHERE tenant_uuid = ? AND status = 'completed' AND completed_at >= ? AND completed_at < ? "
                . 'GROUP BY ' . $refundDayExpr,
            [$tenant, $from, $to]
        );

        $orders = [];
        foreach ($orderRows as $row) {
            $orders[(string) $row['bucket']] = [
                'gross_minor' => (int) $row['gross_minor'],
                'orders_count' => (int) $row['orders_count'],
            ];
        }

        $refunds = [];
        foreach ($refundRows as $row) {
            $refunds[(string) $row['bucket']] = (int) $row['refund_minor'];
        }

        return [
            'orders' => $orders,
            'refunds' => $refunds,
            'sums' => [
                'discount_minor' => (int) ($sumsRow['discount_minor'] ?? 0),
                'shipping_minor' => (int) ($sumsRow['shipping_minor'] ?? 0),
                'tax_minor' => (int) ($sumsRow['tax_minor'] ?? 0),
            ],
            'pending_orders' => (int) ($pendingRow['pending_orders'] ?? 0),
        ];
    }

    /**
     * The two-branch `report_at` derived table (decision 11): branch 1 uses
     * `placed_at` when present and ranged; branch 2 falls back to
     * `created_at` only when `placed_at IS NULL`, ranged on `created_at`.
     * Both branches start `tenant_uuid = ?`; NEVER `WHERE COALESCE(...)`.
     *
     * Binding order per use: `[tenant, from, to, tenant, from, to]`.
     */
    private function reportAtDerivedTableSql(): string
    {
        return '('
            . 'SELECT placed_at AS report_at, status, grand_total, discount_total, shipping_total, tax_total '
            . 'FROM commerce_orders '
            . 'WHERE tenant_uuid = ? AND placed_at IS NOT NULL AND placed_at >= ? AND placed_at < ? '
            . 'UNION ALL '
            . 'SELECT created_at AS report_at, status, grand_total, discount_total, shipping_total, tax_total '
            . 'FROM commerce_orders '
            . 'WHERE tenant_uuid = ? AND placed_at IS NULL AND created_at >= ? AND created_at < ?'
            . ')';
    }
}
