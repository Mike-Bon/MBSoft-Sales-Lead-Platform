<?php

namespace App\Enums;

use Illuminate\Support\Carbon;

/**
 * The dashboard/performance period selector (STEP 11). Every preset
 * resolves to an explicit [start, end] date pair — never a bare label —
 * consumed identically by PerformanceService regardless of which preset
 * produced it, so there is exactly one calculation path for every period.
 */
enum PeriodPreset: string
{
    case CurrentMonth = 'current_month';
    case PreviousMonth = 'previous_month';
    case CurrentQuarter = 'current_quarter';
    case PreviousQuarter = 'previous_quarter';
    case CurrentYear = 'current_year';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::CurrentMonth => 'Current Month',
            self::PreviousMonth => 'Previous Month',
            self::CurrentQuarter => 'Current Quarter',
            self::PreviousQuarter => 'Previous Quarter',
            self::CurrentYear => 'Current Year',
            self::Custom => 'Custom',
        };
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function range(): array
    {
        $now = Carbon::now();

        return match ($this) {
            self::CurrentMonth => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            self::PreviousMonth => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            self::CurrentQuarter => [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()],
            self::PreviousQuarter => [$now->copy()->subQuarterNoOverflow()->startOfQuarter(), $now->copy()->subQuarterNoOverflow()->endOfQuarter()],
            self::CurrentYear => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            self::Custom => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()], // caller must supply explicit dates instead
        };
    }

    /**
     * @return list<self>
     */
    public static function selectable(): array
    {
        return [self::CurrentMonth, self::PreviousMonth, self::CurrentQuarter, self::PreviousQuarter, self::CurrentYear];
    }
}
