<?php

namespace App\Services\BusinessDevelopment;

use App\Enums\LeadStatus;
use App\Enums\OpportunityStage;
use App\Http\Controllers\Concerns\ScopesCrmQueries;
use App\Models\Activity;
use App\Models\Communication;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Phase 13: the one place every Business Development figure is
 * calculated — the BD AgentTools and the dedicated BD page both call
 * this service, never Lead/Opportunity/Activity directly, so the page
 * and the agent can never disagree (the same pattern as Phase 12's
 * AccountEconomicsService).
 *
 * Everything here is:
 *   - READ ONLY. No method writes, sends, or mutates anything.
 *   - TRANSPARENT. Prioritisation returns the exact factor list and
 *     points that produced each score — never a bare number (spec §13:
 *     "do not create a mysterious black-box score").
 *   - DETERMINISTIC. Every threshold and weight comes from
 *     config('services.business_development'), never the model.
 *   - AUTHORISATION-SCOPED. Reuses ScopesCrmQueries::scopeToUser()
 *     exactly like every CRM index page — Manager unrestricted; Team
 *     Head limited to their own team; a Team Member never reaches BD at
 *     all (AgentIdentifier::BusinessDevelopment->isAvailableTo()).
 *
 * There is no lead score stored anywhere — it is recomputed on demand
 * from live data every time, so it can never drift from reality.
 */
class LeadIntelligenceService
{
    use ScopesCrmQueries;

    /**
     * @return array{
     *     leads: Collection<int, array<string, mixed>>,
     *     generated_at: string,
     *     source: string,
     * }
     */
    public function prioritizedLeads(User $actor, ?int $teamId = null, ?int $limit = null): array
    {
        $limit = $this->boundedLimit($limit);
        $leads = $this->openLeadsQuery($actor, $teamId)->get();

        $scored = $leads
            ->map(fn (Lead $lead) => $this->scoreLead($lead))
            ->sortByDesc('score')
            ->take($limit)
            ->values();

        return [
            'leads' => $scored,
            'generated_at' => Carbon::now()->toDateTimeString(),
            'source' => 'leads + activities + opportunities (live, transparent scoring — see docs/BUSINESS_DEVELOPMENT.md)',
        ];
    }

    /**
     * Open leads that have gone cold: no logged activity AND no upcoming
     * follow-up for at least `stale_lead_days`, and not brand new.
     *
     * @return array{leads: Collection<int, array<string, mixed>>, threshold_days: int, source: string}
     */
    public function staleLeads(User $actor, ?int $teamId = null, ?int $limit = null): array
    {
        $limit = $this->boundedLimit($limit);
        $days = (int) config('services.business_development.stale_lead_days', 10);
        $cutoff = Carbon::now()->subDays($days);
        $startOfDay = Carbon::now()->startOfDay();

        // `last_activity_at` is a withMax() select alias, not a real
        // column — it cannot be used in WHERE, so the cold-lead rule is
        // applied in PHP over the (team-bounded) open-lead set, the same
        // way atRiskOpportunities() and CrmMetricsService's attention
        // lists work.
        $leads = $this->openLeadsQuery($actor, $teamId)
            ->where('leads.created_at', '<', $cutoff)
            ->where(fn (Builder $q) => $q->whereNull('next_follow_up_at')->orWhere('next_follow_up_at', '<', $startOfDay))
            ->get()
            ->filter(fn (Lead $lead) => $lead->last_activity_at === null
                || Carbon::parse($lead->last_activity_at)->lt($cutoff))
            ->sortBy(fn (Lead $lead) => $lead->last_activity_at ?? '0000-00-00')
            ->take($limit)
            ->values();

        return [
            'leads' => $leads->map(fn (Lead $lead) => $this->presentLead($lead, [
                'days_since_last_activity' => $this->daysSince($lead->last_activity_at),
                'follow_up' => $lead->followUpStatus()->label(),
            ])),
            'threshold_days' => $days,
            'source' => "leads with no activity and no pending follow-up for {$days}+ days",
        ];
    }

