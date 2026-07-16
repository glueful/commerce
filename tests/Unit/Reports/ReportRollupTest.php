<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Reports;

use Glueful\Extensions\Commerce\Reports\ReportRollup;
use Glueful\Extensions\Commerce\Reports\ReportWindow;
use PHPUnit\Framework\TestCase;

final class ReportRollupTest extends TestCase
{
    public function testDayGroupPassesThroughAndZeroFillsMissingDays(): void
    {
        $window = ReportWindow::fromDates('2026-07-01', '2026-07-03', 'day');

        $result = ReportRollup::fold([
            '2026-07-01' => ['gross_minor' => 100, 'orders_count' => 1],
            '2026-07-03' => ['gross_minor' => 50, 'orders_count' => 2],
        ], $window);

        self::assertSame([
            ['bucket' => '2026-07-01', 'gross_minor' => 100, 'orders_count' => 1],
            ['bucket' => '2026-07-02', 'gross_minor' => 0, 'orders_count' => 0],
            ['bucket' => '2026-07-03', 'gross_minor' => 50, 'orders_count' => 2],
        ], $result);
    }

    public function testZeroFillProducesBucketOnlyEntriesWhenNoDayRowsGiven(): void
    {
        $window = ReportWindow::fromDates('2026-07-01', '2026-07-02', 'day');

        $result = ReportRollup::fold([], $window);

        self::assertSame([
            ['bucket' => '2026-07-01'],
            ['bucket' => '2026-07-02'],
        ], $result);
    }

    public function testFieldSetIsTheUnionOfKeysAcrossAllProvidedDayRows(): void
    {
        $window = ReportWindow::fromDates('2026-07-01', '2026-07-02', 'day');

        $result = ReportRollup::fold([
            '2026-07-01' => ['gross_minor' => 100],
            '2026-07-02' => ['orders_count' => 3],
        ], $window);

        self::assertSame([
            ['bucket' => '2026-07-01', 'gross_minor' => 100, 'orders_count' => 0],
            ['bucket' => '2026-07-02', 'gross_minor' => 0, 'orders_count' => 3],
        ], $result);
    }

    public function testDayRowsOutsideTheWindowDoNotContributeToAnyBucket(): void
    {
        $window = ReportWindow::fromDates('2026-07-01', '2026-07-01', 'day');

        $result = ReportRollup::fold([
            '2026-06-30' => ['gross_minor' => 999],
            '2026-07-01' => ['gross_minor' => 10],
        ], $window);

        self::assertSame([
            ['bucket' => '2026-07-01', 'gross_minor' => 10],
        ], $result);
    }

    public function testWeekGroupSumsDayRowsAcrossTheIsoW53W01YearBoundary(): void
    {
        $window = ReportWindow::fromDates('2026-12-28', '2027-01-04', 'week');

        $result = ReportRollup::fold([
            '2026-12-28' => ['gross_minor' => 10],
            '2026-12-31' => ['gross_minor' => 20],
            '2027-01-01' => ['gross_minor' => 5],
            '2027-01-04' => ['gross_minor' => 7],
        ], $window);

        self::assertSame([
            ['bucket' => '2026-W53', 'gross_minor' => 35],
            ['bucket' => '2027-W01', 'gross_minor' => 7],
        ], $result);
    }

    public function testWeekGroupZeroFillsWeeksWithNoActivity(): void
    {
        // 2026-07-06 (Mon, ISO W28) .. 2026-07-19 (Sun, ISO W29): two clean weeks.
        $window = ReportWindow::fromDates('2026-07-06', '2026-07-19', 'week');

        $result = ReportRollup::fold([
            '2026-07-06' => ['gross_minor' => 3],
        ], $window);

        self::assertSame([
            ['bucket' => '2026-W28', 'gross_minor' => 3],
            ['bucket' => '2026-W29', 'gross_minor' => 0],
        ], $result);
    }

    public function testMonthGroupSumsDayRowsIntoCalendarMonthBuckets(): void
    {
        $window = ReportWindow::fromDates('2026-01-15', '2026-03-10', 'month');

        $result = ReportRollup::fold([
            '2026-01-20' => ['x' => 1],
            '2026-02-01' => ['x' => 2],
            '2026-02-28' => ['x' => 3],
            '2026-03-05' => ['x' => 4],
        ], $window);

        self::assertSame([
            ['bucket' => '2026-01', 'x' => 1],
            ['bucket' => '2026-02', 'x' => 5],
            ['bucket' => '2026-03', 'x' => 4],
        ], $result);
    }

    public function testMonthGroupZeroFillsMonthsWithNoActivity(): void
    {
        $window = ReportWindow::fromDates('2026-01-15', '2026-03-10', 'month');

        $result = ReportRollup::fold([
            '2026-02-01' => ['x' => 9],
        ], $window);

        self::assertSame([
            ['bucket' => '2026-01', 'x' => 0],
            ['bucket' => '2026-02', 'x' => 9],
            ['bucket' => '2026-03', 'x' => 0],
        ], $result);
    }

    public function testBucketOrderIsChronological(): void
    {
        $window = ReportWindow::fromDates('2026-01-15', '2026-03-10', 'month');

        $result = ReportRollup::fold([], $window);

        self::assertSame(['2026-01', '2026-02', '2026-03'], array_column($result, 'bucket'));
    }
}
