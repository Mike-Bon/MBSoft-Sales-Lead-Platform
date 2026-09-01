<?php

namespace App\Http\Controllers\Performance;

use App\Enums\ActualLineChangeType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\StoreManualActualRequest;
use App\Models\ReportingUnit;
use App\Services\Performance\ManualActualEntryService;
use App\Services\PerformanceAuthorizer;
use App\Support\FiscalYear;
use App\Support\Performance\ActualAmountParser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Manager-only single-value entry / correction of an operational actual —
 * the controlled fallback to bulk CSV. Shows the CURRENT value before any
 * change, requires an explicit Save, and (for a change to an existing
 * reported value) a reason. Every write is audited via the same
 * AuthoritativeActualLineWriter as the bulk path.
 */
class FiscalActualEntryController extends Controller
{
    public function __construct(
        private readonly ManualActualEntryService $entry,
        private readonly PerformanceAuthorizer $authorizer,
    ) {}

    public function create(Request $request): View
    {
        $this->authorizer->authorizeManageActuals($request->user());

        $request->validate([
            'fiscal_year' => ['nullable', 'integer', 'between:2000,2100'],
            'reporting_unit_id' => ['nullable', 'integer', 'exists:reporting_units,id'],
            'period_month' => ['nullable', 'integer', 'between:1,12'],
        ]);

        $asOf = Carbon::now();
        $fiscalYear = (int) ($request->integer('fiscal_year') ?: FiscalYear::containing($asOf)->year);
        $unit = $request->filled('reporting_unit_id')
            ? ReportingUnit::with('team:id,name')->find($request->integer('reporting_unit_id'))
            : null;
        $periodMonth = $request->filled('period_month') ? $request->integer('period_month') : null;

        $state = ($unit !== null && $periodMonth !== null)
            ? $this->entry->currentState($fiscalYear, $unit, $periodMonth)
            : null;

        return view('performance.fiscal.actuals.entry.create', [
            'fiscalYear' => $fiscalYear,
            'fiscalYears' => $this->selectableFiscalYears($asOf),
            'fiscalMonths' => FiscalYear::of($fiscalYear)->months(),
            'reportingUnits' => ReportingUnit::query()->active()->with('team:id,name')->orderBy('team_id')->orderBy('name')->get(),
            'selectedUnit' => $unit,
            'selectedMonth' => $periodMonth,
            'state' => $state,
        ]);
    }

    public function store(StoreManualActualRequest $request): RedirectResponse
    {
        $unit = ReportingUnit::query()->findOrFail($request->integer('reporting_unit_id'));

        $revenue = (float) ActualAmountParser::parse((string) $request->input('actual_revenue'), allowBlank: false);
        $unitsParsed = ActualAmountParser::parse((string) ($request->input('actual_units') ?? ''), allowBlank: true);
        $units = $unitsParsed === null ? null : (float) $unitsParsed;

        try {
            $result = $this->entry->save(
                $request->user(),
                $request->integer('fiscal_year'),
                $unit,
                $request->integer('period_month'),
                $revenue,
                $units,
                $request->reason(),
                (string) $request->input('lock'),
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        $message = match ($result->changeType) {
            ActualLineChangeType::Created => 'Actual recorded.',
            ActualLineChangeType::Updated => 'Actual corrected. The previous value is kept in the change history.',
            ActualLineChangeType::Unchanged => 'No change — the value you entered matches what is already recorded.',
        };

        return redirect()->route('performance.fiscal.actuals.entry.create', [
            'fiscal_year' => $request->integer('fiscal_year'),
            'reporting_unit_id' => $unit->id,
            'period_month' => $request->integer('period_month'),
        ])->with('status', $message);
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
