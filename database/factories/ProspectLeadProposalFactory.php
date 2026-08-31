<?php

namespace Database\Factories;

use App\Enums\ProspectLeadEligibility;
use App\Enums\ProspectProposalStatus;
use App\Models\ProspectLeadProposal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProspectLeadProposal>
 */
class ProspectLeadProposalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $org = [
            'name' => 'ABC Beauty',
            'industry' => 'cosmetics',
            'website' => 'https://abcbeauty.ph/',
            'city' => 'Cebu City',
            'state_province' => null,
            'country' => 'Philippines',
            'source' => 'Market Intelligence',
        ];
        $lead = ['source' => 'Market Intelligence', 'description' => null];

        return [
            'user_id' => User::factory()->manager(),
            'status' => ProspectProposalStatus::Pending,
            'eligibility' => ProspectLeadEligibility::EligibleForConfirmation,
            'policy_version' => 'v2.5-default-1',
            'business_name' => $org['name'],
            'website' => $org['website'],
            'domain' => 'abcbeauty.ph',
            'prospect_snapshot' => [
                'business' => $org['name'],
                'domain' => 'abcbeauty.ph',
                'total_score' => 84,
                'priority' => 'high',
                'qualification_outcome' => 'strong_match',
                'scoring_model' => 'v2.3-default-1',
                'duplicate_status' => 'no_match',
                'candidate_org_ids' => [],
            ],
            'proposed_organization' => $org,
            'proposed_lead' => $lead,
            'duplicate_check_status' => 'ok',
            'duplicate_status' => 'no_match',
            'duplicate_ack_required' => false,
            'expires_at' => now()->addHours(48),
        ];
    }

    public function withFingerprint(): static
    {
        return $this->afterMaking(function (ProspectLeadProposal $proposal) {
            $proposal->fingerprint = $proposal->currentFingerprint();
        })->afterCreating(function (ProspectLeadProposal $proposal) {
            $proposal->fingerprint = $proposal->currentFingerprint();
            $proposal->saveQuietly();
        });
    }

    public function reviewRequired(): static
    {
        return $this->state(fn () => [
            'eligibility' => ProspectLeadEligibility::ReviewRequired,
            'duplicate_status' => 'possible_duplicate',
            'duplicate_ack_required' => true,
        ]);
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id]);
    }
}
