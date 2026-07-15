<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Tests\Unit\Reports;

use DateTimeImmutable;
use DateTimeZone;
use Glueful\Extensions\Commerce\Http\DTOs\ReportWindowQuery;
use Glueful\Extensions\Commerce\Reports\ReportWindow;
use Glueful\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

final class ReportWindowTest extends TestCase
{
    private ?string $previousTimezone = null;

    protected function tearDown(): void
    {
        if ($this->previousTimezone !== null) {
            date_default_timezone_set($this->previousTimezone);
            $this->previousTimezone = null;
        }

        parent::tearDown();
    }

    // -- defaults ---------------------------------------------------------

    public function testDefaultsAreTodayMinus29DaysThroughToday(): void
    {
        $today = new DateTimeImmutable('2026-07-15 12:00:00', new DateTimeZone('UTC'));
        $window = ReportWindow::fromDates(null, null, 'day', $today);

        self::assertSame('2026-06-16', $window->fromDate());
        self::assertSame('2026-07-15', $window->toDate());
    }

    public function testExplicitFromAndToOverrideDefaults(): void
    {
        $today = new DateTimeImmutable('2026-07-15 12:00:00', new DateTimeZone('UTC'));
        $window = ReportWindow::fromDates('2026-01-01', '2026-01-10', 'day', $today);

        self::assertSame('2026-01-01', $window->fromDate());
        self::assertSame('2026-01-10', $window->toDate());
    }

    public function testGroupDefaultsToDay(): void
    {
        $window = ReportWindow::fromDates('2026-01-01', '2026-01-10');

        self::assertSame('day', $window->group());
    }

    // -- half-open SQL bounds ----------------------------------------------

    public function testFromSqlIsMidnightOfFromDate(): void
    {
        $window = ReportWindow::fromDates('2026-01-01', '2026-01-10');

        self::assertSame('2026-01-01 00:00:00', $window->fromSql());
    }

    public function testToExclusiveSqlIsMidnightOfTheDayAfterTo(): void
    {
        $window = ReportWindow::fromDates('2026-01-01', '2026-01-10');

        self::assertSame('2026-01-11 00:00:00', $window->toExclusiveSql());
    }

    // -- days() --------------------------------------------------------------

    public function testDaysListsEveryDateInTheClosedWindow(): void
    {
        $window = ReportWindow::fromDates('2026-07-01', '2026-07-03');

        self::assertSame(['2026-07-01', '2026-07-02', '2026-07-03'], $window->days());
    }

    public function testSingleDayWindowHasOneDay(): void
    {
        $window = ReportWindow::fromDates('2026-07-01', '2026-07-01');

        self::assertSame(['2026-07-01'], $window->days());
    }

    // -- validation: from <= to ----------------------------------------------

