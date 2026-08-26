<?php

namespace Tests\Feature\Dashboard;

use App\Enums\LeadPriority;
use App\Enums\OpportunityStage;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\Dashboard\CrmMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AttentionAreasTest extends TestCase
{
    use RefreshDatabase;

    private function metrics(): CrmMetricsService
    {
        return app(CrmMetricsService::class);
    }

    public function test_overdue_follow_ups_are_identified_correctly(): void
    {
        $owner = User::factory()->manager()->create();
        $overdue = Lead::factory()->ownedBy($owner)->withFollowUp(Carbon::now()->subDays(3))->create();
        Lead::factory()->ownedBy($owner)->withFollowUp(Carbon::now()->addDays(3))->create();

        $result = $this->metrics()->overdueLeads(Lead::query()->where('owner_id', $owner->id));

        $this->assertCount(1, $result);
        $this->assertTrue($result->first()->is($overdue));
    }

    public function test_due_today_items_are_identified_correctly(): void
    {
        $owner = User::factory()->manager()->create();
        Lead::factory()->ownedBy($owner)->withFollowUp(Carbon::now())->create();
        Lead::factory()->ownedBy($owner)->withFollowUp(Carbon::now()->addWeek())->create();

        $counts = $this->metrics()->followUpCounts(Lead::query()->where('owner_id', $owner->id));

        $this->assertSame(1, $counts['due_today']);
    }

    public function test_upcoming_follow_ups_are_identified_correctly(): void
    {
        $owner = User::factory()->manager()->create();
        Lead::factory()->ownedBy($owner)->withFollowUp(Carbon::now()->addWeek())->create();
        Lead::factory()->ownedBy($owner)->withFollowUp(Carbon::now()->subDays(2))->create();

        $counts = $this->metrics()->followUpCounts(Lead::query()->where('owner_id', $owner->id));

        $this->assertSame(1, $counts['upcoming']);
        $this->assertSame(1, $counts['overdue']);
    }

    public function test_high_priority_leads_are_identified_correctly(): void
    {
        $owner = User::factory()->manager()->create();
        $high = Lead::factory()->ownedBy($owner)->priority(LeadPriority::High)->create();
        Lead::factory()->ownedBy($owner)->priority(LeadPriority::Low)->create();

        $result = $this->metrics()->highPriorityLeads(Lead::query()->where('owner_id', $owner->id));

        $this->assertCount(1, $result);
        $this->assertTrue($result->first()->is($high));
    }

    public function test_closing_soon_opportunities_are_identified_correctly(): void
    {
        $owner = User::factory()->manager()->create();
        $soon = Opportunity::factory()->ownedBy($owner)->stage(OpportunityStage::Negotiation)->create([
            'expected_close_date' => Carbon::now()->addDays(5),
        ]);
        Opportunity::factory()->ownedBy($owner)->stage(OpportunityStage::Negotiation)->create([
            'expected_close_date' => Carbon::now()->addDays(60),
        ]);
        Opportunity::factory()->ownedBy($owner)->stage(OpportunityStage::ClosedWon)->create([
            'expected_close_date' => Carbon::now()->addDays(2),
            'closed_at' => now(),
        ]);

        $result = $this->metrics()->closingSoonOpportunities(Opportunity::query()->where('owner_id', $owner->id));

        $this->assertCount(1, $result);
        $this->assertTrue($result->first()->is($soon));
    }

    public function test_manager_dashboard_shows_attention_sections(): void
    {
        $manager = User::factory()->manager()->create();
        Lead::factory()->ownedBy($manager)->withFollowUp(Carbon::now()->subDays(1))->create();

        $response = $this->actingAs($manager)->get('/dashboard');

        $response->assertViewHas('attention', function ($attention) {
            return array_key_exists('overdueLeads', $attention)
                && array_key_exists('highPriorityLeads', $attention)
                && array_key_exists('closingSoonOpportunities', $attention)
                && array_key_exists('behindTeams', $attention);
        });
    }
}
