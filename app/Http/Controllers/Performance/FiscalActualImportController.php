<?php

namespace App\Http\Controllers\Performance;

use App\Enums\PerformanceImportType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\ConfirmActualsImportRequest;
use App\Http\Requests\Performance\DownloadActualsTemplateRequest;
use App\Http\Requests\Performance\UploadActualsImportRequest;
use App\Models\PerformanceActualLine;
use App\Models\PerformanceActualLineRevision;
use App\Models\PerformanceImport;
use App\Models\ReportingUnit;
use App\Services\Performance\PerformanceImportService;
use App\Services\PerformanceAuthorizer;
use App\Support\FiscalYear;
use App\Support\Performance\ActualsCsvTemplate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Manager-only maintenance of OPERATIONAL actuals, reached from
 * /performance/fiscal. Bulk CSV is the primary path: download a
 * month-scoped template → upload → validate → preview (CURRENT → NEW) →
 * explicit confirm → persist. Nothing is written until confirm.
 *
 * FiscalPerformanceService / the dashboard / plan data are untouched.
 */
class FiscalActualImportController extends Controller
{
    public function __construct(
        private readonly PerformanceImportService $imports,
        private readonly PerformanceAuthorizer $authorizer,
    ) {}

    /** The management hub: month coverage + recent changes + the actions. */
    public function index(Request $request): View
    {
        $this->authorizer->authorizeManageActuals($request->user());

        $request->validate(['fiscal_year' => ['nullable', 'integer', 'between:2000,2100']]);

        $asOf = Carbon::now();
        $fiscalYear = (int) ($request->integer('fiscal_year') ?: FiscalYear::containing($asOf)->year);
        $fy = FiscalYear::of($fiscalYear);

        $activeUnits = ReportingUnit::query()->active()->count();

        $reportedByMonth = PerformanceActualLine::query()
            ->where('fiscal_year', $fiscalYear)
            ->selectRaw('period_month, COUNT(DISTINCT reporting_unit_id) as units_reported')
            ->groupBy('period_month')
            ->pluck('units_reported', 'period_month');

        $coverage = collect($fy->months())->map(fn (array $m) => [
            'ordinal' => $m['ordinal'],
            'name' => $m['name'],
            'calendar_year' => $m['calendar_year'],
            'units_reported' => (int) ($reportedByMonth[$m['ordinal']] ?? 0),
            'active_units' => $activeUnits,
        ])->all();

        $recentChanges = PerformanceActualLineRevision::query()
            ->with(['reportingUnit:id,name', 'team:id,name', 'changedBy:id,name', 'import:id,original_filename,file_sha256'])
            ->latest('id')
            ->limit(25)
            ->get();

        return view('performance.fiscal.actuals.index', [
            'fiscalYear' => $fiscalYear,
            'fiscalYears' => $this->selectableFiscalYears($asOf),
            'fiscalMonths' => $fy->months(),
            'coverage' => $coverage,
            'activeUnits' => $activeUnits,
            'recentChanges' => $recentChanges,
        ]);
    }

