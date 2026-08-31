<?php

namespace App\Services\Ai\Providers;

use App\Contracts\Ai\LlmProvider;
use App\Support\Ai\AiCompletionResult;
use App\Support\Ai\AiProviderException;
use App\Support\Ai\ToolCall;
use App\Support\Ai\ToolDefinition;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Anthropic's Messages API (https://api.anthropic.com/v1/messages) via
 * Laravel's Http facade — Anthropic publishes no official PHP SDK, so
 * plain HTTPS against their documented REST API is the correct approach
 * (the same situation, and the same resolution, as Phase 6's WhatsApp
 * Cloud API integration).
 *
 * Translates this application's simplified, provider-agnostic message
 * shape (see LlmProvider) into Anthropic's content-block wire format,
 * and Anthropic's response back into AiCompletionResult. No other class
 * in this codebase knows Anthropic's wire format — that is exactly the
 * point of the LlmProvider interface (STEP 4).
 *
 * V2.0.0: retained as a selectable fallback (LLM_PROVIDER=anthropic).
 * The default provider is now GeminiProvider. Reads the same
 * provider-neutral config('services.llm.*') block.
 */
class AnthropicProvider implements LlmProvider
{
    private const API_VERSION = '2023-06-01';

    public function complete(string $systemPrompt, array $messages, array $tools): AiCompletionResult
    {
        $apiKey = config('services.llm.api_key');

        if (! $apiKey) {
            throw new AiProviderException('The AI assistant is not configured (missing API key).');
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => self::API_VERSION,
                'content-type' => 'application/json',
            ])
                ->timeout((int) config('services.llm.timeout', 30))
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => config('services.llm.model'),
                    'max_tokens' => (int) config('services.llm.max_tokens', 1024),
                    'system' => $systemPrompt,
                    'messages' => $this->buildWireMessages($messages),
                    'tools' => $this->buildWireTools($tools),
                ]);
        } catch (ConnectionException $e) {
            throw new AiProviderException('Could not reach the AI provider: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new AiProviderException(
                'AI provider returned an error (HTTP '.$response->status().'): '.data_get($response->json(), 'error.message', 'unknown error'),
            );
        }

        return $this->parseResponse($response->json());
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    private function buildWireMessages(array $messages): array
    {
        $wire = [];

        foreach ($messages as $message) {
            if ($message['role'] === 'tool_result') {
                $block = [
                    'type' => 'tool_result',
                    'tool_use_id' => $message['tool_call_id'],
                    'content' => $message['content'],
                    'is_error' => $message['is_error'] ?? false,
                ];

                // Anthropic requires every tool_result for one assistant
                // turn to arrive together in a single subsequent user
                // message — merge consecutive tool_result entries rather
                // than sending one user message per tool call.
                $last = end($wire);
                if ($last !== false && $last['role'] === 'user' && isset($last['content'][0]['type']) && $last['content'][0]['type'] === 'tool_result') {
                    $wire[array_key_last($wire)]['content'][] = $block;
                } else {
                    $wire[] = ['role' => 'user', 'content' => [$block]];
                }

                continue;
            }

            if ($message['role'] === 'assistant') {
                $content = [];

                if (! empty($message['content'])) {
                    $content[] = ['type' => 'text', 'text' => $message['content']];
                }

                foreach ($message['tool_calls'] ?? [] as $toolCall) {
                    /** @var ToolCall $toolCall */
                    $content[] = ['type' => 'tool_use', 'id' => $toolCall->id, 'name' => $toolCall->name, 'input' => $toolCall->arguments];
                }

                $wire[] = ['role' => 'assistant', 'content' => $content];

                continue;
            }

            $wire[] = ['role' => 'user', 'content' => (string) $message['content']];
        }

        return $wire;
    }

    /**
     * @param  list<ToolDefinition>  $tools
     * @return list<array<string, mixed>>
     */
    private function buildWireTools(array $tools): array
    {
        return array_map(fn (ToolDefinition $tool) => [
            'name' => $tool->name,
            'description' => $tool->description,
            'input_schema' => $tool->parameters,
        ], $tools);
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function parseResponse(?array $body): AiCompletionResult
    {
        if ($body === null) {
            throw new AiProviderException('AI provider returned an unparseable response.');
        }

        $text = null;
        $toolCalls = [];

        foreach ($body['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text = ($text ?? '').$block['text'];
            } elseif (($block['type'] ?? null) === 'tool_use') {
                $toolCalls[] = new ToolCall($block['id'], $block['name'], $block['input'] ?? []);
            }
        }

        return new AiCompletionResult(
            text: $text,
            toolCalls: $toolCalls,
            stopReason: $body['stop_reason'] ?? 'end_turn',
            usage: [
                'input_tokens' => (int) data_get($body, 'usage.input_tokens', 0),
                'output_tokens' => (int) data_get($body, 'usage.output_tokens', 0),
            ],
        );
    }
}
