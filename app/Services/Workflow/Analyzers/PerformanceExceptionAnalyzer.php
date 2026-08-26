<?php

namespace App\Services\Workflow\Analyzers;

use App\Enums\ManagementSignal;
use App\Enums\WorkflowScopeType;
use App\Models\Team;
use App\Services\PerformanceService;
use App\Support\PerformanceSnapshot;
use App\Support\Workflow\AnalysisResult;
use App\Support\Workflow\WorkflowScope;
use Illuminate\Support\Carbon;

/**
 * STEP 8/13: identifies meaningful performance exceptions using
 * PerformanceService's own authoritative calculations exclusively — the
 * classification below (behind pace, insufficient coverage) is
 * PerformanceSnapshot::managementSignal() and the exact same
 * pipeline-coverage predicate ManagerDashboardService already uses for
 * its "Needs Attention" section, not a new formula invented here.
 */
class PerformanceExceptionAnalyzer
{
    public function __construct(private readonly PerformanceService $performance) {}

    public function analyze(WorkflowScope $scope): AnalysisResult
    {
        $periodStart = Carbon::now()->startOfMonth();
        $periodEnd = Carbon::now()->endOfMonth();

        return match ($scope->type) {
            WorkflowScopeType::Individual => $this->individual($scope, $periodStart, $periodEnd),
            WorkflowScopeType::Team => $this->team($scope, $periodStart, $periodEnd),
            WorkflowScopeType::Organisation => $this->organisation($periodStart, $periodEnd),
        };
    }

    private function individual(WorkflowScope $scope, Carbon $start, Carbon $end): AnalysisResult
    {
        $snapshot = $this->performance->forIndividual($scope->subject, $start, $end);

        return $this->fromSnapshots(['You' => $snapshot]);
    }

    private function team(WorkflowScope $scope, Carbon $start, Carbon $end): AnalysisResult
    {
        if (! $scope->team) {
            return new AnalysisResult(false, [], 'No team is associated with this scope.');
        }

        $snapshot = $this->performance->forTeam($scope->team, $start, $end);

        return $this->fromSnapshots([$scope->team->name => $snapshot]);
    }

    private function organisation(Carbon $start, Carbon $end): AnalysisResult
    {
        $organisation = $this->performance->forOrganisation($start, $end);

        $teamSnapshots = Team::orderBy('name')->get()->mapWithKeys(
            fn (Team $team) => [$team->name => $this->performance->forTeam($team, $start, $end)]
        )->all();

        return $this->fromSnapshots(['Organisation' => $organisation, ...$teamSnapshots]);
    }

    /**
     * @param  array<string, PerformanceSnapshot>  $snapshots
     */
    private function fromSnapshots(array $snapshots): AnalysisResult
    {
        $exceptions = [];

        foreach ($snapshots as $label => $snapshot) {
            $signal = $snapshot->managementSignal();
            $lowCoverage = $snapshot->hasTarget
                && $snapshot->remainingTarget > 0
                && ($snapshot->pipelineCoverage === null || $snapshot->pipelineCoverage < 1.0);

            if (! in_array($signal, [ManagementSignal::Behind, ManagementSignal::AtRisk], true) && ! $lowCoverage) {
                continue;
            }

            $exceptions[] = [
                'label' => $label,
                'signal' => $signal->label(),
                'has_target' => $snapshot->hasTarget,
                'target' => $snapshot->hasTarget ? $snapshot->target : null,
                'actual' => $snapshot->actual,
                'achievement_percent' => $snapshot->achievementPercent,
                'gap' => $snapshot->hasTarget ? $snapshot->gap : null,
                'pipeline' => $snapshot->pipeline,
                'pipeline_coverage' => $snapshot->pipelineCoverage,
                'required_run_rate' => $snapshot->requiredRunRate,
                'run_rate' => $snapshot->runRate,
                'low_pipeline_coverage' => $lowCoverage,
            ];
        }

        if ($exceptions === []) {
            return new AnalysisResult(false, [], 'No performance exceptions — everything in scope is on track or has no active target.');
        }

        return new AnalysisResult(true, ['exceptions' => $exceptions], '');
    }
}
