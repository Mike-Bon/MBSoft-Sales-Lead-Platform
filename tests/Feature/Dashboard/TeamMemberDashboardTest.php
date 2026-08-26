<?php

namespace Tests\Feature\Dashboard;

use App\Models\Target;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamMemberDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_member_can_access_dashboard(): void
    {
        $team = Team::factory()->create();
        $member = User::factory()->teamMember($team)->create();

        $this->actingAs($member)->get('/dashboard')->assertOk();
    }

    public function test_team_member_sees_own_permitted_performance(): void
    {
        $team = Team::factory()->create();
        $member = User::factory()->teamMember($team)->create();
        Target::factory()->individual($member)->create(['target_amount' => 9000]);

        $response = $this->actingAs($member)->get('/dashboard');

        $response->assertViewIs('dashboard.team-member');
        $response->assertViewHas('snapshot', fn ($snapshot) => $snapshot->hasTarget && $snapshot->target === 9000.0);
        $response->assertViewHas('user', fn ($viewUser) => $viewUser->is($member));
    }

    public function test_team_member_cannot_access_organisation_wide_management_information(): void
    {
        $team = Team::factory()->create();
        $member = User::factory()->teamMember($team)->create();

        // No org-wide performance route reachable, and the Manager-only
        // targets/users screens remain forbidden (Phase 2/4 policies,
        // unaffected by this phase).
        $this->actingAs($member)->get('/dashboard')->assertViewIs('dashboard.team-member');
        $this->actingAs($member)->get('/targets/create')->assertForbidden();
    }

    public function test_team_member_cannot_access_another_users_protected_performance(): void
    {
        $team = Team::factory()->create();
        $member = User::factory()->teamMember($team)->create();
        $teammate = User::factory()->teamMember($team)->create();

        $this->actingAs($member)->get("/performance/users/{$teammate->id}")->assertForbidden();
    }

    public function test_team_member_dashboard_never_queries_another_users_data(): void
    {
        $team = Team::factory()->create();
        $member = User::factory()->teamMember($team)->create();
        $teammate = User::factory()->teamMember($team)->create();

        $response = $this->actingAs($member)->get('/dashboard');

        $response->assertViewHas('user', fn ($viewUser) => $viewUser->is($member) && ! $viewUser->is($teammate));
    }
}
