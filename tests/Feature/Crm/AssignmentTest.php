<?php

namespace Tests\Feature\Crm;

use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * STEP 14: controlled assignment. The server must derive/validate
 * owner_id and team_id from the acting user's authorized relationships —
 * never trust a client-supplied value.
 */
class AssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_head_cannot_assign_a_record_across_teams(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $head1 = User::factory()->teamHead($teamA)->create();
        $head2 = User::factory()->teamHead($teamB)->create();

        // STEP 14's exact example: Team Head 1 attempts to assign a lead
        // to Team Head 2.
        $response = $this->actingAs($head1)->post('/leads', [
            'priority' => 'medium',
            'owner_id' => $head2->id,
        ]);

        $response->assertSessionHasErrors('owner_id');
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_team_head_owned_lead_is_always_forced_into_their_own_team_regardless_of_input(): void
    {
        $ownTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $head = User::factory()->teamHead($ownTeam)->create();

        $this->actingAs($head)->post('/leads', [
            'priority' => 'medium',
            'team_id' => $otherTeam->id, // attempted bypass
        ])->assertRedirect();

        $lead = Lead::firstOrFail();
        $this->assertSame($ownTeam->id, $lead->team_id);
        $this->assertNotSame($otherTeam->id, $lead->team_id);
    }

    public function test_manager_can_assign_records_where_permitted(): void
    {
        $manager = User::factory()->manager()->create();
        $team = Team::factory()->create();
        $head = User::factory()->teamHead($team)->create();

        $response = $this->actingAs($manager)->post('/leads', [
            'priority' => 'medium',
            'owner_id' => $head->id,
            'team_id' => $team->id,
        ]);

        $response->assertRedirect();

        $lead = Lead::firstOrFail();
        $this->assertSame($head->id, $lead->owner_id);
        $this->assertSame($team->id, $lead->team_id);
    }

    public function test_team_member_cannot_assign_a_lead_to_a_teammate(): void
    {
        $team = Team::factory()->create();
        $member = User::factory()->teamMember($team)->create();
        $teammate = User::factory()->teamMember($team)->create();

        $this->actingAs($member)->post('/leads', [
            'priority' => 'medium',
            'owner_id' => $teammate->id,
        ])->assertRedirect();

        // Silently and safely forced to themselves, not the teammate.
        $lead = Lead::firstOrFail();
        $this->assertSame($member->id, $lead->owner_id);
    }
}
