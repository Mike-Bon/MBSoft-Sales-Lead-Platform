<?php

namespace App\Services\Ai;

use App\Contracts\Ai\LlmProvider;
use App\Enums\AgentInteractionStatus;
use App\Models\User;
use App\Support\Ai\AgentResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The generic single-agent tool-calling engine (STEP 6): system prompt +
 * tool registry + provider + iteration limit, run in a loop until the
 * model returns a final answer or the limit is reached. This class knows
 * nothing about "CRM", "sales", or any specific system prompt — that
 * configuration is supplied by its caller (see AssistantService, which
 * wires up the one Phase 7 CRM assistant instance). This is deliberate:
 * a future phase could construct a second Agent with a different prompt
 * and ToolRegistry without touching this file, LlmProvider, or any
 * AgentTool — with NO orchestrator, registry-of-agents, or
 * agent-to-agent delegation, exactly as CLAUDE.md requires. Building
 * that second agent is explicitly out of scope for Phase 7.
 *
 * Every tool failure (authorization denial, validation error, or an
 * unexpected exception) is caught here and turned into a safe
 * tool_result the model can relay — never a raw stack trace, and never
 * something that aborts the whole conversation turn (STEP 23/28).
 */
final class Agent
{
    public function __construct(
        private readonly LlmProvider $provider,
        private readonly ToolRegistry $tools,
        private readonly string $systemPrompt,
        private readonly int $maxToolIterations = 6,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $history  Prior turns in
     *                                                     this application's simplified message shape (see LlmProvider) — text-only
     *                                                     user/assistant turns are sufficient; intermediate tool calls from
     *                                                     earlier user messages are not replayed (STEP 48: limited conversation
     *                                                     history, bounded token usage).
     */
    public function respond(User $actor, string $userMessage, array $history = []): AgentResponse
    {
        $messages = $history;
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $toolsUsed = [];
        $draft = null;
        $usage = ['input_tokens' => 0, 'output_tokens' => 0];

        for ($iteration = 0; $iteration < $this->maxToolIterations; $iteration++) {
            $result = $this->provider->complete($this->systemPrompt, $messages, $this->tools->definitions());

            $usage['input_tokens'] += $result->usage['input_tokens'] ?? 0;
            $usage['output_tokens'] += $result->usage['output_tokens'] ?? 0;

            if (! $result->hasToolCalls()) {
                return new AgentResponse(AgentInteractionStatus::Completed, $result->text, $toolsUsed, $draft, $usage);
            }

            $messages[] = ['role' => 'assistant', 'content' => $result->text, 'tool_calls' => $result->toolCalls];

            foreach ($result->toolCalls as $toolCall) {
                $toolsUsed[] = ['name' => $toolCall->name, 'arguments' => $this->sanitizeArguments($toolCall->name, $toolCall->arguments)];
                $tool = $this->tools->find($toolCall->name);

                if ($tool === null) {
                    // The model asked for a tool that doesn't exist —
                    // e.g. an injected instruction trying to invoke
                    // "send_email" or "delete_lead", which were never
                    // registered. Reported back as a normal tool
                    // failure, nothing more.
                    $messages[] = $this->toolResultMessage($toolCall->id, ['error' => 'Unknown tool.'], isError: true);

                    continue;
                }

                try {
                    $output = $tool->execute($actor, $toolCall->arguments);

                    if (($output['draft'] ?? null) === true) {
                        $draft = $output;
                    }

                    $messages[] = $this->toolResultMessage($toolCall->id, $output, isError: false);
                } catch (AuthorizationException) {
                    $messages[] = $this->toolResultMessage($toolCall->id, ['error' => 'You are not authorized to do that.'], isError: true);
                } catch (ValidationException $e) {
                    $messages[] = $this->toolResultMessage($toolCall->id, ['error' => implode(' ', $e->validator->errors()->all())], isError: true);
                } catch (Throwable $e) {
                    Log::error('Agent tool execution failed', ['tool' => $toolCall->name, 'exception' => $e->getMessage()]);
                    $messages[] = $this->toolResultMessage($toolCall->id, ['error' => 'This tool failed unexpectedly.'], isError: true);
                }
            }
        }

        Log::warning('Agent reached its maximum tool-call iterations', ['actor_id' => $actor->id, 'tools_used' => $toolsUsed]);

        return new AgentResponse(
            AgentInteractionStatus::LimitReached,
            "I wasn't able to finish that within the allowed number of steps. Try asking a narrower question.",
            $toolsUsed,
            $draft,
            $usage,
        );
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function toolResultMessage(string $toolCallId, array $content, bool $isError): array
    {
        return [
            'role' => 'tool_result',
            'tool_call_id' => $toolCallId,
            'content' => json_encode($content),
            'is_error' => $isError,
        ];
    }

    /**
     * STEP 35/49: arguments recorded for audit are the tool's own
     * (typically small, structural) parameters — but a draft tool's
     * arguments can carry an actual drafted message body/subject, which
     * would otherwise duplicate customer-facing content into a second
     * table unnecessarily. Redact those specific fields; keep everything
     * else (ids, filters, recipient) as-is.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function sanitizeArguments(string $toolName, array $arguments): array
    {
        if (! in_array($toolName, ['draft_email', 'draft_whatsapp'], true)) {
            return $arguments;
        }

        foreach (['subject', 'body'] as $field) {
            if (array_key_exists($field, $arguments)) {
                $arguments[$field] = '[redacted]';
            }
        }

        return $arguments;
    }
}
