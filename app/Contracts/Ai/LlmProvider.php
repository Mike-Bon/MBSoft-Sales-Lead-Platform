<?php

namespace App\Contracts\Ai;

use App\Support\Ai\AiCompletionResult;
use App\Support\Ai\AiProviderException;
use App\Support\Ai\ToolDefinition;

/**
 * STEP 4: the provider-agnostic boundary AssistantService depends on.
 * Swapping providers (Anthropic, OpenAI, a future one) never touches
 * AssistantService or any tool — only a new class implementing this
 * interface, bound in AppServiceProvider.
 *
 * A conversation is a list of turns. AssistantService is the only
 * caller and defines the exact shape it produces, but every
 * implementation must accept:
 *   ['role' => 'user', 'content' => string]
 *   ['role' => 'assistant', 'content' => ?string, 'tool_calls' => list<ToolCall>]
 *   ['role' => 'tool_result', 'tool_call_id' => string, 'content' => string, 'is_error' => bool]
 *
 * System instructions are passed separately from the conversation, and
 * must never be interpretable as part of it — this is the structural
 * half of this application's prompt-injection defense (STEP 24): CRM/
 * customer content only ever enters as 'tool_result' content, never as
 * 'system'.
 */
interface LlmProvider
{
    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  list<ToolDefinition>  $tools
     *
     * @throws AiProviderException
     */
    public function complete(string $systemPrompt, array $messages, array $tools): AiCompletionResult;
}
