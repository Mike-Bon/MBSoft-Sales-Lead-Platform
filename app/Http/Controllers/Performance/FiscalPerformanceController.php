<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Models\ReportingUnit;
use App\Models\Team;
use App\Services\FiscalPerformanceService;
use App\Services\PerformanceAuthorizer;
use App\Support\FiscalYear;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * A SEPARATE screen for OPERATIONAL fiscal-year performance (the
 * corporate budget workbook: monthly phased targets vs monthly actuals,
 * in PHP and units). It does not touch — and must not be confused with —
 * the CRM Closed-Won / pipeline Performance screen
 * (App\Http\Controllers\Performance\PerformanceController).
 *
 * Authorization is PerformanceAuthorizer, exactly as the CRM performance
 * screens: Manager → organisation-wide + any team; Team Head / Member →
 * their own team only. A model-supplied team/unit id outside scope is a
 * visible 403, never silently swapped.
 */
class FiscalPerformanceController extends Controller
{
    public function __construct(
        private readonly FiscalPerformanceService $fiscal,
        private readonly PerformanceAuthorizer $authorizer,
    ) {}

    public function index(Request $request): View
    {
        $request->validate([
            'fiscal_year' => ['nullable', 'integer', 'between:2000,2100'],
            'as_of' => ['nullable', 'date'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'reporting_unit_id' => ['nullable', 'integer', 'exists:reporting_units,id'],
        ]);

        $user = $request->user();
        $asOf = $request->filled('as_of') ? Carbon::parse($request->date('as_of')) : Carbon::now();
        $fiscalYear = (int) ($request->integer('fiscal_year') ?: FiscalYear::containing($asOf)->year);

        // Resolve the requested scope, then authorize it.
        $unit = $request->filled('reporting_unit_id') ? ReportingUnit::with('team')->find($request->integer('reporting_unit_id')) : null;
        $teamId = $request->integer('team_id') ?: ($user->isManager() ? null : $user->team_id);
        $team = $teamId ? Team::find($teamId) : null;

        if ($unit !== null) {
            $this->authorizer->authorizeTeam($user, $unit->team);
            $snapshot = $this->fiscal->forReportingUnit($unit, $fiscalYear, $asOf);
        } elseif ($team !== null) {
            $this->authorizer->authorizeTeam($user, $team);
            $snapshot = $this->fiscal->forTeam($team, $fiscalYear, $asOf);
        } else {
            $this->authorizer->authorizeOrganisation($user);
            $snapshot = $this->fiscal->forOrganisation($fiscalYear, $asOf);
        }

        return view('performance.fiscal.index', [
            'snapshot' => $snapshot,
            'fiscalYear' => $fiscalYear,
            'asOf' => $asOf,
            'fiscalYears' => $this->selectableFiscalYears($asOf),
            'teams' => $this->authorizer->canViewOrganisation($user)
                ? Team::orderBy('name')->get(['id', 'name'])
                : Team::whereKey($user->team_id)->get(['id', 'name']),
            'reportingUnits' => $team !== null || $unit !== null
                ? ReportingUnit::where('team_id', $unit?->team_id ?? $team?->id)->orderBy('name')->get(['id', 'name'])
                : collect(),
            'selectedTeamId' => $unit?->team_id ?? $team?->id,
            'selectedUnitId' => $unit?->id,
        ]);
    }

    /**
     * @return list<int>
     */
    private function selectableFiscalYears(Carbon $asOf): array
    {
        $current = FiscalYear::containing($asOf)->year;

        return range($current + 1, $current - 3);
    }
}
