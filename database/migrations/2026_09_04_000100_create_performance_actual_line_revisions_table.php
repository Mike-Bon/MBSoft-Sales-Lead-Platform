<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fiscal Performance Data Entry & Import UI (additive).
 *
 * An immutable change log for OPERATIONAL actual lines. Operational
 * revenue/units are performance records, so every create or value change
 * — whether from a confirmed bulk CSV import or a manual correction —
 * writes one row here answering: which reporting unit / fiscal month
 * changed, the previous and new revenue/units, who changed it, when,
 * through which channel, and (bulk) which import batch (so the uploaded
 * file's SHA-256 is traceable), plus an optional reason for a manual
 * correction.
 *
 * Rows are never updated or deleted (no updated_at). The FK to the
 * actual line is nullOnDelete and the key dimensions are denormalised so
 * the history stays self-describing regardless. Nothing here touches CRM
 * tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_actual_line_revisions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('performance_actual_line_id')->nullable()
                ->constrained('performance_actual_lines')->nullOnDelete();

            // Denormalised key dimensions — durable even if the line is gone.
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedTinyInteger('period_month'); // fiscal ordinal 1-12 (1 = December)
            $table->foreignId('team_id')->constrained('teams')->restrictOnDelete();
            $table->foreignId('reporting_unit_id')->constrained('reporting_units')->restrictOnDelete();

            // null previous_* => the line was created by this revision.
            // A null *_units is a real value ("units not reported"), never 0.
            $table->decimal('previous_revenue', 14, 2)->nullable();
            $table->decimal('previous_units', 16, 2)->nullable();
            $table->decimal('new_revenue', 14, 2);
            $table->decimal('new_units', 16, 2)->nullable();

            $table->string('change_type');  // 'created' | 'updated'
            $table->string('channel');      // 'csv_import' | 'manual_entry'

            $table->foreignId('performance_import_id')->nullable()
                ->constrained('performance_imports')->nullOnDelete();
            // Nullable: an operator-run CLI `performance:import-actuals`
            // without `--as` is unattributed. Every web write sets it.
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('reason', 500)->nullable(); // required for a manual change of an existing value

            $table->timestamp('created_at')->nullable(); // immutable: created_at only, no updated_at

            $table->index(['fiscal_year', 'reporting_unit_id', 'period_month'], 'perf_actual_rev_key_idx');
            $table->index('performance_import_id');
            $table->index('changed_by');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_actual_line_revisions');
    }
};
