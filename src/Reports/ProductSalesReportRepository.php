<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Reports;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Products report's raw-SQL ranked-variant aggregate (design spec §4.2, plan
 * Global Constraints "Products" bullet): two INDEPENDENT activity branches --
 * sales and refunds -- each independently grouped by `variant_uuid`, combined
 * with `UNION ALL`, then OUTER-grouped by `variant_uuid` again. The count
 * query counts that same outer grouped result (`COUNT(DISTINCT variant_uuid)`
 * over the union, equivalent to counting the outer GROUP BY's row count).
 *
 * Sales branch: `commerce_order_lines` joined to a private two-branch
 * `report_at` derived table over `commerce_orders` (mirrors
 * {@see SalesReportRepository}'s derived table, but projects `uuid AS
 * order_uuid` instead of the money columns, since this branch only needs the
 * join key and `status`), filtered to the revenue statuses
 * `('paid','fulfilled','refunded')`. This branch is windowed on the ORDER's
 * own report time (`placed_at`, falling back to `created_at` -- never
 * `WHERE COALESCE(...)`, which would defeat the folded indexes). It is
 * grouped by variant with snapshot `MAX(product_name)`/`MAX(sku)`,
 * `SUM(quantity)`, `SUM(line_total) AS revenue_minor`, and ZERO refund
 * columns.
 *
 * Refund branch: `commerce_refund_lines` joined to `commerce_refunds`
 * (`tenant_uuid = ?`, `status = 'completed'`, windowed on `completed_at` --
 * decision 5, independent of the underlying order's own window membership)
 * joined to `commerce_order_lines` through BOTH `order_line_uuid` (which
 * specific line was refunded) AND the refund's own `order_uuid` (the joined
 * order line must belong to the refund's order). Refund lines are optional
 * in the as-built refund contract: an INNER JOIN through
 * `commerce_refund_lines` means a completed refund with NO lines contributes
 * NO rows to this branch, so it is never assignable to a product (design
 * spec §4.2). This branch does not filter on the underlying order's own
 * status -- the refund's own `completed` status is the only gate. It is
 * grouped by variant with ZERO sales columns and `SUM(refund line amount)`/
 * `SUM(refund line quantity)` as `attributed_refunded_minor`/
 * `attributed_refunded_quantity`.
 *
 * This preserves a June-completed refund for a May order as a refund-only
 * variant row in June's report (the sales branch has no June rows for that
 * order, but the refund branch does).
 *
 * Snapshot columns (`product_name`/`sku`) are `MAX()`'d over whichever
 * branch(es) produced rows for a variant -- `commerce_order_lines` never
 * updates these columns after insert, so they always reflect the order-time
 * snapshot even if the live catalog product/variant is later renamed.
 *
 * Sort: `revenue` orders by the OUTER `SUM(revenue_minor) DESC`; `quantity`
 * orders by the OUTER `SUM(quantity) DESC`. Ties ALWAYS break
 * `variant_uuid ASC` -- unconditional, not merely a value-tie fallback --
 * so pagination is deterministic across pages.
 */
final class ProductSalesReportRepository
{
    private const REVENUE_STATUSES = "'paid','fulfilled','refunded'";

    /** Sort key (public API) => outer SELECT-list alias. */
    private const SORT_COLUMNS = [
        'revenue' => 'revenue_minor',
        'quantity' => 'quantity',
    ];

    /**
     * @return array{
     *     items: list<array{
     *         variant_uuid: string,
     *         sku: string,
     *         product_name: string,
     *         quantity: int,
     *         revenue_minor: int,
     *         attributed_refunded_minor: int,
     *         attributed_refunded_quantity: int
     *     }>,
     *     total: int
     * }
     */
    public function paginate(
        ApplicationContext $context,
        string $tenant,
        ReportWindow $window,
        string $sort,
        int $page,
        int $perPage
    ): array {
        if (!isset(self::SORT_COLUMNS[$sort])) {
            throw new \InvalidArgumentException("Unsupported products sort field: {$sort}");
        }
        $sortColumn = self::SORT_COLUMNS[$sort];

        $from = $window->fromSql();
        $to = $window->toExclusiveSql();
        $activitySql = $this->activityUnionSql();

        // Binding order: sales branch [tenant, from, to, tenant, from, to]
        // (the two-branch report_at derived table), then refund branch
        // [tenant, from, to] -- 9 params total, identical for both queries
        // below.
        $bindings = [$tenant, $from, $to, $tenant, $from, $to, $tenant, $from, $to];

        $totalRow = db($context)->table('commerce_order_lines')->executeRawFirst(
            'SELECT COUNT(DISTINCT variant_uuid) AS total FROM (' . $activitySql . ') activity',
            $bindings
        );
        $total = (int) ($totalRow['total'] ?? 0);

        $rows = db($context)->table('commerce_order_lines')->executeRaw(
            'SELECT variant_uuid, '
                . 'MAX(sku) AS sku, '
                . 'MAX(product_name) AS product_name, '
                . 'SUM(quantity) AS quantity, '
                . 'SUM(revenue_minor) AS revenue_minor, '
                . 'SUM(attributed_refunded_minor) AS attributed_refunded_minor, '
                . 'SUM(attributed_refunded_quantity) AS attributed_refunded_quantity '
                . 'FROM (' . $activitySql . ') activity '
                . 'GROUP BY variant_uuid '
                . "ORDER BY {$sortColumn} DESC, variant_uuid ASC "
                . 'LIMIT ? OFFSET ?',
            [...$bindings, $perPage, max(0, $page - 1) * $perPage]
        );

        return [
            'items' => array_map(fn (array $row): array => $this->projectRow($row), $rows),
            'total' => $total,
        ];
    }

    private function activityUnionSql(): string
    {
        return $this->salesBranchSql() . ' UNION ALL ' . $this->refundBranchSql();
    }

    /**
     * Sales branch: grouped by variant, snapshot `MAX()`s, summed
     * quantity/revenue, ZERO refund columns.
     */
    private function salesBranchSql(): string
    {
        return 'SELECT ol.variant_uuid AS variant_uuid, '
            . 'MAX(ol.sku) AS sku, '
            . 'MAX(ol.product_name) AS product_name, '
            . 'SUM(ol.quantity) AS quantity, '
            . 'SUM(ol.line_total) AS revenue_minor, '
            . '0 AS attributed_refunded_minor, '
            . '0 AS attributed_refunded_quantity '
            . 'FROM commerce_order_lines ol '
            . 'JOIN ' . $this->reportOrdersDerivedTableSql() . ' ro ON ol.order_uuid = ro.order_uuid '
            . 'WHERE ro.status IN (' . self::REVENUE_STATUSES . ') '
            . 'GROUP BY ol.variant_uuid';
    }

    /**
     * Refund branch: grouped by variant, ZERO sales columns, summed
     * refund-line amount/quantity. `commerce_refund_lines` INNER JOINed
     * through both `order_line_uuid` and `ol.order_uuid = r.order_uuid` --
     * a completed refund with no lines contributes no rows here.
     */
    private function refundBranchSql(): string
    {
        return 'SELECT ol.variant_uuid AS variant_uuid, '
            . 'MAX(ol.sku) AS sku, '
            . 'MAX(ol.product_name) AS product_name, '
            . '0 AS quantity, '
            . '0 AS revenue_minor, '
            . 'SUM(rl.amount) AS attributed_refunded_minor, '
            . 'SUM(rl.quantity) AS attributed_refunded_quantity '
            . 'FROM commerce_refund_lines rl '
            . 'JOIN commerce_refunds r ON rl.refund_uuid = r.uuid '
            . 'JOIN commerce_order_lines ol ON rl.order_line_uuid = ol.uuid AND ol.order_uuid = r.order_uuid '
            . "WHERE r.tenant_uuid = ? AND r.status = 'completed' "
            . 'AND r.completed_at >= ? AND r.completed_at < ? '
            . 'GROUP BY ol.variant_uuid';
    }

    /**
     * The two-branch `report_at` derived table (mirrors
     * {@see SalesReportRepository}): branch 1 uses `placed_at` when present
     * and ranged; branch 2 falls back to `created_at` only when `placed_at
     * IS NULL`, ranged on `created_at`. Both branches start `tenant_uuid = ?`;
     * NEVER `WHERE COALESCE(...)`. Only `uuid`/`status` are projected -- this
     * branch needs the join key and the revenue-status filter, not the money
     * columns `SalesReportRepository` projects for its own aggregates.
     *
     * Binding order per use: `[tenant, from, to, tenant, from, to]`.
     */
    private function reportOrdersDerivedTableSql(): string
    {
        return '('
            . 'SELECT uuid AS order_uuid, status '
            . 'FROM commerce_orders '
            . 'WHERE tenant_uuid = ? AND placed_at IS NOT NULL AND placed_at >= ? AND placed_at < ? '
            . 'UNION ALL '
            . 'SELECT uuid AS order_uuid, status '
            . 'FROM commerce_orders '
            . 'WHERE tenant_uuid = ? AND placed_at IS NULL AND created_at >= ? AND created_at < ?'
            . ')';
    }

    /**
     * @param array<string,mixed> $row
     * @return array{
     *     variant_uuid: string,
     *     sku: string,
     *     product_name: string,
     *     quantity: int,
     *     revenue_minor: int,
     *     attributed_refunded_minor: int,
     *     attributed_refunded_quantity: int
     * }
     */
    private function projectRow(array $row): array
    {
        return [
            'variant_uuid' => (string) $row['variant_uuid'],
            'sku' => (string) $row['sku'],
            'product_name' => (string) $row['product_name'],
            'quantity' => (int) $row['quantity'],
            'revenue_minor' => (int) $row['revenue_minor'],
            'attributed_refunded_minor' => (int) $row['attributed_refunded_minor'],
            'attributed_refunded_quantity' => (int) $row['attributed_refunded_quantity'],
        ];
    }
}
