<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12A: a minimal, generic key/value application-settings store —
 * no equivalent existed before this phase (the only prior precedent,
 * config('services.workflows.*.enabled'), is env-driven and requires a
 * deployment to change, which cannot satisfy "the Manager toggles this
 * live, from the UI, and it persists"). Deliberately generic (a `key`/
 * `value` table, not a `cost_to_serve_settings` table) so a future
 * feature flag reuses this same mechanism rather than each phase
 * inventing its own — the minimum viable persisted-setting
 * infrastructure, not a full settings-management system.
 *
 * RLS follows the same default-deny pattern as every other table in
 * this codebase (ENABLE + FORCE, no policies) — the application's own
 * role has BYPASSRLS; App\Models\Setting's own accessors are the actual
 * authorization boundary (Setting::setValue() is only ever called from
 * CostToServeAccessService, itself only reachable by a Manager).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE settings ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE settings FORCE ROW LEVEL SECURITY');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE settings DISABLE ROW LEVEL SECURITY');
        }

        Schema::dropIfExists('settings');
    }
};
