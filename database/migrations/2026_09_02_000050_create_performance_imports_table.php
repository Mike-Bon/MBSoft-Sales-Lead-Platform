<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FY2026 Fiscal Performance extension (additive).
 *
 * One row per operational-performance import batch (plan or actuals).
 * Gives every performance_plan_line / performance_actual_line a
 * traceable provenance ("which file, when, by whom, how many rows
 * accepted vs rejected") and makes a re-import auditable rather than
 * silent. Does NOT store the raw workbook/CSV blob — only the metadata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_imports', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // 'plan' | 'actual'
            $table->string('source_filename')->nullable();
            $table->unsignedSmallInteger('fiscal_year')->nullable();
            $table->string('status')->default('validating'); // validating | completed | failed
            $table->unsignedInteger('accepted_rows')->default(0);
            $table->unsignedInteger('rejected_rows')->default(0);
            $table->boolean('dry_run')->default(false);
            $table->text('summary')->nullable(); // a short, safe validation-outcome note — never raw file content
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('status');
            $table->index('fiscal_year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_imports');
    }
};
