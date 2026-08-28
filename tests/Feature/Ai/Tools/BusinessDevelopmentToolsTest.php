<?php

namespace Tests\Feature\Ai\Tools;

use App\Enums\LeadStatus;
use App\Enums\OpportunityStage;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\Tools\AnalyzeAccountTool;
use App\Services\Ai\Tools\IdentifyAtRiskOpportunitiesTool;
use App\Services\Ai\Tools\IdentifyFollowUpGapsTool;
use App\Services\Ai\Tools\IdentifyMissingInformationTool;
use App\Services\Ai\Tools\IdentifyStaleLeadsTool;
use App\Services\Ai\Tools\PrioritizeLeadsTool;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Phase 13: the six Business Development analytical tool contracts —
 * transparent output, authorization re-derived from the actor on every
 * call regardless of arguments, and never a leak of another team's
 * records.
 */
class BusinessDevelopmentToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_prioritize_leads_returns_transparent_factors_and_a_source(): void
    {
        $manager = User::factory()->manager()->create();
        Lead::factory()->create(['status' => LeadStatus::Qualified, 'next_follow_up_at' => null]);

        $result = app(PrioritizeLeadsTool::class)->execute($manager, []);

        $this->assertArrayHasKey('leads', $result);
        $this->assertArrayHasKey('source', $result);
        $first = $result['leads']->first();
        $this->assertArrayHasKey('factors', $first);
        $this->assertArrayHasKey('score', $first);
        $this->assertArrayHasKey('recommended_action', $first);
        $this->assertSame($first['score'], collect($first['factors'])->sum('points'));
    }

    public function test_a_team_head_never_sees_another_teams_leads_through_any_bd_tool(): void
    {
        $ownTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $head = User::factory()->teamHead($ownTeam)->create();

        $otherOrg = Organization::factory()->create(['name' => 'Competitor Prospect']);
        $otherLead = Lead::factory()->forTeam($otherTeam)->create([
            'organization_id' => $otherOrg->id,
            'status' => LeadStatus::Qualified,
            'next_follow_up_at' => Carbon::now()->subDays(5),
            'created_at' => Carbon::now()->subDays(60),
        ]);

        $collectIds = function (array $result): array {
            $rows = collect($result['leads'] ?? $result['gaps'] ?? $result['opportunities'] ?? []);

            return $rows->pluck('id')->all();
        };

        foreach ([
            fn () => app(PrioritizeLeadsTool::class)->execute($head, []),
            fn () => app(PrioritizeLeadsTool::class)->execute($head, ['team_id' => $otherTeam->id]),
            fn () => app(IdentifyStaleLeadsTool::class)->execute($head, ['team_id' => $otherTeam->id]),
            fn () => app(IdentifyFollowUpGapsTool::class)->execute($head, ['team_id' => $otherTeam->id]),
        ] as $call) {
            $result = $call();
            $this->assertStringNotContainsString('Competitor Prospect', json_encode($result));
            $this->assertNotContains($otherLead->id, $collectIds($result));
        }
    }

    public function test_analyze_account_denies_an_out_of_scope_organization_as_not_found(): void
    {
        $ownTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $head = User::factory()->teamHead($ownTeam)->create();

        $restricted = Organization::factory()->forTeam($otherTeam)->create(['name' => 'Restricted Co']);

        $this->expectException(AuthorizationException::class);

        app(AnalyzeAccountTool::class)->execute($head, ['organization_id' => $restricted->id]);
    }

    public function test_analyze_account_resolves_by_name_within_scope_only(): void
    {
        $manager = User::factory()->manager()->create();
        $org = Organization::factory()->create(['name' => 'Umbrella Corporation']);
        Opportunity::factory()->create(['organization_id' => $org->id, 'stage' => OpportunityStage::Proposal]);

        $result = app(AnalyzeAccountTool::class)->execute($manager, ['organization_name' => 'Umbrella']);

        $this->assertSame('Umbrella Corporation', $result['known']['organization']);
        $this->assertSame('prospect', $result['inference']['relationship_type']);
    }

    public function test_analyze_account_requires_a_reference(): void
    {
        $manager = User::factory()->manager()->create();

        $this->expectException(ValidationException::class);

        app(AnalyzeAccountTool::class)->execute($manager, []);
    }

    public function test_identify_missing_information_requires_exactly_one_subject(): void
    {
        $manager = User::factory()->manager()->create();
        $lead = Lead::factory()->create();
        $org = Organization::factory()->create();

        $this->expectException(ValidationException::class);

        app(IdentifyMissingInformationTool::class)->execute($manager, [
            'lead_id' => $lead->id,
            'organization_id' => $org->id,
        ]);
    }

    public function test_identify_missing_information_denies_an_unauthorized_lead(): void
    {
        $ownTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $head = User::factory()->teamHead($ownTeam)->create();
        $lead = Lead::factory()->forTeam($otherTeam)->create();

        $this->expectException(AuthorizationException::class);

        app(IdentifyMissingInformationTool::class)->execute($head, ['lead_id' => $lead->id]);
    }

    public function test_at_risk_opportunities_tool_flags_and_explains(): void
    {
        $manager = User::factory()->manager()->create();
        $opportunity = Opportunity::factory()->create([
            'name' => 'Neglected deal',
            'stage' => OpportunityStage::Proposal,
            'expected_close_date' => Carbon::now()->addMonth(),
        ]);
        Activity::factory()->create(['opportunity_id' => $opportunity->id, 'occurred_at' => Carbon::now()->subDays(60)]);

        $result = app(IdentifyAtRiskOpportunitiesTool::class)->execute($manager, []);

        $row = collect($result['opportunities'])->firstWhere('id', $opportunity->id);
        $this->assertNotNull($row);
        $this->assertNotEmpty($row['reasons']);
        $this->assertArrayHasKey('threshold_days', $result);
    }

    public function test_every_bd_tool_bounds_its_result_count(): void
    {
        $manager = User::factory()->manager()->create();
        Lead::factory()->count(40)->create(['status' => LeadStatus::New, 'next_follow_up_at' => null]);

        $result = app(PrioritizeLeadsTool::class)->execute($manager, ['limit' => 999]);

        $this->assertLessThanOrEqual(25, $result['leads']->count());
    }
}
