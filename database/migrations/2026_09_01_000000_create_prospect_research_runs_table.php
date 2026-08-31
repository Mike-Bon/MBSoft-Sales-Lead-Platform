<?php

use App\Enums\ProspectResearchStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2.0.3: one row per user-initiated Market Intelligence research
 * request. MI execution (Gemini + Brave + public-page fetches, ~150-270s
 * realistically) cannot fit inside Hostinger's HTTP window, so the
 * assistant controller dispatches ProspectResearchJob and this row is
 * how the browser polls for, and eventually renders, the result.
 *
 * Deliberately does NOT store: raw provider payloads, API keys, search
 * credentials, hidden model reasoning, Gemini thought signatures, or
 * fetched page bodies. `result` is the same user-visible synthesis text
 * the synchronous path would have returned; `tools_used` is tool NAMES
 * only (no arguments). Mirrors workflow_executions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospect_research_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // The assistant conversation this run belongs to (a per-
            // conversation UUID kept in the session). Nullable: a run is
            // still valid if the session/conversation is later cleared.
            $table->uuid('conversation_key')->nullable()->index();

            // sha256(user_id | submission_id) — see AssistantController.
            // The UNIQUE constraint is the idempotency guard: a browser
            // re-POST (refresh/back/double-click) resends the identical
            // submission_id and therefore hits the same row instead of
            // dispatching a second job / second web+LLM spend.
            $table->string('idempotency_key', 64)->unique();

            $table->text('message');

            $table->string('status')->default(ProspectResearchStatus::Queued->value);

            // The user-facing synthesis text on success; null otherwise.
            $table->text('result')->nullable();

            // Tool NAMES only, e.g. ["discover_prospects","score_prospects"].
            $table->json('tools_used')->nullable();

            $table->foreignId('agent_interaction_id')->nullable()
                ->constrained('agent_interactions')->nullOnDelete();

            // A safe, generic user-facing summary only — never a raw
            // exception message, stack trace, or provider detail.
            $table->string('error_summary')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospect_research_runs');
    }
};