    /**
     * Open leads needing a next action now: follow-up overdue, or no
     * follow-up date set at all. Reuses the exact bucket boundaries
     * CrmMetricsService::followUpCounts() established for the dashboards.
     *
     * @return array{gaps: Collection<int, array<string, mixed>>, source: string}
     */
    public function followUpGaps(User $actor, ?int $teamId = null, ?int $limit = null): array
    {
        $limit = $this->boundedLimit($limit);
        $startOfDay = Carbon::now()->startOfDay();

        $leads = $this->openLeadsQuery($actor, $teamId)
            ->where(fn (Builder $q) => $q
                ->whereNull('next_follow_up_at')
                ->orWhere('next_follow_up_at', '<', $startOfDay))
            // Overdue (has a date, in the past) before never-set.
            ->orderByRaw('next_follow_up_at is null asc')
            ->orderBy('next_follow_up_at')
            ->limit($limit)
            ->get();

        return [
            'gaps' => $leads->map(fn (Lead $lead) => $this->presentLead($lead, [
                'gap' => $lead->next_follow_up_at === null ? 'no_follow_up_set' : 'follow_up_overdue',
                'follow_up_due' => $lead->next_follow_up_at?->toDateTimeString(),
                'days_overdue' => $lead->next_follow_up_at !== null
                    ? max(0, (int) $lead->next_follow_up_at->copy()->startOfDay()->diffInDays(Carbon::now()->startOfDay()))
                    : null,
                'recommended_action' => $lead->next_follow_up_at === null
                    ? 'Set a next follow-up date for this lead.'
                    : 'Follow up now — this contact is overdue.',
            ])),
            'source' => 'open leads with an overdue or missing next follow-up',
        ];
    }

    /**
     * Open opportunities that appear stalled: no logged activity for
     * `stalled_opportunity_days`, or already past their expected close
     * date. Every flagged opportunity names why.
     *
     * @return array{opportunities: Collection<int, array<string, mixed>>, threshold_days: int, source: string}
     */
    public function atRiskOpportunities(User $actor, ?int $teamId = null, ?int $limit = null): array
    {
        $limit = $this->boundedLimit($limit);
        $days = (int) config('services.business_development.stalled_opportunity_days', 21);
        $cutoff = Carbon::now()->subDays($days);
        $today = Carbon::now()->startOfDay();

        $query = $this->scopeToUser(
            Opportunity::query()->with(['organization', 'contact', 'owner']),
            $actor,
        )->whereNotIn('stage', [OpportunityStage::ClosedWon->value, OpportunityStage::ClosedLost->value]);

        if ($teamId !== null) {
            $query->where('opportunities.team_id', $teamId);
        }

        $query->withMax('activities as last_activity_at', 'occurred_at');

        $opportunities = $query->get()->filter(function (Opportunity $opportunity) use ($cutoff, $today) {
            $stalled = $opportunity->last_activity_at === null
                || Carbon::parse($opportunity->last_activity_at)->lt($cutoff);
            $overdue = $opportunity->expected_close_date !== null
                && $opportunity->expected_close_date->lt($today);

            return $stalled || $overdue;
        })->take($limit)->values();

        return [
            'opportunities' => $opportunities->map(function (Opportunity $opportunity) use ($cutoff, $today, $days) {
                $reasons = [];

                if ($opportunity->last_activity_at === null) {
                    $reasons[] = 'No activity has ever been logged against this opportunity.';
                } elseif (Carbon::parse($opportunity->last_activity_at)->lt($cutoff)) {
                    $reasons[] = "No activity for {$days}+ days (last: ".Carbon::parse($opportunity->last_activity_at)->toDateString().').';
                }

                if ($opportunity->expected_close_date !== null && $opportunity->expected_close_date->lt($today)) {
                    $reasons[] = 'Past its expected close date ('.$opportunity->expected_close_date->toDateString().') but still open.';
                }

                return [
                    'id' => $opportunity->id,
                    'name' => $opportunity->name,
                    'organization' => $opportunity->organization?->name,
                    'owner' => $opportunity->owner?->name,
                    'stage' => $opportunity->stage->label(),
                    'value' => $opportunity->value !== null ? (float) $opportunity->value : null,
                    'currency' => $opportunity->currency,
                    'expected_close_date' => $opportunity->expected_close_date?->toDateString(),
                    'last_activity_at' => $opportunity->last_activity_at
                        ? Carbon::parse($opportunity->last_activity_at)->toDateTimeString()
                        : null,
                    'reasons' => $reasons,
                    'recommended_action' => 'Review this opportunity and either log a next step or update its stage.',
                ];
            }),
            'threshold_days' => $days,
            'source' => "open opportunities stalled {$days}+ days or past their expected close date",
        ];
    }

