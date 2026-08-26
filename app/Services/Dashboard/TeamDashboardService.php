<?php

namespace App\Services\Dashboard;

use App\Enums\UserRole;
use App\Models\Communication;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Team;
use App\Services\PerformanceService;
use Illuminate\Support\Carbon;

/**
 * Orchestrates the Team Head Dashboard (STEP 4): team performance,
 * individual member performance, team pipeline, team leads, and
 * follow-ups — all scoped to exactly one team, and all sourced from
 * PerformanceService/CrmMetricsService, never recalculated here.
 * communicationMetrics (Phase 6, STEP 26) is scoped to the same team.
 *
 * Also used for App\Http\Controllers\Performance\TeamPerformanceController
 * (STEP 14's /teams/{team}/performance drill-down), which needs the same
 * data as the dashboard's team section.
 */
class TeamDashboardService
{
    public function __construct(
        private readonly PerformanceService $performance,
        private readonly CrmMetricsService $metrics,
        private readonly CommunicationMetricsService $communicationMetrics,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Team $team, Carbon $periodStart, Carbon $periodEnd): array
    {
        $teamSnapshot = $this->performance->forTeam($team, $periodStart, $periodEnd);

        $members = $team->members()->where('role', '!=', UserRole::Manager->value)->orderBy('name')->get();

        $memberRows = $members->map(fn ($member) => [
            'user' => $member,
            'snapshot' => $this->performance->forIndividual($member, $periodStart, $periodEnd),
        ]);

        // Table-qualified: pipelineByOwner() joins `users`, which also
        // has a team_id column — an unqualified where('team_id', ...)
        // here would become ambiguous once that join is added.
        $leads = Lead::query()->where('leads.team_id', $team->id);
        $opportunities = Opportunity::query()->where('opportunities.team_id', $team->id);
        $communications = Communication::query()->where('communications.team_id', $team->id);

        return [
            'team' => $team,
            'snapshot' => $teamSnapshot,
            'members' => $memberRows,
            'leadStatusCounts' => $this->metrics->leadStatusCounts($leads),
            'pipelineByStage' => $this->metrics->pipelineByStage($opportunities),
            'pipelineByOwner' => $this->metrics->pipelineByOwner($opportunities),
            'followUpCounts' => $this->metrics->followUpCounts($leads),
            'communicationMetrics' => $this->communicationMetrics->summary($communications, $periodStart, $periodEnd),
            'attention' => [
                'overdueLeads' => $this->metrics->overdueLeads($leads),
                'highPriorityLeads' => $this->metrics->highPriorityLeads($leads),
                'closingSoonOpportunities' => $this->metrics->closingSoonOpportunities($opportunities),
            ],
        ];
    }
}
