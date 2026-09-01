<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fiscal Performance Data Entry & Import UI (additive).
 *
 * The web "Import Actuals" flow needs performance_imports to double as a
 * short-lived STAGED PREVIEW record (upload → validate → preview →
 * confirm), exactly like App\Models\ProspectLeadProposal does for the
 * Market Intelligence review flow. All columns are additive and nullable
 * / defaulted — the existing CLI importer and the FY2026 data are
 * untouched.
 *
 * Two distinct integrity concepts are kept SEPARATE:
 *   - file_sha256  : identity of the exact uploaded CSV bytes (computed
 *                    before the temp file is discarded; never a
 *                    reconstruction of parsed data)
 *   - preview_fingerprint : a confirmation token over the reviewed,
 *                    parsed payload — binds one confirm POST to one
 *                    reviewed preview (in App\Models\PerformanceImport)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performance_imports', function (Blueprint $table) {
            // 'csv_import' (CLI or web upload) | 'manual_entry'
            $table->string('channel')->default('csv_import')->after('type');

            // Uploaded-source-file evidence (web upload only).
            $table->string('original_filename')->nullable()->after('source_filename');
            $table->string('file_sha256', 64)->nullable()->after('original_filename');
            $table->unsignedBigInteger('file_size_bytes')->nullable()->after('file_sha256');
            $table->unsignedInteger('data_row_count')->nullable()->after('file_size_bytes');

            // Staged-preview payload: resolved ids + parsed numbers + a
            // per-row create/update/unchanged classification and the
            // current values. NEVER raw CSV cell text.
            $table->longText('preview_payload')->nullable()->after('summary');
            $table->string('preview_fingerprint', 64)->nullable()->after('preview_payload');
            $table->timestamp('preview_expires_at')->nullable()->after('preview_fingerprint');

            $table->foreignId('confirmed_by')->nullable()->after('imported_by')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable()->after('completed_at');

            $table->index(['status', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::table('performance_imports', function (Blueprint $table) {
            $table->dropIndex(['status', 'channel']);
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn([
                'channel', 'original_filename', 'file_sha256', 'file_size_bytes',
                'data_row_count', 'preview_payload', 'preview_fingerprint',
                'preview_expires_at', 'confirmed_at',
            ]);
        });
    }
};