    /**
     * Account-level intelligence for one organisation the actor is
     * authorised to see. Output is deliberately split into KNOWN (facts
     * straight from the database), INFERENCE (a plain rule applied to
     * those facts, labelled as such), and RECOMMENDATION (a suggested
     * next step — never an action).
     *
     * @return array<string, mixed>
     */
    public function analyzeAccount(User $actor, Organization $organization): array
    {
        $leads = $this->scopeToUser(Lead::query(), $actor)
            ->where('organization_id', $organization->id)
            ->get();

        $opportunities = $this->scopeToUser(Opportunity::query(), $actor)
            ->where('organization_id', $organization->id)
            ->get();

        $openOpportunities = $opportunities->filter(fn (Opportunity $o) => ! $o->isClosed());
        $wonOpportunities = $opportunities->filter(fn (Opportunity $o) => $o->isWon());

        // Aggregate max dates only (never rows) for one organisation the
        // actor is already authorised to view.
        $lastActivityAt = Activity::query()->where('organization_id', $organization->id)->max('occurred_at');
        $lastCommunicationAt = Communication::query()->where('organization_id', $organization->id)->max('created_at');

        $lastInteractionAt = collect([$lastActivityAt, $lastCommunicationAt])
            ->filter()
            ->map(fn ($value) => Carbon::parse($value))
            ->max();

        $relationship = $wonOpportunities->isNotEmpty() ? 'customer' : 'prospect';

        $known = [
            'organization' => $organization->name,
            'industry' => $organization->industry,
            'country' => $organization->country,
            'status' => $organization->status,
            'source' => $organization->source,
            'lead_count' => $leads->count(),
            'lead_statuses' => $leads->groupBy(fn (Lead $l) => $l->status->label())->map->count()->all(),
            'open_opportunity_count' => $openOpportunities->count(),
            'open_opportunities' => $openOpportunities->map(fn (Opportunity $o) => [
                'name' => $o->name,
                'stage' => $o->stage->label(),
                'value' => $o->value !== null ? (float) $o->value : null,
                'currency' => $o->currency,
            ])->values()->all(),
            'won_opportunity_count' => $wonOpportunities->count(),
            'last_interaction_at' => $lastInteractionAt?->toDateTimeString(),
        ];

        $inference = [
            'relationship_type' => $relationship,
            'relationship_basis' => $relationship === 'customer'
                ? 'At least one Closed Won opportunity exists for this organisation.'
                : 'No Closed Won opportunity exists for this organisation yet.',
            'days_since_last_interaction' => $this->daysSince($lastInteractionAt),
            'has_qualified_lead_without_opportunity' => $leads->contains(
                fn (Lead $l) => $l->status === LeadStatus::Qualified
            ) && $openOpportunities->isEmpty(),
        ];

        $missing = $this->missingOrganizationFields($organization, $leads);

        $recommendation = $this->accountRecommendation($inference, $missing, $openOpportunities->count());

        return [
            'known' => $known,
            'inference' => $inference,
            'missing_information' => $missing,
            'recommendation' => $recommendation,
            'source' => 'organizations + leads + opportunities + activities + communications (authorised scope only)',
            'notice' => 'KNOWN fields are from the database. INFERENCE items are plain rules applied to those facts. RECOMMENDATION is a suggested next step for a human — nothing here is an action.',
        ];
    }

