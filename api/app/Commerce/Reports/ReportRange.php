<?php

namespace App\Commerce\Reports;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * The days a sales report covers, cut in the account's own timezone.
 *
 * A range is whole local days, first to last, both included. The query
 * bounds are the first day's local midnight and the midnight after the last
 * day, converted to UTC (the zone orders are stored in), so a sale at 23:30
 * in New York on the 31st belongs to the 31st, and a day that DST makes 23
 * or 25 hours long is still one day.
 *
 *   last_7, last_30, last_90   today and the days before it (last_30 is the
 *                              default, as the report always was)
 *   this_month, last_month     calendar months
 *   quarter, last_quarter      quarter to date; the previous whole quarter
 *   ytd, last_year             year to date; the previous calendar year
 *   all                        from the first sale (today when there is none)
 *   custom                     from–to, as given
 *
 * Comparisons: `previous` is the same length immediately before — the
 * previous calendar months when the range is whole months, so "last month"
 * compares with the month before it, not with 30 days — and `year` is the
 * same dates a year earlier.
 *
 * Buckets for the chart and the period table follow the length: days up to
 * 62 days, weeks (from Monday) up to 26 weeks, months beyond.
 */
final class ReportRange
{
    public const PRESETS = ['last_7', 'last_30', 'last_90', 'this_month', 'last_month', 'quarter', 'last_quarter', 'ytd', 'last_year', 'all', 'custom'];

    public const DEFAULT = 'last_30';

    public const COMPARE = ['previous', 'year'];

    public const LABELS = [
        'last_7' => 'Last 7 days',
        'last_30' => 'Last 30 days',
        'last_90' => 'Last 90 days',
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'quarter' => 'Quarter to date',
        'last_quarter' => 'Last quarter',
        'ytd' => 'Year to date',
        'last_year' => 'Last year',
        'all' => 'All time',
        'custom' => 'Custom',
    ];

    /** No report spans more than this: twenty years of days is plenty. */
    private const MAX_DAYS = 366 * 20;

    private function __construct(
        public readonly string $preset,
        /** The first day, at local midnight. */
        public readonly CarbonImmutable $from,
        /** The last day, at local midnight. */
        public readonly CarbonImmutable $to,
        public readonly string $tz,
    ) {}

    /**
     * From a request's `range`, `from` and `to`. An unknown preset is the
     * default; a bad custom range is a validation error.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function fromInput(array $input, string $tz, ?CarbonInterface $firstSale = null, ?CarbonInterface $now = null): self
    {
        $preset = in_array($input['range'] ?? null, self::PRESETS, true) ? $input['range'] : self::DEFAULT;

        if ($preset !== 'custom') {
            return self::preset($preset, $tz, $firstSale, $now);
        }

        $parse = function (string $field) use ($input, $tz) {
            $value = (string) ($input[$field] ?? '');

            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                throw ValidationException::withMessages([$field => 'Give the date as YYYY-MM-DD.']);
            }

            $day = CarbonImmutable::createFromFormat('!Y-m-d', $value, $tz);

            if (! $day || $day->format('Y-m-d') !== $value) {
                throw ValidationException::withMessages([$field => 'That is not a date.']);
            }

            return $day;
        };

        $from = $parse('from');
        $to = $parse('to');

        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages(['to' => 'The end comes before the start.']);
        }

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['from' => 'A report covers at most twenty years.']);
        }

        return new self('custom', $from, $to, $tz);
    }

    public static function preset(string $preset, string $tz, ?CarbonInterface $firstSale = null, ?CarbonInterface $now = null): self
    {
        $today = CarbonImmutable::instance($now ?? now())->setTimezone($tz)->startOfDay();

        [$from, $to] = match ($preset) {
            'last_7' => [$today->subDays(6), $today],
            'last_90' => [$today->subDays(89), $today],
            'this_month' => [$today->startOfMonth(), $today],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'quarter' => [$today->firstOfQuarter(), $today],
            'last_quarter' => [$today->firstOfQuarter()->subMonths(3), $today->firstOfQuarter()->subDay()],
            'ytd' => [$today->startOfYear(), $today],
            'last_year' => [$today->subYear()->startOfYear(), $today->subYear()->endOfYear()->startOfDay()],
            'all' => [$firstSale ? CarbonImmutable::instance($firstSale)->setTimezone($tz)->startOfDay()->min($today) : $today, $today],
            default => [$today->subDays(29), $today],
        };

        return new self(in_array($preset, self::PRESETS, true) && $preset !== 'custom' ? $preset : self::DEFAULT, $from, $to, $tz);
    }

    /** Whole local days, for a statement period or a test. */
    public static function between(CarbonInterface $from, CarbonInterface $to, string $tz, string $preset = 'custom'): self
    {
        return new self(
            $preset,
            CarbonImmutable::instance($from)->setTimezone($tz)->startOfDay(),
            CarbonImmutable::instance($to)->setTimezone($tz)->startOfDay(),
            $tz,
        );
    }

