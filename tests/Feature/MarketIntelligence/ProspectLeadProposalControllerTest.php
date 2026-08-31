<?php

namespace Tests\Feature\MarketIntelligence;

use App\Enums\ProspectLeadEligibility;
use App\Enums\ProspectProposalStatus;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\ProspectLeadProposal;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * V2.5 (spec §33/§34/§37/§38): the human review + confirmation surface.
 * The POST confirm is the only path that writes a lead.
 */
class ProspectLeadProposalControllerTest extends TestCase
{
    use RefreshDatabase;

    private function proposal(User $owner, array $state = []): ProspectLeadProposal
    {
        return ProspectLeadProposal::factory()->ownedBy($owner)->withFingerprint()->create($state);
    }

    private function confirmPayload(ProspectLeadProposal $proposal, array $overrides = []): array
    {
        return array_merge([
            'fingerprint' => $proposal->fingerprint,
            'business_name' => $proposal->business_name,
            'industry' => 'cosmetics',
            'website' => 'https://abcbeauty.ph/',
            'city' => 'Cebu City',
            'country' => 'Philippines',
            'lead_description' => 'Reviewed and confirmed.',
        ], $overrides);
    }

    public function test_the_owner_can_view_the_review_page(): void
    {
        $manager = User::factory()->manager()->create();
        $proposal = $this->proposal($manager);

        $this->actingAs($manager)->get(route('market-intelligence.prospect-proposals.show', $proposal))
            ->assertOk()
            ->assertSee('Create CRM Lead from Prospect Research')
            ->assertSee('Create Lead');
    }

    public function test_another_user_cannot_view_the_review_page(): void
    {
        $proposal = $this->proposal(User::factory()->manager()->create());

        $this->actingAs(User::factory()->manager()->create())
            ->get(route('market-intelligence.prospect-proposals.show', $proposal))
            ->assertForbidden();
    }

    public function test_a_team_member_cannot_view_or_confirm(): void
    {
        $member = User::factory()->teamMember()->create();
        $proposal = $this->proposal($member); // even if somehow owned

        $this->actingAs($member)->get(route('market-intelligence.prospect-proposals.show', $proposal))->assertForbidden();
        $this->actingAs($member)->post(route('market-intelligence.prospect-proposals.confirm', $proposal), $this->confirmPayload($proposal))->assertForbidden();
        $this->assertSame(0, Lead::count());
    }

    public function test_an_explicit_confirmation_creates_the_lead_and_redirects_to_it(): void
    {
        $manager = User::factory()->manager()->create();
        $proposal = $this->proposal($manager);

        $response = $this->actingAs($manager)->post(route('market-intelligence.prospect-proposals.confirm', $proposal), $this->confirmPayload($proposal, ['business_name' => 'ABC Beauty HTTP']));

        $lead = Lead::firstOrFail();
        $response->assertRedirect(route('crm.leads.show', $lead));
        $this->assertSame('ABC Beauty HTTP', $lead->organization->name);
        $this->assertSame(ProspectProposalStatus::Confirmed, $proposal->fresh()->status);
    }

    public function test_a_forged_fingerprint_does_not_create_a_lead(): void
    {
        $manager = User::factory()->manager()->create();
        $proposal = $this->proposal($manager);

        $this->actingAs($manager)->post(route('market-intelligence.prospect-proposals.confirm', $proposal), $this->confirmPayload($proposal, ['fingerprint' => str_repeat('a', 64)]))
            ->assertRedirect(route('market-intelligence.prospect-proposals.show', $proposal))
            ->assertSessionHas('proposal_error');

        $this->assertSame(0, Lead::count());
    }

