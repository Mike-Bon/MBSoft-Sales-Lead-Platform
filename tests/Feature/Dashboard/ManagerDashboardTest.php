<?php

namespace Tests\Feature\Dashboard;

use App\Enums\OpportunityStage;
use App\Enums\PeriodPreset;
use App\Models\Opportunity;
use App\Models\Target;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagerDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_access_dashboard(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->get('/dashboard')->assertOk();
    }

    public function test_manager_sees_organisation_performance(): void
    {
        $manager = User::factory()->manager()->create();
        Target::factory()->manager($manager)->create(['target_amount' => 50000]);

        $response = $this->actingAs($manager)->get('/dashboard');

        $response->assertViewIs('dashboard.manager');
        $response->assertViewHas('organisation', fn ($snapshot) => $snapshot->hasTarget && $snapshot->target === 50000.0);
    }

    public function test_manager_sees_all_permitted_teams(): void
    {
        $manager = User::factory()->manager()->create();
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();

        $response = $this->actingAs($manager)->get('/dashboard');

        $response->assertViewHas('teams', fn ($teams) => $teams->count() === 2);
    }

    public function test_manager_can_drill_into_a_team(): void
    {
        $manager = User::factory()->manager()->create();
        $team = Team::factory()->create();

        $response = $this->actingAs($manager)->get('/dashboard');
        $response->assertSee(route('performance.teams.show', $team), false);
    }

    public function test_manager_can_view_team_performance_detail(): void
    {
        $manager = User::factory()->manager()->create();
        $team = Team::factory()->create();

        $this->actingAs($manager)->get("/teams/{$team->id}/performance")->assertOk();
    }

    public function test_manager_can_sort_teams(): void
    {
        $manager = User::factory()->manager()->create();
        $teamLow = Team::factory()->create(['name' => 'Team Low']);
        $teamHigh = Team::factory()->create(['name' => 'Team High']);

        $headLow = User::factory()->teamHead($teamLow)->create();
        $headHigh = User::factory()->teamHead($teamHigh)->create();

        Opportunity::factory()->ownedBy($headHigh)->stage(OpportunityStage::ClosedWon)->create(['value' => 90000, 'closed_at' => now()]);
        Opportunity::factory()->ownedBy($headLow)->stage(OpportunityStage::ClosedWon)->create(['value' => 1000, 'closed_at' => now()]);

        $response = $this->actingAs($manager)->get('/dashboard?sort=actual&dir=desc');

        $response->assertViewHas('teams', function ($teams) use ($teamHigh) {
            return $teams->first()['team']->is($teamHigh);
        });
    }

    public function test_manager_can_filter_by_period(): void
    {
        $manager = User::factory()->manager()->create();

        $response = $this->actingAs($manager)->get('/dashboard?period=previous_month');

        $response->assertOk();
        $response->assertViewHas('period', fn ($period) => $period->preset === PeriodPreset::PreviousMonth);
    }
}
