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
 * Google Gemini's `generateContent` REST API
 * (https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent)
 * via Laravel's Http facade. Google publishes no first-party PHP SDK, so
 * plain HTTPS against the documented REST API is the correct approach —
 * the same situation, and the same resolution, as AnthropicProvider and
 * Phase 6's WhatsApp Cloud API integration.
 *
 * Translates this application's simplified, provider-agnostic message
 * shape (see LlmProvider) into Gemini's `contents`/`parts` wire format,
 * and Gemini's response back into AiCompletionResult. No other class in
 * this codebase knows Gemini's wire format — that is exactly the point
 * of the LlmProvider interface (Phase 7 STEP 4). Agent, ToolRegistry,
 * every AgentTool, AiCompletionResult, ToolCall and ToolDefinition are
 * untouched by this class existing.
 *
 * Gemini is ONLY the reasoning/tool-selection LLM here. It is given
 * exactly the function declarations the Agent's ToolRegistry exposes and
 * nothing else — no Google Search grounding, no URL-context tool, no
 * code execution, no built-in retrieval. External web discovery stays
 * with Brave (App\Services\MarketIntelligence\Providers\BraveSearchProvider).
 *
 * Tool-call correlation: Gemini's `functionCall`/`functionResponse`
 * parts carry an OPTIONAL `id`. When Gemini supplies one we round-trip
 * it verbatim; when it doesn't we synthesise a deterministic
 * "{name}#{index}" id so Agent.php can still associate each tool result
 * with its call. On the way back we always send the function `name`
 * (Gemini's primary correlation key) and include `id` only when it was a
 * real Gemini id — a synthesised one is meaningless to Gemini and would
 * only add noise, so it is omitted and Gemini falls back to name+order
 * matching, which is why buildWireContents preserves call/result order.
 *
 * Thought signatures (Gemini 3+): each model turn that emits a
 * `functionCall` carries an opaque `thoughtSignature` on the part
 * (for parallel calls, only on the first `functionCall` part). Gemini
 * rejects the next request (HTTP 400) unless that signature is replayed
 * verbatim in the same part. We capture it into ToolCall::$providerSignature
 * on parse and re-attach it, byte-for-byte, as a sibling of `functionCall`
 * when buildWireContents replays that model turn — never decoded,
 * mutated, synthesised, logged, or surfaced anywhere else. The
 * documented dummy/bypass signature values are NOT used: real
 * model-generated calls always carry a real signature. The trailing
 * text-part signature (which Gemini marks "recommended", not required,
 * and whose absence does not cause the 400) is a known minor gap.
 */
class GeminiProvider implements LlmProvider
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    /**
     * JSON Schema keys Gemini's OpenAPI-3.0 `Schema` subset rejects.
     * This application's tool schemas already avoid every one of these
     * (verified), so stripping them is purely defensive against a future
     * tool author adding one.
     */
    private const UNSUPPORTED_SCHEMA_KEYS = [
        '$schema', '$id', '$ref', '$defs', 'definitions', 'additionalProperties',
        'patternProperties', 'oneOf', 'anyOf', 'allOf', 'not', 'if', 'then', 'else',
        'const', 'examples', 'default', 'contentEncoding', 'contentMediaType',
    ];

    public function complete(string $systemPrompt, array $messages, array $tools): AiCompletionResult
    {
        $apiKey = config('services.llm.api_key');

        if (! $apiKey) {
            throw new AiProviderException('The AI assistant is not configured (missing API key).');
        }

        $model = (string) config('services.llm.model');
        $payload = [
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => $this->buildWireContents($messages),
            'generationConfig' => [
                'maxOutputTokens' => (int) config('services.llm.max_tokens', 1024),
                'temperature' => 0,
            ],
        ];

        if ($tools !== []) {
            $payload['tools'] = [['functionDeclarations' => $this->buildWireTools($tools)]];
            // AUTO: the model chooses between a function call and a final
            // text answer. Never ANY — that would force a call every turn
            // and the Agent loop could never reach a final answer.
            $payload['toolConfig'] = ['functionCallingConfig' => ['mode' => 'AUTO']];
        }

        try {
            $response = Http::withHeaders([
                'x-goog-api-key' => $apiKey,
                'content-type' => 'application/json',
            ])
                ->timeout((int) config('services.llm.timeout', 30))
                ->post(sprintf(self::ENDPOINT, $model), $payload);
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
    private function buildWireContents(array $messages): array
    {
        $wire = [];
        $callNames = [];

        foreach ($messages as $message) {
            if ($message['role'] === 'assistant') {
                foreach ($message['tool_calls'] ?? [] as $toolCall) {
                    /** @var ToolCall $toolCall */
                    $callNames[$toolCall->id] = $toolCall->name;
                }
            }
        }

        foreach ($messages as $message) {
            if ($message['role'] === 'tool_result') {
                $name = $callNames[$message['tool_call_id']] ?? $this->nameFromSynthesisedId($message['tool_call_id']);
                $part = [
                    'functionResponse' => [
                        'name' => $name,
                        'response' => $this->toResponseObject($message['content'], (bool) ($message['is_error'] ?? false)),
                    ],
                ];

                if (! $this->isSynthesisedId($message['tool_call_id'], $name)) {
                    $part['functionResponse']['id'] = $message['tool_call_id'];
                }

                // Gemini expects every functionResponse for one model turn
                // to arrive together in a single following `user` content
                // — merge consecutive tool_result entries rather than
                // sending one content per tool call.
                $last = end($wire);
                if ($last !== false && $last['role'] === 'user' && isset($last['parts'][0]['functionResponse'])) {
                    $wire[array_key_last($wire)]['parts'][] = $part;
                } else {
                    $wire[] = ['role' => 'user', 'parts' => [$part]];
                }

                continue;
            }

            if ($message['role'] === 'assistant') {
                $parts = [];

                if (! empty($message['content'])) {
                    $parts[] = ['text' => $message['content']];
                }

                foreach ($message['tool_calls'] ?? [] as $toolCall) {
                    /** @var ToolCall $toolCall */
                    $call = [
                        'name' => $toolCall->name,
                        'args' => $toolCall->arguments === [] ? new \stdClass : $toolCall->arguments,
                    ];

                    if (! $this->isSynthesisedId($toolCall->id, $toolCall->name)) {
                        $call['id'] = $toolCall->id;
                    }

                    $part = ['functionCall' => $call];

                    // Gemini 3+ requires the opaque thoughtSignature to be
                    // replayed verbatim on the exact same part it was
                    // received on (parallel calls: only the first FC part
                    // carries one). Never fabricated — only re-attached
                    // when the originating call actually had one.
                    if ($toolCall->providerSignature !== null) {
                        $part['thoughtSignature'] = $toolCall->providerSignature;
                    }

                    $parts[] = $part;
                }

                $wire[] = ['role' => 'model', 'parts' => $parts];

                continue;
            }

            $wire[] = ['role' => 'user', 'parts' => [['text' => (string) $message['content']]]];
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
            'parameters' => $this->sanitizeSchema($tool->parameters),
        ], $tools);
    }

    /**
     * Gemini's `Schema` is an OpenAPI-3.0 subset: `type` is an enum whose
     * JSON form is upper-case (STRING, OBJECT, ARRAY, …), and a handful
     * of JSON Schema keywords are not accepted. This normalises both.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function sanitizeSchema(array $schema): array
    {
        $clean = [];

        foreach ($schema as $key => $value) {
            if (in_array($key, self::UNSUPPORTED_SCHEMA_KEYS, true)) {
                continue;
            }

            if ($key === 'type' && is_string($value)) {
                $clean[$key] = strtoupper($value);

                continue;
            }

            if ($key === 'properties' && is_array($value)) {
                $clean[$key] = array_map(
                    fn ($sub) => is_array($sub) ? $this->sanitizeSchema($sub) : $sub,
                    $value,
                );

                continue;
            }

            if ($key === 'items' && is_array($value)) {
                $clean[$key] = $this->sanitizeSchema($value);

                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function parseResponse(?array $body): AiCompletionResult
    {
        if ($body === null) {
            throw new AiProviderException('AI provider returned an unparseable response.');
        }

        $candidate = $body['candidates'][0] ?? null;

        if ($candidate === null) {
            $blockReason = data_get($body, 'promptFeedback.blockReason');

            throw new AiProviderException(
                $blockReason !== null
                    ? 'AI provider blocked the request ('.$blockReason.').'
                    : 'AI provider returned no candidates.',
            );
        }

        $finishReason = $candidate['finishReason'] ?? 'STOP';
        $text = null;
        $toolCalls = [];
        $index = 0;

        foreach ($candidate['content']['parts'] ?? [] as $part) {
            if (isset($part['text'])) {
                $text = ($text ?? '').$part['text'];
            } elseif (isset($part['functionCall'])) {
                $call = $part['functionCall'];
                $name = (string) ($call['name'] ?? '');
                $signature = $part['thoughtSignature'] ?? null;
                $toolCalls[] = new ToolCall(
                    id: isset($call['id']) && $call['id'] !== '' ? (string) $call['id'] : $name.'#'.$index,
                    name: $name,
                    arguments: is_array($call['args'] ?? null) ? $call['args'] : [],
                    providerSignature: is_string($signature) && $signature !== '' ? $signature : null,
                );
                $index++;
            }
        }

        if ($text === null && $toolCalls === [] && in_array($finishReason, ['SAFETY', 'RECITATION', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'MALFORMED_FUNCTION_CALL'], true)) {
            throw new AiProviderException('AI provider stopped the response ('.$finishReason.').');
        }

        if ($text === null && $toolCalls === []) {
            throw new AiProviderException('AI provider returned an empty response.');
        }

        return new AiCompletionResult(
            text: $text,
            toolCalls: $toolCalls,
            stopReason: (string) $finishReason,
            usage: [
                'input_tokens' => (int) data_get($body, 'usageMetadata.promptTokenCount', 0),
                'output_tokens' => (int) data_get($body, 'usageMetadata.candidatesTokenCount', 0),
            ],
        );
    }

    /**
     * A synthesised id is exactly "{name}#{digits}" — the only shape
     * parseResponse() ever mints when Gemini omits a real id.
     */
    private function isSynthesisedId(string $id, string $name): bool
    {
        return (bool) preg_match('/^'.preg_quote($name, '/').'#\d+$/', $id);
    }

    private function nameFromSynthesisedId(string $id): string
    {
        return (string) preg_replace('/#\d+$/', '', $id);
    }

    /**
     * Gemini's `functionResponse.response` must be a JSON object. Agent.php
     * always hands us a json_encode()d array here, so this decodes it back;
     * anything unexpected is wrapped so the wire value is still an object.
     */
    private function toResponseObject(string $content, bool $isError): array
    {
        $decoded = json_decode($content, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        return $isError ? ['error' => $content] : ['result' => $content];
    }
}