    /** Inclusive lower bound, in UTC. */
    public function startUtc(): CarbonImmutable
    {
        return $this->from->startOfDay()->utc();
    }

    /** Exclusive upper bound: the midnight after the last day, in UTC. */
    public function endUtc(): CarbonImmutable
    {
        return $this->to->addDay()->startOfDay()->utc();
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    public function compare(string $mode): self
    {
        return $mode === 'year' ? $this->lastYear() : $this->previous();
    }

    /** The same length immediately before; whole months shift by months. */
    public function previous(): self
    {
        if ($this->wholeMonths()) {
            $months = ($this->to->year - $this->from->year) * 12 + $this->to->month - $this->from->month + 1;
            $from = $this->from->subMonthsNoOverflow($months);

            return new self('custom', $from, $from->addMonthsNoOverflow($months)->subDay(), $this->tz);
        }

        $days = $this->days();

        return new self('custom', $this->from->subDays($days), $this->from->subDay(), $this->tz);
    }

    /** The same dates a year earlier (29 February becomes the 28th). */
    public function lastYear(): self
    {
        return new self('custom', $this->from->subYearNoOverflow(), $this->to->subYearNoOverflow(), $this->tz);
    }

    /** day | week | month, by length. */
    public function bucket(): string
    {
        $days = $this->days();

        return match (true) {
            $days <= 62 => 'day',
            $days <= 26 * 7 => 'week',
            default => 'month',
        };
    }

    /** The bucket a moment falls in, in this range's zone. */
    public function keyFor(CarbonInterface $at): string
    {
        $local = CarbonImmutable::instance($at)->setTimezone($this->tz);

        return match ($this->bucket()) {
            'day' => $local->format('Y-m-d'),
            'week' => $local->startOfWeek(CarbonInterface::MONDAY)->format('Y-m-d'),
            default => $local->format('Y-m'),
        };
    }

    /**
     * Every bucket from the first day to the last, in order, so the chart
     * has an even axis and a quiet day reads as quiet rather than missing.
     *
     * @return list<array{key: string, label: string, from: string, to: string}>
     */
    public function buckets(): array
    {
        $bucket = $this->bucket();
        $out = [];
        $cursor = match ($bucket) {
            'day' => $this->from,
            'week' => $this->from->startOfWeek(CarbonInterface::MONDAY),
            default => $this->from->startOfMonth(),
        };

        while ($cursor->lessThanOrEqualTo($this->to)) {
            $next = match ($bucket) {
                'day' => $cursor->addDay(),
                'week' => $cursor->addWeek(),
                default => $cursor->addMonthNoOverflow()->startOfMonth(),
            };
            $first = $cursor->max($this->from);
            $last = $next->subDay()->min($this->to);

            $out[] = [
                'key' => match ($bucket) {
                    'day' => $cursor->format('Y-m-d'),
                    'week' => $cursor->format('Y-m-d'),
                    default => $cursor->format('Y-m'),
                },
                'label' => match ($bucket) {
                    'day' => $cursor->format('j M'),
                    'week' => $first->format('j M'),
                    default => $cursor->format('M Y'),
                },
                'from' => $first->format('Y-m-d'),
                'to' => $last->format('Y-m-d'),
            ];
            $cursor = $next;
        }

        return $out;
    }

    /** A readable name: "Last 30 days" or "1 Jul 2026 – 30 Sep 2026". */
    public function label(): string
    {
        $span = $this->from->equalTo($this->to)
            ? $this->from->format('j M Y')
            : $this->from->format('j M Y').' – '.$this->to->format('j M Y');

        return $this->preset === 'custom' ? $span : self::LABELS[$this->preset];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'from' => $this->from->format('Y-m-d'),
            'to' => $this->to->format('Y-m-d'),
            'tz' => $this->tz,
            'days' => $this->days(),
            'bucket' => $this->bucket(),
            'label' => $this->label(),
            'span' => $this->from->format('j M Y').($this->from->equalTo($this->to) ? '' : ' – '.$this->to->format('j M Y')),
        ];
    }

    private function wholeMonths(): bool
    {
        return $this->from->day === 1 && $this->to->day === $this->to->daysInMonth;
    }
}
