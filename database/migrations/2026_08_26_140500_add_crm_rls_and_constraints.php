<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL-only hardening for the CRM tables, following the exact same
 * pattern and rationale as
 * 2026_08_26_130200_add_organisation_rls_and_constraints.php (see that
 * file for the full write-up). Summary:
 *
 *   - This application connects to Postgres with a single trusted,
 *     BYPASSRLS server-side role, so RLS never applies to this
 *     application's own queries. App\Policies\* (enforced via
 *     $this->authorize() in every CRM controller) is the authoritative
 *     access-control layer for every request this application handles.
 *   - RLS is enabled + forced anyway, as a default-deny safety net for a
 *     future lower-privileged Supabase role (e.g. `anon`/`authenticated`
 *     via a client-side SDK or PostgREST) that does not exist yet. No
 *     permissive policy is defined because there is no such role to
 *     write one for today.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_status_check CHECK (status IN ('active', 'inactive'))");

        DB::statement("ALTER TABLE contacts ADD CONSTRAINT contacts_status_check CHECK (status IN ('active', 'inactive'))");

        DB::statement("ALTER TABLE leads ADD CONSTRAINT leads_status_check CHECK (status IN ('new', 'contacted', 'qualified', 'disqualified', 'converted'))");
        DB::statement("ALTER TABLE leads ADD CONSTRAINT leads_priority_check CHECK (priority IN ('low', 'medium', 'high'))");
        DB::statement('ALTER TABLE leads ADD CONSTRAINT leads_estimated_value_non_negative_check CHECK (estimated_value IS NULL OR estimated_value >= 0)');

        DB::statement("ALTER TABLE opportunities ADD CONSTRAINT opportunities_stage_check CHECK (stage IN ('qualification', 'proposal', 'negotiation', 'closed_won', 'closed_lost'))");
        DB::statement('ALTER TABLE opportunities ADD CONSTRAINT opportunities_value_non_negative_check CHECK (value IS NULL OR value >= 0)');
        DB::statement('ALTER TABLE opportunities ADD CONSTRAINT opportunities_probability_range_check CHECK (probability IS NULL OR (probability >= 0 AND probability <= 100))');

        DB::statement("ALTER TABLE activities ADD CONSTRAINT activities_type_check CHECK (type IN ('call', 'email', 'whatsapp', 'meeting', 'note', 'follow_up', 'proposal', 'other'))");

        foreach (['organizations', 'contacts', 'leads', 'opportunities', 'activities'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['organizations', 'contacts', 'leads', 'opportunities', 'activities'] as $table) {
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }

        DB::statement('ALTER TABLE organizations DROP CONSTRAINT IF EXISTS organizations_status_check');
        DB::statement('ALTER TABLE contacts DROP CONSTRAINT IF EXISTS contacts_status_check');
        DB::statement('ALTER TABLE leads DROP CONSTRAINT IF EXISTS leads_status_check');
        DB::statement('ALTER TABLE leads DROP CONSTRAINT IF EXISTS leads_priority_check');
        DB::statement('ALTER TABLE leads DROP CONSTRAINT IF EXISTS leads_estimated_value_non_negative_check');
        DB::statement('ALTER TABLE opportunities DROP CONSTRAINT IF EXISTS opportunities_stage_check');
        DB::statement('ALTER TABLE opportunities DROP CONSTRAINT IF EXISTS opportunities_value_non_negative_check');
        DB::statement('ALTER TABLE opportunities DROP CONSTRAINT IF EXISTS opportunities_probability_range_check');
        DB::statement('ALTER TABLE activities DROP CONSTRAINT IF EXISTS activities_type_check');
    }
};