    public function test_a_review_required_proposal_needs_the_acknowledgement_checkbox(): void
    {
        $manager = User::factory()->manager()->create();
        // A DIFFERENT business with a similar name — a genuine possible
        // duplicate the human may legitimately want alongside.
        $org = Organization::factory()->create(['name' => 'ABC Beauty Wellness Spa', 'website' => null, 'city' => 'Cebu City']);
        $proposal = $this->proposal($manager, [
            'eligibility' => ProspectLeadEligibility::ReviewRequired,
            'duplicate_status' => 'possible_duplicate',
            'duplicate_ack_required' => true,
            'business_name' => 'ABC Beauty', 'domain' => null,
            'proposed_organization' => ['name' => 'ABC Beauty', 'industry' => null, 'website' => null, 'city' => 'Cebu City', 'state_province' => null, 'country' => null, 'source' => 'Market Intelligence'],
            'prospect_snapshot' => ['business' => 'ABC Beauty', 'domain' => null, 'candidate_org_ids' => [$org->id]],
        ]);
        $proposal->fingerprint = $proposal->currentFingerprint();
        $proposal->saveQuietly();

        // No checkbox → 422 (accepted rule).
        $this->actingAs($manager)->post(route('market-intelligence.prospect-proposals.confirm', $proposal->fresh()), [
            'fingerprint' => $proposal->fingerprint, 'business_name' => 'ABC Beauty',
        ])->assertSessionHasErrors('acknowledge_possible_duplicate');
        $this->assertSame(0, Lead::count());

        // With checkbox → created.
        $this->actingAs($manager)->post(route('market-intelligence.prospect-proposals.confirm', $proposal->fresh()), [
            'fingerprint' => $proposal->fingerprint, 'business_name' => 'ABC Beauty',
            'acknowledge_possible_duplicate' => '1',
        ])->assertRedirect(route('crm.leads.show', Lead::firstOrFail()));
        $this->assertSame(1, Lead::count());
    }

    public function test_a_blocked_proposal_cannot_be_confirmed_through_http(): void
    {
        $manager = User::factory()->manager()->create();
        $proposal = $this->proposal($manager, [
            'eligibility' => ProspectLeadEligibility::BlockedDuplicate,
            'duplicate_status' => 'exact_duplicate',
        ]);

        $this->actingAs($manager)->post(route('market-intelligence.prospect-proposals.confirm', $proposal), $this->confirmPayload($proposal))
            ->assertSessionHas('proposal_error');
        $this->assertSame(0, Lead::count());
    }

    public function test_a_double_submit_creates_exactly_one_lead(): void
    {
        $manager = User::factory()->manager()->create();
        $proposal = $this->proposal($manager);
        $payload = $this->confirmPayload($proposal);

        $this->actingAs($manager)->post(route('market-intelligence.prospect-proposals.confirm', $proposal), $payload)->assertRedirect();
        $this->actingAs($manager)->post(route('market-intelligence.prospect-proposals.confirm', $proposal->fresh()), $payload)->assertRedirect();

        $this->assertSame(1, Lead::count());
        $this->assertSame(1, Organization::count());
    }

    public function test_a_confirmed_true_style_payload_alone_does_nothing_without_a_valid_fingerprint(): void
    {
        $manager = User::factory()->manager()->create();
        $proposal = $this->proposal($manager);

        $this->actingAs($manager)->post(route('market-intelligence.prospect-proposals.confirm', $proposal), [
            'business_name' => 'Hax Co', 'confirmed' => true, 'confirmed_by' => $manager->id, 'owner_id' => 999, 'team_id' => 999,
        ])->assertSessionHasErrors('fingerprint');

        $this->assertSame(0, Lead::count());
    }

    public function test_cancel_marks_the_proposal_cancelled_and_writes_nothing(): void
    {
        $manager = User::factory()->manager()->create();
        $proposal = $this->proposal($manager);

        $this->actingAs($manager)->post(route('market-intelligence.prospect-proposals.cancel', $proposal))->assertRedirect();

        $this->assertSame(ProspectProposalStatus::Cancelled, $proposal->fresh()->status);
        $this->assertSame(0, Lead::count());
    }

    public function test_owner_team_id_in_the_payload_cannot_override_v1_assignment(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $head = User::factory()->teamHead($teamA)->create();
        $proposal = $this->proposal($head);

        $this->actingAs($head)->post(route('market-intelligence.prospect-proposals.confirm', $proposal), $this->confirmPayload($proposal, [
            'business_name' => 'Scoped Co', 'owner_id' => User::factory()->create()->id, 'team_id' => $teamB->id,
        ]))->assertRedirect();

        $lead = Lead::firstOrFail();
        $this->assertSame($teamA->id, $lead->team_id);
        $this->assertSame($head->id, $lead->owner_id);
    }
}