    /**
     * The qualification-relevant CRM fields that are empty for one lead
     * or one organisation — "what should I find out next".
     *
     * @return array{subject_type: string, subject: string, missing: list<string>, source: string}
     */
    public function missingInformation(User $actor, Lead|Organization $subject): array
    {
        if ($subject instanceof Lead) {
            $subject->loadMissing(['organization', 'contact']);
            $missing = [];

            if ($subject->contact_id === null) {
                $missing[] = 'No contact person is linked to this lead.';
            }
            if ($subject->organization_id === null) {
                $missing[] = 'No organisation is linked to this lead.';
            }
            if ($subject->estimated_value === null) {
                $missing[] = 'No estimated value.';
            }
            if ($subject->expected_close_date === null) {
                $missing[] = 'No expected close date.';
            }
            if ($subject->next_follow_up_at === null) {
                $missing[] = 'No next follow-up date set.';
            }
            if (blank($subject->source)) {
                $missing[] = 'No lead source recorded.';
            }
            if (blank($subject->description) && blank($subject->notes)) {
                $missing[] = 'No description or notes — the qualification context is undocumented.';
            }
            if ($this->leadActivityCount($subject) === 0) {
                $missing[] = 'No activity has ever been logged against this lead.';
            }

            return [
                'subject_type' => 'lead',
                'subject' => $subject->organization?->name ?? $subject->contact?->fullName() ?? "Lead #{$subject->id}",
                'missing' => $missing,
                'source' => 'lead record + linked contact/organisation + activity history',
            ];
        }

        $leads = $this->scopeToUser(Lead::query(), $actor)
            ->where('organization_id', $subject->id)
            ->get();

        return [
            'subject_type' => 'account',
            'subject' => $subject->name,
            'missing' => $this->missingOrganizationFields($subject, $leads),
            'source' => 'organisation record + linked contacts + leads',
        ];
    }

    // ---------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------

