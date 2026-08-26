<?php

namespace App\Services\Workflow\Analyzers;

use App\Enums\WorkflowScopeType;
use App\Models\Lead;
use App\Services\Dashboard\CrmMetricsService;
use App\Support\Workflow\AnalysisResult;
use App\Support\Workflow\WorkflowScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * STEP 6/37: identifies overdue/due-today follow-ups within the given
 * scope, reusing CrmMetricsService::overdueLeads()/followUpCounts()
 * verbatim — the exact same definitions the dashboards already use.
 * This class does nothing an AI model is needed for; it is plain
 * deterministic Laravel business logic. The agent's job (elsewhere) is
 * only to prioritise and narrate what this already found.
 */
class DailyFollowUpAnalyzer
{
    public function __construct(private readonly CrmMetricsService $metrics) {}

    public function analyze(WorkflowScope $scope, int $limit = 15): AnalysisResult
    {
        $leads = $this->scopedLeads($scope);

        $counts = $this->metrics->followUpCounts($leads);
        $overdue = $this->metrics->overdueLeads($leads, $limit);

        if ($overdue->isEmpty() && $counts['due_today'] === 0) {
            return new AnalysisResult(false, [], 'No overdue or due-today follow-ups.');
        }

        return new AnalysisResult(true, [
            'overdue_count' => $counts['overdue'],
            'due_today_count' => $counts['due_today'],
            'upcoming_count' => $counts['upcoming'],
            'leads' => $overdue->map(fn (Lead $lead) => [
                'lead_id' => $lead->id,
                'organization' => $lead->organization?->name,
                'contact' => $lead->contact?->fullName(),
                'status' => $lead->status->label(),
                'priority' => $lead->priority->label(),
                'next_follow_up_at' => $lead->next_follow_up_at?->toDateTimeString(),
                'days_overdue' => $lead->next_follow_up_at ? (int) $lead->next_follow_up_at->diffInDays(now()) : null,
                'owner' => $lead->owner?->name,
            ])->all(),
        ], '');
    }

    private function scopedLeads(WorkflowScope $scope): Builder
    {
        return match ($scope->type) {
            WorkflowScopeType::Organisation => Lead::query(),
            WorkflowScopeType::Team => Lead::query()->where('leads.team_id', $scope->team?->id),
            WorkflowScopeType::Individual => Lead::query()->where('owner_id', $scope->subject->id),
        };
    }
}
