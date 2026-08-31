<?php

namespace App\Services\MarketIntelligence;

use App\Enums\LeadStatus;
use App\Enums\ProspectLeadEligibility;
use App\Enums\ProspectProposalStatus;
use App\Models\ProspectLeadProposal;
use App\Models\User;
use App\Services\LeadService;
use App\Services\OrganizationService;
use App\Support\AuditLogger;
use App\Support\MarketIntelligence\DuplicateMatchPolicy;
use App\Support\MarketIntelligence\DuplicateStatus;
use App\Support\MarketIntelligence\ProspectIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * V2.5 (spec §2/§4/§17/§18/§19): the ONLY place a Market Intelligence
 * prospect becomes a CRM lead — and only when a human has explicitly
 * confirmed a specific, unchanged proposal through the trusted HTTP
 * confirm route.
 *
 * It performs, in order, inside one locked transaction:
 *   1. row lock + idempotency (a confirmed proposal never writes twice);
 *   2. proposal actionability (pending, not expired, belongs to actor);
 *   3. fingerprint match (the proposal is exactly what the human reviewed);
 *   4. eligibility gate (blocked states cannot be overridden; a possible
 *      duplicate needs the acknowledgement flag);
 *   5. a fresh authorised CRM duplicate RE-CHECK (spec §18 TOCTOU) — a
 *      duplicate that appeared since review aborts the write; a failed
 *      re-check aborts the write and is NEVER treated as "no match";
 *   6. the write, via the existing V1 OrganizationService + LeadService
 *      (server-side owner/team assignment, activity log, their own
 *      transactions) — no parallel CRM implementation.
 *
 * The confirming actor recorded in the audit is always the human.
 */
