<?php

namespace Tests\Feature\Performance;

use App\Models\PerformanceActualLine;
use App\Models\ReportingUnit;
use App\Models\Team;
use App\Models\User;
use App\Support\Performance\ActualsCsvTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalActualsManageTest extends TestCase
{
    use RefreshDatabase;

    private Team $cec;

    private ReportingUnit $tabun;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cec = Team::factory()->create(['code' => 'CEC', 'name' => 'CEC Team']);
        $this->tabun = ReportingUnit::factory()->for($this->cec)->create(['code' => 'CEC_TABOAN', 'name' => 'TABOAN']);
        ReportingUnit::factory()->for($this->cec)->create(['code' => 'CEC_GS', 'name' => 'GAISANO SOUTH']);
        ReportingUnit::factory()->for($this->cec)->inactive()->create(['code' => 'CEC_OLD', 'name' => 'CLOSED BRANCH']);
    }

    // ── authorization ─────────────────────────────────────────────

    public function test_manager_reaches_every_management_screen(): void
    {
        $this->actingAs(User::factory()->manager()->create());

        $this->get('/performance/fiscal/actuals')->assertOk()->assertSee('Manage Operational Actuals');
        $this->get('/performance/fiscal/actuals/import')->assertOk();
        $this->get('/performance/fiscal/actuals/entry')->assertOk();
        $this->get('/performance/fiscal/actuals/history')->assertOk();
        $this->get('/performance/fiscal/actuals/template?fiscal_year=2026&period_month=1')->assertOk();
    }

    public function test_team_head_is_forbidden_from_every_management_screen_but_keeps_dashboard_access(): void
    {
        $this->actingAs(User::factory()->teamHead($this->cec)->create());

        $this->get('/performance/fiscal')->assertOk(); // unchanged read access
        $this->get('/performance/fiscal/actuals')->assertForbidden();
        $this->get('/performance/fiscal/actuals/import')->assertForbidden();
        $this->get('/performance/fiscal/actuals/entry')->assertForbidden();
        $this->get('/performance/fiscal/actuals/history')->assertForbidden();
        $this->get('/performance/fiscal/actuals/template?fiscal_year=2026&period_month=1')->assertForbidden();
        $this->post('/performance/fiscal/actuals/import')->assertForbidden();
        $this->post('/performance/fiscal/actuals/entry')->assertForbidden();
    }

    public function test_team_member_is_forbidden(): void
    {
        $this->actingAs(User::factory()->teamMember($this->cec)->create());

        $this->get('/performance/fiscal/actuals')->assertForbidden();
        $this->post('/performance/fiscal/actuals/entry')->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/performance/fiscal/actuals')->assertRedirect('/login');
    }

    public function test_the_dashboard_shows_a_manage_actuals_link_only_to_a_manager(): void
    {
        $this->actingAs(User::factory()->manager()->create())->get('/performance/fiscal')
            ->assertOk()->assertSee('Manage Actuals');
        $this->actingAs(User::factory()->teamHead($this->cec)->create())->get('/performance/fiscal')
            ->assertOk()->assertDontSee('Manage Actuals');
    }

    // ── template ─────────────────────────────────────────────────

    public function test_the_template_is_month_scoped_and_lists_active_units_only_with_codes_prefilled(): void
    {
        $csv = ActualsCsvTemplate::build(2026, 3);
        $lines = array_values(array_filter(explode("\n", trim($csv))));

        $this->assertSame(
            'fiscal_year,period_month,team_code,reporting_unit_code,actual_units,actual_revenue,team_name,reporting_unit_name,calendar_month',
            $lines[0],
        );
        $this->assertCount(3, $lines); // header + 2 active units (inactive excluded)
        $this->assertStringNotContainsString('CEC_OLD', $csv);

        $row = str_getcsv($lines[2]); // TABOAN (alphabetical after GAISANO SOUTH)
        $this->assertSame(['2026', '3', 'CEC', 'CEC_TABOAN', '', '', 'CEC Team', 'TABOAN', 'February 2026'], $row);
    }

    public function test_the_template_columns_match_the_actuals_importer_contract(): void
    {
        $header = explode(',', explode("\n", ActualsCsvTemplate::build(2026, 1))[0]);
        // first six columns are exactly the importer's ACTUAL_COLUMNS
        $this->assertSame(
            ['fiscal_year', 'period_month', 'team_code', 'reporting_unit_code', 'actual_units', 'actual_revenue'],
            array_slice($header, 0, 6),
        );
    }

    // ── coverage ─────────────────────────────────────────────────

    public function test_the_hub_reports_month_coverage(): void
    {
        PerformanceActualLine::factory()->create([
            'fiscal_year' => 2026, 'period_month' => 1, 'team_id' => $this->cec->id,
            'reporting_unit_id' => $this->tabun->id, 'actual_revenue' => 1000,
        ]);

        $this->actingAs(User::factory()->manager()->create())
            ->get('/performance/fiscal/actuals?fiscal_year=2026')
            ->assertOk()
            ->assertSee('1 / 2')   // December: 1 of 2 active units reported
            ->assertSee('Partial');
    }
}
