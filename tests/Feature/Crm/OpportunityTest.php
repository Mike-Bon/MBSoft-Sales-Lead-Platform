<?php

namespace Tests\Feature\Crm;

use App\Enums\OpportunityStage;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpportunityTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_opportunity_can_be_created(): void
    {
        $manager = User::factory()->manager()->create();

        $response = $this->actingAs($manager)->post('/opportunities', [
            'name' => 'New Deal',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('opportunities', ['name' => 'New Deal']);
    }

    public function test_an_opportunity_can_be_linked_to_a_lead(): void
    {
        $manager = User::factory()->manager()->create();
        $lead = Lead::factory()->ownedBy($manager)->create();

        $this->actingAs($manager)->post('/opportunities', [
            'name' => 'From Lead',
            'lead_id' => $lead->id,
        ])->assertRedirect();

        $opportunity = Opportunity::where('name', 'From Lead')->firstOrFail();
        $this->assertSame($lead->id, $opportunity->lead_id);
    }

    public function test_an_opportunity_does_not_need_a_lead(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->post('/opportunities', [
            'name' => 'Direct Deal',
        ])->assertRedirect();

        $opportunity = Opportunity::where('name', 'Direct Deal')->firstOrFail();
        $this->assertNull($opportunity->lead_id);
    }

    public function test_opportunity_stages_work(): void
    {
        $manager = User::factory()->manager()->create();
        $opportunity = Opportunity::factory()->ownedBy($manager)->stage(OpportunityStage::Qualification)->create();

        $this->actingAs($manager)->put("/opportunities/{$opportunity->id}", [
            'name' => $opportunity->name,
            'stage' => OpportunityStage::Proposal->value,
        ])->assertRedirect();

        $this->assertSame(OpportunityStage::Proposal, $opportunity->fresh()->stage);
    }

    public function test_closed_won_behaves_correctly(): void
    {
        $manager = User::factory()->manager()->create();
        $opportunity = Opportunity::factory()->ownedBy($manager)->stage(OpportunityStage::Negotiation)->create();

        $this->actingAs($manager)->put("/opportunities/{$opportunity->id}", [
            'name' => $opportunity->name,
            'stage' => OpportunityStage::ClosedWon->value,
        ])->assertRedirect();

        $fresh = $opportunity->fresh();
        $this->assertTrue($fresh->isClosed());
        $this->assertTrue($fresh->isWon());
        $this->assertFalse($fresh->isLost());

        $this->assertDatabaseHas('activities', [
            'opportunity_id' => $opportunity->id,
            'subject' => 'Stage changed',
        ]);
    }

    public function test_closed_lost_behaves_correctly(): void
    {
        $manager = User::factory()->manager()->create();
        $opportunity = Opportunity::factory()->ownedBy($manager)->stage(OpportunityStage::Negotiation)->create();

        $this->actingAs($manager)->put("/opportunities/{$opportunity->id}", [
            'name' => $opportunity->name,
            'stage' => OpportunityStage::ClosedLost->value,
        ])->assertRedirect();

        $fresh = $opportunity->fresh();
        $this->assertTrue($fresh->isClosed());
        $this->assertTrue($fresh->isLost());
        $this->assertFalse($fresh->isWon());
    }

    public function test_unauthorized_user_cannot_modify_another_teams_opportunity(): void
    {
        $ownTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $head = User::factory()->teamHead($ownTeam)->create();

        $opportunity = Opportunity::factory()->forTeam($otherTeam)->create();

        $response = $this->actingAs($head)->put("/opportunities/{$opportunity->id}", [
            'name' => 'Hijacked',
            'stage' => OpportunityStage::ClosedWon->value,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('opportunities', ['name' => 'Hijacked']);
    }
}
