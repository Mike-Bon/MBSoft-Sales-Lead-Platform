<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL-only RLS default-deny safety net for agent_interactions,
 * following the exact pattern used by every previous phase's RLS
 * migration. No CHECK constraint is needed for `status` beyond what the
 * enum already guards in PHP — added anyway for defense in depth,
 * consistent with every other status column in this project.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE agent_interactions ADD CONSTRAINT agent_interactions_status_check CHECK (status IN ('completed', 'failed', 'limit_reached'))");

        DB::statement('ALTER TABLE agent_interactions ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE agent_interactions FORCE ROW LEVEL SECURITY');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE agent_interactions DISABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE agent_interactions DROP CONSTRAINT IF EXISTS agent_interactions_status_check');
    }
};
