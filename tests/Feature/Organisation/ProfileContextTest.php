<?php

namespace Tests\Feature\Organisation;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_sees_their_role_on_their_profile(): void
    {
        $manager = User::factory()->manager()->create();

        $response = $this->actingAs($manager)->get('/profile');

        $response->assertOk();
        $response->assertSee('Manager');
    }

    public function test_team_member_can_identify_their_own_team_via_their_profile(): void
    {
        $team = Team::factory()->create(['name' => 'Team Falcon']);
        $member = User::factory()->teamMember($team)->create();

        $response = $this->actingAs($member)->get('/profile');

        $response->assertOk();
        $response->assertSee('Team Falcon');
        $response->assertSee('Team Member');
    }

    public function test_team_head_sees_the_team_they_head_on_their_profile(): void
    {
        $team = Team::factory()->create(['name' => 'Team Osprey']);
        $head = User::factory()->teamHead($team)->create();
        $team->team_head_id = $head->id;
        $team->save();

        $response = $this->actingAs($head)->get('/profile');

        $response->assertOk();
        $response->assertSee('Team Osprey');
        $response->assertSee('Team Head');
    }
}