final class ProspectLeadCreationService
{
    public function __construct(
        private readonly OrganizationService $organizations,
        private readonly LeadService $leads,
        private readonly ProspectDuplicateCheckService $duplicates,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  from ConfirmProspectLeadRequest
     * @return array<string, mixed>
     */
    public function confirmAndCreate(User $actor, ProspectLeadProposal $proposal, array $validated): array
    {
        try {
            return DB::transaction(function () use ($actor, $proposal, $validated) {
                /** @var ProspectLeadProposal $locked */
                $locked = ProspectLeadProposal::query()->lockForUpdate()->findOrFail($proposal->id);

                if ($locked->user_id !== $actor->id) {
                    return $this->failure('forbidden', 'This proposal does not belong to you.');
                }

                if ($locked->status === ProspectProposalStatus::Confirmed) {
                    return [
                        'status' => 'already_created',
                        'lead_id' => $locked->lead_id,
                        'organization_id' => $locked->organization_id,
                        'lead_url' => $locked->lead_id !== null ? route('crm.leads.show', $locked->lead_id) : null,
                        'message' => 'This proposal was already confirmed — the lead already exists. Nothing was created again.',
                    ];
                }

                if (! $locked->isActionable()) {
                    return $this->failure('stale', $locked->status === ProspectProposalStatus::Pending
                        ? 'This proposal has expired. Prepare the prospect again to create a lead.'
                        : 'This proposal is no longer actionable ('.$locked->status->label().'). Prepare the prospect again.');
                }

                if (! hash_equals($locked->fingerprint, (string) ($validated['fingerprint'] ?? ''))) {
                    return $this->failure('modified', 'This proposal changed since you opened the review page (for example a CRM duplicate re-check updated it). Reload the review page and check it again before confirming.');
                }

                $eligibility = $locked->eligibility;

                if ($eligibility->isBlocked()) {
                    return $this->failure('blocked', $eligibility->label().' — no lead can be created from this proposal.', ['eligibility' => $eligibility->value]);
                }

                if ($eligibility->requiresDuplicateAcknowledgement() && ($validated['acknowledge_possible_duplicate'] ?? false) !== true) {
                    return $this->failure('acknowledgement_required', 'A possible duplicate exists. You must review the matched record and tick the acknowledgement to create a new lead anyway.');
                }

                $orgData = $this->organizationData($locked, $validated);
                $leadData = $this->leadData($locked, $validated);

                // ── TOCTOU re-check (spec §18/§39) ──────────────────
                $identity = ProspectIdentity::fromArray([
                    'business' => $orgData['name'],
                    'website' => $orgData['website'],
                    'domain' => $locked->domain,
                ]);
                $recheck = $this->duplicates->recheckForCreation($actor, $identity, DuplicateMatchPolicy::fromConfig());

                if ($recheck->checkStatus !== 'ok') {
                    $this->updateProposalState($locked, ProspectLeadEligibility::BlockedCheckUnavailable, 'unavailable', $recheck->status?->value);

                    return $this->failure('recheck_unavailable', 'The CRM could not be re-checked for duplicates just now. The lead was NOT created. Try again shortly.');
                }

                $newStatus = $recheck->status;
                $newCandidateIds = array_map(fn ($c) => $c->organizationId, $recheck->candidates);

                if ($newStatus === DuplicateStatus::ExactDuplicate || $newStatus === DuplicateStatus::LikelyDuplicate) {
                    $this->updateProposalState($locked, ProspectLeadEligibility::BlockedDuplicate, 'ok', $newStatus->value);

                    return $this->failure('duplicate_appeared', 'A matching CRM record appeared since you reviewed this prospect. The lead was NOT created. Open the review page to see the match.', [
                        'duplicate_status' => $newStatus->value,
                        'candidate_matches' => array_map(fn ($c) => $c->toArray(), $recheck->candidates),
                    ]);
                }

                if ($newStatus === DuplicateStatus::PossibleDuplicate) {
                    $acknowledgedIds = (array) ($locked->prospect_snapshot['candidate_org_ids'] ?? []);
                    $onlyKnown = $eligibility === ProspectLeadEligibility::ReviewRequired
                        && array_diff($newCandidateIds, $acknowledgedIds) === [];

                    if (! $onlyKnown) {
                        $this->updateProposalState($locked, ProspectLeadEligibility::ReviewRequired, 'ok', $newStatus->value, true);

                        return $this->failure('duplicate_appeared', 'A possible duplicate appeared since you reviewed this prospect. The lead was NOT created. Reload the review page, check the match, and acknowledge it if you still want to proceed.', [
                            'duplicate_status' => $newStatus->value,
                            'candidate_matches' => array_map(fn ($c) => $c->toArray(), $recheck->candidates),
                        ]);
                    }
                }

                // ── the write, through the existing V1 services ─────
                $organization = $this->organizations->create($actor, $orgData);
                $lead = $this->leads->create($actor, $leadData + ['organization_id' => $organization->id]);

                $locked->forceFill([
                    'status' => ProspectProposalStatus::Confirmed->value,
                    'organization_id' => $organization->id,
                    'lead_id' => $lead->id,
                    'decided_by' => $actor->id,
                    'decided_at' => now(),
                ])->save();

                AuditLogger::record('market_intelligence.crm_lead_created', $actor, [
                    'proposal_id' => $locked->id,
                    'proposal_fingerprint' => $locked->fingerprint,
                    'policy_version' => $locked->policy_version,
                    'eligibility' => $eligibility->value,
                    'original_duplicate_status' => $locked->duplicate_status,
                    'recheck_duplicate_status' => $newStatus?->value ?? 'no_match',
                    'possible_duplicate_acknowledged' => $eligibility->requiresDuplicateAcknowledgement(),
                    'organization_id' => $organization->id,
                    'lead_id' => $lead->id,
                    'status' => 'created',
                ]);

                return [
                    'status' => 'created',
                    'lead_id' => $lead->id,
                    'organization_id' => $organization->id,
                    'lead_url' => route('crm.leads.show', $lead),
                    'message' => 'Lead created in the CRM.',
                ];
            });
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return $this->failure('duplicate_appeared', 'A CRM organisation with this name already exists. Open that record instead of creating a new lead.');
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function organizationData(ProspectLeadProposal $proposal, array $validated): array
    {
        $proposed = $proposal->proposed_organization;

        return [
            'name' => $this->str($validated['business_name'] ?? null) ?? $proposed['name'],
            'industry' => $this->str($validated['industry'] ?? null),
            'website' => $this->str($validated['website'] ?? null),
            'city' => $this->str($validated['city'] ?? null),
            'state_province' => $this->str($validated['state_province'] ?? null),
            'country' => $this->str($validated['country'] ?? null),
            'source' => (string) config('services.market_intelligence.lead_creation.default_lead_source', 'Market Intelligence'),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function leadData(ProspectLeadProposal $proposal, array $validated): array
    {
        return [
            'source' => (string) config('services.market_intelligence.lead_creation.default_lead_source', 'Market Intelligence'),
            'status' => LeadStatus::New->value,
            'description' => $this->str($validated['lead_description'] ?? null)
                ?? ($proposal->proposed_lead['description'] ?? null),
        ];
    }

    private function updateProposalState(ProspectLeadProposal $proposal, ProspectLeadEligibility $eligibility, string $checkStatus, ?string $duplicateStatus, bool $ackRequired = false): void
    {
        $proposal->forceFill([
            'eligibility' => $eligibility->value,
            'duplicate_check_status' => $checkStatus,
            'duplicate_status' => $duplicateStatus,
            'duplicate_ack_required' => $ackRequired,
        ]);
        $proposal->fingerprint = $proposal->currentFingerprint();
        $proposal->save();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function failure(string $status, string $message, array $extra = []): array
    {
        return array_merge(['status' => $status, 'created' => false, 'message' => $message], $extra);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array($e->getCode(), ['23000', '23505'], true)
            || str_contains(mb_strtolower($e->getMessage()), 'unique');
    }

    private function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
