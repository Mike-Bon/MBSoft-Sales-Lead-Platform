<?php

namespace App\Services\Ai;

use App\Contracts\Ai\LlmProvider;
use App\Enums\AgentIdentifier;
use App\Enums\AgentInteractionStatus;
use App\Models\AgentInteraction;
use App\Models\User;
use App\Support\Ai\AgentResponse;
use App\Support\Ai\AiProviderException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * STEP 45 backward compatibility: this is Phase 7's exact
 * AssistantService, evolved (as CLAUDE.md/the spec directs) into the
 * general entry layer that invokes one of the three Phase 9 specialized
 * agents rather than always the one Phase 7 general agent. Its callers
 * (AssistantController, WorkflowExecutionService) now say WHICH agent to
 * use; everything else about its contract — audit recording,
 * AiProviderException → safe-failure handling (STEP 28), never throwing
 * to the caller — is unchanged.
 *
 * A fresh App\Services\Ai\Agent (the generic engine, itself completely
 * unmodified since Phase 7) is constructed per call from the requested
 * AgentDefinition's own tools/prompt/limits — cheap (no I/O), and it
 * guarantees one agent's configuration can never leak into another
 * agent's call.
 */
class AssistantService
{
    public function __construct(
        private readonly AgentRegistry $agents,
        private readonly LlmProvider $provider,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $history
     */
    public function respond(AgentIdentifier $agentId, User $actor, string $message, array $history = []): AgentResponse
    {
        $definition = $this->agents->get($agentId);
        $agent = new Agent($this->provider, $definition->tools, $definition->systemPrompt, $definition->maxToolIterations);

        $startedAt = now();

        try {
            $response = $agent->respond($actor, $message, $history);
            $this->record($agentId, $actor, $message, $response, $startedAt, null);

            return $response;
        } catch (AiProviderException $e) {
            Log::error('AI assistant provider failure', ['agent' => $agentId->value, 'exception' => $e->getMessage()]);

            $response = new AgentResponse(
                AgentInteractionStatus::Failed,
                'The AI assistant is temporarily unavailable. You can still use the CRM normally — this only affects the assistant.',
                [],
                null,
                ['input_tokens' => 0, 'output_tokens' => 0],
            );

            $this->record($agentId, $actor, $message, $response, $startedAt, 'AI provider unavailable.');

            return $response;
        }
    }

    private function record(AgentIdentifier $agentId, User $actor, string $message, AgentResponse $response, Carbon $startedAt, ?string $errorSummary): void
    {
        $interaction = new AgentInteraction;
        $interaction->user_id = $actor->id;
        $interaction->agent = $agentId->value;
        // The provider/model actually in effect for this call — driven by
        // LLM_PROVIDER / LLM_MODEL, never assumed (V2.0.0).
        $interaction->provider = (string) config('services.llm.provider');
        $interaction->model = (string) config('services.llm.model');
        $interaction->status = $response->status;
        // Bounded even though the Form Request already caps message
        // length — a second, independent safeguard against an
        // oversized audit row.
        $interaction->request = Str::limit($message, 4000, '');
        $interaction->response = $response->text !== null ? Str::limit($response->text, 4000, '') : null;
        $interaction->tool_calls = $response->toolsUsed;
        $interaction->usage = $response->usage;
        $interaction->error_summary = $errorSummary;
        $interaction->started_at = $startedAt;
        $interaction->completed_at = now();
        $interaction->save();
    }
}
