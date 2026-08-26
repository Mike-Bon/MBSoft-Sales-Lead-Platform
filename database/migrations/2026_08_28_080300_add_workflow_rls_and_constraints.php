<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL-only hardening for the two new Phase 8 tables, following
 * the exact pattern used by every previous phase's RLS migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE workflow_executions ADD CONSTRAINT workflow_executions_workflow_check CHECK (workflow IN ('daily_follow_up_review', 'opportunity_attention_review', 'performance_exception_review'))");
        DB::statement("ALTER TABLE workflow_executions ADD CONSTRAINT workflow_executions_status_check CHECK (status IN ('pending', 'running', 'completed', 'failed'))");
        DB::statement("ALTER TABLE workflow_executions ADD CONSTRAINT workflow_executions_scope_type_check CHECK (scope_type IN ('organisation', 'team', 'individual'))");

        DB::statement("ALTER TABLE workflow_approvals ADD CONSTRAINT workflow_approvals_channel_check CHECK (channel IN ('email', 'whatsapp'))");
        DB::statement("ALTER TABLE workflow_approvals ADD CONSTRAINT workflow_approvals_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'expired'))");

        foreach (['workflow_executions', 'workflow_approvals'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['workflow_executions', 'workflow_approvals'] as $table) {
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }

        DB::statement('ALTER TABLE workflow_executions DROP CONSTRAINT IF EXISTS workflow_executions_workflow_check');
        DB::statement('ALTER TABLE workflow_executions DROP CONSTRAINT IF EXISTS workflow_executions_status_check');
        DB::statement('ALTER TABLE workflow_executions DROP CONSTRAINT IF EXISTS workflow_executions_scope_type_check');
        DB::statement('ALTER TABLE workflow_approvals DROP CONSTRAINT IF EXISTS workflow_approvals_channel_check');
        DB::statement('ALTER TABLE workflow_approvals DROP CONSTRAINT IF EXISTS workflow_approvals_status_check');
    }
};
