<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL-only hardening for the organisational tables.
 *
 * ---------------------------------------------------------------------
 * Where authorization actually lives (read this before touching RLS)
 * ---------------------------------------------------------------------
 * This application does not use Supabase Auth and never exposes Supabase
 * directly to a browser or client-side SDK: every request is handled by
 * this Laravel application, which connects to Postgres with a single
 * trusted, server-side role (see DB_USERNAME in .env) that has the
 * BYPASSRLS attribute (verified: `SELECT rolbypassrls FROM pg_roles`).
 * That means Postgres Row Level Security never applies to this
 * application's own queries, by design and by Postgres semantics.
 *
 * THE AUTHORITATIVE ACCESS-CONTROL LAYER FOR THIS APPLICATION IS:
 *   - App\Policies\TeamPolicy / App\Policies\UserPolicy (server-side,
 *     enforced on every request via route `can:` middleware), plus
 *   - explicit team-scoped query constraints inside the controllers/
 *     services that back /teams and /users (defense in depth, not a
 *     substitute for the policy checks).
 *
 * RLS is enabled below anyway, as required by the project constitution,
 * as a default-deny safety net: if a future phase ever grants table
 * access to a lower-privileged Postgres role (e.g. Supabase's `anon` /
 * `authenticated` roles, used by PostgREST or a client-side SDK), that
 * role will see and change NOTHING on these tables unless a policy is
 * explicitly added for it later. No permissive policy is defined here
 * because no such role exists yet in this architecture — writing one now
 * would be unused, unverifiable security theater rather than a real
 * control.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            // SQLite (used for the automated test suite) has no RLS and no
            // ALTER TABLE ... ADD CONSTRAINT CHECK support in the form used
            // below. The equivalent rules are enforced at the application
            // layer for tests; see StoreUserRequest/UpdateUserRequest.
            return;
        }

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('manager', 'team_head', 'team_member'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_requires_team_check CHECK (role = 'manager' OR team_id IS NOT NULL)");
        DB::statement("ALTER TABLE teams ADD CONSTRAINT teams_status_check CHECK (status IN ('active', 'inactive'))");

        DB::statement('ALTER TABLE users ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE users FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE teams ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE teams FORCE ROW LEVEL SECURITY');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE users DISABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE teams DISABLE ROW LEVEL SECURITY');

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_requires_team_check');
        DB::statement('ALTER TABLE teams DROP CONSTRAINT IF EXISTS teams_status_check');
    }
};
