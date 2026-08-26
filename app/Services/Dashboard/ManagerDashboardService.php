<?php

namespace App\Services\Dashboard;

use App\Enums\ManagementSignal;
use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Models\Communication;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Target;
use App\Models\Team;
use App\Services\PerformanceService;
use App\Support\PerformanceSnapshot;
use Illuminate\Support\Carbon;

/**
 * Orchestrates the Manager Dashboard (STEP 3): organisation performance,
 * per-team performance, pipeline/lead/follow-up overviews, and rule-based
 * attention areas. Every number comes from PerformanceService
 * (target/actual/achievement/gap/pipeline/coverage) or CrmMetricsService
 * (counts/groupings) — this class only composes their results, it never
 * calculates a metric itself. communicationMetrics (Phase 6, STEP 26) is
 * an org-wide count summary, sourced the same way.
 */
class ManagerDashboardService
{
    public function __construct(
        private readonly PerformanceService $performance,
        private readonly CrmMetricsService $metrics,
        private readonly CommunicationMetricsService $communicationMetrics,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Carbon $periodStart, Carbon $periodEnd): array
    {
        $organisation = $this->performance->forOrganisation($periodStart, $periodEnd);

        $teamRows = Team::orderBy('name')->get()->map(fn (Team $team) => [
            'team' => $team,
            'snapshot' => $this->performance->forTeam($team, $periodStart, $periodEnd),
        ]);

        $leads = Lead::query();
        $opportunities = Opportunity::query();
        $communications = Communication::query();

        return [
            'organisation' => $organisation,
            'teams' => $teamRows,
            'leadStatusCounts' => $this->metrics->leadStatusCounts($leads),
            'pipelineByStage' => $this->metrics->pipelineByStage($opportunities),
            'followUpCounts' => $this->metrics->followUpCounts($leads),
            'communicationMetrics' => $this->communicationMetrics->summary($communications, $periodStart, $periodEnd),
            'attention' => [
                'overdueLeads' => $this->metrics->overdueLeads($leads),
                'highPriorityLeads' => $this->metrics->highPriorityLeads($leads),
                'closingSoonOpportunities' => $this->metrics->closingSoonOpportunities($opportunities),
                'lowCoverageTeams' => $teamRows->filter(fn (array $row) => $row['snapshot']->hasTarget
                    && $row['snapshot']->remainingTarget > 0
                    && ($row['snapshot']->pipelineCoverage === null || $row['snapshot']->pipelineCoverage < 1.0))->values(),
                'behindTeams' => $teamRows->filter(fn (array $row) => in_array(
                    $row['snapshot']->managementSignal(),
                    [ManagementSignal::Behind, ManagementSignal::AtRisk],
                    true,
                ))->values(),
            ],
            'trend' => $this->organisationTrend(),
        ];
    }

    /**
     * Historical organisation performance, one point per past period that
     * has an actual recorded Manager target — never fabricated. Reuses
     * PerformanceService::forTarget() exactly (STEP 3.C: "do not fabricate
     * historical data").
     *
     * @return array<int, array{target: Target, snapshot: PerformanceSnapshot}>
     */
    private function organisationTrend(int $limit = 6): array
    {
        return Target::query()
            ->where('target_type', TargetType::Manager->value)
            ->where('status', TargetStatus::Active->value)
            ->orderByDesc('period_start')
            ->limit($limit)
            ->get()
            ->sortBy('period_start')
            ->map(fn (Target $target) => ['target' => $target, 'snapshot' => $this->performance->forTarget($target)])
            ->values()
            ->all();
    }
}
