<?php

namespace App\Services\MarketIntelligence;

use App\Enums\ProspectLeadEligibility;
use App\Enums\ProspectProposalStatus;
use App\Models\ProspectLeadProposal;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\MarketIntelligence\IdentityNormalizer;
use App\Support\MarketIntelligence\ProspectIdentity;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * V2.5 (spec §3/§10): prepares a prospect → CRM lead PROPOSAL. It does
 * NOT write a Lead or an Organization — it persists one
 * `ProspectLeadProposal` row (a "here is what would be created"
 * structure) and returns a review URL for a human.
 *
 * The eligibility is decided deterministically here from the V2.4
 * duplicate-check result (spec §6) — never by the score, the priority,
 * the qualification outcome, or the LLM (spec §1/§23).
 */
final class ProspectLeadProposalService
{
    public function __construct(
        private readonly ProspectDuplicateCheckService $duplicates,
    ) {}

    /**
     * @param  array<string, mixed>  $duplicateCheck  one entry of check_prospect_duplicates' `checked_prospects`
     * @param  array<string, mixed>  $context  optional { industry, location, missing_information[], sources[] }
     * @return array<string, mixed>
     */
    public function prepare(User $actor, array $duplicateCheck, array $context = []): array
    {
        $perHour = (int) (config('services.market_intelligence.lead_creation.max_proposals_per_hour') ?? 20);
        $key = 'market-intel:crm-proposal:'.$actor->id;

        if (RateLimiter::tooManyAttempts($key, $perHour)) {
            return [
                'status' => 'rate_limited',
                'message' => 'You have reached the hourly limit for preparing CRM lead proposals. Try again in '
                    .ceil(RateLimiter::availableIn($key) / 60).' minute(s).',
            ];
        }
        RateLimiter::hit($key, 3600);

        $identity = ProspectIdentity::fromArray($duplicateCheck);

        if ($identity->business === '' && $identity->normalizedHost() === null) {
            return [
                'status' => 'invalid',
                'message' => 'The prospect has no usable identity (no business name and no website). Nothing to prepare.',
            ];
        }

        $checkStatus = is_string($duplicateCheck['check_status'] ?? null) ? $duplicateCheck['check_status'] : 'unavailable';
        $duplicateStatus = is_string($duplicateCheck['duplicate_status'] ?? null) ? $duplicateCheck['duplicate_status'] : null;
        $eligibility = ProspectLeadEligibility::forCheck($checkStatus, $duplicateStatus);

        $policyVersion = (string) config('services.market_intelligence.lead_creation.policy_version', 'v2.5-default-1');

        $organization = $this->proposedOrganization($identity, $context);
        $lead = $this->proposedLead($identity, $duplicateCheck, $context);

        $candidateOrgIds = $this->candidateOrgIds($duplicateCheck);

        $fingerprint = ProspectLeadProposal::fingerprintFor(
            $organization, $lead, $actor->id, $checkStatus, $duplicateStatus,
            $eligibility->requiresDuplicateAcknowledgement(), $policyVersion,
        );

        // One live proposal per prospect per user — a fresh prepare
        // supersedes any earlier pending one so a stale confirm form
        // stops working (spec §17).
        ProspectLeadProposal::query()
            ->where('user_id', $actor->id)
            ->where('status', ProspectProposalStatus::Pending->value)
            ->where(fn ($q) => $q->where('business_name', $identity->business)
                ->orWhere(fn ($q2) => $q2->whereNotNull('domain')->where('domain', IdentityNormalizer::host($identity->domain))))
            ->update(['status' => ProspectProposalStatus::Superseded->value]);

        $proposal = new ProspectLeadProposal;
        $proposal->user_id = $actor->id;
        $proposal->status = ProspectProposalStatus::Pending;
        $proposal->eligibility = $eligibility;
        $proposal->policy_version = $policyVersion;
        $proposal->fingerprint = $fingerprint;
        $proposal->business_name = $identity->business !== '' ? $identity->business : ($identity->normalizedHost() ?? 'Unnamed prospect');
        $proposal->website = $identity->website;
        $proposal->domain = IdentityNormalizer::host($identity->domain) ?? IdentityNormalizer::host($identity->website);
        $proposal->prospect_snapshot = $this->snapshot($identity, $duplicateCheck, $context, $candidateOrgIds);
        $proposal->proposed_organization = $organization;
        $proposal->proposed_lead = $lead;
        $proposal->duplicate_check_status = $checkStatus;
        $proposal->duplicate_status = $duplicateStatus;
        $proposal->duplicate_ack_required = $eligibility->requiresDuplicateAcknowledgement();
        $proposal->expires_at = now()->addHours((int) config('services.market_intelligence.lead_creation.proposal_ttl_hours', 48));
        $proposal->save();

        AuditLogger::record('market_intelligence.crm_proposal_prepared', $actor, [
            'proposal_id' => $proposal->id,
            'eligibility' => $eligibility->value,
            'duplicate_check_status' => $checkStatus,
            'duplicate_status' => $duplicateStatus,
            'duplicate_ack_required' => $proposal->duplicate_ack_required,
            'total_score' => $identity->totalScore,
            'priority' => $identity->priority,
            'qualification_outcome' => $identity->qualificationOutcome,
            'policy_version' => $policyVersion,
            'status' => 'ok',
        ]);

        return [
            'status' => 'ok',
            'proposal_id' => $proposal->id,
            'review_url' => route('market-intelligence.prospect-proposals.show', $proposal),
            'eligibility' => $eligibility->value,
            'eligibility_label' => $eligibility->label(),
            'duplicate_acknowledgement_required' => $proposal->duplicate_ack_required,
            'proposed_organization' => $organization,
            'proposed_lead' => $lead,
            'warnings' => $this->warnings($eligibility, $duplicateCheck),
            'next_step_for_human' => $eligibility->canReachConfirmation()
                ? 'Open the review page, check the proposed CRM data, and explicitly click "Create Lead". The assistant cannot create the lead — only you can.'
                : 'No lead can be created from this prospect right now: '.$eligibility->label().'.',
            'notice' => 'This is a PROPOSAL only. Nothing has been written to the CRM. Creation requires an explicit human '
                .'confirmation on the review page, a fresh CRM duplicate re-check at that moment, and passes the normal V1 '
                .'lead-creation authorization. The assistant cannot confirm, cannot create, and cannot acknowledge a duplicate.',
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function proposedOrganization(ProspectIdentity $identity, array $context): array
    {
        [$city, $country] = $this->splitLocation($identity->location ?? (is_string($context['location'] ?? null) ? $context['location'] : null));

        return [
            'name' => Str::limit($identity->business !== '' ? $identity->business : ($identity->normalizedHost() ?? 'Unnamed prospect'), 250, ''),
            'industry' => $this->cleanField($context['industry'] ?? null, 200),
            'website' => $identity->website !== null ? Str::limit($identity->website, 250, '') : null,
            'city' => $city,
            'state_province' => null,
            'country' => $country,
            'source' => (string) config('services.market_intelligence.lead_creation.default_lead_source', 'Market Intelligence'),
        ];
    }

    /**
     * @param  array<string, mixed>  $duplicateCheck
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function proposedLead(ProspectIdentity $identity, array $duplicateCheck, array $context): array
    {
        $bits = ['Created from Market Intelligence prospect research.'];
        if ($identity->qualificationOutcome !== null) {
            $bits[] = 'Qualification: '.$identity->qualificationOutcome.'.';
        }
        if ($identity->totalScore !== null) {
            $bits[] = 'Prioritisation score: '.$identity->totalScore.'/100'.($identity->priority !== null ? ' ('.$identity->priority.')' : '').'.';
        }
        if (is_string($duplicateCheck['duplicate_status_label'] ?? null)) {
            $bits[] = 'CRM duplicate check: '.$duplicateCheck['duplicate_status_label'].'.';
        }
        $sources = array_slice(array_values(array_filter(
            (array) ($context['sources'] ?? []),
            fn ($s) => is_string($s) && Str::startsWith($s, 'http'),
        )), 0, 3);
        if ($sources !== []) {
            $bits[] = 'Sources: '.implode(' ', $sources);
        }

        return [
            'source' => (string) config('services.market_intelligence.lead_creation.default_lead_source', 'Market Intelligence'),
            'description' => Str::limit(implode(' ', $bits), 900, ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $duplicateCheck
     * @param  array<string, mixed>  $context
     * @param  list<int>  $candidateOrgIds
     * @return array<string, mixed>
     */
    private function snapshot(ProspectIdentity $identity, array $duplicateCheck, array $context, array $candidateOrgIds): array
    {
        return [
            'business' => $identity->business,
            'website' => $identity->website,
            'domain' => $identity->domain,
            'location' => $identity->location,
            'total_score' => $identity->totalScore,
            'priority' => $identity->priority,
            'qualification_outcome' => $identity->qualificationOutcome,
            'scoring_model' => $identity->scoringModel,
            'duplicate_check_status' => $duplicateCheck['check_status'] ?? null,
            'duplicate_status' => $duplicateCheck['duplicate_status'] ?? null,
            'duplicate_status_label' => $duplicateCheck['duplicate_status_label'] ?? null,
            'candidate_matches' => array_slice((array) ($duplicateCheck['candidate_matches'] ?? []), 0, 5),
            'candidate_org_ids' => $candidateOrgIds,
            'missing_information' => array_slice(array_values(array_filter(
                (array) ($context['missing_information'] ?? []),
                fn ($m) => is_string($m),
            )), 0, 12),
        ];
    }

    /**
     * @param  array<string, mixed>  $duplicateCheck
     * @return list<int>
     */
    private function candidateOrgIds(array $duplicateCheck): array
    {
        return array_values(array_filter(array_map(
            fn ($c) => is_array($c) && isset($c['crm_record_id']) ? (int) $c['crm_record_id'] : null,
            (array) ($duplicateCheck['candidate_matches'] ?? []),
        )));
    }

    /**
     * @param  array<string, mixed>  $duplicateCheck
     * @return list<string>
     */
    private function warnings(ProspectLeadEligibility $eligibility, array $duplicateCheck): array
    {
        return match ($eligibility) {
            ProspectLeadEligibility::EligibleForConfirmation => [],
            ProspectLeadEligibility::ReviewRequired => [
                'A POSSIBLE duplicate exists in your CRM. You must open the matched record, review it, and tick the acknowledgement on the review page before a new lead can be created.',
            ],
            ProspectLeadEligibility::BlockedDuplicate => [
                'This business already appears to be in your CRM ('.($duplicateCheck['duplicate_status_label'] ?? 'duplicate').'). No new lead will be created — review the existing record.',
            ],
            ProspectLeadEligibility::BlockedCheckUnavailable => [
                'The CRM duplicate check did not complete. A lead cannot be prepared until a successful duplicate check confirms the prospect is not already in the CRM.',
            ],
            ProspectLeadEligibility::BlockedInsufficientIdentity => [
                'The prospect identity was too thin for a CRM duplicate check, so a lead cannot be prepared.',
            ],
        };
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function splitLocation(?string $location): array
    {
        if ($location === null || trim($location) === '') {
            return [null, null];
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $location))));
        if ($parts === []) {
            return [null, null];
        }

        $city = $this->cleanField($parts[0], 200);
        $country = count($parts) > 1 ? $this->cleanField(end($parts), 200) : null;

        return [$city, $country === $city ? null : $country];
    }

    private function cleanField(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        return $value === '' ? null : Str::limit($value, $max, '');
    }
}
