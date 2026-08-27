<?php

namespace App\Services\CostToServe;

use App\Enums\OpportunityStage;
use App\Http\Controllers\Concerns\ScopesCrmQueries;
use App\Models\Activity;
use App\Models\Communication;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\User;
use App\Support\CostToServe\AccountEconomicsSnapshot;
use App\Support\CostToServe\MetricChange;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Phase 12: the one place revenue/engagement figures are actually
 * calculated — every AgentTool and every dashboard controller in this
 * feature calls this service, never queries Opportunity/Activity/
 * Communication directly (STEP 29: database aggregation, never raw rows
 * handed to the LLM).
 *
 * Revenue is realized (Closed Won) Opportunity value only, filtered to
 * one explicit currency at a time (mirrors PerformanceService's own
 * convention — never summing mixed currencies). There is no cost
 * component anywhere in this class: see docs/COST_TO_SERVE.md for the
 * full data-availability matrix this was built against.
 *
 * Phase 12A: every public method starts with assertAccess(), which
 * combines role authorization (Manager only — Team Head access was
 * removed this phase, see docs/COST_TO_SERVE.md's Phase 12A section)
 * with the global feature switch (CostToServeAccessService). This is
 * the actual enforcement boundary — every AgentTool and the dedicated
 * controller call into this class, never straight to Eloquent, so
 * there is exactly one place this policy can be bypassed from, and it
 * can't be.
 */
class AccountEconomicsService
{
    use ScopesCrmQueries;

    public function __construct(private readonly CostToServeAccessService $access) {}

    /**
     * Phase 12A: the one gate every public method below starts with —
     * combines role authorization (Manager only) with the global
     * feature switch. Checked in this order deliberately: a Team Head
     * always gets the same generic "unauthorized" message regardless
     * of the switch's state, so the switch's on/off value is never
     * revealed to someone who could never use the feature anyway.
     *
     * @throws AuthorizationException
     */
    private function assertAccess(User $actor): void
    {
        if (! $this->access->isRoleAuthorized($actor)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        if (! $this->access->isEnabled()) {
            throw new AuthorizationException('Cost-to-Serve is currently disabled. A Manager can re-enable it from Cost-to-Serve Settings.');
        }
    }

    /**
     * Resolves a tool's `organization_id`/`organization_name` argument
     * to exactly one authorized Organization. Deliberately a
     * ValidationException (not AuthorizationException) for "not
     * found"/"ambiguous" — these are normal conversational outcomes the
     * Agent engine already relays as a plain tool error, not a security
     * event. An organization outside the actor's authorized scope is
     * indistinguishable from "not found" here — never confirms or
     * denies that a restricted organization exists (STEP 27:
     * unauthorized-access probing must learn nothing).
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function resolveOrganization(User $actor, ?int $organizationId, ?string $organizationName): Organization
    {
        $this->assertAccess($actor);

        $query = $this->scopeToUser(Organization::query(), $actor);

        if ($organizationId !== null) {
            $organization = (clone $query)->find($organizationId);

            if (! $organization) {
                throw ValidationException::withMessages([
                    'organization_id' => 'No organization matching that id was found.',
                ]);
            }

            return $organization;
        }

        if ($organizationName !== null && trim($organizationName) !== '') {
            $matches = (clone $query)->where('name', 'like', '%'.trim($organizationName).'%')->limit(5)->get();

            if ($matches->count() === 1) {
                return $matches->first();
            }

            if ($matches->count() > 1) {
                throw ValidationException::withMessages([
                    'organization_name' => 'Multiple organizations matched "'.$organizationName.'": '.$matches->pluck('name')->implode(', ').'. Please specify which one.',
                ]);
            }

            throw ValidationException::withMessages([
                'organization_name' => 'No organization matching "'.$organizationName.'" was found.',
            ]);
        }

        throw ValidationException::withMessages([
            'organization_id' => 'Either organization_id or organization_name is required.',
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function snapshotForOrganization(User $actor, Organization $organization, Carbon $start, Carbon $end, string $currency): AccountEconomicsSnapshot
    {
        $this->authorizeOrganization($actor, $organization);

        return $this->buildSnapshots(
            $this->scopeOrganizations(Organization::query(), $actor)->whereKey($organization->id),
            $start,
            $end,
            $currency,
        )->first() ?? $this->emptySnapshot($organization, $currency);
    }

    /**
     * @return Collection<int, AccountEconomicsSnapshot>
     *
     * @throws AuthorizationException
     */
    public function topAccountsByRevenue(User $actor, Carbon $start, Carbon $end, string $currency, int $limit, ?int $teamId = null): Collection
    {
        $organizations = $this->scopeOrganizations(Organization::query(), $actor, $teamId);

        return $this->buildSnapshots($organizations, $start, $end, $currency)
            ->sortByDesc(fn (AccountEconomicsSnapshot $s) => $s->revenue)
            ->take($limit)
            ->values();
    }

    /**
     * Organisation/team-wide totals for the dashboard's summary row
     * (STEP 21 "summary first") — the same per-organization snapshots
     * topAccountsByRevenue() computes, aggregated across the actor's
     * entire authorized set rather than truncated to a top N.
     *
     * @return array{revenue: float, closed_deals_count: int, average_revenue_per_deal: ?float, accounts_with_revenue_count: int, accounts_count: int}
     *
     * @throws AuthorizationException
     */
    public function organisationSummary(User $actor, Carbon $start, Carbon $end, string $currency, ?int $teamId = null): array
    {
        $organizations = $this->scopeOrganizations(Organization::query(), $actor, $teamId);
        $snapshots = $this->buildSnapshots($organizations, $start, $end, $currency);

        $revenue = (float) $snapshots->sum('revenue');
        $closedDealsCount = (int) $snapshots->sum('closedDealsCount');

        return [
            'revenue' => $revenue,
            'closed_deals_count' => $closedDealsCount,
            'average_revenue_per_deal' => $closedDealsCount > 0 ? $revenue / $closedDealsCount : null,
            'accounts_with_revenue_count' => $snapshots->filter(fn (AccountEconomicsSnapshot $s) => $s->revenue > 0)->count(),
            'accounts_count' => $snapshots->count(),
        ];
    }

    /**
     * @return array{current: AccountEconomicsSnapshot, previous: AccountEconomicsSnapshot, revenue: MetricChange, closed_deals: MetricChange, engagement: MetricChange, average_revenue_per_deal: MetricChange}
     *
     * @throws AuthorizationException
     */
    public function comparePeriods(User $actor, Organization $organization, Carbon $currentStart, Carbon $currentEnd, Carbon $previousStart, Carbon $previousEnd, string $currency): array
    {
        $current = $this->snapshotForOrganization($actor, $organization, $currentStart, $currentEnd, $currency);
        $previous = $this->snapshotForOrganization($actor, $organization, $previousStart, $previousEnd, $currency);

        return [
            'current' => $current,
            'previous' => $previous,
            'revenue' => MetricChange::compute($previous->revenue, $current->revenue),
            'closed_deals' => MetricChange::compute($previous->closedDealsCount, $current->closedDealsCount),
            'engagement' => MetricChange::compute($previous->engagementCount(), $current->engagementCount()),
            'average_revenue_per_deal' => MetricChange::compute($previous->averageRevenuePerDeal ?? 0.0, $current->averageRevenuePerDeal ?? 0.0),
        ];
    }

    /**
     * STEP 11: deterministic, config-driven exception detection —
     * revenue/engagement patterns only, never a cost claim. Each
     * returned row is a snapshot pair plus which rule(s) it tripped,
     * so the agent can explain exactly why (STEP 13).
     *
     * @return list<array{organization: AccountEconomicsSnapshot, previous: AccountEconomicsSnapshot, reasons: list<string>}>
     *
     * @throws AuthorizationException
     */
    public function identifyExceptions(User $actor, Carbon $currentStart, Carbon $currentEnd, Carbon $previousStart, Carbon $previousEnd, string $currency, ?int $teamId, int $limit): array
    {
        $organizations = $this->scopeOrganizations(Organization::query(), $actor, $teamId);

        $current = $this->buildSnapshots($organizations, $currentStart, $currentEnd, $currency)->keyBy('organizationId');
        $previous = $this->buildSnapshots($organizations, $previousStart, $previousEnd, $currency)->keyBy('organizationId');

        $revenueDeclineThreshold = (float) config('services.cost_to_serve.revenue_decline_threshold_percent', 20.0);
        $engagementGrowthThreshold = (float) config('services.cost_to_serve.engagement_growth_threshold_percent', 50.0);
        $zeroRevenueEngagementThreshold = (int) config('services.cost_to_serve.zero_revenue_engagement_threshold', 5);

        $flagged = [];

        foreach ($current as $organizationId => $currentSnapshot) {
            $previousSnapshot = $previous->get($organizationId) ?? $this->emptySnapshotFor($currentSnapshot, $currency);
            $reasons = [];

            $revenueChange = MetricChange::compute($previousSnapshot->revenue, $currentSnapshot->revenue);
            if ($revenueChange->percent !== null && $revenueChange->percent <= -$revenueDeclineThreshold) {
                $reasons[] = "Revenue declined {$revenueChange->percent}% vs. the previous period (configured threshold: {$revenueDeclineThreshold}%).";
            }

            $engagementChange = MetricChange::compute($previousSnapshot->engagementCount(), $currentSnapshot->engagementCount());
            if ($engagementChange->percent !== null && $engagementChange->percent >= $engagementGrowthThreshold && $revenueChange->state !== 'increased') {
                $reasons[] = "Sales engagement rose {$engagementChange->percent}% while revenue did not increase (configured threshold: {$engagementGrowthThreshold}%).";
            }

            if ($currentSnapshot->revenue <= 0.0 && $currentSnapshot->engagementCount() >= $zeroRevenueEngagementThreshold) {
                $reasons[] = "{$currentSnapshot->engagementCount()} engagement touches recorded with zero closed revenue this period (configured threshold: {$zeroRevenueEngagementThreshold}).";
            }

            if ($reasons !== []) {
                $flagged[] = ['organization' => $currentSnapshot, 'previous' => $previousSnapshot, 'reasons' => $reasons];
            }
        }

        return collect($flagged)
            ->sortByDesc(fn (array $row) => $row['organization']->revenue)
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @throws AuthorizationException
     */
    private function authorizeOrganization(User $actor, Organization $organization): void
    {
        if (! $this->scopeOrganizations(Organization::query(), $actor)->whereKey($organization->id)->exists()) {
            throw new AuthorizationException('This action is unauthorized.');
        }
    }

    /**
     * Phase 12A: Cost-to-Serve is Manager-only commercial information,
     * and only while the global feature switch is on (assertAccess()).
     * Team Head is never authorized here regardless of the switch — a
     * change from Phase 12's original Manager-or-Team-Head scoping,
     * required to enforce this phase's access policy. Reuses
     * App\Http\Controllers\Concerns\ScopesCrmQueries::scopeToUser for
     * the actual query (a Manager is unrestricted there too, so the
     * net effect is unchanged for the one role that still has access)
     * rather than a second, hand-rolled query. `$requestedTeamId` is
     * kept as an optional filter (a Manager may still narrow to one
     * team) even though only Manager reaches this method now.
     *
     * @throws AuthorizationException
     */
    private function scopeOrganizations(Builder $query, User $actor, ?int $requestedTeamId = null): Builder
    {
        $this->assertAccess($actor);

        $query = $this->scopeToUser($query, $actor);

        return $requestedTeamId !== null ? $query->where('team_id', $requestedTeamId) : $query;
    }

    /**
     * @return Collection<int, AccountEconomicsSnapshot>
     */
    private function buildSnapshots(Builder $organizations, Carbon $start, Carbon $end, string $currency): Collection
    {
        $organizationIds = (clone $organizations)->pluck('id');

        if ($organizationIds->isEmpty()) {
            return collect();
        }

        $names = (clone $organizations)->pluck('name', 'id');

        // Mirrors PerformanceService::actualSales()'s own boundary
        // normalization exactly — never a different period-boundary
        // convention for what is conceptually the same "did this fall
        // within the period" question.
        $periodStart = $start->copy()->startOfDay();
        $periodEnd = $end->copy()->endOfDay();

        $revenue = Opportunity::query()
            ->whereIn('organization_id', $organizationIds)
            ->where('stage', OpportunityStage::ClosedWon->value)
            ->where('currency', $currency)
            ->whereBetween('closed_at', [$periodStart, $periodEnd])
            ->groupBy('organization_id')
            ->selectRaw('organization_id, SUM(value) as revenue, COUNT(*) as closed_deals_count')
            ->get()
            ->keyBy('organization_id');

        $activityCounts = Activity::query()
            ->whereIn('organization_id', $organizationIds)
            ->whereBetween('occurred_at', [$periodStart, $periodEnd])
            ->groupBy('organization_id')
            ->selectRaw('organization_id, COUNT(*) as count')
            ->pluck('count', 'organization_id');

        $communicationCounts = Communication::query()
            ->whereIn('organization_id', $organizationIds)
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->groupBy('organization_id')
            ->selectRaw('organization_id, COUNT(*) as count')
            ->pluck('count', 'organization_id');

        return $organizationIds->map(function (int $organizationId) use ($names, $revenue, $activityCounts, $communicationCounts, $currency) {
            $row = $revenue->get($organizationId);
            $revenueAmount = $row ? (float) $row->revenue : 0.0;
            $closedDealsCount = $row ? (int) $row->closed_deals_count : 0;

            return new AccountEconomicsSnapshot(
                organizationId: $organizationId,
                organizationName: $names->get($organizationId, 'Unknown'),
                currency: $currency,
                revenue: $revenueAmount,
                closedDealsCount: $closedDealsCount,
                averageRevenuePerDeal: $closedDealsCount > 0 ? $revenueAmount / $closedDealsCount : null,
                activityCount: (int) ($activityCounts->get($organizationId) ?? 0),
                communicationCount: (int) ($communicationCounts->get($organizationId) ?? 0),
            );
        })->values();
    }

    private function emptySnapshot(Organization $organization, string $currency): AccountEconomicsSnapshot
    {
        return new AccountEconomicsSnapshot(
            organizationId: $organization->id,
            organizationName: $organization->name,
            currency: $currency,
            revenue: 0.0,
            closedDealsCount: 0,
            averageRevenuePerDeal: null,
            activityCount: 0,
            communicationCount: 0,
        );
    }

    private function emptySnapshotFor(AccountEconomicsSnapshot $like, string $currency): AccountEconomicsSnapshot
    {
        return new AccountEconomicsSnapshot(
            organizationId: $like->organizationId,
            organizationName: $like->organizationName,
            currency: $currency,
            revenue: 0.0,
            closedDealsCount: 0,
            averageRevenuePerDeal: null,
            activityCount: 0,
            communicationCount: 0,
        );
    }
}
