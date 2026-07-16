<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Reports;

use DateTimeImmutable;
use DateTimeZone;
use Glueful\Extensions\Commerce\Http\DTOs\ReportWindowQuery;
use Glueful\Validation\ValidationException;

/**
 * The half-open UTC date window every report is computed over: `[from 00:00,
 * to+1day 00:00)`. Defaults to the trailing 30 days (`today-29d` .. `today`,
 * inclusive) when `from`/`to` are omitted; capped to a 366-day span.
 *
 * `fromDates()` is the canonical factory -- `fromQuery()` only unpacks a
 * `ReportWindowQuery` DTO and delegates. A `null` `$today` is constructed
 * fresh in UTC; a supplied `$today` is converted to UTC *before* its date is
 * read, so neither the PHP process's default timezone nor an oddly-zoned
 * caller-supplied instant can shift the derived defaults.
 *
 * Bucketing: `bucketBounds()` returns the FINAL requested grouping (day/week/
 * month) as a zero-filled, chronologically ordered list of `{bucket, from,
 * to}` (end-exclusive UTC datetime strings). Week buckets are ISO-8601
 * (Monday-start, `{isoYear}-W{isoWeek}` key, e.g. `2026-W25`); month buckets
 * use a plain `Y-m` key. Bucket boundaries are the FULL calendar week/month
 * containing each day in the window -- not clipped to `[from, to]` -- because
 * every consumer already restricts its underlying query to `[fromSql(),
 * toExclusiveSql())` first; the bucket boundary is only used to classify rows
 * that already passed that outer window filter.
 */
final class ReportWindow
{
    private const MAX_SPAN_DAYS = 366;
    private const DEFAULT_SPAN_DAYS = 29;

    private function __construct(
        private readonly DateTimeImmutable $from,
        private readonly DateTimeImmutable $to,
        private readonly string $group,
    ) {
    }

    /**
     * Canonical factory. `$today` drives the `from`/`to` defaults only --
     * once resolved, `$today`'s own value plays no further role.
     */
    public static function fromDates(
        ?string $from,
        ?string $to,
        string $group = 'day',
        ?DateTimeImmutable $today = null,
    ): self {
        $utcToday = self::normalizeToUtcMidnight($today);

        $fromDate = $from !== null
            ? self::parseDate($from, 'from')
            : $utcToday->modify('-' . self::DEFAULT_SPAN_DAYS . ' days');
        $toDate = $to !== null ? self::parseDate($to, 'to') : $utcToday;

        if ($fromDate > $toDate) {
            throw ValidationException::forField('to', 'The to date must be on or after the from date.');
        }

        $spanDays = $fromDate->diff($toDate)->days + 1;
        if ($spanDays > self::MAX_SPAN_DAYS) {
            throw ValidationException::forField(
                'to',
                'The date window must not span more than ' . self::MAX_SPAN_DAYS . ' days.'
            );
        }

        return new self($fromDate, $toDate, $group);
    }

    /**
     * Unpacks a `ReportWindowQuery` DTO and delegates to `fromDates()`.
     */
    public static function fromQuery(ReportWindowQuery $query, ?DateTimeImmutable $today = null): self
    {
        return self::fromDates($query->from, $query->to, $query->group ?? 'day', $today);
    }

    /** Inclusive UTC window start, `Y-m-d 00:00:00`, for a bound SQL param. */
    public function fromSql(): string
    {
        return $this->from->format('Y-m-d H:i:s');
    }

    /** Exclusive UTC window end (`to` + 1 day, `00:00:00`), for a bound SQL param. */
    public function toExclusiveSql(): string
    {
        return $this->to->modify('+1 day')->format('Y-m-d H:i:s');
    }

    /** @return list<string> every `Y-m-d` date in the closed `[from, to]` window. */
    public function days(): array
    {
        $days = [];
        $cursor = $this->from;
        while ($cursor <= $this->to) {
            $days[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }

        return $days;
    }

    public function group(): string
    {
        return $this->group;
    }

    public function fromDate(): string
    {
        return $this->from->format('Y-m-d');
    }

    public function toDate(): string
    {
        return $this->to->format('Y-m-d');
    }

    /**
     * @return list<array{bucket: string, from: string, to: string}> the final
     *     requested grouping's buckets, chronologically ordered, end-exclusive
     *     UTC datetime bounds. At most 366 day / 54 week / 13 month entries.
     */
    public function bucketBounds(): array
    {
        return match ($this->group) {
            'week' => $this->weekBucketBounds(),
            'month' => $this->monthBucketBounds(),
            default => $this->dayBucketBounds(),
        };
    }

    /** @return list<array{bucket: string, from: string, to: string}> */
    private function dayBucketBounds(): array
    {
        $bounds = [];
        foreach ($this->days() as $day) {
            $start = self::parseDate($day, 'from');
            $bounds[] = [
                'bucket' => $day,
                'from' => $start->format('Y-m-d H:i:s'),
                'to' => $start->modify('+1 day')->format('Y-m-d H:i:s'),
            ];
        }

        return $bounds;
    }

    /** @return list<array{bucket: string, from: string, to: string}> */
    private function weekBucketBounds(): array
    {
        $bounds = [];
        $seen = [];
        foreach ($this->days() as $day) {
            $date = self::parseDate($day, 'from');
            $monday = $date->modify('monday this week');
            $key = $monday->format('o') . '-W' . $monday->format('W');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $bounds[] = [
                'bucket' => $key,
                'from' => $monday->format('Y-m-d H:i:s'),
                'to' => $monday->modify('+7 days')->format('Y-m-d H:i:s'),
            ];
        }

        return $bounds;
    }

    /** @return list<array{bucket: string, from: string, to: string}> */
    private function monthBucketBounds(): array
    {
        $bounds = [];
        $seen = [];
        foreach ($this->days() as $day) {
            $date = self::parseDate($day, 'from');
            $firstOfMonth = $date->modify('first day of this month');
            $key = $firstOfMonth->format('Y-m');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $bounds[] = [
                'bucket' => $key,
                'from' => $firstOfMonth->format('Y-m-d H:i:s'),
                'to' => $firstOfMonth->modify('first day of next month')->format('Y-m-d H:i:s'),
            ];
        }

        return $bounds;
    }

    /**
     * A `null` `$today` is created fresh in UTC; a supplied `$today` is
     * converted to UTC before its date is read. Either way the result is
     * truncated to UTC midnight, so the PHP process's default timezone never
     * changes the derived `from`/`to` defaults.
     */
    private static function normalizeToUtcMidnight(?DateTimeImmutable $today): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        $normalized = $today !== null ? $today->setTimezone($utc) : new DateTimeImmutable('now', $utc);

        return $normalized->setTime(0, 0, 0);
    }

    private static function parseDate(string $value, string $field): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw ValidationException::forField($field, "The {$field} must be a valid date in the format Y-m-d.");
        }

        return $date;
    }
}
