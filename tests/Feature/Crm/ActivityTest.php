<?php

namespace Tests\Feature\Crm;

use App\Enums\ActivityType;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_activity_can_be_created(): void
    {
        $manager = User::factory()->manager()->create();
        $lead = Lead::factory()->ownedBy($manager)->create();

        $response = $this->actingAs($manager)->post('/activities', [
            'lead_id' => $lead->id,
            'type' => ActivityType::Call->value,
            'subject' => 'Intro call',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('activities', ['lead_id' => $lead->id, 'subject' => 'Intro call']);
    }

    public function test_activity_appears_on_the_correct_lead_timeline(): void
    {
        $manager = User::factory()->manager()->create();
        $leadA = Lead::factory()->ownedBy($manager)->create();
        $leadB = Lead::factory()->ownedBy($manager)->create();

        $this->actingAs($manager)->post('/activities', [
            'lead_id' => $leadA->id,
            'type' => ActivityType::Note->value,
            'subject' => 'Only on A',
        ]);

        $this->assertTrue($leadA->activities()->where('subject', 'Only on A')->exists());
        $this->assertFalse($leadB->activities()->where('subject', 'Only on A')->exists());
    }

    public function test_activity_belongs_to_the_correct_user_and_team(): void
    {
        $team = Team::factory()->create();
        $member = User::factory()->teamMember($team)->create();
        $lead = Lead::factory()->ownedBy($member)->create();

        $this->actingAs($member)->post('/activities', [
            'lead_id' => $lead->id,
            'type' => ActivityType::Email->value,
            'subject' => 'Follow-up email',
        ]);

        $this->assertDatabaseHas('activities', [
            'lead_id' => $lead->id,
            'user_id' => $member->id,
            'team_id' => $team->id,
        ]);
    }

    public function test_unauthorized_user_cannot_log_an_activity_against_another_teams_protected_lead(): void
    {
        $ownTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $head = User::factory()->teamHead($ownTeam)->create();
        $otherTeamLead = Lead::factory()->forTeam($otherTeam)->create();

        // Guessing a valid lead id belonging to another team must not
        // let the activity be attached to it.
        $response = $this->actingAs($head)->post('/activities', [
            'lead_id' => $otherTeamLead->id,
            'type' => ActivityType::Note->value,
            'subject' => 'Should not be allowed',
        ]);

        $response->assertSessionHasErrors();
        $this->assertDatabaseMissing('activities', ['subject' => 'Should not be allowed']);
    }
}
