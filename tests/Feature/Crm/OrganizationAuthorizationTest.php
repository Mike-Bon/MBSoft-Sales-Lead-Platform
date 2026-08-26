<?php

namespace Tests\Feature\Crm;

use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_create_an_organization(): void
    {
        $manager = User::factory()->manager()->create();

        $response = $this->actingAs($manager)->post('/organizations', [
            'name' => 'Acme Corp',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('organizations', ['name' => 'Acme Corp']);
    }

    public function test_authorized_team_head_can_create_an_organization(): void
    {
        $team = Team::factory()->create();
        $head = User::factory()->teamHead($team)->create();

        $response = $this->actingAs($head)->post('/organizations', [
            'name' => 'Team Head Org',
        ]);

        $response->assertRedirect();

        $organization = Organization::where('name', 'Team Head Org')->firstOrFail();
        $this->assertSame($team->id, $organization->team_id);
        $this->assertSame($head->id, $organization->owner_id);
    }

    public function test_unauthorized_user_cannot_modify_another_teams_organization(): void
    {
        $ownTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $head = User::factory()->teamHead($ownTeam)->create();

        $organization = Organization::factory()->forTeam($otherTeam)->create();

        $this->actingAs($head)->get("/organizations/{$organization->id}/edit")->assertForbidden();

        $response = $this->actingAs($head)->put("/organizations/{$organization->id}", [
            'name' => 'Renamed',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('organizations', ['name' => 'Renamed']);
    }
}
