<?php

namespace App\Http\Dashboard;

use App\Enums\DashboardRange;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The dashboard's clock (spec 14 §Data contract): one inclusive window, its comparison window,
 * and the buckets the line chart is drawn from.
 *
 * The business day is `config('crm.timezone')` (Asia/Manila); queries run in UTC because that
 * is what the database stores. `quarter` is the trailing 90 days rather than a calendar
 * quarter — the D1 fixture's "Monthly, Jul – Oct" is exactly that shape, and it keeps the
 * toggle meaningfully different from the last 30 days.
 */
final readonly class DashboardPeriod
{
    public function __construct(
        public DashboardRange $range,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public CarbonImmutable $previousStart,
        public CarbonImmutable $previousEnd,
    ) {}

    public static function for(DashboardRange $range, ?CarbonInterface $now = null): self
    {
        $timezone = (string) config('crm.timezone', 'Asia/Manila');

        $end = $now !== null
            ? CarbonImmutable::instance($now)->setTimezone($timezone)
            : CarbonImmutable::now($timezone);

        $start = match ($range) {
            DashboardRange::Last30Days => $end->subDays(29)->startOfDay(),
            DashboardRange::Quarter => $end->subDays(89)->startOfDay(),
            DashboardRange::YearToDate => $end->startOfYear(),
        };

        if ($range === DashboardRange::YearToDate) {
            // "vs the same period last year" is the trend the YTD fixture tells.
            $previousStart = $start->subYear();
            $previousEnd = $end->subYear();
        } else {
            $previousEnd = $start->subSecond();
            $previousStart = $previousEnd->subDays((int) $start->diffInDays($end))->startOfDay();
        }

        return new self($range, $start, $end, $previousStart, $previousEnd);
    }

    public function startUtc(): CarbonImmutable
    {
        return $this->start->utc();
    }

    public function endUtc(): CarbonImmutable
    {
        return $this->end->utc();
    }

    public function previousStartUtc(): CarbonImmutable
    {
        return $this->previousStart->utc();
    }

    public function previousEndUtc(): CarbonImmutable
    {
        return $this->previousEnd->utc();
    }

    public function startDate(): string
    {
        return $this->start->toDateString();
    }

    public function endDate(): string
    {
        return $this->end->toDateString();
    }

    public function previousStartDate(): string
    {
        return $this->previousStart->toDateString();
    }

    public function previousEndDate(): string
    {
        return $this->previousEnd->toDateString();
    }

    /**
     * The weekly or monthly points the line chart uses.
     *
     * 30 days reads weekly (five points, the last one capped at "now"); the longer ranges
     * read monthly so a quarter is four columns, not thirteen.
     *
     * @return array<int, array{label: string, start: CarbonImmutable, end: CarbonImmutable}>
     */
    public function buckets(): array
    {
        return $this->range === DashboardRange::Last30Days
            ? $this->weeklyBuckets()
            : $this->monthlyBuckets();
    }

    public function caption(): string
    {
        if ($this->range === DashboardRange::Last30Days) {
            return sprintf('Weekly, %s – %s', $this->start->format('j M'), $this->end->format('j M'));
        }

        if ($this->start->format('Y-m') === $this->end->format('Y-m')) {
            return sprintf('Monthly, %s', $this->end->format('F Y'));
        }

        return sprintf('Monthly, %s – %s', $this->start->format('M'), $this->end->format('M Y'));
    }

    /**
     * @return array<int, array{label: string, start: CarbonImmutable, end: CarbonImmutable}>
     */
    private function weeklyBuckets(): array
    {
        $buckets = [];
        $cursor = $this->start;

        while ($cursor->lessThanOrEqualTo($this->end)) {
            $bucketEnd = $cursor->addDays(6)->endOfDay();

            if ($bucketEnd->greaterThan($this->end)) {
                $bucketEnd = $this->end;
            }

            $buckets[] = [
                'label' => $cursor->format('j M'),
                'start' => $cursor,
                'end' => $bucketEnd,
            ];

            if ($bucketEnd->greaterThanOrEqualTo($this->end)) {
                break;
            }

            $cursor = $bucketEnd->addDay()->startOfDay();
        }

        return $buckets;
    }

    /**
     * @return array<int, array{label: string, start: CarbonImmutable, end: CarbonImmutable}>
     */
    private function monthlyBuckets(): array
    {
        $buckets = [];
        $cursor = $this->start->startOfMonth();

        while ($cursor->lessThanOrEqualTo($this->end)) {
            $bucketStart = $cursor->lessThan($this->start) ? $this->start : $cursor;
            $monthEnd = $cursor->endOfMonth();
            $bucketEnd = $monthEnd->greaterThan($this->end) ? $this->end : $monthEnd;

            $buckets[] = [
                'label' => $cursor->format('M'),
                'start' => $bucketStart,
                'end' => $bucketEnd,
            ];

            $cursor = $cursor->addMonth()->startOfMonth();
        }

        return $buckets;
    }
}
