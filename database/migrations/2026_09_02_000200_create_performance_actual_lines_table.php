<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FY2026 Fiscal Performance extension (additive).
 *
 * The monthly OPERATIONAL ACTUALS from the corporate workbook's per-team
 * sheets: one row per (fiscal_year, fiscal-month ordinal, team,
 * reporting unit). These are the authoritative operational revenue/unit
 * figures — they are NEVER converted into CRM Opportunities, and CRM
 * Closed-Won pipeline performance is left completely alone.
 *
 * `actual_units` is decimal(16,2), not an integer: the corporate budget
 * treats "units" as a weighted / fractional business measure (e.g. a
 * branch month of 278.40), so the stored value must keep that precision.
 * `actual_revenue` follows the app's money convention, decimal(14,2)
 * (same as targets.target_amount).
 *
 * `reporting_unit_id` is NOT NULL here (every actual comes from a branch
 * row), so a plain composite UNIQUE
 * (fiscal_year, period_month, team_id, reporting_unit_id) is safe with
 * no NULL semantics to worry about, and is the idempotency key: a
 * repeated import of the same FY/month/team/unit UPDATES the row.
 *
 * `source` / `imported_at` / `performance_import_id` keep every actual
 * line traceable to the import batch that wrote it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_actual_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedTinyInteger('period_month'); // fiscal ordinal 1-12 (1 = December)
            $table->foreignId('team_id')->constrained('teams')->restrictOnDelete();
            $table->foreignId('reporting_unit_id')->constrained('reporting_units')->restrictOnDelete();

            $table->decimal('actual_units', 16, 2)->nullable();
            $table->decimal('actual_revenue', 14, 2);
            $table->string('currency', 3)->default('PHP');

            $table->string('source')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->foreignId('performance_import_id')->nullable()->constrained('performance_imports')->nullOnDelete();

            $table->timestamps();

            $table->unique(['fiscal_year', 'period_month', 'team_id', 'reporting_unit_id'], 'performance_actual_lines_business_key');
            $table->index(['fiscal_year', 'team_id']);
            $table->index(['fiscal_year', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_actual_lines');
    }
};
