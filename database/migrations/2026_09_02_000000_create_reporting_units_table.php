<?php

use App\Enums\ReportingUnitStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FY2026 Fiscal Performance extension (additive).
 *
 * A reporting unit is an internal branch / concession / reporting
 * location that belongs to exactly one Team. It is the "branch /
 * account / location" level of the corporate budget workbook (TABUN,
 * GAISANO SOUTH, E MALL, METRO AYALA CEBU, …).
 *
 * It is DELIBERATELY NOT an Organization: Organizations are external
 * companies/customers/prospects (unique name, industry, website) and
 * feed leads, opportunities, Cost-to-Serve and V2.4 CRM duplicate
 * detection — none of which a seller's own retail location belongs in.
 *
 * `code` is the stable import identity. Uniqueness is (team_id, code):
 * two teams may legitimately have units with the same short code, but a
 * team never has two units with the same code. Alias resolution
 * (e.g. "E MALL" vs "E-MALL") happens when the import CSV is prepared —
 * the CSV always carries the canonical code — so no silent fuzzy match
 * ever occurs at import time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reporting_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->restrictOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('status')->default(ReportingUnitStatus::Active->value);
            $table->integer('sort_order')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'code']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reporting_units');
    }
};
