<?php

namespace App\Services\Dashboard;

use App\Enums\LeadStatus;
use App\Enums\OpportunityStage;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\PerformanceService;
use Illuminate\Support\Carbon;

/**
 * Orchestrates the Team Member Dashboard (STEP 5) — intentionally the
 * simplest of the three: one user's own target/actual/achievement/gap/
 * pipeline, their own open leads and opportunities, and their own
 * follow-ups. No organisation- or team-wide data is ever queried here —
 * the scope is enforced by every query below filtering to exactly
 * $user->id, not by hiding anything on the frontend.
 */
class IndividualDashboardService
{
    public function __construct(
        private readonly PerformanceService $performance,
        private readonly CrmMetricsService $metrics,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, Carbon $periodStart, Carbon $periodEnd): array
    {
        $leads = Lead::query()->where('leads.owner_id', $user->id);
        $opportunities = Opportunity::query()->where('opportunities.owner_id', $user->id);

        return [
            'user' => $user,
            'snapshot' => $this->performance->forIndividual($user, $periodStart, $periodEnd),
            'leadStatusCounts' => $this->metrics->leadStatusCounts($leads),
            'openLeadsCount' => (clone $leads)->whereNotIn('status', [
                LeadStatus::Disqualified->value,
                LeadStatus::Converted->value,
            ])->count(),
            'openOpportunitiesCount' => (clone $opportunities)->whereNotIn('stage', [
                OpportunityStage::ClosedWon->value,
                OpportunityStage::ClosedLost->value,
            ])->count(),
            'followUpCounts' => $this->metrics->followUpCounts($leads),
            'overdueLeads' => $this->metrics->overdueLeads($leads),
            'upcomingLeads' => (clone $leads)
                ->with(['organization', 'contact'])
                ->where('next_follow_up_at', '>', Carbon::now()->endOfDay())
                ->orderBy('next_follow_up_at')
                ->limit(10)
                ->get(),
        ];
    }
}
