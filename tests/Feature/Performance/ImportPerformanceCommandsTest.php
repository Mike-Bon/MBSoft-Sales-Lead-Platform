<?php

namespace Tests\Feature\Performance;

use App\Models\PerformanceActualLine;
use App\Models\PerformancePlanLine;
use App\Models\ReportingUnit;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportPerformanceCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $cec = Team::factory()->create(['code' => 'CEC']);
        ReportingUnit::factory()->for($cec)->create(['code' => 'TABUN']);
    }

    private function csv(string $name, string $contents): string
    {
        $path = sys_get_temp_dir().'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_import_plan_command_imports_a_clean_file(): void
    {
        $path = $this->csv('plan.csv',
            "fiscal_year,period_month,team_code,reporting_unit_code,target_units,target_revenue\n"
            ."2026,1,CEC,TABUN,100,800000\n");

        $this->artisan('performance:import-plan', ['file' => $path])
            ->expectsOutputToContain('Imported 1 plan line')
            ->assertExitCode(0);

        $this->assertSame(1, PerformancePlanLine::count());
    }

    public function test_import_actuals_command_dry_run_writes_nothing(): void
    {
        $path = $this->csv('act.csv',
            "fiscal_year,period_month,team_code,reporting_unit_code,actual_units,actual_revenue\n"
            ."2026,1,CEC,TABUN,100,800000\n");

        $this->artisan('performance:import-actuals', ['file' => $path, '--dry-run' => true])
            ->expectsOutputToContain('Dry run OK')
            ->assertExitCode(0);

        $this->assertSame(0, PerformanceActualLine::count());
    }

    public function test_a_bad_file_fails_the_command_with_row_numbered_errors_and_no_writes(): void
    {
        $path = $this->csv('bad.csv',
            "fiscal_year,period_month,team_code,reporting_unit_code,actual_units,actual_revenue\n"
            ."2026,1,CEC,TABUN,100,800000\n"
            ."2026,99,CEC,TABUN,100,800000\n");

        $this->artisan('performance:import-actuals', ['file' => $path])
            ->expectsOutputToContain('line 3')
            ->assertExitCode(1);

        $this->assertSame(0, PerformanceActualLine::count());
    }

    public function test_the_as_option_must_be_a_manager(): void
    {
        $member = User::factory()->teamMember()->create(['email' => 'tm@example.test']);
        $path = $this->csv('p.csv',
            "fiscal_year,period_month,team_code,reporting_unit_code,target_units,target_revenue\n2026,1,CEC,TABUN,1,1\n");

        $this->artisan('performance:import-plan', ['file' => $path, '--as' => 'tm@example.test'])
            ->expectsOutputToContain('not a Manager')
            ->assertExitCode(1);

        $this->assertSame(0, PerformancePlanLine::count());
    }

    public function test_a_manager_as_option_attributes_the_batch(): void
    {
        $manager = User::factory()->manager()->create(['email' => 'mgr@example.test']);
        $path = $this->csv('p2.csv',
            "fiscal_year,period_month,team_code,reporting_unit_code,target_units,target_revenue\n2026,1,CEC,TABUN,1,1\n");

        $this->artisan('performance:import-plan', ['file' => $path, '--as' => 'mgr@example.test'])->assertExitCode(0);

        $this->assertSame($manager->id, PerformancePlanLine::first()->import->imported_by);
    }
}
