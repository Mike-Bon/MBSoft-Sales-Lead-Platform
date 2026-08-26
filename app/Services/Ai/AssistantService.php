<?php

namespace App\Services\Ai;

use App\Enums\AgentInteractionStatus;
use App\Models\AgentInteraction;
use App\Models\User;
use App\Support\Ai\AgentResponse;
use App\Support\Ai\AiProviderException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The thin binding between the HTTP layer and the one Phase 7 agent:
 * calls Agent::respond(), records an AgentInteraction audit row (STEP
 * 35) for every call, and guarantees STEP 28 — the CRM must remain
 * fully functional when the AI provider is unavailable — by catching
 * AiProviderException here rather than letting it reach the controller
 * as an uncaught exception.
 */
class AssistantService
{
    public function __construct(private readonly Agent $agent) {}

    /**
     * @param  array<int, array<string, mixed>>  $history
     */
    public function respond(User $actor, string $message, array $history = []): AgentResponse
    {
        $startedAt = now();

        try {
            $response = $this->agent->respond($actor, $message, $history);
            $this->record($actor, $message, $response, $startedAt, null);

            return $response;
        } catch (AiProviderException $e) {
            Log::error('AI assistant provider failure', ['exception' => $e->getMessage()]);

            $response = new AgentResponse(
                AgentInteractionStatus::Failed,
                'The AI assistant is temporarily unavailable. You can still use the CRM normally — this only affects the assistant.',
                [],
                null,
                ['input_tokens' => 0, 'output_tokens' => 0],
            );

            $this->record($actor, $message, $response, $startedAt, 'AI provider unavailable.');

            return $response;
        }
    }

    private function record(User $actor, string $message, AgentResponse $response, Carbon $startedAt, ?string $errorSummary): void
    {
        $interaction = new AgentInteraction;
        $interaction->user_id = $actor->id;
        $interaction->agent = 'crm-assistant';
        $interaction->provider = 'anthropic';
        $interaction->model = (string) config('services.anthropic.model');
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
