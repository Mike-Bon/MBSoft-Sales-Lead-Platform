<?php

namespace App\Services\MarketIntelligence;

use App\Http\Controllers\Concerns\ScopesCrmQueries;
use App\Models\Organization;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\MarketIntelligence\CrmOrganizationIdentity;
use App\Support\MarketIntelligence\DuplicateCheckedProspect;
use App\Support\MarketIntelligence\DuplicateMatchPolicy;
use App\Support\MarketIntelligence\DuplicateStatus;
use App\Support\MarketIntelligence\ProspectIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * V2.4 (spec §2/§3/§8/§9/§30): the bounded shell around the pure
 * ProspectDuplicateMatcher.
 *
 * Its ONLY new power over V2.1–V2.3 is a NARROW, READ-ONLY CRM lookup:
 * a `SELECT` of identity columns from `organizations`, always first
 * passed through `ScopesCrmQueries::scopeToUser()` — the same primitive
 * every CRM index page and every V1 CRM AgentTool uses. A record the
 * actor cannot see is never loaded, never counted, never mentioned
 * (spec §9). It performs NO write of any kind and NO external web I/O.
 */
final class ProspectDuplicateCheckService
{
    use ScopesCrmQueries;

    public function __construct(
        private readonly ProspectDuplicateMatcher $matcher,
    ) {}

    /**
     * @param  list<ProspectIdentity>  $identities
     * @return array<string, mixed>
     */
    public function check(User $actor, array $identities, DuplicateMatchPolicy $policy): array
    {
        $perHour = (int) (config('services.market_intelligence.duplicate_check.max_checks_per_hour') ?? 12);
        $key = 'market-intel:duplicate-check:'.$actor->id;

        if (RateLimiter::tooManyAttempts($key, $perHour)) {
            return $this->result('rate_limited', $policy, [], [
                'message' => 'You have reached the hourly limit for CRM duplicate checks. Try again in '
                    .ceil(RateLimiter::availableIn($key) / 60).' minute(s).',
            ]);
        }
        RateLimiter::hit($key, 3600);

        $identities = array_slice($identities, 0, $policy->maxProspectsPerCheck);
        $scopeNote = $this->scopeNote($actor);

        $checked = [];
        foreach ($identities as $identity) {
            $checked[] = $this->checkOne($actor, $identity, $policy, $scopeNote);
        }

        $statusCounts = $this->statusCounts($checked);
        $checkStatusCounts = $this->checkStatusCounts($checked);
        $examined = array_sum(array_map(fn (DuplicateCheckedProspect $c) => $c->candidatesExamined, $checked));

        AuditLogger::record('market_intelligence.duplicate_check', $actor, [
            'match_policy' => $policy->version,
            'config_valid' => $policy->configValid,
            'prospect_count' => count($checked),
            'crm_candidates_examined' => $examined,
            'duplicate_status_distribution' => $statusCounts,
            'check_status_distribution' => $checkStatusCounts,
            'status' => 'ok',
        ]);

        return $this->result('ok', $policy, $checked, [
            'duplicate_status_distribution' => $statusCounts,
            'crm_candidates_examined' => $examined,
        ]);
    }

    /**
     * V2.5 (spec §18): a single authorised CRM duplicate re-check used by
     * ProspectLeadCreationService immediately before it writes a lead, to
     * close the time-of-check/time-of-use gap. It does NOT consume the
     * per-user hourly duplicate-check budget and does NOT emit the
     * `market_intelligence.duplicate_check` audit event (the re-check
     * outcome is recorded in the `crm_lead_created` audit instead). It
     * runs no external web research — same deterministic scoped matcher.
     */
    public function recheckForCreation(User $actor, ProspectIdentity $identity, DuplicateMatchPolicy $policy): DuplicateCheckedProspect
    {
        return $this->checkOne($actor, $identity, $policy, $this->scopeNote($actor));
    }

    private function checkOne(User $actor, ProspectIdentity $identity, DuplicateMatchPolicy $policy, string $scopeNote): DuplicateCheckedProspect
    {
        if (! $identity->isCheckable()) {
            return new DuplicateCheckedProspect(
                identity: $identity,
                checkStatus: 'skipped',
                status: null,
                candidates: [],
                candidatesExamined: 0,
                policyVersion: $policy->version,
                scopeNote: $scopeNote,
                nextAction: 'Not enough identity information (no usable website/domain or business name) to run a CRM duplicate check.',
            );
        }

        try {
            $records = $this->authorisedCandidates($actor, $identity, $policy);
        } catch (Throwable) {
            return new DuplicateCheckedProspect(
                identity: $identity,
                checkStatus: 'unavailable',
                status: null,
                candidates: [],
                candidatesExamined: 0,
                policyVersion: $policy->version,
                scopeNote: $scopeNote,
                nextAction: 'The CRM could not be checked right now — do NOT treat this as "no duplicate". Retry before relying on the result.',
            );
        }

        $result = $this->matcher->match($identity, $records, $policy);

        return new DuplicateCheckedProspect(
            identity: $identity,
            checkStatus: 'ok',
            status: $result['status'],
            candidates: $result['candidates'],
            candidatesExamined: count($records),
            policyVersion: $policy->version,
            scopeNote: $scopeNote,
            nextAction: $this->nextAction($result['status'], $actor),
        );
    }

