<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\Dashboard\TeamDashboardService;
use App\Services\PerformanceAuthorizer;
use App\Support\PeriodSelection;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * STEP 14: /teams/{team}/performance — the detailed team performance
 * view a Manager drills into from the Manager Dashboard's team table, or
 * a Team Head reaches directly for their own team. Reuses
 * TeamDashboardService (the same data the Team Head Dashboard itself
 * shows) rather than assembling a second, parallel implementation.
 */
class TeamPerformanceController extends Controller
{
    public function __construct(
        private readonly TeamDashboardService $teamDashboard,
        private readonly PerformanceAuthorizer $authorizer,
    ) {}

    public function show(Request $request, Team $team): View
    {
        $this->authorizer->authorizeTeam($request->user(), $team);

        $period = PeriodSelection::fromRequest($request);

        return view('performance.teams.show', [
            'period' => $period,
            ...$this->teamDashboard->build($team, $period->start, $period->end),
        ]);
    }
}
