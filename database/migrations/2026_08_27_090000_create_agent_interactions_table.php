<?php

use App\Enums\AgentInteractionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STEP 35: an audit trail of assistant request/response cycles — one row
 * per user message sent to the agent, not one row per internal tool
 * call (those are recorded, sanitized, inside `tool_calls`). Deliberately
 * does NOT store: hidden chain-of-thought, the system prompt, or any
 * secret/credential. `request`/`response` are the actual user-visible
 * conversation text; `tool_calls` records only tool name + sanitized
 * arguments (never full tool *results*, which could otherwise duplicate
 * customer PII into a second table unnecessarily — STEP 22/49).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('agent')->default('crm-assistant');
            $table->string('provider');
            $table->string('model');
            $table->string('status')->default(AgentInteractionStatus::Completed->value);

            $table->text('request');
            $table->text('response')->nullable();

            // Sanitized: [{name: 'search_leads', arguments: {...}}, ...] —
            // tool names and arguments only, never full tool results.
            $table->json('tool_calls')->nullable();

            // {input_tokens, output_tokens} where the provider reports it.
            $table->json('usage')->nullable();

            // A safe, generic summary only — never a raw exception
            // message or stack trace (STEP 23's rule applies here too).
            $table->string('error_summary')->nullable();

            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index('user_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_interactions');
    }
};
