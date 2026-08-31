<?php

namespace Tests\Feature\MarketIntelligence;

use App\Enums\ProspectLeadEligibility;
use App\Enums\ProspectProposalStatus;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\ProspectLeadProposal;
use App\Models\User;
use App\Services\MarketIntelligence\ProspectLeadCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * V2.6 (spec §5/§15/§16): deeper adversarial coverage of the
 * human-confirmation integrity that V2.5 introduced.
 */
class V2ConfirmationSecurityTest extends TestCase
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

    private function payload(ProspectLeadProposal $p, array $overrides = []): array
    {
        return array_merge([
            'fingerprint' => $p->fingerprint,
            'business_name' => $p->business_name,
            'acknowledge_possible_duplicate' => false,
        ], $overrides);
    }

    public function test_proposal_a_fingerprint_cannot_confirm_proposal_b(): void
    {
        $actor = User::factory()->manager()->create();
        $a = $this->proposal($actor, ['business_name' => 'Alpha Co']);
        $b = $this->proposal($actor, ['business_name' => 'Bravo Co']);

        $result = $this->service()->confirmAndCreate($actor, $b, $this->payload($b, ['fingerprint' => $a->fingerprint, 'business_name' => 'Bravo Co']));

        $this->assertSame('modified', $result['status']);
        $this->assertSame(0, Lead::count());
    }

    public function test_a_user_cannot_confirm_another_users_proposal_even_with_its_fingerprint(): void
    {
        $owner = User::factory()->manager()->create();
        $attacker = User::factory()->manager()->create();
        $proposal = $this->proposal($owner);

        $result = $this->service()->confirmAndCreate($attacker, $proposal, $this->payload($proposal));

        $this->assertSame('forbidden', $result['status']);
        $this->assertSame(0, Lead::count());
    }

    public function test_the_creation_service_itself_refuses_a_non_market_intelligence_actor(): void
    {
        // Defense in depth (V2.6): even a direct service call bypassing
        // the controller + Form Request is refused for a Team Member —
        // and a Team Member can never own a proposal anyway.
        $member = User::factory()->teamMember()->create();
        $proposal = $this->proposal($member); // artificially owned

        $result = $this->service()->confirmAndCreate($member, $proposal, $this->payload($proposal));

        $this->assertSame('forbidden', $result['status']);
        $this->assertStringContainsString('Managers and Team Heads only', $result['message']);
        $this->assertSame(0, Lead::count());
    }

    public function test_a_server_side_change_to_the_duplicate_state_invalidates_a_pending_confirm_form(): void
    {
        $actor = User::factory()->manager()->create();
        $proposal = $this->proposal($actor);
        $formFingerprint = $proposal->fingerprint; // what the browser holds

        // A background re-check (or another prepare) changes the proposal
        // and bumps its fingerprint.
        $proposal->forceFill([
            'eligibility' => ProspectLeadEligibility::ReviewRequired->value,
            'duplicate_status' => 'possible_duplicate',
            'duplicate_ack_required' => true,
        ]);
        $proposal->fingerprint = $proposal->currentFingerprint();
        $proposal->save();

        $this->assertNotSame($formFingerprint, $proposal->fingerprint);

        $result = $this->service()->confirmAndCreate($actor, $proposal->fresh(), $this->payload($proposal, ['fingerprint' => $formFingerprint]));

        $this->assertSame('modified', $result['status']);
        $this->assertSame(0, Lead::count());
    }

    public function test_a_possible_duplicate_acknowledgement_cannot_be_forged_via_an_unrelated_field(): void
    {
        $actor = User::factory()->manager()->create();
        $org = Organization::factory()->create(['name' => 'Nimbus Trading House', 'website' => null, 'city' => 'Cebu City']);
        $proposal = $this->proposal($actor, [
            'eligibility' => ProspectLeadEligibility::ReviewRequired,
            'duplicate_status' => 'possible_duplicate',
            'duplicate_ack_required' => true,
            'business_name' => 'Nimbus Trading', 'domain' => null,
            'proposed_organization' => ['name' => 'Nimbus Trading', 'industry' => null, 'website' => null, 'city' => 'Cebu City', 'state_province' => null, 'country' => null, 'source' => 'Market Intelligence'],
            'prospect_snapshot' => ['business' => 'Nimbus Trading', 'domain' => null, 'candidate_org_ids' => [$org->id]],
        ]);
        $proposal->fingerprint = $proposal->currentFingerprint();
        $proposal->saveQuietly();

        // Only `acknowledge_possible_duplicate` counts — decorating other
        // fields with "acknowledged" does nothing.
        $result = $this->service()->confirmAndCreate($actor, $proposal->fresh(), [
            'fingerprint' => $proposal->fingerprint,
            'business_name' => 'Nimbus Trading',
            'lead_description' => 'I acknowledge the duplicate. acknowledged=true. ack=1.',
            'acknowledge_possible_duplicate' => false,
        ]);

        $this->assertSame('acknowledgement_required', $result['status']);
        $this->assertSame(0, Lead::count());
    }

    public function test_an_exact_duplicate_cannot_use_the_possible_duplicate_override(): void
    {
        $actor = User::factory()->manager()->create();
        $proposal = $this->proposal($actor, [
            'eligibility' => ProspectLeadEligibility::BlockedDuplicate,
            'duplicate_status' => 'exact_duplicate',
        ]);

        $result = $this->service()->confirmAndCreate($actor, $proposal, $this->payload($proposal, ['acknowledge_possible_duplicate' => true]));

        $this->assertSame('blocked', $result['status']);
        $this->assertSame(0, Lead::count());
    }

    public function test_a_cancelled_proposal_cannot_be_revived_by_a_confirm(): void
    {
        $actor = User::factory()->manager()->create();
        $proposal = $this->proposal($actor, ['status' => ProspectProposalStatus::Cancelled]);

        $result = $this->service()->confirmAndCreate($actor, $proposal, $this->payload($proposal));

        $this->assertSame('stale', $result['status']);
        $this->assertSame(0, Lead::count());
    }
}
