<?php

namespace Tests\Feature\MarketIntelligence;

use App\Enums\ProspectLeadEligibility;
use App\Enums\ProspectProposalStatus;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\ProspectLeadProposal;
use App\Models\User;
use App\Services\MarketIntelligence\ProspectLeadProposalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\ProspectFixtures;
use Tests\TestCase;

/**
 * V2.5 (spec §3/§6/§10/§30/§38): preparation writes NO CRM record — it
 * persists one proposal row, decides eligibility deterministically, and
 * returns a review URL.
 */
class ProspectLeadProposalServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ProspectLeadProposalService
    {
        return app(ProspectLeadProposalService::class);
    }

    public function test_preparing_a_proposal_creates_no_lead_and_no_organization(): void
    {
        $orgsBefore = Organization::count();
        $leadsBefore = Lead::count();

        $result = $this->service()->prepare(User::factory()->manager()->create(), ProspectFixtures::duplicateCheckResult(), ['industry' => 'cosmetics']);

        $this->assertSame('ok', $result['status']);
        $this->assertSame($orgsBefore, Organization::count());
        $this->assertSame($leadsBefore, Lead::count());
        $this->assertDatabaseCount('prospect_lead_proposals', 1);
        $this->assertStringContainsString('/market-intelligence/prospect-proposals/', $result['review_url']);
    }

    public function test_no_match_is_eligible_for_confirmation(): void
    {
        $result = $this->service()->prepare(User::factory()->manager()->create(), ProspectFixtures::duplicateCheckResult());

        $this->assertSame(ProspectLeadEligibility::EligibleForConfirmation->value, $result['eligibility']);
        $this->assertFalse($result['duplicate_acknowledgement_required']);
    }

    public function test_possible_duplicate_is_review_required_with_acknowledgement(): void
    {
        $result = $this->service()->prepare(User::factory()->manager()->create(), ProspectFixtures::duplicateCheckResult([
            'duplicate_status' => 'possible_duplicate',
            'duplicate_status_label' => 'POSSIBLE DUPLICATE',
            'candidate_matches' => [['crm_record_id' => 7, 'business_name' => 'ABC Beauty', 'match_reasons' => []]],
        ]));

        $this->assertSame(ProspectLeadEligibility::ReviewRequired->value, $result['eligibility']);
        $this->assertTrue($result['duplicate_acknowledgement_required']);
        $this->assertNotEmpty($result['warnings']);
    }

    /**
     * @dataProvider blockedCases
     */
    public function test_blocked_states(array $overrides, ProspectLeadEligibility $expected): void
    {
        $result = $this->service()->prepare(User::factory()->manager()->create(), ProspectFixtures::duplicateCheckResult($overrides));

        $this->assertSame($expected->value, $result['eligibility']);
        $this->assertStringContainsString('No lead can be created', $result['next_step_for_human']);
        $this->assertFalse(ProspectLeadEligibility::from($result['eligibility'])->canReachConfirmation());
    }

    public static function blockedCases(): array
    {
        return [
            'likely' => [['duplicate_status' => 'likely_duplicate', 'duplicate_status_label' => 'LIKELY DUPLICATE'], ProspectLeadEligibility::BlockedDuplicate],
            'exact' => [['duplicate_status' => 'exact_duplicate', 'duplicate_status_label' => 'EXACT DUPLICATE'], ProspectLeadEligibility::BlockedDuplicate],
            'unavailable' => [['check_status' => 'unavailable', 'duplicate_status' => null], ProspectLeadEligibility::BlockedCheckUnavailable],
            'skipped' => [['check_status' => 'skipped', 'duplicate_status' => null], ProspectLeadEligibility::BlockedInsufficientIdentity],
        ];
    }

    public function test_a_high_score_does_not_change_eligibility(): void
    {
        $result = $this->service()->prepare(User::factory()->manager()->create(), ProspectFixtures::duplicateCheckResult([
            'duplicate_status' => 'exact_duplicate',
            'duplicate_status_label' => 'EXACT DUPLICATE',
            'carried_from_scoring' => ['total_score' => 100, 'priority' => 'high', 'qualification_outcome' => 'strong_match'],
        ]));

        $this->assertSame(ProspectLeadEligibility::BlockedDuplicate->value, $result['eligibility']);
    }

    public function test_the_proposed_fields_are_source_derived_and_never_fabricate_missing_data(): void
    {
        $result = $this->service()->prepare(User::factory()->manager()->create(), ProspectFixtures::duplicateCheckResult([
            'business' => 'ABC Beauty Corporation', 'website' => 'https://abcbeauty.ph/', 'domain' => 'abcbeauty.ph',
        ]), ['industry' => 'cosmetics', 'location' => 'Cebu City, Philippines']);

        $org = $result['proposed_organization'];
        $this->assertSame('ABC Beauty Corporation', $org['name']);
        $this->assertSame('cosmetics', $org['industry']);
        $this->assertSame('https://abcbeauty.ph/', $org['website']);
        $this->assertSame('Cebu City', $org['city']);
        $this->assertSame('Philippines', $org['country']);
        $this->assertSame('Market Intelligence', $org['source']);
        // No fabricated contact / phone / email / revenue.
        $this->assertArrayNotHasKey('phone', $org);
        $this->assertArrayNotHasKey('email', $org);
        $this->assertStringContainsString('Created from Market Intelligence prospect research', $result['proposed_lead']['description']);
    }

    public function test_a_fresh_prepare_supersedes_the_previous_pending_proposal_for_the_same_prospect(): void
    {
        $actor = User::factory()->manager()->create();

        $first = $this->service()->prepare($actor, ProspectFixtures::duplicateCheckResult());
        $second = $this->service()->prepare($actor, ProspectFixtures::duplicateCheckResult());

        $this->assertSame(ProspectProposalStatus::Superseded, ProspectLeadProposal::find($first['proposal_id'])->status);
        $this->assertSame(ProspectProposalStatus::Pending, ProspectLeadProposal::find($second['proposal_id'])->status);
    }

    public function test_the_stored_fingerprint_matches_the_current_content(): void
    {
        $result = $this->service()->prepare(User::factory()->manager()->create(), ProspectFixtures::duplicateCheckResult());
        $proposal = ProspectLeadProposal::find($result['proposal_id']);

        $this->assertSame($proposal->fingerprint, $proposal->currentFingerprint());
        $this->assertSame(64, strlen($proposal->fingerprint));
    }

    public function test_preparation_is_audited_without_page_bodies(): void
    {
        Log::shouldReceive('channel')->with('audit')->andReturn($spy = \Mockery::mock());
        $spy->shouldReceive('info')->once()->with('market_intelligence.crm_proposal_prepared', \Mockery::on(function ($context) {
            return isset($context['proposal_id'], $context['eligibility'], $context['duplicate_status'])
                && $context['eligibility'] === 'eligible_for_confirmation'
                && ! str_contains(strtolower(json_encode($context)), '<html');
        }));

        $this->service()->prepare(User::factory()->manager()->create(), ProspectFixtures::duplicateCheckResult());
    }

    public function test_the_per_user_hourly_proposal_limit_is_enforced(): void
    {
        $actor = User::factory()->manager()->create();
        $key = 'market-intel:crm-proposal:'.$actor->id;
        for ($i = 0; $i < (int) config('services.market_intelligence.lead_creation.max_proposals_per_hour'); $i++) {
            RateLimiter::hit($key, 3600);
        }

        $result = $this->service()->prepare($actor, ProspectFixtures::duplicateCheckResult());

        $this->assertSame('rate_limited', $result['status']);
        $this->assertDatabaseCount('prospect_lead_proposals', 0);
    }
}
