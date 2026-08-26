<?php

namespace Tests\Feature\Crm;

use App\Enums\FollowUpStatus;
use App\Enums\LeadPriority;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_lead_can_be_created(): void
    {
        $manager = User::factory()->manager()->create();

        $response = $this->actingAs($manager)->post('/leads', [
            'priority' => LeadPriority::High->value,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseCount('leads', 1);
    }

    public function test_lead_requires_a_valid_ownership_team_relationship(): void
    {
        $team = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $head = User::factory()->teamHead($team)->create();

        // A Team Head cannot assign a lead to a user outside their team,
        // even by directly supplying that user's id.
        $otherTeamUser = User::factory()->teamMember($otherTeam)->create();

        $response = $this->actingAs($head)->post('/leads', [
            'priority' => LeadPriority::Medium->value,
            'owner_id' => $otherTeamUser->id,
        ]);

        $response->assertSessionHasErrors('owner_id');
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_lead_status_changes_correctly(): void
    {
        $manager = User::factory()->manager()->create();
        $lead = Lead::factory()->ownedBy($manager)->status(LeadStatus::New)->create();

        $response = $this->actingAs($manager)->put("/leads/{$lead->id}", [
            'status' => LeadStatus::Qualified->value,
            'priority' => $lead->priority->value,
        ]);

        $response->assertRedirect();
        $this->assertSame(LeadStatus::Qualified, $lead->fresh()->status);
    }

    public function test_lead_status_change_is_recorded_on_the_activity_timeline(): void
    {
        $manager = User::factory()->manager()->create();
        $lead = Lead::factory()->ownedBy($manager)->status(LeadStatus::New)->create();

        $this->actingAs($manager)->put("/leads/{$lead->id}", [
            'status' => LeadStatus::Qualified->value,
            'priority' => $lead->priority->value,
        ]);

        $this->assertDatabaseHas('activities', [
            'lead_id' => $lead->id,
            'subject' => 'Status changed',
        ]);
    }

    public function test_lead_priority_can_be_set_and_updated(): void
    {
        $manager = User::factory()->manager()->create();
        $lead = Lead::factory()->ownedBy($manager)->priority(LeadPriority::Low)->create();

        $this->actingAs($manager)->put("/leads/{$lead->id}", [
            'status' => $lead->status->value,
            'priority' => LeadPriority::High->value,
        ])->assertRedirect();

        $this->assertSame(LeadPriority::High, $lead->fresh()->priority);
    }

    public function test_follow_up_date_classification_works(): void
    {
        $manager = User::factory()->manager()->create();

        $overdue = Lead::factory()->ownedBy($manager)->withFollowUp(now()->subDays(2))->create();
        $dueToday = Lead::factory()->ownedBy($manager)->withFollowUp(now())->create();
        $upcoming = Lead::factory()->ownedBy($manager)->withFollowUp(now()->addWeek())->create();
        $notSet = Lead::factory()->ownedBy($manager)->create();

        $this->assertSame(FollowUpStatus::Overdue, $overdue->followUpStatus());
        $this->assertSame(FollowUpStatus::DueToday, $dueToday->followUpStatus());
        $this->assertSame(FollowUpStatus::Upcoming, $upcoming->followUpStatus());
        $this->assertSame(FollowUpStatus::NotSet, $notSet->followUpStatus());
    }

    public function test_leads_index_can_be_filtered_by_follow_up_bucket(): void
    {
        $manager = User::factory()->manager()->create();

        Lead::factory()->ownedBy($manager)->withFollowUp(now()->subDays(2))->create();
        Lead::factory()->ownedBy($manager)->withFollowUp(now()->addWeek())->create();

        $response = $this->actingAs($manager)->get('/leads?follow_up=overdue');

        $response->assertOk();
        $response->assertViewHas('leads', function ($leads) {
            return $leads->total() === 1;
        });
    }
}
