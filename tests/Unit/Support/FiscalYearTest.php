<?php

namespace Tests\Unit\Support;

use App\Support\FiscalYear;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * FY start month is config-driven (default 12 = December), so FY2026 is
 * 2025-12-01 → 2026-11-30 with ordinals 1 = Dec … 12 = Nov. Nothing here
 * reads the clock — every "as of" is explicit.
 */
class FiscalYearTest extends TestCase
{
    public function test_fy2026_spans_december_to_november(): void
    {
        $fy = FiscalYear::of(2026);

        $this->assertSame('2025-12-01', $fy->start()->toDateString());
        $this->assertSame('2026-11-30', $fy->end()->toDateString());
        $this->assertSame('FY2026', $fy->label());
    }

    public function test_the_twelve_fiscal_months_are_ordered_december_first(): void
    {
        $months = FiscalYear::of(2026)->months();

        $this->assertCount(12, $months);
        $this->assertSame(['December', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November'],
            array_column($months, 'name'));
        $this->assertSame(1, $months[0]['ordinal']);
        $this->assertSame(2025, $months[0]['calendar_year']);
        $this->assertSame(12, $months[0]['calendar_month']);
        $this->assertSame('2025-12-01', $months[0]['start']->toDateString());
        $this->assertSame('2025-12-31', $months[0]['end']->toDateString());
        $this->assertSame(12, $months[11]['ordinal']);
        $this->assertSame(2026, $months[11]['calendar_year']);
        $this->assertSame(11, $months[11]['calendar_month']);
        $this->assertSame('2026-11-30', $months[11]['end']->toDateString());
    }

    public function test_calendar_for_ordinal_maps_both_calendar_years(): void
    {
        $fy = FiscalYear::of(2026);

        $this->assertSame(['year' => 2025, 'month' => 12], $fy->calendarForOrdinal(1));
        $this->assertSame(['year' => 2026, 'month' => 1], $fy->calendarForOrdinal(2));
        $this->assertSame(['year' => 2026, 'month' => 3], $fy->calendarForOrdinal(4));
        $this->assertSame(['year' => 2026, 'month' => 11], $fy->calendarForOrdinal(12));
    }

    public function test_ordinal_for_a_calendar_date(): void
    {
        $fy = FiscalYear::of(2026);

        $this->assertSame(1, $fy->ordinalFor(Carbon::parse('2025-12-15')));
        $this->assertSame(1, $fy->ordinalFor(Carbon::parse('2025-12-01')));
        $this->assertSame(1, $fy->ordinalFor(Carbon::parse('2025-12-31')));
        $this->assertSame(9, $fy->ordinalFor(Carbon::parse('2026-08-01')));
        $this->assertSame(12, $fy->ordinalFor(Carbon::parse('2026-11-30')));
        $this->assertNull($fy->ordinalFor(Carbon::parse('2025-11-30')));
        $this->assertNull($fy->ordinalFor(Carbon::parse('2026-12-01')));
    }

    public function test_months_elapsed_as_of_a_date(): void
    {
        $fy = FiscalYear::of(2026);

        $this->assertSame(0, $fy->monthsElapsedAsOf(Carbon::parse('2025-11-30')));
        $this->assertSame(1, $fy->monthsElapsedAsOf(Carbon::parse('2025-12-01')));
        $this->assertSame(9, $fy->monthsElapsedAsOf(Carbon::parse('2026-08-15')));
        $this->assertSame(9, $fy->monthsElapsedAsOf(Carbon::parse('2026-08-31')));
        $this->assertSame(10, $fy->monthsElapsedAsOf(Carbon::parse('2026-09-01')));
        $this->assertSame(12, $fy->monthsElapsedAsOf(Carbon::parse('2026-11-30')));
        $this->assertSame(12, $fy->monthsElapsedAsOf(Carbon::parse('2027-03-01')));
    }

    public function test_completed_months_as_of_a_date(): void
    {
        $fy = FiscalYear::of(2026);

        $this->assertSame(0, $fy->completedMonthsAsOf(Carbon::parse('2025-12-15')));
        $this->assertSame(1, $fy->completedMonthsAsOf(Carbon::parse('2025-12-31')));
        $this->assertSame(8, $fy->completedMonthsAsOf(Carbon::parse('2026-08-15')));
        $this->assertSame(9, $fy->completedMonthsAsOf(Carbon::parse('2026-08-31')));
        $this->assertSame(12, $fy->completedMonthsAsOf(Carbon::parse('2026-11-30')));
    }

    public function test_remaining_months_after_a_horizon(): void
    {
        $fy = FiscalYear::of(2026);

        $this->assertSame(3, $fy->remainingMonthsAfter(9));
        $this->assertSame(0, $fy->remainingMonthsAfter(12));
        $this->assertSame(12, $fy->remainingMonthsAfter(0));
        $this->assertSame(0, $fy->remainingMonthsAfter(15));
    }

    public function test_containing_resolves_the_fiscal_year_for_a_date(): void
    {
        $this->assertSame(2026, FiscalYear::containing(Carbon::parse('2025-12-01'))->year);
        $this->assertSame(2026, FiscalYear::containing(Carbon::parse('2026-11-30'))->year);
        $this->assertSame(2025, FiscalYear::containing(Carbon::parse('2025-11-30'))->year);
        $this->assertSame(2027, FiscalYear::containing(Carbon::parse('2026-12-01'))->year);
    }

    public function test_previous_fiscal_year(): void
    {
        $prev = FiscalYear::of(2026)->previous();

        $this->assertSame(2025, $prev->year);
        $this->assertSame('2024-12-01', $prev->start()->toDateString());
        $this->assertSame('2025-11-30', $prev->end()->toDateString());
    }

    public function test_a_january_start_month_makes_the_fiscal_year_the_calendar_year(): void
    {
        $fy = FiscalYear::of(2026, startMonth: 1);

        $this->assertSame('2026-01-01', $fy->start()->toDateString());
        $this->assertSame('2026-12-31', $fy->end()->toDateString());
        $this->assertSame(['year' => 2026, 'month' => 1], $fy->calendarForOrdinal(1));
        $this->assertSame(2026, FiscalYear::containing(Carbon::parse('2026-06-15'), startMonth: 1)->year);
    }

    public function test_invalid_inputs_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FiscalYear::of(2026)->calendarForOrdinal(13);
    }
}
