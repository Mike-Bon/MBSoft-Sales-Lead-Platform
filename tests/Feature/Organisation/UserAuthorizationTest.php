<?php

namespace Tests\Feature\Organisation;

use App\Enums\UserRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_view_the_user_list(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->get('/users')->assertOk();
    }

    public function test_team_member_cannot_manage_users(): void
    {
        $team = Team::factory()->create();
        $member = User::factory()->teamMember($team)->create();

        $this->actingAs($member)->get('/users')->assertForbidden();
        $this->actingAs($member)->get('/users/create')->assertForbidden();
        $this->actingAs($member)->post('/users', [
            'name' => 'New Person',
            'email' => 'new-person@example.test',
            'password' => 'password123',
            'role' => UserRole::TeamMember->value,
            'team_id' => $team->id,
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'new-person@example.test']);
    }

    public function test_team_head_cannot_manage_roles(): void
    {
        $team = Team::factory()->create();
        $head = User::factory()->teamHead($team)->create();
        $member = User::factory()->teamMember($team)->create();

        $response = $this->actingAs($head)->put("/users/{$member->id}", [
            'role' => UserRole::TeamHead->value,
            'team_id' => $team->id,
        ]);

        $response->assertForbidden();
        $this->assertSame(UserRole::TeamMember, $member->fresh()->role);
    }

    public function test_team_head_cannot_promote_themselves_to_manager(): void
    {
        $team = Team::factory()->create();
        $head = User::factory()->teamHead($team)->create();

        $response = $this->actingAs($head)->put("/users/{$head->id}", [
            'role' => UserRole::Manager->value,
            'team_id' => null,
        ]);

        $response->assertForbidden();
        $this->assertSame(UserRole::TeamHead, $head->fresh()->role);
    }

    public function test_team_head_cannot_assign_themselves_to_another_team(): void
    {
        $ownTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $head = User::factory()->teamHead($ownTeam)->create();

        $response = $this->actingAs($head)->put("/users/{$head->id}", [
            'role' => UserRole::TeamHead->value,
            'team_id' => $otherTeam->id,
        ]);

        $response->assertForbidden();
        $this->assertSame($ownTeam->id, $head->fresh()->team_id);
    }

    public function test_team_head_cannot_modify_another_teams_membership(): void
    {
        $ownTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $head = User::factory()->teamHead($ownTeam)->create();
        $otherTeamMember = User::factory()->teamMember($otherTeam)->create();

        $response = $this->actingAs($head)->put("/users/{$otherTeamMember->id}", [
            'role' => UserRole::TeamMember->value,
            'team_id' => $ownTeam->id,
        ]);

        $response->assertForbidden();
        $this->assertSame($otherTeam->id, $otherTeamMember->fresh()->team_id);
    }

    public function test_manager_can_create_a_user_with_a_role_and_team(): void
    {
        $manager = User::factory()->manager()->create();
        $team = Team::factory()->create();

        $response = $this->actingAs($manager)->post('/users', [
            'name' => 'New Member',
            'email' => 'new-member@example.test',
            'password' => 'password123',
            'role' => UserRole::TeamMember->value,
            'team_id' => $team->id,
        ]);

        $response->assertRedirect(route('organisation.users.index'));

        $this->assertDatabaseHas('users', [
            'email' => 'new-member@example.test',
            'team_id' => $team->id,
        ]);

        $created = User::where('email', 'new-member@example.test')->first();
        $this->assertSame(UserRole::TeamMember, $created->role);
    }

    public function test_creating_a_non_manager_user_without_a_team_fails_validation(): void
    {
        $manager = User::factory()->manager()->create();

        $response = $this->actingAs($manager)->post('/users', [
            'name' => 'No Team',
            'email' => 'no-team@example.test',
            'password' => 'password123',
            'role' => UserRole::TeamMember->value,
        ]);

        $response->assertSessionHasErrors('team_id');
        $this->assertDatabaseMissing('users', ['email' => 'no-team@example.test']);
    }

    public function test_manager_cannot_demote_the_only_manager(): void
    {
        $manager = User::factory()->manager()->create();
        $team = Team::factory()->create();

        $response = $this->actingAs($manager)->put("/users/{$manager->id}", [
            'role' => UserRole::TeamMember->value,
            'team_id' => $team->id,
        ]);

        $response->assertSessionHasErrors('role');
        $this->assertSame(UserRole::Manager, $manager->fresh()->role);
    }
}
