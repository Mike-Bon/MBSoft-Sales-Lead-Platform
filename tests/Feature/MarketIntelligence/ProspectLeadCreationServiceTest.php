<?php

namespace Tests\Feature\MarketIntelligence;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Enums\ProspectLeadEligibility;
use App\Enums\ProspectProposalStatus;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\ProspectLeadProposal;
use App\Models\Team;
use App\Models\User;
use App\Services\MarketIntelligence\ProspectLeadCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * V2.5 (spec §17/§18/§19/§20/§38/§39/§40): the confirmed-write path.
 * Every gate is tested: fingerprint, eligibility, acknowledgement, the
 * TOCTOU duplicate re-check, idempotency, atomicity, and reuse of the
 * V1 LeadService / OrganizationService.
 */
class ProspectLeadCreationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ProspectLeadCreationService
    {
        return app(ProspectLeadCreationService::class);
    }

    private function proposal(User $owner, array $state = []): ProspectLeadProposal
    {
        return ProspectLeadProposal::factory()->ownedBy($owner)->withFingerprint()->create($state);
    }

    private function validated(ProspectLeadProposal $proposal, array $overrides = []): array
    {
        return array_merge([
            'fingerprint' => $proposal->fingerprint,
            'business_name' => $proposal->proposed_organization['name'],
            'industry' => $proposal->proposed_organization['industry'] ?? 'cosmetics',
            'website' => $proposal->proposed_organization['website'] ?? null,
            'city' => 'Cebu City',
            'state_province' => null,
            'country' => 'Philippines',
            'lead_description' => null,
            'acknowledge_possible_duplicate' => false,
        ], $overrides);
    }

    public function test_a_valid_confirmation_creates_an_organization_and_a_lead_via_the_v1_services(): void
    {
        $actor = User::factory()->manager()->create();
        $proposal = $this->proposal($actor);

        $result = $this->service()->confirmAndCreate($actor, $proposal, $this->validated($proposal, ['business_name' => 'ABC Beauty Manila']));

        $this->assertSame('created', $result['status']);

        $lead = Lead::findOrFail($result['lead_id']);
        $org = Organization::findOrFail($result['organization_id']);
        $this->assertSame('ABC Beauty Manila', $org->name);
        $this->assertSame('Market Intelligence', $org->source);
        $this->assertSame('Market Intelligence', $lead->source);
        $this->assertSame(LeadStatus::New, $lead->status);
        $this->assertSame($org->id, $lead->organization_id);
        $this->assertSame($actor->id, $lead->owner_id);

        // LeadService logged its "Lead created" activity — V1 path reused.
        $this->assertTrue(Activity::where('lead_id', $lead->id)->where('type', ActivityType::Note)->where('subject', 'Lead created')->exists());

        $proposal->refresh();
        $this->assertSame(ProspectProposalStatus::Confirmed, $proposal->status);
        $this->assertSame($actor->id, $proposal->decided_by);
        $this->assertSame($lead->id, $proposal->lead_id);
    }

    public function test_a_fingerprint_mismatch_is_rejected_and_writes_nothing(): void
    {
        $actor = User::factory()->manager()->create();
        $proposal = $this->proposal($actor);

        $result = $this->service()->confirmAndCreate($actor, $proposal, $this->validated($proposal, ['fingerprint' => str_repeat('0', 64)]));

        $this->assertSame('modified', $result['status']);
        $this->assertSame(0, Lead::count());
        $this->assertSame(0, Organization::count());
    }

    public function test_a_blocked_proposal_cannot_be_confirmed(): void
    {
        $actor = User::factory()->manager()->create();
        $proposal = $this->proposal($actor, [
            'eligibility' => ProspectLeadEligibility::BlockedDuplicate,
            'duplicate_status' => 'exact_duplicate',
        ]);

        $result = $this->service()->confirmAndCreate($actor, $proposal, $this->validated($proposal));

        $this->assertSame('blocked', $result['status']);
        $this->assertSame(0, Lead::count());
    }

    public function test_a_possible_duplicate_needs_the_acknowledgement_flag(): void
    {
        $actor = User::factory()->manager()->create();
        $org = Organization::factory()->create(['name' => 'ABC Beauty Cebu', 'website' => null, 'city' => 'Cebu City']);
        $proposal = $this->proposal($actor, [
            'eligibility' => ProspectLeadEligibility::ReviewRequired,
            'duplicate_status' => 'possible_duplicate',
            'duplicate_ack_required' => true,
            'prospect_snapshot' => ['business' => 'ABC Beauty', 'domain' => null, 'candidate_org_ids' => [$org->id]],
            'business_name' => 'ABC Beauty', 'domain' => null,
            'proposed_organization' => ['name' => 'ABC Beauty', 'industry' => null, 'website' => null, 'city' => 'Cebu City', 'state_province' => null, 'country' => null, 'source' => 'Market Intelligence'],
        ]);
        $proposal->fingerprint = $proposal->currentFingerprint();
        $proposal->saveQuietly();

        $withoutAck = $this->service()->confirmAndCreate($actor, $proposal->fresh(), $this->validated($proposal, ['business_name' => 'ABC Beauty', 'website' => null, 'country' => null, 'acknowledge_possible_duplicate' => false]));
        $this->assertSame('acknowledgement_required', $withoutAck['status']);
        $this->assertSame(0, Lead::count());

        $withAck = $this->service()->confirmAndCreate($actor, $proposal->fresh(), $this->validated($proposal, ['business_name' => 'ABC Beauty', 'website' => null, 'country' => null, 'acknowledge_possible_duplicate' => true]));
        $this->assertSame('created', $withAck['status']);
        $this->assertSame(1, Lead::count());
    }

    public function test_a_duplicate_that_appears_after_review_aborts_the_write_toctou(): void
    {
        $actor = User::factory()->manager()->create();
        $proposal = $this->proposal($actor); // eligible, no_match at prepare time

        // Between review and confirm, someone adds the exact org.
        Organization::factory()->create(['name' => 'ABC Beauty', 'website' => 'https://abcbeauty.ph/']);

        $result = $this->service()->confirmAndCreate($actor, $proposal, $this->validated($proposal, ['business_name' => 'ABC Beauty', 'website' => 'https://abcbeauty.ph/']));

        $this->assertSame('duplicate_appeared', $result['status']);
        $this->assertSame('exact_duplicate', $result['duplicate_status']);
        $this->assertSame(1, Organization::count(), 'No second organisation was created.');
        $this->assertSame(0, Lead::count());
        $this->assertSame(ProspectLeadEligibility::BlockedDuplicate, $proposal->fresh()->eligibility);
    }

    public function test_a_recheck_that_cannot_complete_aborts_the_write_and_is_not_treated_as_no_match(): void
    {
        $actor = User::factory()->manager()->create();
        $proposal = $this->proposal($actor);

        Schema::drop('organizations'); // force the re-check query to throw

        $result = $this->service()->confirmAndCreate($actor, $proposal, $this->validated($proposal));

        $this->assertSame('recheck_unavailable', $result['status']);
        $this->assertSame(0, Lead::count());
        $this->assertStringNotContainsStringIgnoringCase('no match', $result['message']);
    }

    public function test_confirming_twice_creates_exactly_one_lead(): void
    {
        $actor = User::factory()->manager()->create();
        $proposal = $this->proposal($actor);

        $first = $this->service()->confirmAndCreate($actor, $proposal->fresh(), $this->validated($proposal));
        $second = $this->service()->confirmAndCreate($actor, $proposal->fresh(), $this->validated($proposal));

        $this->assertSame('created', $first['status']);
        $this->assertSame('already_created', $second['status']);
        $this->assertSame($first['lead_id'], $second['lead_id']);
        $this->assertSame(1, Lead::count());
        $this->assertSame(1, Organization::count());
    }

    public function test_an_expired_or_superseded_proposal_cannot_be_confirmed(): void
    {
        $actor = User::factory()->manager()->create();

        $expired = $this->proposal($actor, ['expires_at' => now()->subHour()]);
        $this->assertSame('stale', $this->service()->confirmAndCreate($actor, $expired, $this->validated($expired))['status']);

        $superseded = $this->proposal($actor, ['status' => ProspectProposalStatus::Superseded]);
        $this->assertSame('stale', $this->service()->confirmAndCreate($actor, $superseded, $this->validated($superseded))['status']);

        $this->assertSame(0, Lead::count());
    }

    public function test_an_organization_name_uniqueness_conflict_aborts_without_a_partial_write(): void
    {
        $actor = User::factory()->manager()->create();
        Organization::factory()->create(['name' => 'Existing Name Co', 'website' => null]);
        $proposal = $this->proposal($actor, [
            'business_name' => 'Different Prospect', 'domain' => null,
            'proposed_organization' => ['name' => 'Different Prospect', 'industry' => null, 'website' => null, 'city' => null, 'state_province' => null, 'country' => null, 'source' => 'Market Intelligence'],
            'prospect_snapshot' => ['business' => 'Different Prospect', 'domain' => null, 'candidate_org_ids' => []],
        ]);
        $proposal->fingerprint = $proposal->currentFingerprint();
        $proposal->saveQuietly();

        $result = $this->service()->confirmAndCreate($actor, $proposal->fresh(), $this->validated($proposal, ['business_name' => 'Existing Name Co', 'website' => null, 'city' => null, 'country' => null]));

        $this->assertSame('duplicate_appeared', $result['status']);
        $this->assertSame(1, Organization::count());
        $this->assertSame(0, Lead::count());
    }

    public function test_a_team_head_confirmation_assigns_to_their_own_team(): void
    {
        $team = Team::factory()->create();
        $head = User::factory()->teamHead($team)->create();
        $proposal = $this->proposal($head);

        $result = $this->service()->confirmAndCreate($head, $proposal, $this->validated($proposal, ['business_name' => 'Team Scoped Co']));

        $this->assertSame('created', $result['status']);
        $lead = Lead::findOrFail($result['lead_id']);
        $this->assertSame($team->id, $lead->team_id);
        $this->assertSame($head->id, $lead->owner_id);
        $this->assertSame($team->id, Organization::findOrFail($result['organization_id'])->team_id);
    }

    public function test_the_creation_audit_records_the_human_actor_and_the_recheck_outcome(): void
    {
        $actor = User::factory()->manager()->create();
        $proposal = $this->proposal($actor);

        Log::shouldReceive('channel')->with('audit')->andReturn($spy = \Mockery::mock());
        $spy->shouldReceive('info')->once()->with('market_intelligence.crm_lead_created', \Mockery::on(function ($context) use ($actor) {
            return $context['actor_id'] === $actor->id
                && isset($context['lead_id'], $context['organization_id'], $context['proposal_fingerprint'])
                && $context['recheck_duplicate_status'] === 'no_match'
                && $context['status'] === 'created';
        }));

        $this->service()->confirmAndCreate($actor, $proposal, $this->validated($proposal));
    }
}
