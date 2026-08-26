<?php

namespace Tests\Feature\Workflow;

use App\Enums\LeadStatus;
use App\Enums\OpportunityStage;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Target;
use App\Models\Team;
use App\Models\User;
use App\Services\PerformanceService;
use App\Services\Workflow\Analyzers\DailyFollowUpAnalyzer;
use App\Services\Workflow\Analyzers\OpportunityAttentionAnalyzer;
use App\Services\Workflow\Analyzers\PerformanceExceptionAnalyzer;
use App\Support\Workflow\WorkflowScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * STEP 37: every analyzer is plain deterministic Laravel logic, fully
 * testable without any AI/agent involvement at all — exactly the point
 * of keeping deterministic filtering out of the model's hands.
 */
class AnalyzersTest extends TestCase
{
    use RefreshDatabase;

    // ── DailyFollowUpAnalyzer ────────────────────────────────────────

    public function test_no_findings_when_there_are_no_overdue_or_due_today_leads(): void
    {
        $user = User::factory()->create();
        Lead::factory()->create(['owner_id' => $user->id, 'next_follow_up_at' => now()->addWeek()]);

        $result = app(DailyFollowUpAnalyzer::class)->analyze(WorkflowScope::forUser($user));

        $this->assertFalse($result->hasFindings);
        $this->assertNotEmpty($result->noFindingsMessage);
    }

    public function test_overdue_leads_are_found_and_scoped_to_individual_owner(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Lead::factory()->create(['owner_id' => $user->id, 'next_follow_up_at' => now()->subDays(3)]);
        Lead::factory()->create(['owner_id' => $other->id, 'next_follow_up_at' => now()->subDays(3)]);

        $result = app(DailyFollowUpAnalyzer::class)->analyze(WorkflowScope::forUser($user));

        $this->assertTrue($result->hasFindings);
        $this->assertSame(1, $result->findings['overdue_count']);
        $this->assertCount(1, $result->findings['leads']);
    }

    public function test_team_head_scope_only_sees_their_own_teams_leads(): void
    {
        $ownTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $teamHead = User::factory()->teamHead($ownTeam)->create();
        Lead::factory()->create(['team_id' => $ownTeam->id, 'owner_id' => User::factory()->teamMember($ownTeam)->create()->id, 'next_follow_up_at' => now()->subDay()]);
        Lead::factory()->create(['team_id' => $otherTeam->id, 'owner_id' => User::factory()->teamMember($otherTeam)->create()->id, 'next_follow_up_at' => now()->subDay()]);

        $result = app(DailyFollowUpAnalyzer::class)->analyze(WorkflowScope::forUser($teamHead));

        $this->assertCount(1, $result->findings['leads']);
    }

    public function test_manager_scope_sees_organisation_wide_leads(): void
    {
        $manager = User::factory()->manager()->create();
        $team = Team::factory()->create();
        Lead::factory()->create(['team_id' => $team->id, 'owner_id' => User::factory()->teamMember($team)->create()->id, 'next_follow_up_at' => now()->subDay()]);

        $result = app(DailyFollowUpAnalyzer::class)->analyze(WorkflowScope::forUser($manager));

        $this->assertTrue($result->hasFindings);
    }

    public function test_disqualified_and_converted_leads_never_count_as_overdue(): void
    {
        $user = User::factory()->create();
        Lead::factory()->create(['owner_id' => $user->id, 'status' => LeadStatus::Disqualified, 'next_follow_up_at' => now()->subDays(3)]);

        $result = app(DailyFollowUpAnalyzer::class)->analyze(WorkflowScope::forUser($user));

        $this->assertFalse($result->hasFindings);
    }

    // ── OpportunityAttentionAnalyzer ─────────────────────────────────

    public function test_no_findings_for_a_healthy_recently_active_opportunity(): void
    {
        $user = User::factory()->create();
        $opportunity = Opportunity::factory()->create([
            'owner_id' => $user->id,
            'stage' => OpportunityStage::Proposal,
            'expected_close_date' => now()->addMonths(2),
        ]);
        Activity::factory()->create(['opportunity_id' => $opportunity->id, 'occurred_at' => now()->subDay(), 'user_id' => $user->id]);

        $result = app(OpportunityAttentionAnalyzer::class)->analyze(WorkflowScope::forUser($user), stalledDays: 14, closingSoonDays: 7);

        $this->assertFalse($result->hasFindings);
    }

    public function test_closing_soon_opportunities_are_identified(): void
    {
        $user = User::factory()->create();
        Opportunity::factory()->create([
            'owner_id' => $user->id,
            'stage' => OpportunityStage::Negotiation,
            'expected_close_date' => now()->addDays(3),
        ]);

        $result = app(OpportunityAttentionAnalyzer::class)->analyze(WorkflowScope::forUser($user), stalledDays: 14, closingSoonDays: 7);

        $this->assertTrue($result->hasFindings);
        $this->assertCount(1, $result->findings['closing_soon']);
    }

