<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Reports;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Stock report's raw-SQL point-in-time list (design spec §4.4, decision 10):
 * `commerce_stock` (`tenant_uuid = ?`, `tracked = ?` bound `true`,
 * `quantity <= :threshold`) JOIN `commerce_variants` (`status = 'active'`; variants have no
 * `deleted_at`) JOIN `commerce_products` (`deleted_at IS NULL` --
 * draft/inactive products remain visible to stock administrators until
 * trashed). `uuid` is globally unique on all three tables (each has its own
 * `unique('uuid')` index -- see the catalog/inventory migrations), so the
 * variant/product joins need no additional tenant predicate beyond the
 * `commerce_stock` row's own `tenant_uuid = ?` filter.
 *
 * `status` is derived per row, never stored: `out_of_stock` when
 * `quantity <= 0`, else `low_stock` (both classes only ever reach this query
 * because `quantity <= threshold` already excludes anything higher).
 * `?status=` narrows to exactly one class via an additional predicate;
 * `threshold` itself is resolved by {@see StockThreshold::resolve()} in the
 * controller and passed in already-validated -- this repository trusts it.
 *
 * Order: `quantity ASC, variant_uuid ASC` -- the tie-break is unconditional
 * (not merely a value-tie fallback), so pagination across identical
 * quantities is deterministic.
 *
 * Portability note: `commerce_stock.tracked` is a genuine `boolean` column
 * (`$table->boolean('tracked')`, migrations/002). A literal `tracked = 1`
 * compiles fine on SQLite/MySQL (both give integers boolean affinity) but
 * fails on PostgreSQL (`operator does not exist: boolean = integer` -- an
 * integer *literal* has a fixed type PostgreSQL never implicitly casts to
 * boolean). Binding the value as a parameter instead (`tracked = ?` / `true`)
 * sidesteps this: native `PDO::ATTR_EMULATE_PREPARES = false` prepares (used
 * for every driver, {@see \Glueful\Database\Connection}) let PostgreSQL infer
 * the placeholder's type from the boolean column itself, and its `boolin()`
 * parser accepts the `'1'` text `ParameterBinder::flattenBindings()` sends
 * for PHP `true` -- the same mechanism the framework's own query builder
 * already relies on for boolean bindings everywhere else.
 */
final class StockReportRepository
{
    /**
     * @return array{
     *     items: list<array{
     *         variant_uuid: string,
     *         sku: string,
     *         product_name: string,
     *         quantity: int,
     *         status: string
     *     }>,
     *     total: int
     * }
     */
    public function paginate(
        ApplicationContext $context,
        string $tenant,
        int $threshold,
        ?string $status,
        int $page,
        int $perPage
    ): array {
        $whereSql = $this->whereSql($status);
        $bindings = [$tenant, true, $threshold];

        $totalRow = db($context)->table('commerce_stock')->executeRawFirst(
            'SELECT COUNT(*) AS total FROM ' . $this->joinSql() . ' WHERE ' . $whereSql,
            $bindings
        );
        $total = (int) ($totalRow['total'] ?? 0);

        $rows = db($context)->table('commerce_stock')->executeRaw(
            'SELECT s.variant_uuid AS variant_uuid, v.sku AS sku, p.name AS product_name, s.quantity AS quantity '
                . 'FROM ' . $this->joinSql() . ' '
                . 'WHERE ' . $whereSql . ' '
                . 'ORDER BY s.quantity ASC, s.variant_uuid ASC '
                . 'LIMIT ? OFFSET ?',
            [...$bindings, $perPage, max(0, $page - 1) * $perPage]
        );

        return [
            'items' => array_map(fn (array $row): array => $this->projectRow($row), $rows),
            'total' => $total,
        ];
    }

    private function joinSql(): string
    {
        return 'commerce_stock s '
            . 'JOIN commerce_variants v ON v.uuid = s.variant_uuid '
            . 'JOIN commerce_products p ON p.uuid = v.product_uuid';
    }

    /**
     * Base predicate is always `tenant_uuid = ? AND tracked = ? AND
     * quantity <= ? AND v.status = 'active' AND p.deleted_at IS NULL`
     * (binding order `[tenant, true, threshold]` -- see the class docblock's
     * portability note for why `tracked` is bound rather than a `= 1`
     * literal); `?status=` appends one more (parameter-free, since 0 is a
     * fixed class boundary, not user input) predicate to narrow to exactly
     * one class.
     */
    private function whereSql(?string $status): string
    {
        $sql = "s.tenant_uuid = ? AND s.tracked = ? AND s.quantity <= ? "
            . "AND v.status = 'active' AND p.deleted_at IS NULL";

        return match ($status) {
            'out_of_stock' => $sql . ' AND s.quantity <= 0',
            'low_stock' => $sql . ' AND s.quantity > 0',
            default => $sql,
        };
    }

    /**
     * @param array<string,mixed> $row
     * @return array{
     *     variant_uuid: string,
     *     sku: string,
     *     product_name: string,
     *     quantity: int,
     *     status: string
     * }
     */
    private function projectRow(array $row): array
    {
        $quantity = (int) $row['quantity'];

        return [
            'variant_uuid' => (string) $row['variant_uuid'],
            'sku' => (string) $row['sku'],
            'product_name' => (string) $row['product_name'],
            'quantity' => $quantity,
            'status' => $quantity <= 0 ? 'out_of_stock' : 'low_stock',
        ];
    }
}