    public function testFromAfterToThrows422(): void
    {
        try {
            ReportWindow::fromDates('2026-07-10', '2026-07-01');
            self::fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getStatusCode());
            self::assertTrue($e->hasError('to') || $e->hasError('from'));
        }
    }

    public function testFromEqualToToIsAllowed(): void
    {
        $window = ReportWindow::fromDates('2026-07-01', '2026-07-01');

        self::assertSame(['2026-07-01'], $window->days());
    }

    // -- validation: span cap ------------------------------------------------

    public function test366DayInclusiveWindowIsAllowed(): void
    {
        // 2026-01-01 .. 2027-01-01 inclusive is 366 calendar days.
        $window = ReportWindow::fromDates('2026-01-01', '2027-01-01');

        self::assertCount(366, $window->days());
    }

    public function test367DayInclusiveWindowThrows422(): void
    {
        $this->expectException(ValidationException::class);

        // 2026-01-01 .. 2027-01-02 inclusive is 367 calendar days.
        ReportWindow::fromDates('2026-01-01', '2027-01-02');
    }

    public function test422ExceptionUsesForFieldHelper(): void
    {
        try {
            ReportWindow::fromDates('2026-01-01', '2027-01-02');
            self::fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getStatusCode());
        }
    }

    // -- future `to` is allowed -----------------------------------------------

    public function testFutureToIsAllowed(): void
    {
        // $today is pinned to 2026-07-15, so 2026-08-01 is "in the future"
        // relative to it, yet still well within the 366-day span cap.
        $today = new DateTimeImmutable('2026-07-15 12:00:00', new DateTimeZone('UTC'));
        $window = ReportWindow::fromDates('2026-07-01', '2026-08-01', 'day', $today);

        self::assertSame('2026-08-01', $window->toDate());
    }

    // -- fromQuery / fromDates equivalence ------------------------------------

    public function testFromQueryDelegatesToFromDates(): void
    {
        $today = new DateTimeImmutable('2026-07-15 12:00:00', new DateTimeZone('UTC'));
        $query = new ReportWindowQuery(from: '2026-06-01', to: '2026-06-10', group: 'week');

        $viaQuery = ReportWindow::fromQuery($query, $today);
        $viaDates = ReportWindow::fromDates('2026-06-01', '2026-06-10', 'week', $today);

        self::assertSame($viaDates->fromDate(), $viaQuery->fromDate());
        self::assertSame($viaDates->toDate(), $viaQuery->toDate());
        self::assertSame($viaDates->group(), $viaQuery->group());
        self::assertSame($viaDates->bucketBounds(), $viaQuery->bucketBounds());
    }

    public function testFromQueryWithNullGroupDefaultsToDay(): void
    {
        $query = new ReportWindowQuery(from: '2026-06-01', to: '2026-06-10', group: null);

        $window = ReportWindow::fromQuery($query);

        self::assertSame('day', $window->group());
    }

    public function testFromQueryWithNullFromAndToUsesSameDefaultsAsFromDates(): void
    {
        $today = new DateTimeImmutable('2026-07-15 12:00:00', new DateTimeZone('UTC'));
        $query = new ReportWindowQuery(from: null, to: null, group: null);

        $window = ReportWindow::fromQuery($query, $today);

        self::assertSame('2026-06-16', $window->fromDate());
        self::assertSame('2026-07-15', $window->toDate());
    }

    // -- UTC normalization: process timezone must never leak in ----------------

    public function testNullTodayIsConstructedInUtcRegardlessOfProcessTimezone(): void
    {
        $this->previousTimezone = date_default_timezone_get();
        date_default_timezone_set('Pacific/Kiritimati'); // UTC+14

        $window = ReportWindow::fromDates(null, null);

        self::assertSame(gmdate('Y-m-d'), $window->toDate());
    }

    public function testSuppliedNonUtcTodayIsConvertedToUtcBeforeDerivingDefaults(): void
    {
        $this->previousTimezone = date_default_timezone_get();
        date_default_timezone_set('Pacific/Kiritimati'); // process TZ must not matter either

        // 2026-07-16 03:00 JST == 2026-07-15 18:00 UTC.
        $today = new DateTimeImmutable('2026-07-16 03:00:00', new DateTimeZone('Asia/Tokyo'));
        $window = ReportWindow::fromDates(null, null, 'day', $today);

        self::assertSame('2026-07-15', $window->toDate());
        self::assertSame('2026-06-16', $window->fromDate());
    }

    // -- bucketBounds(): day -----------------------------------------------------

    public function testDayBucketBoundsAreHalfOpenPerDay(): void
    {
        $window = ReportWindow::fromDates('2026-07-01', '2026-07-02', 'day');

        self::assertSame([
            ['bucket' => '2026-07-01', 'from' => '2026-07-01 00:00:00', 'to' => '2026-07-02 00:00:00'],
            ['bucket' => '2026-07-02', 'from' => '2026-07-02 00:00:00', 'to' => '2026-07-03 00:00:00'],
        ], $window->bucketBounds());
    }

    // -- bucketBounds(): week, including the ISO W53/W01 year boundary -----------

    public function testWeekBucketBoundsCrossingIsoW53ToW01YearBoundary(): void
    {
        // 2026-12-28 (Mon, ISO week 2026-W53) .. 2027-01-04 (Mon, ISO week 2027-W01).
        $window = ReportWindow::fromDates('2026-12-28', '2027-01-04', 'week');

        self::assertSame([
            ['bucket' => '2026-W53', 'from' => '2026-12-28 00:00:00', 'to' => '2027-01-04 00:00:00'],
            ['bucket' => '2027-W01', 'from' => '2027-01-04 00:00:00', 'to' => '2027-01-11 00:00:00'],
        ], $window->bucketBounds());
    }

    public function testWeekBucketBoundsAreIsoMondayStart(): void
    {
        // 2026-07-15 is a Wednesday inside ISO week 2026-W29 (Mon 2026-07-13 .. Mon 2026-07-20).
        $window = ReportWindow::fromDates('2026-07-15', '2026-07-15', 'week');

        self::assertSame([
            ['bucket' => '2026-W29', 'from' => '2026-07-13 00:00:00', 'to' => '2026-07-20 00:00:00'],
        ], $window->bucketBounds());
    }

    public function testWeekBucketCountIsBoundedForAFullYearWindow(): void
    {
        $window = ReportWindow::fromDates('2026-01-01', '2027-01-01', 'week');

        self::assertLessThanOrEqual(54, count($window->bucketBounds()));
    }

    // -- bucketBounds(): month -----------------------------------------------------

    public function testMonthBucketBoundsFoldByCalendarMonth(): void
    {
        $window = ReportWindow::fromDates('2026-01-15', '2026-03-10', 'month');

        self::assertSame([
            ['bucket' => '2026-01', 'from' => '2026-01-01 00:00:00', 'to' => '2026-02-01 00:00:00'],
            ['bucket' => '2026-02', 'from' => '2026-02-01 00:00:00', 'to' => '2026-03-01 00:00:00'],
            ['bucket' => '2026-03', 'from' => '2026-03-01 00:00:00', 'to' => '2026-04-01 00:00:00'],
        ], $window->bucketBounds());
    }

    public function testMonthBucketCountIsBoundedForAFullYearWindow(): void
    {
        $window = ReportWindow::fromDates('2026-01-01', '2027-01-01', 'month');

        self::assertLessThanOrEqual(13, count($window->bucketBounds()));
    }
}
