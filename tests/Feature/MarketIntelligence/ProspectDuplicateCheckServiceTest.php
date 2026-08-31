<?php

namespace Tests\Feature\MarketIntelligence;

use App\Models\Lead;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\MarketIntelligence\ProspectDuplicateCheckService;
use App\Support\MarketIntelligence\DuplicateMatchPolicy;
use App\Support\MarketIntelligence\ProspectIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ProspectFixtures;
use Tests\TestCase;

/**
 * V2.4 (spec §8/§9/§20/§32/§33/§35/§36): the bounded shell — server-side
 * authorization scoping, restricted-record non-disclosure, read-only
 * guarantee, safe failure, audit.
 */
class ProspectDuplicateCheckServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ProspectDuplicateCheckService
    {
        return app(ProspectDuplicateCheckService::class);
    }

    private function abcIdentity(): ProspectIdentity
    {
        return ProspectFixtures::prospectIdentity([
            'business' => 'ABC Beauty Corporation',
            'website' => 'https://abcbeauty.ph/',
            'domain' => 'abcbeauty.ph',
            'location' => 'Cebu City',
        ]);
    }

    public function test_a_manager_matches_an_organisation_anywhere_in_the_crm(): void
    {
        $team = Team::factory()->create();
        Organization::factory()->forTeam($team)->create([
            'name' => 'ABC Beauty Corp.', 'website' => 'https://www.abcbeauty.ph/', 'city' => 'Cebu City',
        ]);

        $result = $this->service()->check(User::factory()->manager()->create(), [$this->abcIdentity()], DuplicateMatchPolicy::default());

        $prospect = $result['checked_prospects'][0];
        $this->assertSame('ok', $prospect['check_status']);
        $this->assertSame('exact_duplicate', $prospect['duplicate_status']);
        $this->assertCount(1, $prospect['candidate_matches']);
        $this->assertStringContainsString('every organisation', $prospect['scope_note']);
    }

    public function test_a_team_head_matches_an_organisation_in_their_own_team(): void
    {
        $team = Team::factory()->create();
        $head = User::factory()->teamHead($team)->create();
        Organization::factory()->forTeam($team)->create([
            'name' => 'ABC Beauty Corp.', 'website' => 'https://www.abcbeauty.ph/', 'city' => 'Cebu City',
        ]);

        $result = $this->service()->check($head, [$this->abcIdentity()], DuplicateMatchPolicy::default());

        $this->assertSame('exact_duplicate', $result['checked_prospects'][0]['duplicate_status']);
    }

    /**
     * The critical V2.4 test (spec §9/§35): a perfect duplicate that
     * belongs only to another team is invisible — not matched, not
     * counted, not mentioned, not leaked through audit.
     */
    public function test_a_restricted_duplicate_under_another_team_is_invisible_to_a_team_head(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $headA = User::factory()->teamHead($teamA)->create();

        Organization::factory()->forTeam($teamB)->create([
            'name' => 'ABC Beauty Corp.', 'website' => 'https://www.abcbeauty.ph/', 'city' => 'Cebu City', 'country' => 'Philippines',
        ]);

        Log::shouldReceive('channel')->with('audit')->andReturn($spy = \Mockery::mock());
        $spy->shouldReceive('info')->once()->with('market_intelligence.duplicate_check', \Mockery::on(function ($context) {
            $blob = strtolower(json_encode($context));

            return $context['crm_candidates_examined'] === 0
                && ! str_contains($blob, 'abc beauty')
                && $context['duplicate_status_distribution']['exact_duplicate'] === 0;
        }));

        $result = $this->service()->check($headA, [$this->abcIdentity()], DuplicateMatchPolicy::default());
        $prospect = $result['checked_prospects'][0];

        // The restricted record is invisible: no candidate, nothing
        // examined, status unaffected. (The prospect's own name — the
        // actor's input — is naturally echoed back and is not a leak.)
        $this->assertSame('no_match', $prospect['duplicate_status']);
        $this->assertSame([], $prospect['candidate_matches']);
        $this->assertSame(0, $prospect['candidates_examined']);
        $this->assertStringContainsString('not a guarantee', $prospect['next_action']);
    }

    public function test_a_null_team_organisation_owned_by_another_user_is_invisible_to_a_team_head(): void
    {
        $head = User::factory()->teamHead()->create();
        $otherManager = User::factory()->manager()->create();

        $org = Organization::factory()->create([
            'name' => 'ABC Beauty Corp.', 'website' => 'https://www.abcbeauty.ph/', 'team_id' => null,
        ]);
        $org->owner_id = $otherManager->id;
        $org->save();

        $result = $this->service()->check($head, [$this->abcIdentity()], DuplicateMatchPolicy::default());

        $this->assertSame('no_match', $result['checked_prospects'][0]['duplicate_status']);
    }

    public function test_duplicate_checking_never_writes_to_the_crm(): void
    {
        $team = Team::factory()->create();
        $org = Organization::factory()->forTeam($team)->create([
            'name' => 'ABC Beauty Corp.', 'website' => 'https://www.abcbeauty.ph/', 'notes' => 'original note',
        ]);
        Lead::factory()->forTeam($team)->create(['organization_id' => $org->id]);

        $orgsBefore = Organization::count();
        $leadsBefore = Lead::count();

        $this->service()->check(User::factory()->manager()->create(), [
            $this->abcIdentity(),
            ProspectFixtures::prospectIdentity(['business' => 'Totally New Co', 'domain' => 'totallynew.example']),
        ], DuplicateMatchPolicy::default());

        $this->assertSame($orgsBefore, Organization::count());
        $this->assertSame($leadsBefore, Lead::count());
        $this->assertSame('original note', $org->fresh()->notes);
        $this->assertSame(1, Lead::where('organization_id', $org->id)->count());
    }

    public function test_the_matched_organisation_exposes_crm_linkage_but_no_account_intelligence(): void
    {
        $team = Team::factory()->create();
        $org = Organization::factory()->forTeam($team)->create([
            'name' => 'ABC Beauty Corp.', 'website' => 'https://www.abcbeauty.ph/',
        ]);
        Lead::factory()->forTeam($team)->create(['organization_id' => $org->id]);

        $result = $this->service()->check(User::factory()->manager()->create(), [$this->abcIdentity()], DuplicateMatchPolicy::default());
        $candidate = $result['checked_prospects'][0]['candidate_matches'][0];

        $this->assertTrue($candidate['crm_linkage']['has_lead']);
        $this->assertFalse($candidate['crm_linkage']['has_opportunity']);
        foreach (['notes', 'opportunity_value', 'estimated_value', 'revenue', 'communications', 'owner', 'team'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $candidate);
        }
    }

    public function test_a_crm_lookup_failure_is_reported_as_unavailable_not_no_match(): void
    {
        Schema::drop('organizations');

        $result = $this->service()->check(User::factory()->manager()->create(), [$this->abcIdentity()], DuplicateMatchPolicy::default());
        $prospect = $result['checked_prospects'][0];

        $this->assertSame('unavailable', $prospect['check_status']);
        $this->assertNull($prospect['duplicate_status']);
        $this->assertStringContainsString('do NOT treat this as', $prospect['next_action']);
    }

    public function test_a_thin_identity_is_skipped_not_treated_as_no_match(): void
    {
        $result = $this->service()->check(User::factory()->manager()->create(), [
            new ProspectIdentity(business: '', website: null, domain: null, location: null),
        ], DuplicateMatchPolicy::default());

        $this->assertSame('skipped', $result['checked_prospects'][0]['check_status']);
        $this->assertNull($result['checked_prospects'][0]['duplicate_status']);
    }

    public function test_the_v2_3_score_fields_pass_through_unchanged(): void
    {
        $identity = ProspectIdentity::fromArray([
            'identity' => ['business' => 'Totally New Co', 'domain' => 'totallynew.example'],
            'total_score' => 84,
            'priority' => 'high',
            'qualification_outcome' => 'strong_match',
            'scoring_model' => 'v2.3-default-1',
        ]);

        $result = $this->service()->check(User::factory()->manager()->create(), [$identity], DuplicateMatchPolicy::default());
        $carried = $result['checked_prospects'][0]['carried_from_scoring'];

        $this->assertSame(84, $carried['total_score']);
        $this->assertSame('high', $carried['priority']);
        $this->assertSame('strong_match', $carried['qualification_outcome']);
    }

    public function test_the_per_user_hourly_limit_is_enforced(): void
    {
        $actor = User::factory()->manager()->create();
        $key = 'market-intel:duplicate-check:'.$actor->id;
        for ($i = 0; $i < (int) config('services.market_intelligence.duplicate_check.max_checks_per_hour'); $i++) {
            RateLimiter::hit($key, 3600);
        }

        $result = $this->service()->check($actor, [$this->abcIdentity()], DuplicateMatchPolicy::default());

        $this->assertSame('rate_limited', $result['status']);
    }

    public function test_the_result_carries_the_deterministic_read_only_notice_and_policy_version(): void
    {
        $result = $this->service()->check(User::factory()->manager()->create(), [$this->abcIdentity()], DuplicateMatchPolicy::default());

        $this->assertStringContainsString('not a confidence score', $result['notice']);
        $this->assertStringContainsString('never as "no match"', $result['notice']);
        $this->assertSame('v2.4-default-1', $result['match_policy']['version']);
    }
}
