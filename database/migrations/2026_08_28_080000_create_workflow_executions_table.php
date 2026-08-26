<?php

use App\Enums\WorkflowStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STEP 13: one row per workflow run. `execution_key` is the idempotency
 * guard (STEP 12) — deterministically derived from
 * (workflow, scope_type, scope_id, run date) by WorkflowExecutionService,
 * so the same job dispatched or retried twice for the same day/scope
 * can never produce two executions (enforced by the unique index below,
 * not merely by application-level care).
 *
 * `user_id` is the scope OWNER — whose dashboard/insights this execution
 * belongs to (STEP 23/24: an Organisation-scope execution belongs to
 * the Manager who will see it; a Team-scope execution belongs to that
 * team's Head; an Individual-scope execution belongs to that one user).
 * It is never a "run as" identity with elevated access — every
 * analyzer still queries through the exact same authorization-scoped
 * services a real request from that user would use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_executions', function (Blueprint $table) {
            $table->id();

            $table->string('workflow');
            $table->string('trigger')->default('scheduled');
            $table->string('status')->default(WorkflowStatus::Pending->value);

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('scope_type');
            $table->foreignId('scope_team_id')->nullable()->constrained('teams')->nullOnDelete();

            // Unique per (workflow, scope, day) — see class docblock.
            $table->string('execution_key')->unique();

            // Findings + the agent's recommendation text, and a small
            // structured summary for the "AI Insights" UI — never the
            // system prompt, never full tool results (STEP 42/45).
            $table->text('result')->nullable();
            $table->json('findings')->nullable();

            $table->foreignId('agent_interaction_id')->nullable()->constrained('agent_interactions')->nullOnDelete();

            $table->string('error_summary')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index('user_id');
            $table->index('workflow');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_executions');
    }
};
