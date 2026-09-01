<?php

namespace Tests\Feature\Performance;

use App\Enums\PerformanceImportChannel;
use App\Enums\PerformanceImportStatus;
use App\Models\PerformanceActualLine;
use App\Models\PerformanceActualLineRevision;
use App\Models\PerformanceImport;
use App\Models\ReportingUnit;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class FiscalActualImportUiTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Team $cec;

    private ReportingUnit $tabun;

    private ReportingUnit $gsouth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->manager()->create();
        $this->cec = Team::factory()->create(['code' => 'CEC', 'name' => 'CEC Team']);
        $this->tabun = ReportingUnit::factory()->for($this->cec)->create(['code' => 'CEC_TABOAN', 'name' => 'TABOAN']);
        $this->gsouth = ReportingUnit::factory()->for($this->cec)->create(['code' => 'CEC_GS', 'name' => 'GAISANO SOUTH']);
    }

    private function upload(string $body, string $name = 'actuals.csv'): TestResponse
    {
        return $this->actingAs($this->manager)->post('/performance/fiscal/actuals/import', [
            'file' => UploadedFile::fake()->createWithContent($name, $body),
        ]);
    }

    private const HEADER = "fiscal_year,period_month,team_code,reporting_unit_code,actual_units,actual_revenue\n";

    // ── upload → preview (no write) ──────────────────────────────

    public function test_a_valid_upload_creates_a_previewing_batch_and_writes_nothing(): void
    {
        $res = $this->upload(self::HEADER."2026,1,CEC,CEC_TABOAN,,14639.06\n2026,1,CEC,CEC_GS,10.5,21972.99\n");

        $import = PerformanceImport::sole();
        $res->assertRedirect('/performance/fiscal/actuals/import/'.$import->id);

        $this->assertSame(PerformanceImportStatus::Previewing, $import->status);
        $this->assertSame(PerformanceImportChannel::CsvImport, $import->channel);
        $this->assertSame(0, PerformanceActualLine::count());
        $this->assertSame(0, PerformanceActualLineRevision::count());
        $this->assertSame(2, $import->data_row_count);
        $this->assertNotNull($import->file_sha256);
        $this->assertNotNull($import->preview_fingerprint);

        // payload carries resolved ids + parsed numbers, never raw cell text
        $this->assertArrayHasKey('rows', $import->preview_payload);
        $this->assertSame('created', $import->preview_payload['rows'][0]['change']);
    }

    public function test_the_preview_page_shows_current_to_new_for_an_update(): void
    {
        PerformanceActualLine::factory()->create([
            'fiscal_year' => 2026, 'period_month' => 1, 'team_id' => $this->cec->id,
            'reporting_unit_id' => $this->tabun->id, 'actual_revenue' => 100, 'actual_units' => null,
        ]);

        $this->upload(self::HEADER."2026,1,CEC,CEC_TABOAN,,999\n");
        $import = PerformanceImport::sole();

        $this->actingAs($this->manager)->get('/performance/fiscal/actuals/import/'.$import->id)
            ->assertOk()
            ->assertSee('UPDATE')
            ->assertSee('TABOAN')
            ->assertSee('1 change');
    }

    // ── validation errors ───────────────────────────────────────

    public function test_an_invalid_file_is_rejected_with_row_numbered_errors_and_no_preview_rows(): void
    {
        $this->upload(self::HEADER
            ."2026,1,CEC,CEC_TABOAN,,1000\n"
            ."2026,13,CEC,CEC_TABOAN,,1000\n"        // bad month
            ."2026,1,CEC,NOPE,,1000\n"               // unknown unit
            ."2026,1,CEC,CEC_TABOAN,,=SUM(A1:A2)\n"  // formula
            ."2026,1,CEC,CEC_TABOAN,,-5\n"           // negative
            ."2026,1,CEC,CEC_TABOAN,,1e6\n");        // scientific

        $import = PerformanceImport::sole();
        $this->assertSame(PerformanceImportStatus::Failed, $import->status);
        $this->assertSame(0, PerformanceActualLine::count());

        $this->actingAs($this->manager)->get('/performance/fiscal/actuals/import/'.$import->id)
            ->assertOk()
            ->assertSee('line 3')
            ->assertSee('line 4')
            ->assertSee('=SUM(A1:A2)')
            ->assertSee('-5')
            ->assertSee('1e6')
            ->assertDontSee('Confirm &amp; import', false);
    }

    public function test_a_units_value_without_a_revenue_value_is_rejected(): void
    {
        $this->upload(self::HEADER."2026,1,CEC,CEC_TABOAN,10,\n");
        $this->assertSame(PerformanceImportStatus::Failed, PerformanceImport::sole()->status);
    }

    public function test_a_row_with_both_value_cells_blank_is_skipped_not_an_error(): void
    {
        $this->upload(self::HEADER
            ."2026,1,CEC,CEC_TABOAN,,5000\n"
            ."2026,1,CEC,CEC_GS,,\n");   // not reported — skipped

        $import = PerformanceImport::sole();
        $this->assertSame(PerformanceImportStatus::Previewing, $import->status);
        $this->assertSame(1, $import->data_row_count);
    }

    public function test_a_template_with_no_values_filled_in_is_rejected(): void
    {
        $this->upload(self::HEADER."2026,1,CEC,CEC_TABOAN,,\n2026,1,CEC,CEC_GS,,\n");
        $this->assertSame(PerformanceImportStatus::Failed, PerformanceImport::sole()->status);
    }

    public function test_an_oversized_or_non_csv_upload_is_refused(): void
    {
        $this->actingAs($this->manager)->post('/performance/fiscal/actuals/import', [
            'file' => UploadedFile::fake()->create('big.csv', 2048), // 2 MB > 512 KB cap
        ])->assertSessionHasErrors('file');

        $this->actingAs($this->manager)->post('/performance/fiscal/actuals/import', [
            'file' => UploadedFile::fake()->create('sheet.xlsx', 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ])->assertSessionHasErrors('file');

        $this->assertSame(0, PerformanceImport::count());
    }

    // ── confirm ─────────────────────────────────────────────────

    public function test_confirm_writes_the_expected_lines_and_revisions(): void
    {
        $this->upload(self::HEADER
            ."2026,1,CEC,CEC_TABOAN,,14639.06\n"
            ."2026,2,CEC,CEC_GS,0.25,0\n");     // fractional units + a genuine reported zero revenue
        $import = PerformanceImport::sole();

        $this->actingAs($this->manager)->post("/performance/fiscal/actuals/import/{$import->id}/confirm", [
            'fingerprint' => $import->preview_fingerprint,
        ])->assertRedirect('/performance/fiscal/actuals');

        $this->assertSame(2, PerformanceActualLine::count());
        $this->assertSame(2, PerformanceActualLineRevision::count());

        $tabun = PerformanceActualLine::where('reporting_unit_id', $this->tabun->id)->sole();
        $this->assertSame('14639.06', $tabun->actual_revenue);
        $this->assertNull($tabun->actual_units);   // blank units stayed NULL

        $gs = PerformanceActualLine::where('reporting_unit_id', $this->gsouth->id)->sole();
        $this->assertSame('0.00', $gs->actual_revenue);  // reported zero
        $this->assertSame('0.25', $gs->actual_units);     // fractional units preserved

        $import->refresh();
        $this->assertSame(PerformanceImportStatus::Completed, $import->status);
        $this->assertSame($this->manager->id, $import->confirmed_by);
        $rev = PerformanceActualLineRevision::where('reporting_unit_id', $this->tabun->id)->sole();
        $this->assertSame($import->id, $rev->performance_import_id);  // file SHA-256 traceable
        $this->assertNull($rev->previous_revenue);
        $this->assertSame('14639.06', $rev->new_revenue);
    }

    public function test_confirm_updates_an_existing_line_without_duplicating_it(): void
    {
        PerformanceActualLine::factory()->create([
            'fiscal_year' => 2026, 'period_month' => 1, 'team_id' => $this->cec->id,
            'reporting_unit_id' => $this->tabun->id, 'actual_revenue' => 100, 'actual_units' => 5,
        ]);
        $this->upload(self::HEADER."2026,1,CEC,CEC_TABOAN,7,250\n");
        $import = PerformanceImport::sole();

        $this->actingAs($this->manager)->post("/performance/fiscal/actuals/import/{$import->id}/confirm", [
            'fingerprint' => $import->preview_fingerprint,
        ]);

        $this->assertSame(1, PerformanceActualLine::count());
        $line = PerformanceActualLine::sole();
        $this->assertSame('250.00', $line->actual_revenue);
        $rev = PerformanceActualLineRevision::sole();
        $this->assertSame('updated', $rev->change_type->value);
        $this->assertSame('100.00', $rev->previous_revenue);
        $this->assertSame('250.00', $rev->new_revenue);
    }

    public function test_a_second_confirm_of_the_same_preview_is_a_no_op(): void
    {
        $this->upload(self::HEADER."2026,1,CEC,CEC_TABOAN,,500\n");
        $import = PerformanceImport::sole();
        $fp = $import->preview_fingerprint;

        $this->actingAs($this->manager)->post("/performance/fiscal/actuals/import/{$import->id}/confirm", ['fingerprint' => $fp]);
        $this->actingAs($this->manager)->post("/performance/fiscal/actuals/import/{$import->id}/confirm", ['fingerprint' => $fp])
            ->assertRedirect('/performance/fiscal/actuals');

        $this->assertSame(1, PerformanceActualLine::count());
        $this->assertSame(1, PerformanceActualLineRevision::count());
    }

    public function test_a_wrong_fingerprint_is_rejected(): void
    {
        $this->upload(self::HEADER."2026,1,CEC,CEC_TABOAN,,500\n");
        $import = PerformanceImport::sole();

        $this->actingAs($this->manager)->post("/performance/fiscal/actuals/import/{$import->id}/confirm", [
            'fingerprint' => str_repeat('0', 64),
        ])->assertSessionHas('import_error');

        $this->assertSame(0, PerformanceActualLine::count());
    }

    public function test_an_expired_preview_cannot_be_confirmed(): void
    {
        $this->upload(self::HEADER."2026,1,CEC,CEC_TABOAN,,500\n");
        $import = PerformanceImport::sole();
        $fp = $import->preview_fingerprint;
        $import->forceFill(['preview_expires_at' => Carbon::now()->subMinute()])->save();

        $this->actingAs($this->manager)->post("/performance/fiscal/actuals/import/{$import->id}/confirm", ['fingerprint' => $fp])
            ->assertSessionHas('import_error');
        $this->assertSame(0, PerformanceActualLine::count());
    }

    public function test_a_stale_preview_is_regenerated_when_the_data_changed_underneath(): void
    {
        $this->upload(self::HEADER."2026,1,CEC,CEC_TABOAN,,500\n");
        $import = PerformanceImport::sole();
        $fp = $import->preview_fingerprint;

        // Someone else records an actual for the same cell after the preview.
        PerformanceActualLine::factory()->create([
            'fiscal_year' => 2026, 'period_month' => 1, 'team_id' => $this->cec->id,
            'reporting_unit_id' => $this->tabun->id, 'actual_revenue' => 111,
        ]);

        $this->actingAs($this->manager)->post("/performance/fiscal/actuals/import/{$import->id}/confirm", ['fingerprint' => $fp])
            ->assertSessionHas('import_error');

        $import->refresh();
        $this->assertSame(PerformanceImportStatus::Previewing, $import->status); // still awaiting confirmation
        $this->assertSame('updated', $import->preview_payload['rows'][0]['change']); // reclassified
        $this->assertNotSame($fp, $import->preview_fingerprint);                    // fresh token
        $this->assertSame('111.00', PerformanceActualLine::sole()->actual_revenue); // not overwritten
    }

    public function test_cancel_discards_the_preview(): void
    {
        $this->upload(self::HEADER."2026,1,CEC,CEC_TABOAN,,500\n");
        $import = PerformanceImport::sole();

        $this->actingAs($this->manager)->post("/performance/fiscal/actuals/import/{$import->id}/cancel")
            ->assertRedirect('/performance/fiscal/actuals');

        $this->assertSame(PerformanceImportStatus::Cancelled, $import->fresh()->status);
        $this->assertSame(0, PerformanceActualLine::count());
    }

    // ── cross-actor / CSRF ──────────────────────────────────────

    public function test_another_manager_cannot_view_or_confirm_someone_elses_preview(): void
    {
        $this->upload(self::HEADER."2026,1,CEC,CEC_TABOAN,,500\n");
        $import = PerformanceImport::sole();
        $other = User::factory()->manager()->create();

        $this->actingAs($other)->get('/performance/fiscal/actuals/import/'.$import->id)->assertForbidden();
        $this->actingAs($other)->post("/performance/fiscal/actuals/import/{$import->id}/confirm", [
            'fingerprint' => $import->preview_fingerprint,
        ])->assertForbidden();
    }

    public function test_a_team_head_cannot_confirm_a_preview(): void
    {
        $this->upload(self::HEADER."2026,1,CEC,CEC_TABOAN,,500\n");
        $import = PerformanceImport::sole();

        $this->actingAs(User::factory()->teamHead($this->cec)->create())
            ->post("/performance/fiscal/actuals/import/{$import->id}/confirm", ['fingerprint' => $import->preview_fingerprint])
            ->assertForbidden();
        $this->assertSame(0, PerformanceActualLine::count());
    }

    public function test_the_actuals_routes_sit_behind_the_web_and_auth_middleware(): void
    {
        $route = app('router')->getRoutes()->getByName('performance.fiscal.actuals.import.confirm');

        $this->assertContains('web', $route->gatherMiddleware());   // CSRF + session
        $this->assertContains('auth', $route->gatherMiddleware());
    }

    public function test_no_crm_records_are_ever_created(): void
    {
        $this->upload(self::HEADER."2026,1,CEC,CEC_TABOAN,,500\n");
        $import = PerformanceImport::sole();
        $this->actingAs($this->manager)->post("/performance/fiscal/actuals/import/{$import->id}/confirm", [
            'fingerprint' => $import->preview_fingerprint,
        ]);

        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('opportunities', 0);
        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('activities', 0);
    }
}
