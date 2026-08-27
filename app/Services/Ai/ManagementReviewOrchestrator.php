<?php

namespace App\Services\Ai;

use App\Enums\AgentIdentifier;
use App\Models\User;
use App\Support\Ai\AgentResponse;
use App\Support\Ai\ManagementReviewResult;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * STEP 20/21/22/37: the one Phase 9 cross-agent workflow — a genuinely
 * multi-domain request runs the Performance Agent, then the Sales
 * Agent, in an application-defined, fixed sequence. This class IS the
 * orchestrator; it is plain Laravel control flow, never an LLM deciding
 * "which agents should I invoke" (STEP 22). Each sub-agent call goes
 * through AssistantService exactly like any other agent invocation —
 * same authorization, same audit trail (one AgentInteraction row per
 * sub-agent), same failure handling. Neither agent can invoke the
 * other, and this orchestrator never lets one call the other again
 * (STEP 19/40: no recursive/arbitrary delegation) — it calls each
 * agent exactly once, in this fixed order, full stop.
 */
final class ManagementReviewOrchestrator
{
    public function __construct(private readonly AssistantService $assistant) {}

    public function run(User $actor, string $message): ManagementReviewResult
    {
        $performance = $this->safeCall(AgentIdentifier::Performance, $actor, $message);
        $sales = $this->safeCall(AgentIdentifier::Sales, $actor, $message);

        return new ManagementReviewResult($performance, $sales);
    }

    private function safeCall(AgentIdentifier $agentId, User $actor, string $message): ?AgentResponse
    {
        try {
            // Each sub-agent receives only the original request text —
            // never the other sub-agent's output, its own conversation
            // history, or any other cross-agent context (STEP 30/31):
            // there is nothing here for one agent's output to be
            // mistaken as an instruction to the other (STEP 34).
            return $this->assistant->respond($agentId, $actor, $message, []);
        } catch (Throwable $e) {
            // AssistantService itself never throws for a provider
            // failure (STEP 28) — this is a last-resort guard against
            // any other unexpected failure, so one sub-agent's problem
            // can never take down the whole review (STEP 38).
            Log::error('Management review sub-agent failed', ['agent' => $agentId->value, 'exception' => $e->getMessage()]);

            return null;
        }
    }
}
