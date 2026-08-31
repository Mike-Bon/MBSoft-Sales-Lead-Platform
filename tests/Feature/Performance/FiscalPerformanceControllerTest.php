<?php

namespace Tests\Feature\Performance;

use App\Models\PerformanceActualLine;
use App\Models\PerformancePlanLine;
use App\Models\ReportingUnit;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalPerformanceControllerTest extends TestCase
{
    use RefreshDatabase;

    private Team $cec;

    private Team $cbe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cec = Team::factory()->create(['name' => 'CEC Team', 'code' => 'CEC']);
        $this->cbe = Team::factory()->create(['name' => 'CBE Team', 'code' => 'CBE']);

        foreach ([$this->cec, $this->cbe] as $team) {
            $unit = ReportingUnit::factory()->for($team)->create(['code' => $team->code.'1', 'name' => $team->name.' Main']);
            for ($k = 1; $k <= 9; $k++) {
                PerformancePlanLine::factory()->create(['fiscal_year' => 2026, 'period_month' => $k, 'team_id' => $team->id, 'reporting_unit_id' => $unit->id, 'target_revenue' => 100_000 * $k]);
                PerformanceActualLine::factory()->create(['fiscal_year' => 2026, 'period_month' => $k, 'team_id' => $team->id, 'reporting_unit_id' => $unit->id, 'actual_revenue' => 90_000 * $k]);
            }
        }
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/performance/fiscal')->assertRedirect('/login');
    }

    public function test_a_manager_sees_the_organisation_wide_operational_view(): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->get('/performance/fiscal?fiscal_year=2026&as_of=2026-08-31')
            ->assertOk()
            ->assertSee('Fiscal Year Performance')
            ->assertSee('Operational Performance')
            ->assertSee('YTD Target Attainment')
            ->assertSee('FY Attainment to Date')
            ->assertSee('CEC Team')
            ->assertSee('CBE Team')
            ->assertSee('Monthly plan vs actual');
    }

    public function test_the_two_attainment_metrics_are_labelled_distinctly_and_not_both_ytd_percent(): void
    {
        $response = $this->actingAs(User::factory()->manager()->create())
            ->get('/performance/fiscal?fiscal_year=2026&as_of=2026-08-31')
            ->assertOk();

        $response->assertSee('YTD Target Attainment');
        $response->assertSee('FY Attainment to Date');
        $response->assertDontSee('YTD %');
    }

    public function test_a_team_head_sees_only_their_own_team(): void
    {
        $this->actingAs(User::factory()->teamHead($this->cec)->create())
            ->get('/performance/fiscal?fiscal_year=2026&as_of=2026-08-31')
            ->assertOk()
            ->assertSee('CEC Team')
            ->assertDontSee('CBE Team');
    }

    public function test_a_team_head_requesting_another_team_is_forbidden(): void
    {
        $this->actingAs(User::factory()->teamHead($this->cec)->create())
            ->get('/performance/fiscal?team_id='.$this->cbe->id)
            ->assertForbidden();
    }

    public function test_a_team_member_cannot_reach_the_organisation_view_but_sees_their_team(): void
    {
        // No team_id → controller scopes a non-manager to their own team.
        $this->actingAs(User::factory()->teamMember($this->cec)->create())
            ->get('/performance/fiscal?fiscal_year=2026&as_of=2026-08-31')
            ->assertOk()
            ->assertSee('CEC Team')
            ->assertDontSee('CBE Team');
    }

    public function test_it_renders_with_no_operational_data_at_all(): void
    {
        PerformancePlanLine::query()->delete();
        PerformanceActualLine::query()->delete();

        $this->actingAs(User::factory()->manager()->create())
            ->get('/performance/fiscal')
            ->assertOk()
            ->assertSee('Fiscal Year Performance');
    }

    public function test_the_existing_pipeline_performance_screen_is_untouched(): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->get('/performance')
            ->assertOk();
    }
}
