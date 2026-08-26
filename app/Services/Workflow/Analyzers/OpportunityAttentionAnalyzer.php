<?php

namespace App\Services\Workflow\Analyzers;

use App\Enums\OpportunityStage;
use App\Enums\WorkflowScopeType;
use App\Models\Opportunity;
use App\Support\Workflow\AnalysisResult;
use App\Support\Workflow\WorkflowScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * STEP 7/37: identifies open opportunities that may need attention,
 * using only existing columns and deterministic thresholds — never a
 * predictive/ML score, and never a claim that an opportunity will close
 * or fail (STEP 7 is explicit about this). Every signal below is a
 * plain fact ("no activity logged in N days"), not a prediction.
 */
class OpportunityAttentionAnalyzer
{
    public function analyze(WorkflowScope $scope, int $stalledDays, int $closingSoonDays, int $limit = 15): AnalysisResult
    {
        $open = $this->scopedOpportunities($scope)->whereNotIn('stage', [
            OpportunityStage::ClosedWon->value,
            OpportunityStage::ClosedLost->value,
        ]);

        $closingSoon = (clone $open)
            ->whereNotNull('expected_close_date')
            ->whereBetween('expected_close_date', [Carbon::now()->startOfDay(), Carbon::now()->addDays($closingSoonDays)->endOfDay()])
            ->with(['organization', 'contact', 'owner'])
            ->limit($limit)
            ->get();

        $stalled = (clone $open)
            ->whereDoesntHave('activities', fn ($q) => $q->where('occurred_at', '>=', Carbon::now()->subDays($stalledDays)))
            ->with(['organization', 'contact', 'owner'])
            ->limit($limit)
            ->get();

        $missingCloseDate = (clone $open)
            ->whereNull('expected_close_date')
            ->with(['organization', 'contact', 'owner'])
            ->limit($limit)
            ->get();

        if ($closingSoon->isEmpty() && $stalled->isEmpty() && $missingCloseDate->isEmpty()) {
            return new AnalysisResult(false, [], 'No open opportunities currently need attention.');
        }

        $describe = fn (Opportunity $o) => [
            'opportunity_id' => $o->id,
            'name' => $o->name,
            'organization' => $o->organization?->name,
            'stage' => $o->stage->label(),
            'value' => $o->value !== null ? (float) $o->value : null,
            'currency' => $o->currency,
            'expected_close_date' => $o->expected_close_date?->toDateString(),
            'owner' => $o->owner?->name,
        ];

        return new AnalysisResult(true, [
            'closing_soon' => $closingSoon->map($describe)->all(),
            'stalled_no_recent_activity' => $stalled->map($describe)->all(),
            'missing_expected_close_date' => $missingCloseDate->map($describe)->all(),
            'stalled_threshold_days' => $stalledDays,
            'closing_soon_threshold_days' => $closingSoonDays,
        ], '');
    }

    private function scopedOpportunities(WorkflowScope $scope): Builder
    {
        return match ($scope->type) {
            WorkflowScopeType::Organisation => Opportunity::query(),
            WorkflowScopeType::Team => Opportunity::query()->where('opportunities.team_id', $scope->team?->id),
            WorkflowScopeType::Individual => Opportunity::query()->where('owner_id', $scope->subject->id),
        };
    }
}
