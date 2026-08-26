<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use App\Services\PerformanceAuthorizer;
use App\Services\PerformanceService;
use App\Support\PeriodSelection;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * A verification page for Phase 4's calculation engine (STEP 21) — not
 * the final Manager/Team Head dashboard (that's
 * App\Http\Controllers\DashboardController, Phase 5). Every number shown
 * here comes from PerformanceService, the same authoritative source the
 * real dashboards use.
 */
class PerformanceController extends Controller
{
    public function __construct(
        private readonly PerformanceService $performance,
        private readonly PerformanceAuthorizer $authorizer,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $period = PeriodSelection::fromRequest($request);

        $organisation = null;
        $teams = [];
        $individual = null;

        if ($this->authorizer->canViewOrganisation($user)) {
            $organisation = $this->performance->forOrganisation($period->start, $period->end);

            foreach (Team::orderBy('name')->get() as $team) {
                $teams[] = ['team' => $team, 'snapshot' => $this->performance->forTeam($team, $period->start, $period->end)];
            }
        } elseif ($user->team_id !== null) {
            $team = Team::find($user->team_id);

            if ($team && $this->authorizer->canViewTeam($user, $team)) {
                $teams[] = ['team' => $team, 'snapshot' => $this->performance->forTeam($team, $period->start, $period->end)];
            }
        }

        if (! $user->isManager()) {
            $individual = ['user' => $user, 'snapshot' => $this->performance->forIndividual($user, $period->start, $period->end)];
        }

        return view('performance.index', [
            'period' => $period,
            'periodStart' => $period->start,
            'periodEnd' => $period->end,
            'organisation' => $organisation,
            'teams' => $teams,
            'individual' => $individual,
        ]);
    }

    public function individual(Request $request, User $user): View
    {
        $this->authorizer->authorizeIndividual($request->user(), $user);

        $period = PeriodSelection::fromRequest($request);

        return view('performance.individual', [
            'targetUser' => $user,
            'period' => $period,
            'periodStart' => $period->start,
            'periodEnd' => $period->end,
            'snapshot' => $this->performance->forIndividual($user, $period->start, $period->end),
        ]);
    }
}
