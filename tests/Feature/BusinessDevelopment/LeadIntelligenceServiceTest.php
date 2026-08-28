<?php

namespace Tests\Feature\BusinessDevelopment;

use App\Enums\LeadPriority;
use App\Enums\LeadStatus;
use App\Enums\OpportunityStage;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\BusinessDevelopment\LeadIntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 13: the one place every Business Development figure is
 * calculated. These tests pin the two things the spec is most explicit
 * about: the score is a TRANSPARENT sum of named factors (spec §13 —
 * "do not create a mysterious black-box score"), and every method is
 * authorization-scoped exactly like a CRM index page (Manager sees all,
 * Team Head sees only their own team).
 */
class LeadIntelligenceServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): LeadIntelligenceService
    {
        return app(LeadIntelligenceService::class);
    }

    public function test_the_priority_score_is_the_plain_sum_of_the_named_factors(): void
    {
        $manager = User::factory()->manager()->create();
        $org = Organization::factory()->create(['name' => 'Acme Manufacturing']);

        $lead = Lead::factory()->create([
            'organization_id' => $org->id,
            'status' => LeadStatus::Qualified,   // +4
            'priority' => LeadPriority::High,     // +3
            'next_follow_up_at' => null,          // follow_up_missing +2
            'estimated_value' => 5000,            // below high-value threshold
        ]);
        // no activity ever → +1

        $result = $this->service()->prioritizedLeads($manager);
        $scored = $result['leads']->firstWhere('id', $lead->id);

        $this->assertNotNull($scored);
        $this->assertSame(10, $scored['score']);
        $this->assertSame('high', $scored['priority_band']);

        // Every point is attributable to a listed factor.
        $sumOfFactorPoints = collect($scored['factors'])->sum('points');
        $this->assertSame($scored['score'], $sumOfFactorPoints);

        $labels = collect($scored['factors'])->pluck('factor')->implode(' | ');
        $this->assertStringContainsString('Qualified', $labels);
        $this->assertStringContainsString('High priority', $labels);
        $this->assertStringContainsString('No follow-up date is set', $labels);
        $this->assertStringContainsString('No activity ever logged', $labels);
        $this->assertNotEmpty($scored['recommended_action']);
    }

    public function test_recent_engagement_and_open_opportunity_and_overdue_follow_up_all_score(): void
    {
        $manager = User::factory()->manager()->create();
        $org = Organization::factory()->create();

        $lead = Lead::factory()->create([
            'organization_id' => $org->id,
            'status' => LeadStatus::Contacted,   // +2
            'priority' => LeadPriority::Low,      // +0
            'next_follow_up_at' => Carbon::now()->subDays(3), // overdue +4
            'estimated_value' => 90000,          // high value +2
        ]);
        Activity::factory()->create(['lead_id' => $lead->id, 'occurred_at' => Carbon::now()->subDay()]); // recent +2
        Opportunity::factory()->create(['lead_id' => $lead->id, 'organization_id' => $org->id, 'stage' => OpportunityStage::Proposal]); // open opp +3

        $scored = $this->service()->prioritizedLeads($manager)['leads']->firstWhere('id', $lead->id);

        // 2 + 4 + 2 + 2 + 3 = 13
        $this->assertSame(13, $scored['score']);
        $this->assertSame(collect($scored['factors'])->sum('points'), $scored['score']);
    }

    public function test_prioritized_leads_never_include_terminal_leads(): void
    {
        $manager = User::factory()->manager()->create();

        Lead::factory()->create(['status' => LeadStatus::Converted]);
        Lead::factory()->create(['status' => LeadStatus::Disqualified]);
        $open = Lead::factory()->create(['status' => LeadStatus::New]);

        $ids = $this->service()->prioritizedLeads($manager)['leads']->pluck('id');

        $this->assertTrue($ids->contains($open->id));
        $this->assertCount(1, $ids);
    }

    public function test_a_team_head_only_sees_their_own_teams_leads(): void
    {
        $ownTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $head = User::factory()->teamHead($ownTeam)->create();

        $ownLead = Lead::factory()->forTeam($ownTeam)->create();
        $otherLead = Lead::factory()->forTeam($otherTeam)->create();

        $ids = $this->service()->prioritizedLeads($head)['leads']->pluck('id');

        $this->assertTrue($ids->contains($ownLead->id));
        $this->assertFalse($ids->contains($otherLead->id));
    }

    public function test_a_team_head_passing_another_teams_id_still_only_gets_their_own_team(): void
    {
        $ownTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $head = User::factory()->teamHead($ownTeam)->create();

        Lead::factory()->forTeam($ownTeam)->create();
        $otherLead = Lead::factory()->forTeam($otherTeam)->create();

        // Explicitly asking for the other team's id can only narrow the
        // already-scoped query — it can never widen it.
        $ids = $this->service()->prioritizedLeads($head, $otherTeam->id)['leads']->pluck('id');

        $this->assertFalse($ids->contains($otherLead->id));
        $this->assertCount(0, $ids);
    }

    public function test_stale_leads_are_cold_and_old_enough(): void
    {
        config(['services.business_development.stale_lead_days' => 10]);
        $manager = User::factory()->manager()->create();

        $cold = Lead::factory()->create([
            'status' => LeadStatus::Contacted,
            'created_at' => Carbon::now()->subDays(40),
            'next_follow_up_at' => null,
        ]);
        Activity::factory()->create(['lead_id' => $cold->id, 'occurred_at' => Carbon::now()->subDays(30)]);

        $freshlyCreated = Lead::factory()->create(['created_at' => Carbon::now()->subDay()]);

        $warm = Lead::factory()->create(['created_at' => Carbon::now()->subDays(40)]);
        Activity::factory()->create(['lead_id' => $warm->id, 'occurred_at' => Carbon::now()->subDay()]);

        $ids = collect($this->service()->staleLeads($manager)['leads'])->pluck('id');

        $this->assertTrue($ids->contains($cold->id));
        $this->assertFalse($ids->contains($freshlyCreated->id));
        $this->assertFalse($ids->contains($warm->id));
    }

    public function test_follow_up_gaps_flags_overdue_and_missing_but_not_future(): void
    {
        $manager = User::factory()->manager()->create();

        $overdue = Lead::factory()->create(['next_follow_up_at' => Carbon::now()->subDays(2)]);
        $missing = Lead::factory()->create(['next_follow_up_at' => null]);
        $future = Lead::factory()->create(['next_follow_up_at' => Carbon::now()->addWeek()]);

        $gaps = collect($this->service()->followUpGaps($manager)['gaps']);
        $ids = $gaps->pluck('id');

        $this->assertTrue($ids->contains($overdue->id));
        $this->assertTrue($ids->contains($missing->id));
        $this->assertFalse($ids->contains($future->id));

        $this->assertSame('follow_up_overdue', $gaps->firstWhere('id', $overdue->id)['gap']);
        $this->assertSame('no_follow_up_set', $gaps->firstWhere('id', $missing->id)['gap']);

        // days_overdue is a whole number of days, never a raw float.
        $this->assertIsInt($gaps->firstWhere('id', $overdue->id)['days_overdue']);
        $this->assertSame(2, $gaps->firstWhere('id', $overdue->id)['days_overdue']);
        $this->assertNull($gaps->firstWhere('id', $missing->id)['days_overdue']);
    }

    public function test_at_risk_opportunities_names_why_each_was_flagged(): void
    {
        config(['services.business_development.stalled_opportunity_days' => 21]);
        $manager = User::factory()->manager()->create();
        $org = Organization::factory()->create();

        $stalled = Opportunity::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Stalled deal',
            'stage' => OpportunityStage::Proposal,
            'expected_close_date' => Carbon::now()->addMonth(),
        ]);
        Activity::factory()->create(['opportunity_id' => $stalled->id, 'occurred_at' => Carbon::now()->subDays(40)]);

        $overdue = Opportunity::factory()->create([
            'organization_id' => $org->id,
            'name' => 'Overdue deal',
            'stage' => OpportunityStage::Negotiation,
            'expected_close_date' => Carbon::now()->subWeek(),
        ]);
        Activity::factory()->create(['opportunity_id' => $overdue->id, 'occurred_at' => Carbon::now()]);

        $healthy = Opportunity::factory()->create([
            'organization_id' => $org->id,
            'stage' => OpportunityStage::Proposal,
            'expected_close_date' => Carbon::now()->addMonth(),
        ]);
        Activity::factory()->create(['opportunity_id' => $healthy->id, 'occurred_at' => Carbon::now()]);

        $closed = Opportunity::factory()->create([
            'organization_id' => $org->id,
            'stage' => OpportunityStage::ClosedWon,
        ]);

        $rows = collect($this->service()->atRiskOpportunities($manager)['opportunities']);
        $ids = $rows->pluck('id');

        $this->assertTrue($ids->contains($stalled->id));
        $this->assertTrue($ids->contains($overdue->id));
        $this->assertFalse($ids->contains($healthy->id));
        $this->assertFalse($ids->contains($closed->id));

        $this->assertNotEmpty($rows->firstWhere('id', $stalled->id)['reasons']);
        $this->assertStringContainsString('close date', implode(' ', $rows->firstWhere('id', $overdue->id)['reasons']));
    }

    public function test_analyze_account_separates_known_inference_and_recommendation(): void
    {
        $manager = User::factory()->manager()->create();
        $org = Organization::factory()->create(['name' => 'Globex', 'industry' => 'Manufacturing']);

        Lead::factory()->create(['organization_id' => $org->id, 'status' => LeadStatus::Qualified, 'next_follow_up_at' => null]);
        Opportunity::factory()->create(['organization_id' => $org->id, 'stage' => OpportunityStage::ClosedWon]);

        $result = $this->service()->analyzeAccount($manager, $org);

        $this->assertArrayHasKey('known', $result);
        $this->assertArrayHasKey('inference', $result);
        $this->assertArrayHasKey('recommendation', $result);
        $this->assertSame('Globex', $result['known']['organization']);
        // A Closed Won opp exists → inferred customer.
        $this->assertSame('customer', $result['inference']['relationship_type']);
        $this->assertIsString($result['recommendation']);
    }

    public function test_analyze_account_infers_prospect_when_no_closed_won_exists(): void
    {
        $manager = User::factory()->manager()->create();
        $org = Organization::factory()->create();
        Opportunity::factory()->create(['organization_id' => $org->id, 'stage' => OpportunityStage::Proposal]);

        $result = $this->service()->analyzeAccount($manager, $org);

        $this->assertSame('prospect', $result['inference']['relationship_type']);
    }

    public function test_missing_information_lists_empty_qualification_fields_for_a_lead(): void
    {
        $manager = User::factory()->manager()->create();
        $lead = Lead::factory()->create([
            'contact_id' => null,
            'estimated_value' => null,
            'expected_close_date' => null,
            'next_follow_up_at' => null,
            'source' => null,
            'description' => null,
            'notes' => null,
        ]);

        $missing = $this->service()->missingInformation($manager, $lead)['missing'];

        $this->assertContains('No contact person is linked to this lead.', $missing);
        $this->assertContains('No estimated value.', $missing);
        $this->assertContains('No next follow-up date set.', $missing);
        $this->assertContains('No activity has ever been logged against this lead.', $missing);
    }

    public function test_every_result_carries_a_source_string(): void
    {
        $manager = User::factory()->manager()->create();
        Lead::factory()->create();
        Opportunity::factory()->create();
        $org = Organization::factory()->create();

        $this->assertArrayHasKey('source', $this->service()->prioritizedLeads($manager));
        $this->assertArrayHasKey('source', $this->service()->staleLeads($manager));
        $this->assertArrayHasKey('source', $this->service()->followUpGaps($manager));
        $this->assertArrayHasKey('source', $this->service()->atRiskOpportunities($manager));
        $this->assertArrayHasKey('source', $this->service()->analyzeAccount($manager, $org));
    }
}
