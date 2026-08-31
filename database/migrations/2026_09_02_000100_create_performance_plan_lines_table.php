<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FY2026 Fiscal Performance extension (additive).
 *
 * The monthly PHASED budget from the corporate workbook's "Budget"
 * sheet: one row per (fiscal_year, fiscal-month ordinal, team,
 * reporting unit). `period_month` is the FISCAL ordinal — 1 = December,
 * 2 = January, … 12 = November.
 *
 * `reporting_unit_id` is nullable so a team-level (or unallocated)
 * budget line can be stored where the workbook does not phase a team's
 * budget down to branches. PostgreSQL treats NULLs as distinct in a
 * plain composite UNIQUE, which would let two team-level lines for the
 * same (year, month, team) slip through, so the idempotency key is
 * enforced with TWO PARTIAL unique indexes instead:
 *
 *   - (fiscal_year, period_month, team_id, reporting_unit_id)
 *       WHERE reporting_unit_id IS NOT NULL   -- branch-level lines
 *   - (fiscal_year, period_month, team_id)
 *       WHERE reporting_unit_id IS NULL       -- the one team-level line
 *
 * Both PostgreSQL and SQLite support partial unique indexes. The
 * importer also upserts on the same business key in the application
 * layer; these indexes are the backstop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_plan_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedTinyInteger('period_month'); // fiscal ordinal 1-12 (1 = December)
            $table->foreignId('team_id')->constrained('teams')->restrictOnDelete();
            $table->foreignId('reporting_unit_id')->nullable()->constrained('reporting_units')->restrictOnDelete();

            // "units" is a weighted / fractional business measure in the
            // corporate budget (e.g. 278.40 for a branch month), never an
            // integer count — so it is stored as decimal(16,2).
            $table->decimal('target_units', 16, 2)->nullable();
            $table->decimal('target_revenue', 14, 2);
            $table->string('currency', 3)->default('PHP');

            $table->string('source')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->foreignId('performance_import_id')->nullable()->constrained('performance_imports')->nullOnDelete();

            $table->timestamps();

            $table->index(['fiscal_year', 'team_id']);
            $table->index(['fiscal_year', 'period_month']);
        });

        DB::statement('CREATE UNIQUE INDEX performance_plan_lines_unit_unique ON performance_plan_lines (fiscal_year, period_month, team_id, reporting_unit_id) WHERE reporting_unit_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX performance_plan_lines_team_unique ON performance_plan_lines (fiscal_year, period_month, team_id) WHERE reporting_unit_id IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_plan_lines');
    }
};