    /**
     * The bounded, authorisation-scoped candidate set. `scopeToUser()`
     * runs BEFORE the query executes, so out-of-scope organisations are
     * never fetched into PHP (spec §9/§30).
     *
     * @return list<CrmOrganizationIdentity>
     */
    private function authorisedCandidates(User $actor, ProspectIdentity $identity, DuplicateMatchPolicy $policy): array
    {
        $host = $identity->normalizedHost();
        $terms = $identity->distinctiveTokens() ?: $identity->nameTokens();
        $terms = array_slice($terms, 0, 3);

        if ($host === null && $terms === []) {
            return [];
        }

        $query = $this->scopeToUser(Organization::query(), $actor)
            ->withCount(['leads', 'opportunities'])
            ->where(function (Builder $inner) use ($host, $terms) {
                if ($host !== null) {
                    $inner->orWhereRaw('lower(website) like ?', ['%'.$host.'%']);
                }
                foreach ($terms as $token) {
                    $inner->orWhereRaw('lower(name) like ?', ['%'.mb_strtolower($token).'%']);
                }
            })
            ->orderBy('id')
            ->limit($policy->candidateScanCap);

        return $query->get()->map(fn (Organization $org) => new CrmOrganizationIdentity(
            id: $org->id,
            name: (string) $org->name,
            website: $org->website,
            email: $org->email,
            city: $org->city,
            stateProvince: $org->state_province,
            country: $org->country,
            hasLead: ($org->leads_count ?? 0) > 0,
            hasOpportunity: ($org->opportunities_count ?? 0) > 0,
        ))->all();
    }

    private function nextAction(DuplicateStatus $status, User $actor): string
    {
        return match ($status) {
            DuplicateStatus::ExactDuplicate => 'This business is already in your CRM — do not create a new lead. Open the matched record instead.',
            DuplicateStatus::LikelyDuplicate => 'Very likely already in your CRM. A human must confirm it is the same business before any lead is created.',
            DuplicateStatus::PossibleDuplicate => 'A possible match exists in your CRM. Human review is required before creating a lead.',
            DuplicateStatus::NoMatch => 'No match in your authorised CRM view — eligible for human review before CRM creation.'
                .($actor->isManager() ? '' : ' Records outside your access were not checked, so this is not a guarantee the business is absent org-wide.'),
        };
    }

    private function scopeNote(User $actor): string
    {
        return $actor->isManager()
            ? 'Checked every organisation in the CRM.'
            : 'Checked only organisations within your authorised team scope; records under other teams were not examined and are not reported.';
    }

    /**
     * @param  list<DuplicateCheckedProspect>  $checked
     * @return array<string, int>
     */
    private function statusCounts(array $checked): array
    {
        $counts = [];
        foreach (DuplicateStatus::cases() as $case) {
            $counts[$case->value] = 0;
        }
        foreach ($checked as $c) {
            if ($c->status !== null) {
                $counts[$c->status->value]++;
            }
        }

        return $counts;
    }

    /**
     * @param  list<DuplicateCheckedProspect>  $checked
     * @return array<string, int>
     */
    private function checkStatusCounts(array $checked): array
    {
        $counts = ['ok' => 0, 'skipped' => 0, 'unavailable' => 0];
        foreach ($checked as $c) {
            $counts[$c->checkStatus] = ($counts[$c->checkStatus] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  list<DuplicateCheckedProspect>  $checked
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function result(string $status, DuplicateMatchPolicy $policy, array $checked, array $extra): array
    {
        return array_merge([
            'status' => $status,
            'match_policy' => $policy->toArray(),
            'checked_prospects' => array_map(fn (DuplicateCheckedProspect $c) => $c->toArray(), $checked),
            'notice' => 'This is a DETERMINISTIC identity match against the CRM records you are authorised to see — not a '
                .'confidence score, not a decision, and not a CRM action. Nothing has been created, changed, or contacted. '
                .'A failed check is reported as "unavailable", never as "no match". Records outside your authorisation were '
                .'not examined. The assistant does not choose which record is a duplicate.',
        ], array_filter($extra, fn ($v) => $v !== null));
    }
}