    /** GET — download the month-scoped, pre-filled template. */
    public function template(DownloadActualsTemplateRequest $request): StreamedResponse
    {
        $fiscalYear = $request->fiscalYear();
        $periodMonth = $request->periodMonth();

        $csv = ActualsCsvTemplate::build($fiscalYear, $periodMonth);
        $filename = ActualsCsvTemplate::filename($fiscalYear, $periodMonth);

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** GET — the upload form. */
    public function create(Request $request): View
    {
        $this->authorizer->authorizeManageActuals($request->user());

        $asOf = Carbon::now();

        return view('performance.fiscal.actuals.import.create', [
            'fiscalYears' => $this->selectableFiscalYears($asOf),
            'fiscalMonths' => FiscalYear::containing($asOf)->months(),
            'defaultFiscalYear' => FiscalYear::containing($asOf)->year,
        ]);
    }

    /** POST — upload + validate → staged preview (nothing written). */
    public function store(UploadActualsImportRequest $request): RedirectResponse
    {
        $file = $request->file('file');
        $path = $file->getRealPath();

        // Source-file identity/integrity — computed on the exact uploaded
        // bytes BEFORE the framework discards the temp file.
        $sha256 = hash_file('sha256', $path);
        $size = (int) filesize($path);
        $originalName = $this->sanitizeFilename($file->getClientOriginalName());

        $result = $this->imports->preview(
            PerformanceImportType::Actual, $path, $request->user(), $originalName, $sha256, $size,
        );

        if (! $result->ok) {
            return redirect()
                ->route('performance.fiscal.actuals.import.show', $result->import)
                ->with('import_errors', true);
        }

        return redirect()->route('performance.fiscal.actuals.import.show', $result->import);
    }

    /** GET — review the staged preview. */
    public function show(Request $request, PerformanceImport $import): View
    {
        $this->authorize('view', $import);

        $payload = $import->preview_payload ?? [];
        $errors = $payload['errors'] ?? [];

        $rows = collect($payload['rows'] ?? []);
        $unitNames = ReportingUnit::query()
            ->whereIn('id', $rows->pluck('reporting_unit_id')->unique()->all())
            ->with('team:id,name')
            ->get()
            ->keyBy('id');

        $fy = $import->fiscal_year !== null ? FiscalYear::of($import->fiscal_year) : null;

        return view('performance.fiscal.actuals.import.show', [
            'import' => $import,
            'validationErrors' => $errors,
            'rows' => $rows->map(fn (array $r) => [
                ...$r,
                'unit_name' => $unitNames[$r['reporting_unit_id']]->name ?? ('#'.$r['reporting_unit_id']),
                'team_name' => $unitNames[$r['reporting_unit_id']]->team->name ?? '—',
                'month_name' => $fy?->ordinalName($r['period_month']) ?? (string) $r['period_month'],
                'calendar_year' => $fy?->calendarForOrdinal($r['period_month'])['year'],
            ])->all(),
            'stats' => $payload['stats'] ?? [],
            'expired' => $import->isPreviewExpired(),
        ]);
    }

    /** POST — confirm and persist. */
    public function confirm(ConfirmActualsImportRequest $request, PerformanceImport $import): RedirectResponse
    {
        $outcome = $this->imports->commitPreview($import, $request->user(), $request->fingerprint());

        if ($outcome->committed()) {
            return redirect()->route('performance.fiscal.actuals.index')
                ->with('status', 'Actuals imported: '.$outcome->message);
        }

        if ($outcome->status === 'already_completed') {
            return redirect()->route('performance.fiscal.actuals.index')
                ->with('status', $outcome->message);
        }

        return redirect()->route('performance.fiscal.actuals.import.show', $outcome->import)
            ->with('import_error', $outcome->message);
    }

    /** POST — discard the staged preview. */
    public function cancel(Request $request, PerformanceImport $import): RedirectResponse
    {
        $this->authorize('cancel', $import);

        $this->imports->cancelPreview($import, $request->user());

        return redirect()->route('performance.fiscal.actuals.index')
            ->with('status', 'Import discarded. Nothing was written.');
    }

    /** GET — full change history. */
    public function history(Request $request): View
    {
        $this->authorizer->authorizeManageActuals($request->user());

        $request->validate([
            'fiscal_year' => ['nullable', 'integer', 'between:2000,2100'],
            'reporting_unit_id' => ['nullable', 'integer', 'exists:reporting_units,id'],
        ]);

        $revisions = PerformanceActualLineRevision::query()
            ->with(['reportingUnit:id,name', 'team:id,name', 'changedBy:id,name', 'import:id,original_filename,file_sha256'])
            ->when($request->filled('fiscal_year'), fn ($q) => $q->where('fiscal_year', $request->integer('fiscal_year')))
            ->when($request->filled('reporting_unit_id'), fn ($q) => $q->where('reporting_unit_id', $request->integer('reporting_unit_id')))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('performance.fiscal.actuals.history', [
            'revisions' => $revisions,
            'reportingUnits' => ReportingUnit::query()->orderBy('name')->get(['id', 'name']),
            'fiscalYears' => $this->selectableFiscalYears(Carbon::now()),
            'selectedFiscalYear' => $request->integer('fiscal_year') ?: null,
            'selectedUnitId' => $request->integer('reporting_unit_id') ?: null,
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

    private function sanitizeFilename(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base) ?? 'upload.csv';

        return mb_substr(trim($base, '._-') ?: 'upload.csv', 0, 120);
    }
}
