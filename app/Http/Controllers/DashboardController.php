<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Services\Dashboard\IndividualDashboardService;
use App\Services\Dashboard\ManagerDashboardService;
use App\Services\Dashboard\TeamDashboardService;
use App\Services\Workflow\AiInsightsSummaryService;
use App\Support\PerformanceSnapshot;
use App\Support\PeriodSelection;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * One route (/dashboard), three entirely different, role-determined
 * payloads (STEP 2) — which view a request gets is decided here, from
 * the authenticated user's own stored role/team, never from a request
 * parameter. Nothing in this controller calculates a KPI; it only asks
 * the Phase 5 dashboard services (which themselves only ask
 * PerformanceService/CrmMetricsService) for an already-authorized,
 * already-computed payload. The only thing this controller does beyond
 * that is reorder the Manager's already-computed team list for display
 * (STEP 3.B "sort teams") — reordering is not a calculation.
 *
 * `aiInsights` (Phase 8, STEP 53/54/55) is added the same additive way:
 * a small, already-scoped-to-this-user read, never a redesign of the
 * dashboard.
 */
class DashboardController extends Controller
{
    private const SORTABLE_FIELDS = ['name', 'target', 'actual', 'achievement', 'gap', 'pipeline'];

    public function __construct(
        private readonly ManagerDashboardService $managerDashboard,
        private readonly TeamDashboardService $teamDashboard,
        private readonly IndividualDashboardService $individualDashboard,
        private readonly AiInsightsSummaryService $aiInsights,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $period = PeriodSelection::fromRequest($request);
        $aiInsights = $this->aiInsights->forUser($user);

        if ($user->isManager()) {
            $data = $this->managerDashboard->build($period->start, $period->end);
            $data['teams'] = $this->sortTeams($data['teams'], $request);

            return view('dashboard.manager', ['period' => $period, 'aiInsights' => $aiInsights, ...$data]);
        }

        $team = $user->team_id ? Team::find($user->team_id) : null;

        if ($user->isTeamHead()) {
            if (! $team) {
                return view('dashboard.no-team', ['period' => $period]);
            }

            return view('dashboard.team-head', [
                'period' => $period,
                'aiInsights' => $aiInsights,
                ...$this->teamDashboard->build($team, $period->start, $period->end),
            ]);
        }

        // Team Member.
        return view('dashboard.team-member', [
            'period' => $period,
            'team' => $team,
            'aiInsights' => $aiInsights,
            ...$this->individualDashboard->build($user, $period->start, $period->end),
        ]);
    }

    /**
     * @param  Collection<int, array{team: Team, snapshot: PerformanceSnapshot}>  $teams
     * @return Collection<int, array{team: Team, snapshot: PerformanceSnapshot}>
     */
    private function sortTeams($teams, Request $request)
    {
        $sort = $request->query('sort');
        $sort = in_array($sort, self::SORTABLE_FIELDS, true) ? $sort : 'name';
        $descending = $request->query('dir') === 'desc';

        $key = match ($sort) {
            'name' => fn (array $row) => $row['team']->name,
            'target' => fn (array $row) => $row['snapshot']->target,
            'actual' => fn (array $row) => $row['snapshot']->actual,
            'achievement' => fn (array $row) => $row['snapshot']->achievementPercent ?? -1,
            'gap' => fn (array $row) => $row['snapshot']->gap,
            'pipeline' => fn (array $row) => $row['snapshot']->pipeline,
        };

        $sorted = $teams->sortBy($key, SORT_REGULAR, $descending);

        return $sorted->values();
    }
}