    /**
     * Open (non-terminal) leads in the actor's authorised scope, with
     * the two computed attributes scoring needs: the most recent
     * activity date, and how many still-open opportunities the lead has.
     */
    private function openLeadsQuery(User $actor, ?int $teamId): Builder
    {
        $query = $this->scopeToUser(
            Lead::query()->with(['organization', 'contact', 'owner']),
            $actor,
        )
            ->whereNotIn('status', [LeadStatus::Disqualified->value, LeadStatus::Converted->value])
            ->withMax('activities as last_activity_at', 'occurred_at')
            ->withCount(['opportunities as open_opportunity_count' => fn (Builder $q) => $q
                ->whereNotIn('stage', [OpportunityStage::ClosedWon->value, OpportunityStage::ClosedLost->value])]);

        if ($teamId !== null) {
            $query->where('leads.team_id', $teamId);
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function scoreLead(Lead $lead): array
    {
        $weights = config('services.business_development.weights');
        $recentDays = (int) config('services.business_development.recent_engagement_days', 7);
        $highValue = (float) config('services.business_development.high_value_threshold', 50000);
        $bands = config('services.business_development.bands');

        $factors = [];
        $score = 0;
        $lastActivityAt = $lead->last_activity_at ? Carbon::parse($lead->last_activity_at) : null;

        $add = function (string $key, string $label) use (&$factors, &$score, $weights) {
            $points = (int) ($weights[$key] ?? 0);

            if ($points === 0) {
                return;
            }

            $factors[] = ['factor' => $label, 'points' => $points];
            $score += $points;
        };

        match ($lead->status) {
            LeadStatus::Qualified => $add('status_qualified', 'Lead is Qualified'),
            LeadStatus::Contacted => $add('status_contacted', 'Lead has been Contacted'),
            default => null,
        };

        match ($lead->priority->value) {
            'high' => $add('priority_high', 'Manually marked High priority'),
            'medium' => $add('priority_medium', 'Manually marked Medium priority'),
            default => null,
        };

        if ($lead->next_follow_up_at !== null && $lead->next_follow_up_at->isPast()) {
            $add('follow_up_overdue', 'Follow-up is overdue ('.$lead->next_follow_up_at->toDateString().')');
        } elseif ($lead->next_follow_up_at === null) {
            $add('follow_up_missing', 'No follow-up date is set');
        }

        if ($lastActivityAt !== null && $lastActivityAt->gte(Carbon::now()->subDays($recentDays))) {
            $add('recent_engagement', "Engaged within the last {$recentDays} days");
        } elseif ($lastActivityAt === null) {
            $add('no_engagement_ever', 'No activity ever logged — needs a first touch');
        }

        if ((int) ($lead->open_opportunity_count ?? 0) > 0) {
            $add('has_open_opportunity', 'Already has an open opportunity');
        }

        if ($lead->estimated_value !== null && (float) $lead->estimated_value >= $highValue) {
            $add('high_estimated_value', 'Estimated value at or above '.number_format($highValue));
        }

        $band = match (true) {
            $score >= (int) ($bands['high'] ?? 8) => 'high',
            $score >= (int) ($bands['medium'] ?? 4) => 'medium',
            default => 'low',
        };

        return array_merge($this->presentLead($lead, []), [
            'score' => $score,
            'priority_band' => $band,
            'factors' => $factors,
            'recommended_action' => $this->leadRecommendation($lead, $band),
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function presentLead(Lead $lead, array $extra): array
    {
        return [
            'id' => $lead->id,
            'organization' => $lead->organization?->name,
            'contact' => $lead->contact?->fullName(),
            'status' => $lead->status->label(),
            'priority' => $lead->priority->label(),
            'estimated_value' => $lead->estimated_value !== null ? (float) $lead->estimated_value : null,
            'currency' => $lead->currency,
            'next_follow_up_at' => $lead->next_follow_up_at?->toDateTimeString(),
            'last_activity_at' => $lead->last_activity_at ? Carbon::parse($lead->last_activity_at)->toDateTimeString() : null,
            'owner' => $lead->owner?->name,
            ...$extra,
        ];
    }

    private function leadRecommendation(Lead $lead, string $band): string
    {
        if ($lead->next_follow_up_at !== null && $lead->next_follow_up_at->isPast()) {
            return 'Follow up now — the scheduled follow-up is overdue.';
        }

        if ($lead->status === LeadStatus::Qualified && (int) ($lead->open_opportunity_count ?? 0) === 0) {
            return 'Qualified with no open opportunity — consider creating one.';
        }

        if ($lead->last_activity_at === null) {
            return 'Make first contact and log the outcome.';
        }

        return $band === 'high'
            ? 'Prioritise a next touch within 24 hours.'
            : 'Schedule a next step when capacity allows.';
    }

    /**
     * @param  array<string, mixed>  $inference
     * @param  list<string>  $missing
     */
    private function accountRecommendation(array $inference, array $missing, int $openOpportunityCount): string
    {
        if (($inference['has_qualified_lead_without_opportunity'] ?? false) === true) {
            return 'There is a Qualified lead with no open opportunity — consider creating one.';
        }

        $daysSince = $inference['days_since_last_interaction'] ?? null;

        if ($openOpportunityCount === 0 && $daysSince !== null && $daysSince > 30) {
            return 'No open opportunity and no interaction in over 30 days — consider a re-engagement contact.';
        }

        if ($missing !== []) {
            return 'Fill the missing information above before the next qualification step.';
        }

        return 'Continue working the open opportunities; no gap detected.';
    }

    /**
     * @param  Collection<int, Lead>  $leads
     * @return list<string>
     */
    private function missingOrganizationFields(Organization $organization, Collection $leads): array
    {
        $missing = [];

        if (blank($organization->industry)) {
            $missing[] = 'No industry recorded.';
        }
        if (blank($organization->country)) {
            $missing[] = 'No country recorded.';
        }
        if (blank($organization->email) && blank($organization->phone)) {
            $missing[] = 'No organisation email or phone on file.';
        }
        if (blank($organization->website)) {
            $missing[] = 'No website recorded.';
        }
        if ($organization->contacts()->count() === 0) {
            $missing[] = 'No contact people are linked to this organisation.';
        }
        if ($leads->isNotEmpty() && $leads->every(fn (Lead $l) => $l->next_follow_up_at === null)) {
            $missing[] = 'None of this account\'s leads has a next follow-up date set.';
        }

        return $missing;
    }

    private function leadActivityCount(Lead $lead): int
    {
        return $lead->activities()->count();
    }

    private function daysSince(Carbon|string|null $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return (int) Carbon::parse($value)->startOfDay()->diffInDays(Carbon::now()->startOfDay());
    }

    private function boundedLimit(?int $limit): int
    {
        $max = (int) config('services.business_development.max_results_per_query', 25);

        if ($limit === null || $limit < 1) {
            return $max;
        }

        return min($limit, $max);
    }
}
