<?php

namespace Tests\Unit\Support\CostToServe;

use App\Support\CostToServe\MetricChange;
use Tests\TestCase;

/**
 * Phase 12 STEP 10: zero/near-zero denominator handling — never
 * Infinity/NaN, never a misleadingly huge percentage.
 */
class MetricChangeTest extends TestCase
{
    public function test_an_ordinary_increase(): void
    {
        $change = MetricChange::compute(previous: 100.0, current: 150.0);

        $this->assertSame(50.0, $change->percent);
        $this->assertSame('increased', $change->state);
    }

    public function test_an_ordinary_decrease(): void
    {
        $change = MetricChange::compute(previous: 200.0, current: 150.0);

        $this->assertSame(-25.0, $change->percent);
        $this->assertSame('decreased', $change->state);
    }

    public function test_zero_previous_and_positive_current_is_new_not_infinite(): void
    {
        $change = MetricChange::compute(previous: 0.0, current: 500.0);

        $this->assertNull($change->percent);
        $this->assertSame('new', $change->state);
        $this->assertSame(500.0, $change->current);
    }

    public function test_zero_previous_and_zero_current_is_unchanged(): void
    {
        $change = MetricChange::compute(previous: 0.0, current: 0.0);

        $this->assertNull($change->percent);
        $this->assertSame('unchanged', $change->state);
    }

    public function test_negligible_change_is_unchanged_not_a_rounding_artifact(): void
    {
        $change = MetricChange::compute(previous: 1000.0, current: 1000.3);

        $this->assertSame('unchanged', $change->state);
    }

    public function test_a_negative_previous_value_still_computes_a_sane_percent(): void
    {
        // Not a realistic revenue scenario, but the formula must not
        // divide by a signed value and silently flip the sign.
        $change = MetricChange::compute(previous: -100.0, current: -50.0);

        $this->assertSame(50.0, $change->percent);
        $this->assertSame('increased', $change->state);
    }

    public function test_to_array_rounds_and_includes_every_field(): void
    {
        $change = MetricChange::compute(previous: 100.0, current: 133.333);

        $array = $change->toArray();

        $this->assertSame(100.0, $array['previous']);
        $this->assertSame(133.33, $array['current']);
        $this->assertSame(33.3, $array['percent_change']);
        $this->assertSame('increased', $array['state']);
    }
}
