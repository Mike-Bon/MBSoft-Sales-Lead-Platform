<?php

namespace Tests\Feature\Crm;

use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_head_cannot_access_another_teams_lead(): void
    {
        $ownTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $head1 = User::factory()->teamHead($ownTeam)->create();
        $head2 = User::factory()->teamHead($otherTeam)->create();

        $team2Lead = Lead::factory()->ownedBy($head2)->create();

        // Exactly the STEP 13 example: Team Head 1 attempts GET
        // /leads/{Team Head 2 lead}.
        $this->actingAs($head1)->get("/leads/{$team2Lead->id}")->assertForbidden();
    }

    public function test_team_member_cannot_access_unauthorized_leads(): void
    {
        $team = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $member = User::factory()->teamMember($team)->create();

        $otherTeamLead = Lead::factory()->forTeam($otherTeam)->create();
        $this->actingAs($member)->get("/leads/{$otherTeamLead->id}")->assertForbidden();

        // Even within their own team, a Team Member cannot edit a
        // teammate's lead — only view it and their own.
        $teammate = User::factory()->teamMember($team)->create();
        $teammateLead = Lead::factory()->ownedBy($teammate)->create();

        $this->actingAs($member)->get("/leads/{$teammateLead->id}")->assertOk();
        $this->actingAs($member)->get("/leads/{$teammateLead->id}/edit")->assertForbidden();
    }

    public function test_manager_can_access_all_permitted_leads(): void
    {
        $manager = User::factory()->manager()->create();
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();

        $leadA = Lead::factory()->forTeam($teamA)->create();
        $leadB = Lead::factory()->forTeam($teamB)->create();

        $this->actingAs($manager)->get("/leads/{$leadA->id}")->assertOk();
        $this->actingAs($manager)->get("/leads/{$leadB->id}")->assertOk();
    }
}
