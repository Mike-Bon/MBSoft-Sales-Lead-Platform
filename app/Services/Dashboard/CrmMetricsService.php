<?php

namespace App\Services\Dashboard;

use App\Enums\LeadPriority;
use App\Enums\LeadStatus;
use App\Enums\OpportunityStage;
use App\Models\Lead;
use App\Models\Opportunity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only aggregation over Lead/Opportunity for dashboard display —
 * lead status counts, pipeline broken down by stage/owner, follow-up
 * bucket counts, and the rule-based "attention" lists (STEP 15). This is
 * deliberately separate from App\Services\PerformanceService: everything
 * here is presentation aggregation (counts, groupings), never a
 * target/actual/achievement/gap/pipeline-total calculation — those
 * remain exclusively PerformanceService's job, reused as-is, never
 * duplicated.
 *
 * Every method takes an already-scoped Eloquent Builder, exactly like
 * PerformanceService::actualSales()/openPipeline() — the caller decides
 * the authorized owner/team/organisation scope, this class only
 * aggregates in SQL (never loads full record sets into PHP for a count).
 */
class CrmMetricsService
{
    /**
     * @return array<string, int> keyed by LeadStatus value
     */
    public function leadStatusCounts(Builder $leads): array
    {
        $counts = (clone $leads)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return collect(LeadStatus::cases())
            ->mapWithKeys(fn (LeadStatus $status) => [$status->value => (int) ($counts[$status->value] ?? 0)])
            ->all();
    }

    /**
     * @return array<string, float> keyed by OpportunityStage value, open stages only
     */
    public function pipelineByStage(Builder $opportunities): array
    {
        $sums = (clone $opportunities)
            ->whereNotIn('stage', [OpportunityStage::ClosedWon->value, OpportunityStage::ClosedLost->value])
            ->selectRaw('stage, sum(value) as aggregate')
            ->groupBy('stage')
            ->pluck('aggregate', 'stage');

        return collect(OpportunityStage::cases())
            ->filter(fn (OpportunityStage $stage) => ! $stage->isClosed())
            ->mapWithKeys(fn (OpportunityStage $stage) => [$stage->value => (float) ($sums[$stage->value] ?? 0)])
            ->all();
    }

    /**
     * @return Collection<int, object{owner_id: int, owner_name: string, total: float}>
     */
    public function pipelineByOwner(Builder $opportunities): Collection
    {
        return (clone $opportunities)
            ->whereNotIn('stage', [OpportunityStage::ClosedWon->value, OpportunityStage::ClosedLost->value])
            ->join('users', 'users.id', '=', 'opportunities.owner_id')
            ->selectRaw('opportunities.owner_id, users.name as owner_name, sum(opportunities.value) as total')
            ->groupBy('opportunities.owner_id', 'users.name')
            ->orderByDesc('total')
            ->get();
    }

    /**
     * @return array{overdue: int, due_today: int, upcoming: int, not_set: int}
     */
    public function followUpCounts(Builder $leads): array
    {
        $now = Carbon::now();
        $base = (clone $leads)->whereNotIn('status', [LeadStatus::Disqualified->value, LeadStatus::Converted->value]);

        return [
            'overdue' => (clone $base)->whereNotNull('next_follow_up_at')->where('next_follow_up_at', '<', $now->copy()->startOfDay())->count(),
            'due_today' => (clone $base)->whereBetween('next_follow_up_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()])->count(),
            'upcoming' => (clone $base)->where('next_follow_up_at', '>', $now->copy()->endOfDay())->count(),
            'not_set' => (clone $base)->whereNull('next_follow_up_at')->count(),
        ];
    }

    /**
     * Attention area (STEP 15): overdue follow-ups, most overdue first.
     *
     * @return Collection<int, Lead>
     */
    public function overdueLeads(Builder $leads, int $limit = 10): Collection
    {
        return (clone $leads)
            ->with(['organization', 'contact', 'owner'])
            ->whereNotNull('next_follow_up_at')
            ->where('next_follow_up_at', '<', Carbon::now()->startOfDay())
            ->whereNotIn('status', [LeadStatus::Disqualified->value, LeadStatus::Converted->value])
            ->orderBy('next_follow_up_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Attention area: open, high-priority leads.
     *
     * @return Collection<int, Lead>
     */
    public function highPriorityLeads(Builder $leads, int $limit = 10): Collection
    {
        return (clone $leads)
            ->with(['organization', 'contact', 'owner'])
            ->where('priority', LeadPriority::High->value)
            ->whereNotIn('status', [LeadStatus::Disqualified->value, LeadStatus::Converted->value])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Attention area: open opportunities expected to close within the
     * given number of days (default 14 — a defensible, documented V1
     * threshold, not tied to any specific business input).
     *
     * @return Collection<int, Opportunity>
     */
    public function closingSoonOpportunities(Builder $opportunities, int $withinDays = 14, int $limit = 10): Collection
    {
        $now = Carbon::now();

        return (clone $opportunities)
            ->with(['organization', 'contact', 'owner'])
            ->whereNotIn('stage', [OpportunityStage::ClosedWon->value, OpportunityStage::ClosedLost->value])
            ->whereNotNull('expected_close_date')
            ->whereBetween('expected_close_date', [$now->copy()->startOfDay(), $now->copy()->addDays($withinDays)->endOfDay()])
            ->orderBy('expected_close_date')
            ->limit($limit)
            ->get();
    }
}
