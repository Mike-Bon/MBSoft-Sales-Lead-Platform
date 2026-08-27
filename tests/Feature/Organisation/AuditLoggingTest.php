<?php

namespace Tests\Feature\Organisation;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Phase 11 STEP 3: closes CLAUDE.md's "log role/team/ownership changes"
 * requirement. Asserts against the dedicated `audit` log channel
 * (config/logging.php), not the general application log.
 */
class AuditLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_user_writes_an_audit_log_entry(): void
    {
        Log::shouldReceive('channel')->with('audit')->andReturn($spy = \Mockery::mock());
        $spy->shouldReceive('info')->once()->with('user.created', \Mockery::on(function ($context) {
            return $context['role'] === 'team_member' && isset($context['target_user_id']);
        }));

        $manager = User::factory()->manager()->create();
        $team = Team::factory()->create();

        $this->actingAs($manager)->post('/users', [
            'name' => 'New Person',
            'email' => 'new.person@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'team_member',
            'team_id' => $team->id,
        ]);
    }

    public function test_changing_a_users_role_and_team_writes_an_audit_log_entry(): void
    {
        $manager = User::factory()->manager()->create();
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $target = User::factory()->teamMember($teamA)->create();

        Log::shouldReceive('channel')->with('audit')->andReturn($spy = \Mockery::mock());
        $spy->shouldReceive('info')->once()->with('user.role_or_team_changed', \Mockery::on(function ($context) use ($target, $teamA, $teamB) {
            return $context['target_user_id'] === $target->id
                && $context['previous_team_id'] === $teamA->id
                && $context['new_team_id'] === $teamB->id;
        }));

        $this->actingAs($manager)->put("/users/{$target->id}", [
            'role' => 'team_member',
            'team_id' => $teamB->id,
        ]);
    }

    public function test_creating_a_team_writes_an_audit_log_entry(): void
    {
        $manager = User::factory()->manager()->create();

        Log::shouldReceive('channel')->with('audit')->andReturn($spy = \Mockery::mock());
        $spy->shouldReceive('info')->once()->with('team.created', \Mockery::type('array'));

        $this->actingAs($manager)->post('/teams', ['name' => 'New Team']);
    }

    public function test_assigning_a_team_head_writes_an_audit_log_entry(): void
    {
        $manager = User::factory()->manager()->create();
        $team = Team::factory()->create();
        $candidate = User::factory()->teamMember($team)->create();

        Log::shouldReceive('channel')->with('audit')->andReturn($spy = \Mockery::mock());
        $spy->shouldReceive('info')->once()->with('team.head_assigned', \Mockery::on(function ($context) use ($team, $candidate) {
            return $context['team_id'] === $team->id && $context['new_head_id'] === $candidate->id;
        }));

        $this->actingAs($manager)->put("/teams/{$team->id}/head", ['team_head_id' => $candidate->id]);
    }

    public function test_updating_a_teams_name_does_not_write_an_audit_log_entry(): void
    {
        // Deliberate scoping (see TeamManagementService): name/code/status
        // aren't in CLAUDE.md's named audit list.
        $manager = User::factory()->manager()->create();
        $team = Team::factory()->create();

        Log::spy();

        $this->actingAs($manager)->put("/teams/{$team->id}", ['name' => 'Renamed Team']);

        Log::shouldNotHaveReceived('channel');
    }
}
