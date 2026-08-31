<?php

namespace Tests\Feature\Performance;

use App\Enums\PerformanceImportStatus;
use App\Enums\PerformanceImportType;
use App\Models\PerformanceActualLine;
use App\Models\PerformancePlanLine;
use App\Models\ReportingUnit;
use App\Models\Team;
use App\Models\User;
use App\Services\Performance\PerformanceImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PerformanceImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private Team $cec;

    private ReportingUnit $tabun;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cec = Team::factory()->create(['name' => 'CEC Team', 'code' => 'CEC']);
        $this->tabun = ReportingUnit::factory()->for($this->cec)->create(['code' => 'TABUN', 'name' => 'Tabunok']);
    }

    private function write(string $name, string $contents): string
    {
        $path = sys_get_temp_dir().'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }

    private function service(): PerformanceImportService
    {
        return app(PerformanceImportService::class);
    }

    public function test_a_clean_plan_file_imports_and_is_idempotent(): void
    {
        $csv = "fiscal_year,period_month,team_code,reporting_unit_code,target_units,target_revenue\n"
            ."2026,1,CEC,TABUN,100,800000.00\n"
            ."2026,2,CEC,TABUN,110,900000\n";
        $path = $this->write('plan_ok.csv', $csv);

        $r1 = $this->service()->import(PerformanceImportType::Plan, $path);
        $this->assertTrue($r1->ok);
        $this->assertTrue($r1->committed);
        $this->assertSame(2, $r1->acceptedRows);
        $this->assertSame(['created' => 2, 'updated' => 0], $r1->stats);
        $this->assertSame(PerformanceImportStatus::Completed, $r1->import->status);
        $this->assertSame(2, PerformancePlanLine::count());

        $dec = PerformancePlanLine::where(['fiscal_year' => 2026, 'period_month' => 1, 'team_id' => $this->cec->id, 'reporting_unit_id' => $this->tabun->id])->first();
        $this->assertSame('100.00', $dec->target_units);
        $this->assertSame('800000.00', $dec->target_revenue);
        $this->assertSame('plan_ok.csv', $dec->source);
        $this->assertNotNull($dec->imported_at);

        // Re-import updated numbers → UPDATE, never duplicate.
        $csv2 = "fiscal_year,period_month,team_code,reporting_unit_code,target_units,target_revenue\n"
            ."2026,1,CEC,TABUN,105,820000\n"
            ."2026,2,CEC,TABUN,110,900000\n";
        $r2 = $this->service()->import(PerformanceImportType::Plan, $this->write('plan_ok.csv', $csv2));
        $this->assertSame(['created' => 0, 'updated' => 2], $r2->stats);
        $this->assertSame(2, PerformancePlanLine::count());
        $this->assertSame('105.00', $dec->fresh()->target_units);
    }

    public function test_fractional_weighted_units_are_imported_without_rounding(): void
    {
        $csv = "fiscal_year,period_month,team_code,reporting_unit_code,target_units,target_revenue\n"
            ."2026,1,CEC,TABUN,278.4,28337.35\n"
            ."2026,2,CEC,TABUN,12.25,900000\n"
            ."2026,3,CEC,TABUN,0.50,100000\n"
            ."2026,4,CEC,TABUN,0,100000\n"
            ."2026,5,CEC,TABUN,,100000\n";
        $r = $this->service()->import(PerformanceImportType::Plan, $this->write('plan_frac.csv', $csv));

        $this->assertTrue($r->committed);
        $this->assertSame(5, $r->acceptedRows);

        $by = fn (int $m) => PerformancePlanLine::where(['fiscal_year' => 2026, 'period_month' => $m, 'reporting_unit_id' => $this->tabun->id])->value('target_units');
        $this->assertSame('278.40', $by(1));
        $this->assertSame('12.25', $by(2));
        $this->assertSame('0.50', $by(3));
        $this->assertSame('0.00', $by(4));   // explicit zero preserved
        $this->assertNull($by(5));            // blank stays NULL, never 0
    }

    public function test_a_blank_reporting_unit_code_creates_a_team_level_plan_line(): void
    {
        $csv = "fiscal_year,period_month,team_code,reporting_unit_code,target_units,target_revenue\n"
            ."2026,1,CEC,,,500000\n";
        $r = $this->service()->import(PerformanceImportType::Plan, $this->write('plan_team.csv', $csv));

        $this->assertTrue($r->committed);
        $line = PerformancePlanLine::first();
        $this->assertNull($line->reporting_unit_id);
        $this->assertNull($line->target_units);
        $this->assertSame('500000.00', $line->target_revenue);
    }

    public function test_a_single_bad_row_rejects_the_whole_file_and_writes_nothing(): void
    {
        $csv = "fiscal_year,period_month,team_code,reporting_unit_code,target_units,target_revenue\n"
            ."2026,1,CEC,TABUN,100,800000\n"
            ."2026,13,CEC,TABUN,100,800000\n"      // bad fiscal month
            ."2026,3,CEC,TABUN,100,900000\n";
        $r = $this->service()->import(PerformanceImportType::Plan, $this->write('plan_bad.csv', $csv));

        $this->assertFalse($r->ok);
        $this->assertFalse($r->committed);
        $this->assertSame(0, PerformancePlanLine::count());
        $this->assertStringContainsString('line 3', $r->errors[0]);
        $this->assertStringContainsString('fiscal ordinal 1-12', $r->errors[0]);
        $this->assertSame(PerformanceImportStatus::Failed, $r->import->status);
        $this->assertSame(0, $r->import->accepted_rows);
    }

    public function test_unknown_team_unknown_unit_and_wrong_team_unit_are_each_rejected_distinctly(): void
    {
        $other = Team::factory()->create(['code' => 'CBE']);
        ReportingUnit::factory()->for($other)->create(['code' => 'ESCARIO']);

        $csv = "fiscal_year,period_month,team_code,reporting_unit_code,actual_units,actual_revenue\n"
            ."2026,1,ZZZ,TABUN,10,1000\n"           // unknown team
            ."2026,1,CEC,NOPE,10,1000\n"            // unknown unit
            ."2026,1,CEC,ESCARIO,10,1000\n";        // unit of a different team
        $r = $this->service()->import(PerformanceImportType::Actual, $this->write('act_bad.csv', $csv));

        $this->assertFalse($r->ok);
        $this->assertStringContainsString('unknown team_code "ZZZ"', $r->errors[0]);
        $this->assertStringContainsString('unknown reporting_unit_code "NOPE"', $r->errors[1]);
        $this->assertStringContainsString('does not belong to team "CEC"', $r->errors[2]);
    }

    public function test_actuals_require_a_reporting_unit_code(): void
    {
        $csv = "fiscal_year,period_month,team_code,reporting_unit_code,actual_units,actual_revenue\n"
            ."2026,1,CEC,,10,1000\n";
        $r = $this->service()->import(PerformanceImportType::Actual, $this->write('act_nounit.csv', $csv));

        $this->assertFalse($r->ok);
        $this->assertStringContainsString('reporting_unit_code is required', $r->errors[0]);
    }

    public function test_malformed_and_negative_numbers_are_rejected(): void
    {
        $csv = "fiscal_year,period_month,team_code,reporting_unit_code,actual_units,actual_revenue\n"
            ."2026,1,CEC,TABUN,abc,1000\n"
            ."2026,2,CEC,TABUN,10,-5000\n"
            ."2026,3,CEC,TABUN,10,=SUM(A1:A9)\n";
        $r = $this->service()->import(PerformanceImportType::Actual, $this->write('act_nums.csv', $csv));

        $this->assertFalse($r->ok);
        $this->assertCount(3, $r->errors);
        $this->assertStringContainsString('actual_units "abc"', $r->errors[0]);
        $this->assertStringContainsString('actual_revenue "-5000"', $r->errors[1]);
        $this->assertStringContainsString('=SUM(A1:A9)', $r->errors[2]);
        $this->assertSame(0, PerformanceActualLine::count());
    }

    public function test_currency_symbols_and_thousands_separators_are_tolerated(): void
    {
        $csv = "fiscal_year,period_month,team_code,reporting_unit_code,actual_units,actual_revenue\n"
            ."2026,1,CEC,TABUN,\"1,250\",\"₱ 1,234,567.89\"\n";
        $r = $this->service()->import(PerformanceImportType::Actual, $this->write('act_fmt.csv', $csv));

        $this->assertTrue($r->committed);
        $line = PerformanceActualLine::first();
        $this->assertSame('1250.00', $line->actual_units);
        $this->assertSame('1234567.89', $line->actual_revenue);
    }

    public function test_duplicate_rows_inside_one_file_are_detected_and_the_file_is_rejected(): void
    {
        $csv = "fiscal_year,period_month,team_code,reporting_unit_code,target_units,target_revenue\n"
            ."2026,1,CEC,TABUN,100,800000\n"
            ."2026,1,CEC,TABUN,100,999999\n";
        $r = $this->service()->import(PerformanceImportType::Plan, $this->write('plan_dupe.csv', $csv));

        $this->assertFalse($r->ok);
        $this->assertStringContainsString('duplicate of line 2', $r->errors[0]);
        $this->assertSame(0, PerformancePlanLine::count());
    }

    public function test_a_missing_required_column_rejects_the_file(): void
    {
        $csv = "fiscal_year,period_month,team_code,target_revenue\n2026,1,CEC,800000\n";
        $r = $this->service()->import(PerformanceImportType::Plan, $this->write('plan_cols.csv', $csv));

        $this->assertFalse($r->ok);
        $this->assertStringContainsString('Missing required column', $r->errors[0]);
        $this->assertStringContainsString('reporting_unit_code', $r->errors[0]);
    }

    public function test_dry_run_validates_without_writing(): void
    {
        $csv = "fiscal_year,period_month,team_code,reporting_unit_code,target_units,target_revenue\n"
            ."2026,1,CEC,TABUN,100,800000\n";
        $r = $this->service()->import(PerformanceImportType::Plan, $this->write('plan_dry.csv', $csv), null, dryRun: true);

        $this->assertTrue($r->ok);
        $this->assertFalse($r->committed);
        $this->assertTrue($r->dryRun);
        $this->assertSame(1, $r->acceptedRows);
        $this->assertSame(0, PerformancePlanLine::count());
        $this->assertTrue($r->import->dry_run);
        $this->assertSame(PerformanceImportStatus::Completed, $r->import->status);
    }

    public function test_the_batch_row_records_provenance_and_the_importer(): void
    {
        $manager = User::factory()->manager()->create();
        $csv = "fiscal_year,period_month,team_code,reporting_unit_code,target_units,target_revenue\n"
            ."2026,1,CEC,TABUN,100,800000\n";
        $r = $this->service()->import(PerformanceImportType::Plan, $this->write('plan_prov.csv', $csv), $manager);

        $this->assertSame($manager->id, $r->import->imported_by);
        $this->assertSame('plan_prov.csv', $r->import->source_filename);
        $this->assertSame(2026, $r->import->fiscal_year);
        $this->assertNotNull($r->import->completed_at);
        $this->assertSame($r->import->id, PerformancePlanLine::first()->performance_import_id);
    }

    public function test_no_actuals_are_ever_written_as_opportunities(): void
    {
        $csv = "fiscal_year,period_month,team_code,reporting_unit_code,actual_units,actual_revenue\n"
            ."2026,1,CEC,TABUN,100,800000\n";
        $this->service()->import(PerformanceImportType::Actual, $this->write('act_noopp.csv', $csv));

        $this->assertDatabaseCount('opportunities', 0);
        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('activities', 0);
    }
}
