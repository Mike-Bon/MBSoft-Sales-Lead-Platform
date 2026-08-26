<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL-only hardening for `targets`, following the exact pattern
 * used in the two earlier RLS migrations (Phase 2's
 * add_organisation_rls_and_constraints and Phase 3's
 * add_crm_rls_and_constraints — see either for the full write-up on why
 * RLS is a default-deny safety net here rather than the authoritative
 * access-control layer, which remains App\Policies\TargetPolicy).
 *
 * Also adds two partial unique indexes as a database-level backstop for
 * STEP 6's "prevent duplicate active targets for the same owner/team,
 * target type, and period" — TargetService checks this proactively
 * before insert, but a unique index guarantees it can never be
 * violated by a race condition or a future code path that bypasses the
 * service.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE targets ADD CONSTRAINT targets_type_check CHECK (target_type IN ('manager', 'team', 'individual'))");
        DB::statement("ALTER TABLE targets ADD CONSTRAINT targets_period_type_check CHECK (period_type IN ('monthly', 'quarterly', 'annual'))");
        DB::statement("ALTER TABLE targets ADD CONSTRAINT targets_status_check CHECK (status IN ('active', 'inactive'))");
        DB::statement('ALTER TABLE targets ADD CONSTRAINT targets_amount_non_negative_check CHECK (target_amount >= 0)');
        DB::statement('ALTER TABLE targets ADD CONSTRAINT targets_period_order_check CHECK (period_end >= period_start)');

        // One active Manager/Individual target per owner per exact period.
        DB::statement("
            CREATE UNIQUE INDEX targets_unique_active_owner
            ON targets (target_type, owner_id, period_start, period_end)
            WHERE status = 'active' AND owner_id IS NOT NULL
        ");

        // One active Team target per team per exact period.
        DB::statement("
            CREATE UNIQUE INDEX targets_unique_active_team
            ON targets (target_type, team_id, period_start, period_end)
            WHERE status = 'active' AND team_id IS NOT NULL
        ");

        DB::statement('ALTER TABLE targets ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE targets FORCE ROW LEVEL SECURITY');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE targets DISABLE ROW LEVEL SECURITY');
        DB::statement('DROP INDEX IF EXISTS targets_unique_active_owner');
        DB::statement('DROP INDEX IF EXISTS targets_unique_active_team');
        DB::statement('ALTER TABLE targets DROP CONSTRAINT IF EXISTS targets_type_check');
        DB::statement('ALTER TABLE targets DROP CONSTRAINT IF EXISTS targets_period_type_check');
        DB::statement('ALTER TABLE targets DROP CONSTRAINT IF EXISTS targets_status_check');
        DB::statement('ALTER TABLE targets DROP CONSTRAINT IF EXISTS targets_amount_non_negative_check');
        DB::statement('ALTER TABLE targets DROP CONSTRAINT IF EXISTS targets_period_order_check');
    }
};
