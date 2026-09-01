<?php

namespace Tests\Feature\Performance;

use App\Enums\ActualLineChangeType;
use App\Enums\PerformanceImportChannel;
use App\Enums\PerformanceImportStatus;
use App\Enums\PerformanceImportType;
use App\Models\PerformanceActualLine;
use App\Models\PerformanceActualLineRevision;
use App\Models\PerformanceImport;
use App\Models\ReportingUnit;
use App\Models\Team;
use App\Models\User;
use App\Services\Performance\AuthoritativeActualLineWriter;
use App\Services\Performance\PerformanceImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActualLineRevisionAndWriterTest extends TestCase
{
    use RefreshDatabase;

    private Team $cec;

    private ReportingUnit $tabun;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cec = Team::factory()->create(['code' => 'CEC', 'name' => 'CEC Team']);
        $this->tabun = ReportingUnit::factory()->for($this->cec)->create(['code' => 'CEC_TABOAN', 'name' => 'TABOAN']);
    }

    private function writer(): AuthoritativeActualLineWriter
    {
        return app(AuthoritativeActualLineWriter::class);
    }

    public function test_the_writer_creates_updates_and_no_ops(): void
    {
        $mgr = User::factory()->manager()->create();

        $r1 = $this->writer()->write(2026, 1, $this->tabun, 100.0, null, PerformanceImportChannel::ManualEntry, $mgr);
        $this->assertSame(ActualLineChangeType::Created, $r1->changeType);
        $this->assertNull($r1->previousRevenue);

        $r2 = $this->writer()->write(2026, 1, $this->tabun, 100.0, null, PerformanceImportChannel::ManualEntry, $mgr);
        $this->assertSame(ActualLineChangeType::Unchanged, $r2->changeType);
        $this->assertNull($r2->revision);

        $r3 = $this->writer()->write(2026, 1, $this->tabun, 250.5, 4.25, PerformanceImportChannel::ManualEntry, $mgr, reason: 'fix');
        $this->assertSame(ActualLineChangeType::Updated, $r3->changeType);
        $this->assertSame(100.0, $r3->previousRevenue);

        $this->assertSame(1, PerformanceActualLine::count());
        $this->assertSame(2, PerformanceActualLineRevision::count()); // no revision for the no-op
        $this->assertSame('250.50', PerformanceActualLine::sole()->actual_revenue);
    }

    public function test_the_writer_never_coerces_a_blank_unit_to_zero(): void
    {
        $mgr = User::factory()->manager()->create();
        $this->writer()->write(2026, 1, $this->tabun, 100.0, null, PerformanceImportChannel::ManualEntry, $mgr);
        $this->assertNull(PerformanceActualLine::sole()->actual_units);

        // an explicit 0 is a different, reported value
        $r = $this->writer()->write(2026, 1, $this->tabun, 100.0, 0.0, PerformanceImportChannel::ManualEntry, $mgr, reason: 'z');
        $this->assertSame(ActualLineChangeType::Updated, $r->changeType);
        $this->assertSame('0.00', PerformanceActualLine::sole()->actual_units);
    }

    public function test_a_csv_import_revision_links_the_batch_so_the_file_hash_is_traceable(): void
    {
        $mgr = User::factory()->manager()->create();
        $path = sys_get_temp_dir().'/rev.csv';
        file_put_contents($path, "fiscal_year,period_month,team_code,reporting_unit_code,actual_units,actual_revenue\n2026,1,CEC,CEC_TABOAN,,777\n");

        app(PerformanceImportService::class)->import(PerformanceImportType::Actual, $path, $mgr);

        $rev = PerformanceActualLineRevision::sole();
        $this->assertSame(PerformanceImportChannel::CsvImport, $rev->channel);
        $this->assertNotNull($rev->performance_import_id);
        $this->assertSame($mgr->id, $rev->changed_by);
        $this->assertSame('777.00', $rev->new_revenue);
        $this->assertNotNull($rev->import);
    }

    public function test_a_cli_import_without_an_actor_records_an_unattributed_revision(): void
    {
        $path = sys_get_temp_dir().'/rev2.csv';
        file_put_contents($path, "fiscal_year,period_month,team_code,reporting_unit_code,actual_units,actual_revenue\n2026,1,CEC,CEC_TABOAN,,777\n");

        app(PerformanceImportService::class)->import(PerformanceImportType::Actual, $path); // no --as

        $rev = PerformanceActualLineRevision::sole();
        $this->assertNull($rev->changed_by);
        $this->assertSame('created', $rev->change_type->value);
    }

    public function test_re_importing_an_identical_actuals_file_records_no_new_revision(): void
    {
        $mgr = User::factory()->manager()->create();
        $path = sys_get_temp_dir().'/rev3.csv';
        file_put_contents($path, "fiscal_year,period_month,team_code,reporting_unit_code,actual_units,actual_revenue\n2026,1,CEC,CEC_TABOAN,5,777\n");
        $svc = app(PerformanceImportService::class);

        $r1 = $svc->import(PerformanceImportType::Actual, $path, $mgr);
        $r2 = $svc->import(PerformanceImportType::Actual, $path, $mgr);

        $this->assertSame(['created' => 1, 'updated' => 0, 'unchanged' => 0], $r1->stats);
        $this->assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 1], $r2->stats);
        $this->assertSame(1, PerformanceActualLineRevision::count());
    }

    public function test_prune_command_only_removes_stale_previews_and_cancelled_rows(): void
    {
        $keptCompleted = PerformanceImport::factory()->create(['status' => PerformanceImportStatus::Completed, 'created_at' => now()->subYear()]);
        $keptFresh = PerformanceImport::factory()->create(['status' => PerformanceImportStatus::Previewing, 'created_at' => now()->subDay()]);
        $stalePreview = PerformanceImport::factory()->create(['status' => PerformanceImportStatus::Previewing, 'created_at' => now()->subDays(30)]);
        $staleCancelled = PerformanceImport::factory()->create(['status' => PerformanceImportStatus::Cancelled, 'created_at' => now()->subDays(30)]);

        $this->artisan('performance:prune-import-previews')->assertExitCode(0);

        $this->assertModelExists($keptCompleted);
        $this->assertModelExists($keptFresh);
        $this->assertModelMissing($stalePreview);
        $this->assertModelMissing($staleCancelled);
    }
}
