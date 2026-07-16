<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Reports;

/**
 * Folds day-bucketed additive integer rows (as produced by a `GROUP BY` on
 * `DateBucketSql::dayExpression()`) into the window's FINAL requested
 * grouping -- day (pass-through), ISO-8601 week, or calendar month -- and
 * zero-fills every bucket in the window, including buckets with no data at
 * all.
 *
 * Pure PHP, driver-independent: this is precisely why sales bucket in SQL by
 * UTC day only (`ReportWindow`/`DateBucketSql` docblocks) -- cross-driver
 * week/month functions diverge, so the rollup happens here instead.
 *
 * ONLY for additive figures (money/order counts that are correct to sum
 * across days, e.g. gross revenue, order counts, discount/shipping/tax
 * totals). Customer counts are NOT additive across days -- the same customer
 * ordering on two different days of one ISO week must be counted once, not
 * twice -- so the customer report never uses `ReportRollup`; it uses bounded
 * DB-side `DISTINCT` aggregates against `ReportWindow::bucketBounds()`
 * instead (see `ReportBoundarySql`).
 */
final class ReportRollup
{
    /**
     * @param array<string, array<string, int>> $dayRows `Y-m-d` => additive
     *     int field map. A date outside the window is ignored defensively.
     *     The set of fields zero-filled for buckets with no data is the union
     *     of field names across every entry given (so callers should include
     *     the full field shape on at least one row when the window is not
     *     entirely empty; on a completely empty `$dayRows`, buckets contain
     *     only their `bucket` key).
     * @return list<array{bucket: string, ...}>
     */
    public static function fold(array $dayRows, ReportWindow $window): array
    {
        $buckets = $window->bucketBounds();

        $fieldNames = [];
        foreach ($dayRows as $fields) {
            foreach (array_keys($fields) as $field) {
                $fieldNames[$field] = true;
            }
        }
        $fieldNames = array_keys($fieldNames);

        $sums = [];
        foreach ($buckets as $bucket) {
            $sums[$bucket['bucket']] = array_fill_keys($fieldNames, 0);
        }

        foreach ($dayRows as $date => $fields) {
            $dayStart = $date . ' 00:00:00';
            foreach ($buckets as $bucket) {
                if ($dayStart >= $bucket['from'] && $dayStart < $bucket['to']) {
                    foreach ($fields as $field => $value) {
                        $sums[$bucket['bucket']][$field] = ($sums[$bucket['bucket']][$field] ?? 0) + (int) $value;
                    }
                    break;
                }
            }
        }

        $result = [];
        foreach ($buckets as $bucket) {
            $result[] = ['bucket' => $bucket['bucket']] + $sums[$bucket['bucket']];
        }

        return $result;
    }

    private function __construct()
    {
    }
}
