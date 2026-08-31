<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * A fiscal year whose start month is config-driven
 * (config('performance.fiscal_year_start_month'), default 12 = December).
 *
 * FiscalYear::of(2026) with a December start represents
 * 2025-12-01 → 2026-11-30, and its fiscal-month ORDINALS run
 * 1 = December (of the prior calendar year) … 12 = November.
 *
 * Every method that reasons about "now" accepts an explicit $asOf date —
 * nothing here reads the wall clock implicitly. Used only by
 * App\Services\FiscalPerformanceService and its AI tool / views; the
 * CRM's own Target / PerformanceService are entirely separate.
 */
final class FiscalYear
{
    public const MONTH_COUNT = 12;

    private function __construct(
        public readonly int $year,
        public readonly int $startMonth,
    ) {}

    public static function of(int $year, ?int $startMonth = null): self
    {
        if ($year < 2000 || $year > 2100) {
            throw new InvalidArgumentException("Fiscal year [{$year}] is out of the supported range.");
        }

        $startMonth ??= (int) config('performance.fiscal_year_start_month', 12);

        if ($startMonth < 1 || $startMonth > 12) {
            throw new InvalidArgumentException("Fiscal year start month [{$startMonth}] must be 1-12.");
        }

        return new self($year, $startMonth);
    }

    /**
     * The fiscal year that contains a given calendar date.
     * With a December start, 2025-12-15 belongs to FY2026.
     */
    public static function containing(Carbon $date, ?int $startMonth = null): self
    {
        $startMonth ??= (int) config('performance.fiscal_year_start_month', 12);

        // A start month of 1 (January) means the fiscal year == calendar year.
        $year = $startMonth === 1
            ? $date->year
            : ($date->month >= $startMonth ? $date->year + 1 : $date->year);

        return self::of($year, $startMonth);
    }

    public function start(): Carbon
    {
        $calendarYear = $this->startMonth === 1 ? $this->year : $this->year - 1;

        return Carbon::create($calendarYear, $this->startMonth, 1)->startOfDay();
    }

    public function end(): Carbon
    {
        return $this->start()->copy()->addMonths(self::MONTH_COUNT)->subDay()->startOfDay();
    }

    public function label(): string
    {
        return 'FY'.$this->year;
    }

    /**
     * The calendar (year, month) for a fiscal-month ordinal (1-12).
     * FY2026 ordinal 1 → [2025, 12]; ordinal 2 → [2026, 1]; ordinal 12 → [2026, 11].
     *
     * @return array{year: int, month: int}
     */
    public function calendarForOrdinal(int $ordinal): array
    {
        $this->assertOrdinal($ordinal);

        $anchor = $this->start()->copy()->addMonths($ordinal - 1);

        return ['year' => $anchor->year, 'month' => $anchor->month];
    }

    public function ordinalStart(int $ordinal): Carbon
    {
        $this->assertOrdinal($ordinal);

        return $this->start()->copy()->addMonths($ordinal - 1)->startOfMonth();
    }

    public function ordinalEnd(int $ordinal): Carbon
    {
        return $this->ordinalStart($ordinal)->copy()->endOfMonth()->startOfDay();
    }

    public function ordinalName(int $ordinal): string
    {
        $c = $this->calendarForOrdinal($ordinal);

        return Carbon::create($c['year'], $c['month'], 1)->format('F');
    }

    /**
     * The fiscal-month ordinal (1-12) that a calendar date falls in,
     * or null if the date is outside this fiscal year.
     */
    public function ordinalFor(Carbon $date): ?int
    {
        $day = $date->copy()->startOfDay();

        if ($day->lt($this->start()) || $day->gt($this->end())) {
            return null;
        }

        return $this->start()->diffInMonths($day->copy()->startOfMonth()) + 1;
    }

    /**
     * Every fiscal month as an explicit descriptor. Deterministic, no clock.
     *
     * @return list<array{ordinal: int, name: string, calendar_year: int, calendar_month: int, start: Carbon, end: Carbon}>
     */
    public function months(): array
    {
        $months = [];

        for ($ordinal = 1; $ordinal <= self::MONTH_COUNT; $ordinal++) {
            $c = $this->calendarForOrdinal($ordinal);

            $months[] = [
                'ordinal' => $ordinal,
                'name' => $this->ordinalName($ordinal),
                'calendar_year' => $c['year'],
                'calendar_month' => $c['month'],
                'start' => $this->ordinalStart($ordinal),
                'end' => $this->ordinalEnd($ordinal),
            ];
        }

        return $months;
    }

    /**
     * How many fiscal months have BEGUN as of $asOf (0-12). A month that
     * has started but not finished counts. Before the fiscal year → 0;
     * after it → 12. This is the "reporting horizon" for YTD figures:
     * as-of 2026-08-31 (FY2026) → 9 (Dec … Aug).
     */
    public function monthsElapsedAsOf(Carbon $asOf): int
    {
        $day = $asOf->copy()->startOfDay();

        if ($day->lt($this->start())) {
            return 0;
        }

        if ($day->gt($this->end())) {
            return self::MONTH_COUNT;
        }

        return $this->start()->diffInMonths($day->copy()->startOfMonth()) + 1;
    }

    /**
     * How many fiscal months have FULLY ENDED as of $asOf (0-12). A month
     * counts only once $asOf is on or after its last day. As-of
     * 2026-08-15 → 8 (Dec … Jul); as-of 2026-08-31 → 9.
     */
    public function completedMonthsAsOf(Carbon $asOf): int
    {
        $completed = 0;

        foreach ($this->months() as $month) {
            if ($asOf->copy()->startOfDay()->gte($month['end'])) {
                $completed++;
            }
        }

        return $completed;
    }

    /**
     * Fiscal months still to come after the reporting horizon (0-12).
     */
    public function remainingMonthsAfter(int $throughOrdinal): int
    {
        return max(self::MONTH_COUNT - max($throughOrdinal, 0), 0);
    }

    public function previous(): self
    {
        return self::of($this->year - 1, $this->startMonth);
    }

    private function assertOrdinal(int $ordinal): void
    {
        if ($ordinal < 1 || $ordinal > self::MONTH_COUNT) {
            throw new InvalidArgumentException("Fiscal month ordinal [{$ordinal}] must be 1-12.");
        }
    }
}