    public function test_stalled_opportunities_with_no_recent_activity_are_identified(): void
    {
        $user = User::factory()->create();
        $opportunity = Opportunity::factory()->create(['owner_id' => $user->id, 'stage' => OpportunityStage::Proposal]);
        Activity::factory()->create(['opportunity_id' => $opportunity->id, 'occurred_at' => now()->subDays(30), 'user_id' => $user->id]);

        $result = app(OpportunityAttentionAnalyzer::class)->analyze(WorkflowScope::forUser($user), stalledDays: 14, closingSoonDays: 7);

        $this->assertTrue($result->hasFindings);
        $this->assertCount(1, $result->findings['stalled_no_recent_activity']);
    }

    public function test_missing_expected_close_date_is_identified(): void
    {
        $user = User::factory()->create();
        Opportunity::factory()->create(['owner_id' => $user->id, 'stage' => OpportunityStage::Qualification, 'expected_close_date' => null]);

        $result = app(OpportunityAttentionAnalyzer::class)->analyze(WorkflowScope::forUser($user), stalledDays: 14, closingSoonDays: 7);

        $this->assertCount(1, $result->findings['missing_expected_close_date']);
    }

    public function test_closed_opportunities_are_never_flagged(): void
    {
        $user = User::factory()->create();
        Opportunity::factory()->create([
            'owner_id' => $user->id,
            'stage' => OpportunityStage::ClosedWon,
            'expected_close_date' => now()->addDay(),
            'closed_at' => now(),
        ]);

        $result = app(OpportunityAttentionAnalyzer::class)->analyze(WorkflowScope::forUser($user), stalledDays: 14, closingSoonDays: 7);

        $this->assertFalse($result->hasFindings);
    }

    public function test_analyzer_never_claims_an_opportunity_will_close_or_fail(): void
    {
        // A structural guarantee, not just a style note: the analyzer's
        // own output vocabulary never includes a prediction verb.
        $user = User::factory()->create();
        Opportunity::factory()->create(['owner_id' => $user->id, 'stage' => OpportunityStage::Proposal, 'expected_close_date' => now()->addDays(2)]);

        $result = app(OpportunityAttentionAnalyzer::class)->analyze(WorkflowScope::forUser($user), stalledDays: 14, closingSoonDays: 7);

        $json = json_encode($result->findings);
        $this->assertStringNotContainsString('will close', $json);
        $this->assertStringNotContainsString('will fail', $json);
        $this->assertStringNotContainsString('guaranteed', $json);
    }

    // ── PerformanceExceptionAnalyzer ─────────────────────────────────

    public function test_no_exception_when_on_track(): void
    {
        $user = User::factory()->create();
        Target::factory()->individual($user)->create(['target_amount' => 1000]);
        Opportunity::factory()->create([
            'owner_id' => $user->id,
            'stage' => OpportunityStage::ClosedWon,
            'value' => 1000,
            'closed_at' => now(),
        ]);

        $result = app(PerformanceExceptionAnalyzer::class)->analyze(WorkflowScope::forUser($user));

        $this->assertFalse($result->hasFindings);
    }

    public function test_behind_pace_individual_is_flagged_as_an_exception(): void
    {
        $user = User::factory()->create();
        Target::factory()->individual($user)->create(['target_amount' => 1000000]);

        $result = app(PerformanceExceptionAnalyzer::class)->analyze(WorkflowScope::forUser($user));

        $this->assertTrue($result->hasFindings);
        $this->assertSame('Behind', $result->findings['exceptions'][0]['signal']);
    }

    public function test_the_exception_figures_are_identical_to_performanceservices_own_calculation(): void
    {
        $user = User::factory()->create();
        Target::factory()->individual($user)->create(['target_amount' => 1000000]);

        $expected = app(PerformanceService::class)->forIndividual($user, now()->startOfMonth(), now()->endOfMonth());
        $result = app(PerformanceExceptionAnalyzer::class)->analyze(WorkflowScope::forUser($user));

        $this->assertSame($expected->achievementPercent, $result->findings['exceptions'][0]['achievement_percent']);
        $this->assertSame($expected->gap, $result->findings['exceptions'][0]['gap']);
        $this->assertSame($expected->pipelineCoverage, $result->findings['exceptions'][0]['pipeline_coverage']);
    }

    public function test_manager_scope_flags_behind_teams_individually(): void
    {
        $manager = User::factory()->manager()->create();
        $behindTeam = Team::factory()->create();
        $onTrackTeam = Team::factory()->create();
        Target::factory()->team($behindTeam)->create(['target_amount' => 1000000]);

        $result = app(PerformanceExceptionAnalyzer::class)->analyze(WorkflowScope::forUser($manager));

        $labels = collect($result->findings['exceptions'])->pluck('label');
        $this->assertTrue($labels->contains($behindTeam->name));
        $this->assertFalse($labels->contains($onTrackTeam->name));
    }

    public function test_team_head_scope_never_sees_another_teams_exception(): void
    {
        $ownTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $teamHead = User::factory()->teamHead($ownTeam)->create();
        Target::factory()->team($otherTeam)->create(['target_amount' => 1000000]);

        $result = app(PerformanceExceptionAnalyzer::class)->analyze(WorkflowScope::forUser($teamHead));

        $this->assertFalse($result->hasFindings);
    }
}
