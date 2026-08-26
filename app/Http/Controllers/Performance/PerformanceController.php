<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use App\Services\PerformanceAuthorizer;
use App\Services\PerformanceService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * A verification page for Phase 4's calculation engine (STEP 21) — not
 * the final Manager/Team Head dashboard. Every number shown here comes
 * from PerformanceService, the same authoritative source Phase 5's real
 * dashboards will use.
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

        [$periodStart, $periodEnd] = $this->resolvePeriod($request);

        $organisation = null;
        $teams = [];
        $individual = null;

        if ($this->authorizer->canViewOrganisation($user)) {
            $organisation = $this->performance->forOrganisation($periodStart, $periodEnd);

            foreach (Team::orderBy('name')->get() as $team) {
                $teams[] = ['team' => $team, 'snapshot' => $this->performance->forTeam($team, $periodStart, $periodEnd)];
            }
        } elseif ($user->team_id !== null) {
            $team = Team::find($user->team_id);

            if ($team && $this->authorizer->canViewTeam($user, $team)) {
                $teams[] = ['team' => $team, 'snapshot' => $this->performance->forTeam($team, $periodStart, $periodEnd)];
            }
        }

        if (! $user->isManager()) {
            $individual = ['user' => $user, 'snapshot' => $this->performance->forIndividual($user, $periodStart, $periodEnd)];
        }

        return view('performance.index', [
            'periodStart' => $periodStart,
            'periodEnd' => $periodEnd,
            'organisation' => $organisation,
            'teams' => $teams,
            'individual' => $individual,
        ]);
    }

    public function individual(Request $request, User $user): View
    {
        $this->authorizer->authorizeIndividual($request->user(), $user);

        [$periodStart, $periodEnd] = $this->resolvePeriod($request);

        return view('performance.individual', [
            'targetUser' => $user,
            'periodStart' => $periodStart,
            'periodEnd' => $periodEnd,
            'snapshot' => $this->performance->forIndividual($user, $periodStart, $periodEnd),
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolvePeriod(Request $request): array
    {
        $request->validate([
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
        ]);

        if ($request->filled('period_start') && $request->filled('period_end')) {
            return [Carbon::parse($request->query('period_start')), Carbon::parse($request->query('period_end'))];
        }

        return [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()];
    }
}
