<?php

namespace App\Services\Ai\Providers;

use App\Contracts\Ai\LlmProvider;
use App\Support\Ai\AiCompletionResult;
use App\Support\Ai\AiProviderException;

/**
 * Bound as the LlmProvider when `services.llm.provider` names a provider
 * this application does not implement. It never silently substitutes a
 * working provider (that would hide an operator's configuration mistake);
 * instead it fails exactly the way a missing API key already does — the
 * assistant page still loads, sending a message returns the safe "AI
 * temporarily unavailable" response, and the real reason
 * ("Unsupported LLM_PROVIDER [...]") is written to the server log by
 * AssistantService's existing AiProviderException handler.
 */
final class MisconfiguredLlmProvider implements LlmProvider
{
    public function __construct(private readonly string $configuredProvider) {}

    public function complete(string $systemPrompt, array $messages, array $tools): AiCompletionResult
    {
        throw new AiProviderException(
            "Unsupported LLM_PROVIDER [{$this->configuredProvider}]. Set LLM_PROVIDER to 'gemini' or 'anthropic'.",
        );
    }
}
