<?php

namespace Tests\Feature\MarketIntelligence;

use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\ProspectLeadProposal;
use App\Models\Team;
use App\Models\User;
use App\Services\MarketIntelligence\ProspectDuplicateCheckService;
use App\Services\MarketIntelligence\ProspectLeadProposalService;
use App\Support\MarketIntelligence\DuplicateMatchPolicy;
use App\Support\MarketIntelligence\ProspectIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Support\ProspectFixtures;
use Tests\TestCase;

/**
 * V2.6 (spec §10): the full cross-team non-disclosure matrix. Team Head
 * A researches a prospect that matches SEVERAL records that live only
 * under Team B — an exact-domain match, an exact-name match, a fuzzy
 * match, a null-team record, and one carrying a secret-looking note.
 * None of them may surface, be counted, be named, or change the result,
 * however the request is crafted.
 */
class V2CrossTeamPrivacyAttackTest extends TestCase
{
    use RefreshDatabase;

    private Team $teamA;

    private Team $teamB;

    private User $headA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teamA = Team::factory()->create();
        $this->teamB = Team::factory()->create();
        $this->headA = User::factory()->teamHead($this->teamA)->create();
        $headB = User::factory()->teamHead($this->teamB)->create();

        // Everything below belongs to Team B and must be invisible to Head A.
        $exactDomain = Organization::factory()->forTeam($this->teamB)->create([
            'name' => 'Northwind Beauty Corporation', 'website' => 'https://www.northwindbeauty.ph/',
            'city' => 'Cebu City', 'notes' => 'SECRET-B-9F1: retention target, do not disclose.',
        ]);
        Lead::factory()->forTeam($this->teamB)->create(['organization_id' => $exactDomain->id]);
        Opportunity::factory()->forTeam($this->teamB)->create(['organization_id' => $exactDomain->id, 'owner_id' => $headB->id]);

        Organization::factory()->forTeam($this->teamB)->create([
            'name' => 'Northwind Beauty', 'website' => null, 'city' => 'Cebu City',
        ]);
        Organization::factory()->forTeam($this->teamB)->create([
            'name' => 'Northwind Beauty Wellness Group', 'website' => null, 'city' => 'Cebu City',
        ]);

        // Null-team record owned by the other team's head.
        $nullTeam = Organization::factory()->create([
            'name' => 'Northwind Beauty Ltd', 'website' => 'https://northwindbeauty.ph/shop', 'team_id' => null,
        ]);
        $nullTeam->owner_id = $headB->id;
        $nullTeam->save();
    }

    private function identity(array $overrides = []): ProspectIdentity
    {
        return ProspectFixtures::prospectIdentity(array_merge([
            'business' => 'Northwind Beauty Corporation',
            'website' => 'https://northwindbeauty.ph/',
            'domain' => 'northwindbeauty.ph',
            'location' => 'Cebu City',
        ], $overrides));
    }

    public function test_a_team_head_duplicate_check_sees_none_of_the_other_teams_records(): void
    {
        $result = app(ProspectDuplicateCheckService::class)->check($this->headA, [$this->identity()], DuplicateMatchPolicy::default());
        $prospect = $result['checked_prospects'][0];

        $this->assertSame('no_match', $prospect['duplicate_status']);
        $this->assertSame([], $prospect['candidate_matches']);
        $this->assertSame(0, $prospect['candidates_examined']);

        $blob = json_encode($result);
        $this->assertStringNotContainsString('SECRET-B-9F1', $blob);
        $this->assertStringNotContainsString('crm_record_id', $blob);
    }

    public function test_a_manager_by_contrast_sees_them_all(): void
    {
        $result = app(ProspectDuplicateCheckService::class)->check(User::factory()->manager()->create(), [$this->identity()], DuplicateMatchPolicy::default());

        $this->assertContains($result['checked_prospects'][0]['duplicate_status'], ['exact_duplicate', 'likely_duplicate']);
        $this->assertGreaterThanOrEqual(1, count($result['checked_prospects'][0]['candidate_matches']));
    }

    public function test_a_crafted_team_id_owner_id_or_crm_id_in_the_payload_cannot_widen_scope(): void
    {
        $result = app(ProspectDuplicateCheckService::class)->check($this->headA, [
            ProspectIdentity::fromArray([
                'business' => 'Northwind Beauty Corporation',
                'website' => 'https://northwindbeauty.ph/',
                'domain' => 'northwindbeauty.ph',
                'team_id' => $this->teamB->id,
                'owner_id' => 999,
                'crm_record_id' => 1,
                'candidate_matches' => [['crm_record_id' => 1, 'business_name' => 'Northwind Beauty Corporation']],
            ]),
        ], DuplicateMatchPolicy::default());

        $this->assertSame('no_match', $result['checked_prospects'][0]['duplicate_status']);
        $this->assertSame(0, $result['checked_prospects'][0]['candidates_examined']);
    }

    public function test_the_team_head_audit_never_counts_or_names_a_restricted_record(): void
    {
        Log::shouldReceive('channel')->with('audit')->andReturn($spy = \Mockery::mock());
        $spy->shouldReceive('info')->once()->with('market_intelligence.duplicate_check', \Mockery::on(function ($context) {
            $blob = strtolower(json_encode($context));

            return $context['crm_candidates_examined'] === 0
                && $context['duplicate_status_distribution']['exact_duplicate'] === 0
                && ! str_contains($blob, 'northwind')
                && ! str_contains($blob, 'secret');
        }));

        app(ProspectDuplicateCheckService::class)->check($this->headA, [$this->identity()], DuplicateMatchPolicy::default());
    }

    public function test_a_team_head_preparing_a_proposal_gets_no_match_and_can_proceed_even_though_a_restricted_duplicate_exists(): void
    {
        // Head A cannot see Team B's records, so from A's authorised view
        // the prospect is genuinely new. This is correct and documented:
        // "no_match" for a Team Head means "not in your scope", never a
        // guarantee of org-wide absence.
        $dupCheck = app(ProspectDuplicateCheckService::class)->check($this->headA, [$this->identity()], DuplicateMatchPolicy::default());

        $result = app(ProspectLeadProposalService::class)->prepare($this->headA, $dupCheck['checked_prospects'][0], ['industry' => 'cosmetics']);

        $this->assertSame('ok', $result['status']);
        $this->assertSame('eligible_for_confirmation', $result['eligibility']);
        // The prospect_snapshot carries no restricted record.
        $proposal = ProspectLeadProposal::findOrFail($result['proposal_id']);
        $this->assertStringNotContainsString('SECRET-B-9F1', json_encode($proposal->prospect_snapshot));
        $this->assertStringNotContainsString('not a guarantee', json_encode($proposal->prospect_snapshot));
        $this->assertStringContainsString('not a guarantee the business is absent org-wide', $dupCheck['checked_prospects'][0]['next_action']);
    }
}
