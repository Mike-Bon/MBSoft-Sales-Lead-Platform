<?php

namespace Tests\Feature\Performance;

use App\Models\ReportingUnit;
use App\Models\Team;
use App\Models\User;
use App\Support\Performance\ReportingUnitCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SeedReportingUnitsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function seedTeams(): void
    {
        foreach (ReportingUnitCatalog::teamCodes() as $code) {
            Team::factory()->create(['name' => "{$code} TEAM", 'code' => $code]);
        }
    }

    public function test_the_catalog_holds_exactly_44_units_with_unique_codes_and_known_statuses(): void
    {
        $catalog = ReportingUnitCatalog::fy2026();

        $this->assertCount(44, $catalog);
        $this->assertCount(44, collect($catalog)->pluck('code')->unique());
        $this->assertEqualsCanonicalizing(
            ReportingUnitCatalog::teamCodes(),
            collect($catalog)->pluck('team_code')->unique()->values()->all(),
        );
        foreach ($catalog as $row) {
            $this->assertContains($row['mapping_status'], ['EXACT', 'NORMALIZED_CONFIRMED', 'ALIAS_CONFIRMED']);
        }
    }

    public function test_it_creates_all_44_units_under_the_right_teams(): void
    {
        $this->seedTeams();

        $this->artisan('performance:seed-reporting-units')
            ->expectsOutputToContain('44 new')
            ->assertExitCode(0);

        $this->assertSame(44, ReportingUnit::count());
        $cec = Team::where('code', 'CEC')->first();
        $this->assertSame(3, ReportingUnit::where('team_id', $cec->id)->count());
        $emall = ReportingUnit::where('code', 'CEC_E_MALL')->first();
        $this->assertSame('E MALL', $emall->name);           // canonical = Budget label
        $this->assertSame($cec->id, $emall->team_id);
        $this->assertSame(3, $emall->sort_order);
    }

    public function test_it_is_idempotent(): void
    {
        $this->seedTeams();

        $this->artisan('performance:seed-reporting-units')->assertExitCode(0);
        $this->artisan('performance:seed-reporting-units')
            ->expectsOutputToContain('0 new, 0 updated, 44 unchanged')
            ->assertExitCode(0);

        $this->assertSame(44, ReportingUnit::count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->seedTeams();

        $this->artisan('performance:seed-reporting-units', ['--dry-run' => true])
            ->expectsOutputToContain('Would apply: 44 new')
            ->assertExitCode(0);

        $this->assertSame(0, ReportingUnit::count());
    }

    public function test_it_fails_closed_when_a_team_code_is_missing(): void
    {
        // only 9 of 10 teams
        foreach (array_slice(ReportingUnitCatalog::teamCodes(), 0, 9) as $code) {
            Team::factory()->create(['code' => $code]);
        }

        $this->artisan('performance:seed-reporting-units')
            ->expectsOutputToContain('Missing team code(s) in `teams.code`: MNE')
            ->assertExitCode(1);

        $this->assertSame(0, ReportingUnit::count());
    }

    public function test_it_never_creates_organizations_or_opportunities(): void
    {
        $this->seedTeams();
        $this->artisan('performance:seed-reporting-units')->assertExitCode(0);

        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('opportunities', 0);
    }

    public function test_show_teams_command_is_read_only_and_lists_heads(): void
    {
        $team = Team::factory()->create(['name' => 'CEC TEAM', 'code' => 'CEC']);
        $head = User::factory()->teamHead($team)->create(['name' => 'Martin Melgar', 'email' => 'martin@example.test']);
        $team->forceFill(['team_head_id' => $head->id])->save(); // team_head_id is guarded on the model

        $this->artisan('performance:show-teams')->assertExitCode(0);

        Artisan::call('performance:show-teams');
        $out = Artisan::output();
        $this->assertStringContainsString('Martin Melgar', $out);
        $this->assertStringContainsString('martin@example.test', $out);
        $this->assertStringContainsString('CEC', $out);

        // read-only: nothing created/changed
        $this->assertSame(1, Team::count());
        $this->assertSame(1, User::count());
        $this->assertDatabaseCount('reporting_units', 0);
    }
}
