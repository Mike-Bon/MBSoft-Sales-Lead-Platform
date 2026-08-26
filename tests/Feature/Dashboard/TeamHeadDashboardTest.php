<?php

namespace Tests\Feature\Dashboard;

use App\Enums\PeriodPreset;
use App\Models\Target;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamHeadDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_head_can_access_dashboard(): void
    {
        $team = Team::factory()->create();
        $head = User::factory()->teamHead($team)->create();

        $this->actingAs($head)->get('/dashboard')->assertOk();
    }

    public function test_team_head_sees_own_teams_performance(): void
    {
        $team = Team::factory()->create();
        $head = User::factory()->teamHead($team)->create();
        Target::factory()->team($team)->create(['target_amount' => 20000]);

        $response = $this->actingAs($head)->get('/dashboard');

        $response->assertViewIs('dashboard.team-head');
        $response->assertViewHas('snapshot', fn ($snapshot) => $snapshot->hasTarget && $snapshot->target === 20000.0);
        $response->assertViewHas('team', fn ($viewTeam) => $viewTeam->is($team));
    }

    public function test_team_head_sees_permitted_team_members(): void
    {
        $team = Team::factory()->create();
        $head = User::factory()->teamHead($team)->create();
        $memberA = User::factory()->teamMember($team)->create();
        $memberB = User::factory()->teamMember($team)->create();

        $response = $this->actingAs($head)->get('/dashboard');

        $response->assertViewHas('members', function ($members) use ($memberA, $memberB) {
            $ids = $members->pluck('user.id')->all();

            return in_array($memberA->id, $ids, true) && in_array($memberB->id, $ids, true);
        });
    }

    public function test_team_head_cannot_see_another_teams_performance_on_their_own_dashboard(): void
    {
        $ownTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $head = User::factory()->teamHead($ownTeam)->create();
        User::factory()->teamMember($otherTeam)->create();

        $response = $this->actingAs($head)->get('/dashboard');

        $response->assertViewHas('team', fn ($viewTeam) => $viewTeam->is($ownTeam));
        $response->assertDontSee($otherTeam->name);
    }

    public function test_team_head_cannot_access_another_teams_drill_down_url(): void
    {
        $ownTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $head = User::factory()->teamHead($ownTeam)->create();

        $this->actingAs($head)->get("/teams/{$otherTeam->id}/performance")->assertForbidden();
    }

    public function test_team_head_can_access_their_own_teams_drill_down_url(): void
    {
        $team = Team::factory()->create();
        $head = User::factory()->teamHead($team)->create();

        $this->actingAs($head)->get("/teams/{$team->id}/performance")->assertOk();
    }

    public function test_team_head_can_filter_permitted_team_data_by_period(): void
    {
        $team = Team::factory()->create();
        $head = User::factory()->teamHead($team)->create();

        $response = $this->actingAs($head)->get('/dashboard?period=current_quarter');

        $response->assertOk();
        $response->assertViewHas('period', fn ($period) => $period->preset === PeriodPreset::CurrentQuarter);
    }
}
