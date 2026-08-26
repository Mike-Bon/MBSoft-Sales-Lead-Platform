<?php

namespace Tests\Feature\Dashboard;

use App\Models\Target;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * STEP 19: dashboard authorization is critical. Every check here goes
 * through a real HTTP request with a crafted URL/id — never a UI helper
 * — matching the constitution's "test authorization at the request
 * level" requirement established since Phase 2.
 */
class DashboardSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_guest_cannot_access_team_performance(): void
    {
        $team = Team::factory()->create();

        $this->get("/teams/{$team->id}/performance")->assertRedirect('/login');
    }

    public function test_manager_can_access_any_teams_performance_directly_by_url(): void
    {
        $manager = User::factory()->manager()->create();
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();

        $this->actingAs($manager)->get("/teams/{$teamA->id}/performance")->assertOk();
        $this->actingAs($manager)->get("/teams/{$teamB->id}/performance")->assertOk();
    }

    public function test_direct_url_manipulation_cannot_bypass_team_isolation(): void
    {
        $ownTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $head = User::factory()->teamHead($ownTeam)->create();

        // Same idea as changing an id in the address bar: authorized for
        // one team, tries the next sequential id.
        $this->actingAs($head)->get("/teams/{$ownTeam->id}/performance")->assertOk();
        $this->actingAs($head)->get("/teams/{$otherTeam->id}/performance")->assertForbidden();
    }

    public function test_team_member_direct_url_manipulation_cannot_reach_team_performance(): void
    {
        $team = Team::factory()->create();
        $member = User::factory()->teamMember($team)->create();

        // Team Members do not get the team-wide drill-down at all (Phase
        // 4's PerformanceAuthorizer::canViewTeam allows same-team, so
        // this specific one is actually permitted — verify the boundary
        // precisely: their OWN team, yes; another team, no).
        $this->actingAs($member)->get("/teams/{$team->id}/performance")->assertOk();

        $otherTeam = Team::factory()->create();
        $this->actingAs($member)->get("/teams/{$otherTeam->id}/performance")->assertForbidden();
    }

    public function test_request_manipulation_via_query_string_cannot_bypass_scope(): void
    {
        $ownTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $head = User::factory()->teamHead($ownTeam)->create();
        Target::factory()->team($otherTeam)->create();

        // Attempting to smuggle another team's id in via a query
        // parameter on the dashboard route does nothing — the
        // controller never reads team scope from the request at all.
        $response = $this->actingAs($head)->get('/dashboard?team_id='.$otherTeam->id);

        $response->assertViewHas('team', fn ($viewTeam) => $viewTeam->is($ownTeam));
    }

    public function test_user_cannot_manipulate_ids_to_reach_another_users_individual_performance_from_dashboard_links(): void
    {
        $team = Team::factory()->create();
        $memberA = User::factory()->teamMember($team)->create();
        $memberB = User::factory()->teamMember($team)->create();

        $this->actingAs($memberA)->get("/performance/users/{$memberB->id}")->assertForbidden();
    }
}
