<?php

namespace Tests\Feature\Performance;

use App\Enums\PerformanceImportChannel;
use App\Models\PerformanceActualLine;
use App\Models\PerformanceActualLineRevision;
use App\Models\ReportingUnit;
use App\Models\Team;
use App\Models\User;
use App\Support\Performance\ManualEntryState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class FiscalActualManualEntryTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Team $cec;

    private ReportingUnit $tabun;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->manager()->create();
        $this->cec = Team::factory()->create(['code' => 'CEC', 'name' => 'CEC Team']);
        $this->tabun = ReportingUnit::factory()->for($this->cec)->create(['code' => 'CEC_TABOAN', 'name' => 'TABOAN']);
    }

    private function lockFor(?PerformanceActualLine $line): string
    {
        return ManualEntryState::for(2026, $this->tabun->id, 3, $line)->lockToken;
    }

    private function submit(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->manager)->post('/performance/fiscal/actuals/entry', array_merge([
            'fiscal_year' => 2026,
            'reporting_unit_id' => $this->tabun->id,
            'period_month' => 3,
            'actual_revenue' => '12345.67',
            'actual_units' => '',
            'reason' => '',
            'lock' => $this->lockFor(null),
        ], $overrides));
    }

    public function test_the_form_shows_the_current_value_before_a_change(): void
    {
        PerformanceActualLine::factory()->create([
            'fiscal_year' => 2026, 'period_month' => 3, 'team_id' => $this->cec->id,
            'reporting_unit_id' => $this->tabun->id, 'actual_revenue' => 5000, 'actual_units' => 12.5,
        ]);

        $this->actingAs($this->manager)
            ->get('/performance/fiscal/actuals/entry?fiscal_year=2026&reporting_unit_id='.$this->tabun->id.'&period_month=3')
            ->assertOk()
            ->assertSee('Currently recorded')
            ->assertSee('12.50');
    }

    public function test_a_manager_can_create_a_previously_missing_actual_without_a_reason(): void
    {
        $this->submit(['actual_units' => '3.25'])->assertRedirect();

        $line = PerformanceActualLine::sole();
        $this->assertSame('12345.67', $line->actual_revenue);
        $this->assertSame('3.25', $line->actual_units);   // fractional units preserved

        $rev = PerformanceActualLineRevision::sole();
        $this->assertSame('created', $rev->change_type->value);
        $this->assertSame(PerformanceImportChannel::ManualEntry, $rev->channel);
        $this->assertNull($rev->previous_revenue);
        $this->assertNull($rev->performance_import_id);
        $this->assertSame($this->manager->id, $rev->changed_by);
    }

    public function test_blank_units_are_stored_as_null_and_an_explicit_zero_is_stored_as_zero(): void
    {
        $this->submit(['actual_revenue' => '900', 'actual_units' => '']);
        $this->assertNull(PerformanceActualLine::sole()->actual_units);

        PerformanceActualLine::query()->delete();
        $this->submit(['actual_revenue' => '900', 'actual_units' => '0']);
        $this->assertSame('0.00', PerformanceActualLine::sole()->actual_units);
    }

    public function test_changing_an_existing_reported_value_requires_a_reason(): void
    {
        $line = PerformanceActualLine::factory()->create([
            'fiscal_year' => 2026, 'period_month' => 3, 'team_id' => $this->cec->id,
            'reporting_unit_id' => $this->tabun->id, 'actual_revenue' => 5000,
        ]);

        $this->submit(['actual_revenue' => '5500', 'reason' => '', 'lock' => $this->lockFor($line)])
            ->assertSessionHasErrors('reason');
        $this->assertSame('5000.00', $line->fresh()->actual_revenue);
        $this->assertSame(0, PerformanceActualLineRevision::count());

        $this->submit(['actual_revenue' => '5500', 'reason' => 'Branch resubmitted corrected September figure', 'lock' => $this->lockFor($line)])
            ->assertRedirect();
        $this->assertSame('5500.00', $line->fresh()->actual_revenue);
        $rev = PerformanceActualLineRevision::sole();
        $this->assertSame('updated', $rev->change_type->value);
        $this->assertSame('5000.00', $rev->previous_revenue);
        $this->assertSame('Branch resubmitted corrected September figure', $rev->reason);
    }

    public function test_negative_and_malformed_values_are_rejected_and_nothing_is_written(): void
    {
        foreach (['-5', 'abc', '1e6', 'NaN', '=1+1', '5.'] as $bad) {
            $this->submit(['actual_revenue' => $bad])->assertSessionHasErrors('actual_revenue');
        }
        $this->submit(['actual_units' => '-3'])->assertSessionHasErrors('actual_units');

        $this->assertSame(0, PerformanceActualLine::count());
        $this->assertSame(0, PerformanceActualLineRevision::count());
    }

    public function test_a_no_op_save_writes_nothing_and_records_no_revision(): void
    {
        $line = PerformanceActualLine::factory()->create([
            'fiscal_year' => 2026, 'period_month' => 3, 'team_id' => $this->cec->id,
            'reporting_unit_id' => $this->tabun->id, 'actual_revenue' => 5000, 'actual_units' => null,
        ]);
        $updatedAt = $line->updated_at;

        $this->travel(1)->minutes();
        $this->submit(['actual_revenue' => '5000', 'actual_units' => '', 'lock' => $this->lockFor($line->fresh())])
            ->assertRedirect();

        $this->assertSame(0, PerformanceActualLineRevision::count());
        $this->assertEquals($updatedAt, $line->fresh()->updated_at); // untouched
    }

    public function test_a_stale_lock_is_rejected(): void
    {
        $this->submit(['lock' => 'not-the-current-token'])->assertSessionHasErrors('lock');
        $this->assertSame(0, PerformanceActualLine::count());
    }

    public function test_a_team_head_cannot_submit_the_manual_form(): void
    {
        $this->actingAs(User::factory()->teamHead($this->cec)->create())
            ->post('/performance/fiscal/actuals/entry', [
                'fiscal_year' => 2026, 'reporting_unit_id' => $this->tabun->id, 'period_month' => 3,
                'actual_revenue' => '100', 'lock' => 'x',
            ])->assertForbidden();
        $this->assertSame(0, PerformanceActualLine::count());
    }
}
