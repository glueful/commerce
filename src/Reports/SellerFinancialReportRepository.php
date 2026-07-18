<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Reports;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Support\DateBucketSql;

/**
 * Seller/marketplace financial report's raw-SQL day-grouped aggregate
 * (design spec §6.1/§6.2, MV3 Task 11): mirrors
 * {@see SalesReportRepository::salesByDay()}'s day-grouped
 * `GROUP BY DateBucketSql::dayExpression(...)` idiom, ONE query against the
 * append-only `commerce_marketplace_ledger` (design spec §2.5), scoped to a
 * single `(tenant_uuid, account_key, currency)` account -- balances/report
 * figures are currency-separated (§2.9), so this never spans currencies.
 * Grouping by day only (never week/month) -- {@see ReportRollup} folds day
 * rows into the caller's requested grouping in pure PHP, the same division
 * of labor {@see SalesReportRepository}'s own docblock establishes; no
 * database week/month function is used here either.
 *
 * Unlike `commerce_orders`' `placed_at`/`created_at` duality, every ledger
 * row has exactly one `created_at` timestamp -- there is no two-branch
 * `report_at` derived table to build here.
 *
 * Only the four entry types the financial report's `gross/commission/
 * refunds/reversed` fields are built from ({@see self::KNOWN_TYPES}) are
 * summed; `adjustment`/`payout_debit`/`reserve_*` rows on the SAME account
 * are deliberately excluded from these day-bucketed sums (they never
 * contribute to gross/commission/refund/reversal math) -- a caller wanting
 * the account's LIVE, non-windowed balance (which DOES include those types)
 * uses {@see \Glueful\Extensions\Commerce\Marketplace\SellerBalanceService}
 * instead, never this class.
 */
final class SellerFinancialReportRepository
{
    private const KNOWN_TYPES = ['sale_credit', 'commission_debit', 'refund_debit', 'commission_reversal'];

    /**
     * @return array<string, array{
     *     gross_minor: int, commission_minor: int, refunds_minor: int, commission_reversed_minor: int
     * }> `Y-m-d` => additive field map, shaped for {@see ReportRollup::fold()}
     */
    public function reportByDay(
        ApplicationContext $context,
        string $tenant,
        string $accountKey,
        string $currency,
        ReportWindow $window
    ): array {
        $driver = db($context)->getDriverName();
        $dayExpr = DateBucketSql::dayExpression($driver, 'created_at');

        $rows = db($context)->table('commerce_marketplace_ledger')->executeRaw(
            'SELECT ' . $dayExpr . ' AS bucket, entry_type, SUM(amount) AS total '
                . 'FROM commerce_marketplace_ledger '
                . 'WHERE tenant_uuid = ? AND account_key = ? AND currency = ? '
                . 'AND created_at >= ? AND created_at < ? '
                . 'GROUP BY ' . $dayExpr . ', entry_type',
            [$tenant, $accountKey, $currency, $window->fromSql(), $window->toExclusiveSql()]
        );

        /** @var array<string, array<string,int>> $sums day => entry_type => SUM(amount) */
        $sums = [];
        foreach ($rows as $row) {
            $entryType = (string) $row['entry_type'];
            if (!in_array($entryType, self::KNOWN_TYPES, true)) {
                continue;
            }

            $day = (string) $row['bucket'];
            $sums[$day] ??= array_fill_keys(self::KNOWN_TYPES, 0);
            $sums[$day][$entryType] = (int) $row['total'];
        }

        $result = [];
        foreach ($sums as $day => $s) {
            $result[$day] = [
                'gross_minor' => $s['sale_credit'],
                'commission_minor' => -$s['commission_debit'],
                'refunds_minor' => -$s['refund_debit'],
                'commission_reversed_minor' => $s['commission_reversal'],
            ];
        }

        return $result;
    }

    /**
     * Folds day-bucketed ledger sums into the window's requested grouping
     * AND derives `net_minor` (`gross − commission − refunds + reversed`,
     * pinned by design spec §6.1/§6.2) for both the series and its window
     * summary -- the ONE seam the seller
     * ({@see \Glueful\Extensions\Commerce\Http\Seller\SellerFinancialController})
     * and operator
     * ({@see \Glueful\Extensions\Commerce\Http\Admin\AdminMarketplaceFinancialController})
     * financial-report endpoints share, so the net formula and the summary-
     * from-series discipline ({@see AdminReportController}'s own convention:
     * additive fields summed from the already-folded series, never a third
     * query) are defined exactly once. `net_minor` is itself additive (a sum
     * of additive terms), so summing the summary from the series' already-
     * folded fields is exact either way -- this mirrors, rather than
     * contradicts, `AdminReportController::sales()`'s care to RECOMPUTE
     * non-linear figures (like AOV) from summed inputs instead of summing
     * per-bucket derived values.
     *
     * @param array<string, array{
     *     gross_minor: int, commission_minor: int, refunds_minor: int, commission_reversed_minor: int
     * }> $dayRows
     * @return array{
     *     series: list<array{
     *         bucket: string, gross_minor: int, commission_minor: int, refunds_minor: int,
     *         commission_reversed_minor: int, net_minor: int
     *     }>,
     *     summary: array{
     *         gross_minor: int, commission_minor: int, refunds_minor: int,
     *         commission_reversed_minor: int, net_minor: int
     *     }
     * }
     */
    public static function foldSeries(array $dayRows, ReportWindow $window): array
    {
        /**
         * `ReportRollup::fold()` only guarantees a bare `{bucket: string}` shape
         * when `$dayRows` is completely empty; every other bucket carries the
         * full field shape because `reportByDay()` always seeds all four fields
         * together per day.
         *
         * @var list<array{
         *     bucket: string, gross_minor?: int, commission_minor?: int,
         *     refunds_minor?: int, commission_reversed_minor?: int
         * }> $folded
         */
        $folded = ReportRollup::fold($dayRows, $window);

        $series = [];
        foreach ($folded as $row) {
            $gross = (int) ($row['gross_minor'] ?? 0);
            $commission = (int) ($row['commission_minor'] ?? 0);
            $refunds = (int) ($row['refunds_minor'] ?? 0);
            $reversed = (int) ($row['commission_reversed_minor'] ?? 0);

            $series[] = [
                'bucket' => $row['bucket'],
                'gross_minor' => $gross,
                'commission_minor' => $commission,
                'refunds_minor' => $refunds,
                'commission_reversed_minor' => $reversed,
                'net_minor' => $gross - $commission - $refunds + $reversed,
            ];
        }

        $summaryGross = (int) array_sum(array_column($series, 'gross_minor'));
        $summaryCommission = (int) array_sum(array_column($series, 'commission_minor'));
        $summaryRefunds = (int) array_sum(array_column($series, 'refunds_minor'));
        $summaryReversed = (int) array_sum(array_column($series, 'commission_reversed_minor'));

        return [
            'series' => $series,
            'summary' => [
                'gross_minor' => $summaryGross,
                'commission_minor' => $summaryCommission,
                'refunds_minor' => $summaryRefunds,
                'commission_reversed_minor' => $summaryReversed,
                'net_minor' => $summaryGross - $summaryCommission - $summaryRefunds + $summaryReversed,
            ],
        ];
    }
}
