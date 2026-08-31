<?php

namespace Tests\Feature\Ai;

use App\Contracts\Ai\AgentTool;
use App\Contracts\Ai\LlmProvider;
use App\Enums\AgentInteractionStatus;
use App\Models\User;
use App\Services\Ai\Agent;
use App\Services\Ai\Providers\GeminiProvider;
use App\Services\Ai\ToolRegistry;
use App\Support\Ai\AiProviderException;
use App\Support\Ai\ToolCall;
use App\Support\Ai\ToolDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Drives the real wire-format translation code in GeminiProvider against
 * Http::fake() — no request ever reaches generativelanguage.googleapis.com.
 *
 * Special attention (per the V2.0.0 provider-swap decision) to tool-call
 * correlation: Gemini's functionCall/functionResponse parts carry only an
 * OPTIONAL id, so the provider must associate every tool result with its
 * originating call by name + order (and by id when Gemini supplied one),
 * without any change to Agent.php or the neutral LlmProvider contract.
 */
class GeminiProviderTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://generativelanguage.googleapis.com/v1beta/models/*';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.llm.provider' => 'gemini',
            'services.llm.api_key' => 'test-key',
            'services.llm.model' => 'gemini-3.6-flash',
            'services.llm.max_tokens' => 512,
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function fakeOnce(array $body, int $status = 200): void
    {
        Http::fake([self::URL => Http::response($body, $status)]);
    }

    private function textCandidate(string $text): array
    {
        return [
            'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => $text]]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 30, 'candidatesTokenCount' => 7, 'totalTokenCount' => 37],
        ];
    }

    /**
     * @param  list<array{string, array<string, mixed>}>  $calls  [name, args] pairs
     */
    private function functionCallCandidate(array $calls): array
    {
        $parts = array_map(fn ($c) => ['functionCall' => ['name' => $c[0], 'args' => $c[1] === [] ? new \stdClass : $c[1]]], $calls);

        return ['candidates' => [['content' => ['role' => 'model', 'parts' => $parts], 'finishReason' => 'STOP']]];
    }

    // ─────────────────────────  happy paths  ─────────────────────────

    public function test_a_text_only_response_is_parsed_and_usage_is_mapped(): void
    {
        $this->fakeOnce($this->textCandidate('Your pipeline is healthy.'));

        $result = (new GeminiProvider)->complete('system', [['role' => 'user', 'content' => 'How is my pipeline?']], []);

        $this->assertSame('Your pipeline is healthy.', $result->text);
        $this->assertSame([], $result->toolCalls);
        $this->assertSame('STOP', $result->stopReason);
        $this->assertSame(30, $result->usage['input_tokens']);
        $this->assertSame(7, $result->usage['output_tokens']);
    }

    public function test_the_request_targets_the_configured_model_and_sends_the_api_key_only_as_a_header(): void
    {
        $this->fakeOnce($this->textCandidate('ok'));

        (new GeminiProvider)->complete('system', [['role' => 'user', 'content' => 'hi']], []);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v1beta/models/gemini-3.6-flash:generateContent')
                && $request->hasHeader('x-goog-api-key', 'test-key')
                && ! str_contains($request->url(), 'test-key')
                && ! str_contains($request->url(), 'key=');
        });
    }

    public function test_the_system_prompt_is_sent_as_system_instruction_never_inside_the_conversation(): void
    {
        $this->fakeOnce($this->textCandidate('ok'));

        (new GeminiProvider)->complete('YOU ARE A CONSTRAINED ASSISTANT', [['role' => 'user', 'content' => 'hi']], []);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return data_get($body, 'systemInstruction.parts.0.text') === 'YOU ARE A CONSTRAINED ASSISTANT'
                && ! str_contains(json_encode($body['contents']), 'CONSTRAINED ASSISTANT');
        });
    }

    public function test_user_and_assistant_history_is_mapped_to_user_and_model_roles(): void
    {
        $this->fakeOnce($this->textCandidate('ok'));

        (new GeminiProvider)->complete('system', [
            ['role' => 'user', 'content' => 'first question'],
            ['role' => 'assistant', 'content' => 'first answer', 'tool_calls' => []],
            ['role' => 'user', 'content' => 'second question'],
        ], []);

        Http::assertSent(function ($request) {
            $contents = $request->data()['contents'];

            return $contents[0]['role'] === 'user'
                && $contents[0]['parts'][0]['text'] === 'first question'
                && $contents[1]['role'] === 'model'
                && $contents[1]['parts'][0]['text'] === 'first answer'
                && $contents[2]['role'] === 'user';
        });
    }

    public function test_tool_definitions_are_mapped_to_function_declarations_with_uppercased_schema_types_and_auto_mode(): void
    {
        $this->fakeOnce($this->textCandidate('ok'));

        (new GeminiProvider)->complete('system', [['role' => 'user', 'content' => 'hi']], [
            new ToolDefinition('search_leads', 'Search leads.', [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['new', 'won']],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['status'],
            ]),
        ]);

        Http::assertSent(function ($request) {
            $decl = $request->data()['tools'][0]['functionDeclarations'][0];

            return $decl['name'] === 'search_leads'
                && $decl['description'] === 'Search leads.'
                && $decl['parameters']['type'] === 'OBJECT'
                && $decl['parameters']['properties']['status']['type'] === 'STRING'
                && $decl['parameters']['properties']['status']['enum'] === ['new', 'won']
                && $decl['parameters']['properties']['tags']['type'] === 'ARRAY'
                && $decl['parameters']['properties']['tags']['items']['type'] === 'STRING'
                && $decl['parameters']['required'] === ['status']
                && $request->data()['toolConfig']['functionCallingConfig']['mode'] === 'AUTO'
                && $request->data()['generationConfig']['maxOutputTokens'] === 512;
        });
    }

    public function test_unsupported_json_schema_keywords_are_stripped_before_sending(): void
    {
        $this->fakeOnce($this->textCandidate('ok'));

        (new GeminiProvider)->complete('system', [['role' => 'user', 'content' => 'hi']], [
            new ToolDefinition('t', 'desc', [
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'x' => ['type' => 'string', 'const' => 'no', 'default' => 'y'],
                ],
            ]),
        ]);

        Http::assertSent(function ($request) {
            $params = $request->data()['tools'][0]['functionDeclarations'][0]['parameters'];
            $blob = json_encode($params);

            return ! str_contains($blob, '$schema')
                && ! str_contains($blob, 'additionalProperties')
                && ! str_contains($blob, 'const')
                && ! str_contains($blob, '"default"')
                && $params['properties']['x']['type'] === 'STRING';
        });
    }

    public function test_no_tools_means_no_tools_or_tool_config_keys_in_the_request(): void
    {
        $this->fakeOnce($this->textCandidate('ok'));

        (new GeminiProvider)->complete('system', [['role' => 'user', 'content' => 'hi']], []);

        Http::assertSent(fn ($request) => ! array_key_exists('tools', $request->data()) && ! array_key_exists('toolConfig', $request->data()));
    }

    // ────────────────────  tool-call correlation  ────────────────────

    public function test_one_function_call_is_parsed_with_a_synthesised_id_when_gemini_omits_one(): void
    {
        $this->fakeOnce([
            'candidates' => [['content' => ['role' => 'model', 'parts' => [
                ['functionCall' => ['name' => 'search_leads', 'args' => ['status' => 'new']]],
            ]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 4],
        ]);

        $result = (new GeminiProvider)->complete('system', [['role' => 'user', 'content' => 'new leads']], [
            new ToolDefinition('search_leads', 'x', ['type' => 'object', 'properties' => []]),
        ]);

        $this->assertCount(1, $result->toolCalls);
        $this->assertSame('search_leads#0', $result->toolCalls[0]->id);
        $this->assertSame('search_leads', $result->toolCalls[0]->name);
        $this->assertSame(['status' => 'new'], $result->toolCalls[0]->arguments);
    }

    public function test_a_real_gemini_function_call_id_is_preserved_verbatim(): void
    {
        $this->fakeOnce([
            'candidates' => [['content' => ['parts' => [
                ['functionCall' => ['name' => 'get_lead', 'args' => ['id' => 5], 'id' => 'call_abc123']],
            ]], 'finishReason' => 'STOP']],
        ]);

        $result = (new GeminiProvider)->complete('system', [['role' => 'user', 'content' => 'lead 5']], [
            new ToolDefinition('get_lead', 'x', ['type' => 'object', 'properties' => []]),
        ]);

        $this->assertSame('call_abc123', $result->toolCalls[0]->id);
    }

    public function test_two_different_function_calls_in_one_response_keep_order_and_get_distinct_ids(): void
    {
        $this->fakeOnce([
            'candidates' => [['content' => ['parts' => [
                ['functionCall' => ['name' => 'get_pipeline_summary', 'args' => new \stdClass]],
                ['functionCall' => ['name' => 'get_my_performance', 'args' => new \stdClass]],
            ]], 'finishReason' => 'STOP']],
        ]);

        $result = (new GeminiProvider)->complete('system', [['role' => 'user', 'content' => 'summary']], [
            new ToolDefinition('get_pipeline_summary', 'x', ['type' => 'object', 'properties' => []]),
            new ToolDefinition('get_my_performance', 'x', ['type' => 'object', 'properties' => []]),
        ]);

        $this->assertCount(2, $result->toolCalls);
        $this->assertSame(['get_pipeline_summary', 'get_my_performance'], array_map(fn ($c) => $c->name, $result->toolCalls));
        $this->assertSame(['get_pipeline_summary#0', 'get_my_performance#1'], array_map(fn ($c) => $c->id, $result->toolCalls));
    }

    public function test_two_calls_to_the_same_function_in_one_response_get_distinct_ids_and_keep_their_own_args_in_order(): void
    {
        $this->fakeOnce([
            'candidates' => [['content' => ['parts' => [
                ['functionCall' => ['name' => 'get_lead', 'args' => ['id' => 1]]],
                ['functionCall' => ['name' => 'get_lead', 'args' => ['id' => 2]]],
            ]], 'finishReason' => 'STOP']],
        ]);

        $result = (new GeminiProvider)->complete('system', [['role' => 'user', 'content' => 'leads 1 and 2']], [
            new ToolDefinition('get_lead', 'x', ['type' => 'object', 'properties' => []]),
        ]);

        $this->assertCount(2, $result->toolCalls);
        $this->assertSame('get_lead#0', $result->toolCalls[0]->id);
        $this->assertSame('get_lead#1', $result->toolCalls[1]->id);
        $this->assertSame(['id' => 1], $result->toolCalls[0]->arguments);
        $this->assertSame(['id' => 2], $result->toolCalls[1]->arguments);
    }

    public function test_tool_results_for_repeated_same_function_calls_are_returned_in_call_order_by_name(): void
    {
        $this->fakeOnce($this->textCandidate('done'));

        // The message history Agent.php would build after two parallel
        // get_lead calls (synthesised ids) and their results.
        (new GeminiProvider)->complete('system', [
            ['role' => 'user', 'content' => 'leads 1 and 2'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                new ToolCall('get_lead#0', 'get_lead', ['id' => 1]),
                new ToolCall('get_lead#1', 'get_lead', ['id' => 2]),
            ]],
            ['role' => 'tool_result', 'tool_call_id' => 'get_lead#0', 'content' => '{"name":"Alpha"}', 'is_error' => false],
            ['role' => 'tool_result', 'tool_call_id' => 'get_lead#1', 'content' => '{"name":"Beta"}', 'is_error' => false],
        ], [new ToolDefinition('get_lead', 'x', ['type' => 'object', 'properties' => []])]);

        Http::assertSent(function ($request) {
            $contents = $request->data()['contents'];

            // model turn carries both functionCall parts, no id key (synthesised)
            $model = $contents[1];
            $modelOk = $model['role'] === 'model'
                && count($model['parts']) === 2
                && $model['parts'][0]['functionCall']['name'] === 'get_lead'
                && ! array_key_exists('id', $model['parts'][0]['functionCall']);

            // the two results are merged into ONE user turn, in order, by name
            $results = $contents[2];
            $resultsOk = $results['role'] === 'user'
                && count($results['parts']) === 2
                && $results['parts'][0]['functionResponse']['name'] === 'get_lead'
                && $results['parts'][0]['functionResponse']['response'] === ['name' => 'Alpha']
                && ! array_key_exists('id', $results['parts'][0]['functionResponse'])
                && $results['parts'][1]['functionResponse']['response'] === ['name' => 'Beta'];

            return $modelOk && $resultsOk && count($contents) === 3;
        });
    }

    public function test_a_real_gemini_id_is_echoed_back_on_the_function_response(): void
    {
        $this->fakeOnce($this->textCandidate('done'));

        (new GeminiProvider)->complete('system', [
            ['role' => 'user', 'content' => 'lead 5'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [new ToolCall('call_xyz', 'get_lead', ['id' => 5])]],
            ['role' => 'tool_result', 'tool_call_id' => 'call_xyz', 'content' => '{"name":"Gamma"}', 'is_error' => false],
        ], [new ToolDefinition('get_lead', 'x', ['type' => 'object', 'properties' => []])]);

        Http::assertSent(function ($request) {
            $contents = $request->data()['contents'];

            return $contents[1]['parts'][0]['functionCall']['id'] === 'call_xyz'
                && $contents[2]['parts'][0]['functionResponse']['id'] === 'call_xyz'
                && $contents[2]['parts'][0]['functionResponse']['name'] === 'get_lead';
        });
    }

    public function test_an_error_tool_result_is_wrapped_as_an_object(): void
    {
        $this->fakeOnce($this->textCandidate('understood'));

        (new GeminiProvider)->complete('system', [
            ['role' => 'user', 'content' => 'do it'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [new ToolCall('t#0', 't', [])]],
            ['role' => 'tool_result', 'tool_call_id' => 't#0', 'content' => '{"error":"You are not authorized to do that."}', 'is_error' => true],
        ], [new ToolDefinition('t', 'x', ['type' => 'object', 'properties' => []])]);

        Http::assertSent(fn ($request) => data_get($request->data(), 'contents.2.parts.0.functionResponse.response.error') === 'You are not authorized to do that.');
    }

    // ─────────────  multi-iteration / mixed parts (via Agent)  ────────

    public function test_assistant_text_and_a_function_call_can_arrive_in_the_same_response(): void
    {
        $this->fakeOnce([
            'candidates' => [['content' => ['parts' => [
                ['text' => 'Let me look that up.'],
                ['functionCall' => ['name' => 'get_pipeline_summary', 'args' => new \stdClass]],
            ]], 'finishReason' => 'STOP']],
        ]);

        $result = (new GeminiProvider)->complete('system', [['role' => 'user', 'content' => 'pipeline?']], [
            new ToolDefinition('get_pipeline_summary', 'x', ['type' => 'object', 'properties' => []]),
        ]);

        $this->assertSame('Let me look that up.', $result->text);
        $this->assertCount(1, $result->toolCalls);
    }

    public function test_multi_iteration_tool_calling_runs_end_to_end_through_the_real_agent(): void
    {
        Http::fake([
            self::URL => Http::sequence()
                ->push($this->functionCallCandidate([['echo', ['value' => 'one']]]), 200)
                ->push($this->functionCallCandidate([['echo', ['value' => 'two']]]), 200)
                ->push($this->textCandidate('Echoed one and two.'), 200),
        ]);

        $agent = new Agent(new GeminiProvider, new ToolRegistry([$this->echoTool()]), 'system prompt', maxToolIterations: 6);
        $response = $agent->respond(User::factory()->create(), 'Echo one then two');

        $this->assertSame(AgentInteractionStatus::Completed, $response->status);
        $this->assertSame('Echoed one and two.', $response->text);
        $this->assertSame(['one', 'two'], array_map(fn ($c) => $c['arguments']['value'], $response->toolsUsed));
        Http::assertSentCount(3);
    }

    public function test_a_function_call_followed_by_a_final_text_answer_completes(): void
    {
        Http::fake([
            self::URL => Http::sequence()
                ->push($this->functionCallCandidate([['echo', ['value' => 'ping']]]), 200)
                ->push($this->textCandidate('The tool said pong.'), 200),
        ]);

        $agent = new Agent(new GeminiProvider, new ToolRegistry([$this->echoTool()]), 'system prompt');
        $response = $agent->respond(User::factory()->create(), 'Echo ping');

        $this->assertSame('The tool said pong.', $response->text);
        $this->assertSame([['name' => 'echo', 'arguments' => ['value' => 'ping']]], $response->toolsUsed);
    }

    public function test_the_max_tool_iteration_limit_still_applies_with_the_real_provider(): void
    {
        // Gemini keeps asking for a tool forever.
        Http::fake([self::URL => Http::response($this->functionCallCandidate([['echo', ['value' => 'again']]]), 200)]);

        $agent = new Agent(new GeminiProvider, new ToolRegistry([$this->echoTool()]), 'system prompt', maxToolIterations: 3);
        $response = $agent->respond(User::factory()->create(), 'loop');

        $this->assertSame(AgentInteractionStatus::LimitReached, $response->status);
        Http::assertSentCount(3);
    }

    // ────────────────────────  error handling  ───────────────────────

    public function test_a_missing_api_key_throws_before_any_request_is_made(): void
    {
        config(['services.llm.api_key' => null]);
        Http::fake();

        try {
            (new GeminiProvider)->complete('system', [['role' => 'user', 'content' => 'hi']], []);
            $this->fail('Expected AiProviderException.');
        } catch (AiProviderException) {
            Http::assertNothingSent();
        }
    }

    public function test_a_401_authentication_error_becomes_a_sanitised_ai_provider_exception(): void
    {
        $this->fakeOnce(['error' => ['code' => 401, 'message' => 'API key not valid', 'status' => 'UNAUTHENTICATED']], 401);

        $this->expectException(AiProviderException::class);
        (new GeminiProvider)->complete('system', [['role' => 'user', 'content' => 'hi']], []);
    }

    public function test_a_403_permission_error_becomes_an_ai_provider_exception(): void
    {
        $this->fakeOnce(['error' => ['code' => 403, 'message' => 'Permission denied', 'status' => 'PERMISSION_DENIED']], 403);

        $this->expectException(AiProviderException::class);
        (new GeminiProvider)->complete('system', [['role' => 'user', 'content' => 'hi']], []);
    }

    public function test_a_429_quota_error_becomes_an_ai_provider_exception(): void
    {
        $this->fakeOnce(['error' => ['code' => 429, 'message' => 'Resource has been exhausted', 'status' => 'RESOURCE_EXHAUSTED']], 429);

        $this->expectException(AiProviderException::class);
        (new GeminiProvider)->complete('system', [['role' => 'user', 'content' => 'hi']], []);
    }

    public function test_a_500_provider_error_becomes_an_ai_provider_exception(): void
    {
        $this->fakeOnce(['error' => ['code' => 500, 'message' => 'internal']], 503);

        $this->expectException(AiProviderException::class);
        (new GeminiProvider)->complete('system', [['role' => 'user', 'content' => 'hi']], []);
    }

    public function test_a_connection_timeout_becomes_an_ai_provider_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->expectException(AiProviderException::class);
        (new GeminiProvider)->complete('system', [['role' => 'user', 'content' => 'hi']], []);
    }

    public function test_a_malformed_response_body_becomes_an_ai_provider_exception(): void
    {
        Http::fake([self::URL => Http::response('this is not json', 200)]);

        $this->expectException(AiProviderException::class);
        (new GeminiProvider)->complete('system', [['role' => 'user', 'content' => 'hi']], []);
    }

    public function test_a_prompt_level_safety_block_with_no_candidates_becomes_an_ai_provider_exception(): void
    {
        $this->fakeOnce(['candidates' => [], 'promptFeedback' => ['blockReason' => 'SAFETY']]);

        $this->expectException(AiProviderException::class);
        (new GeminiProvider)->complete('system', [['role' => 'user', 'content' => 'hi']], []);
    }

    public function test_a_candidate_stopped_for_safety_with_no_parts_becomes_an_ai_provider_exception(): void
    {
        $this->fakeOnce(['candidates' => [['content' => ['parts' => []], 'finishReason' => 'SAFETY']]]);

        $this->expectException(AiProviderException::class);
        (new GeminiProvider)->complete('system', [['role' => 'user', 'content' => 'hi']], []);
    }

    public function test_an_empty_candidate_with_no_parts_becomes_an_ai_provider_exception(): void
    {
        $this->fakeOnce(['candidates' => [['content' => ['parts' => []], 'finishReason' => 'STOP']]]);

        $this->expectException(AiProviderException::class);
        (new GeminiProvider)->complete('system', [['role' => 'user', 'content' => 'hi']], []);
    }

    // ──────────────────────────  no key leak  ────────────────────────

    public function test_the_api_key_never_appears_in_the_completion_result(): void
    {
        config(['services.llm.api_key' => 'super-secret-gemini-key']);
        $this->fakeOnce($this->textCandidate('ok'));

        $result = (new GeminiProvider)->complete('system', [['role' => 'user', 'content' => 'hi']], []);

        $this->assertStringNotContainsString('super-secret-gemini-key', json_encode($result));
    }

    public function test_the_api_key_never_appears_in_a_thrown_provider_exception(): void
    {
        config(['services.llm.api_key' => 'super-secret-gemini-key']);
        $this->fakeOnce(['error' => ['message' => 'API key not valid']], 401);

        try {
            (new GeminiProvider)->complete('system', [['role' => 'user', 'content' => 'hi']], []);
            $this->fail('Expected AiProviderException.');
        } catch (AiProviderException $e) {
            $this->assertStringNotContainsString('super-secret-gemini-key', $e->getMessage());
            $this->assertStringNotContainsString('super-secret-gemini-key', (string) $e);
        }
    }

    public function test_the_bound_provider_is_gemini_by_default(): void
    {
        $this->assertInstanceOf(GeminiProvider::class, app(LlmProvider::class));
    }

    // ─────────────  Gemini 3+ thought-signature round-trip  ──────────
    //
    // Gemini 3 rejects a continuation request (HTTP 400) unless the
    // opaque thoughtSignature from each model turn's functionCall part
    // is replayed verbatim on the same part. These tests inspect the
    // ACTUAL outgoing continuation request bodies, not just the final
    // AgentResponse.

    private function signedFunctionCallCandidate(string $name, array $args, string $signature, ?string $id = null): array
    {
        $fc = ['name' => $name, 'args' => $args === [] ? new \stdClass : $args];
        if ($id !== null) {
            $fc['id'] = $id;
        }

        return ['candidates' => [['content' => ['role' => 'model', 'parts' => [
            ['functionCall' => $fc, 'thoughtSignature' => $signature],
        ]], 'finishReason' => 'STOP']]];
    }

    public function test_a_single_signed_function_call_is_replayed_with_its_signature_on_the_same_part(): void
    {
        Http::fake([
            self::URL => Http::sequence()
                ->push($this->signedFunctionCallCandidate('echo', ['value' => 'ping'], 'SIG-A'), 200)
                ->push($this->textCandidate('pong'), 200),
        ]);

        $agent = new Agent(new GeminiProvider, new ToolRegistry([$this->echoTool()]), 'system prompt');
        $agent->respond(User::factory()->create(), 'echo ping');

        $secondRequest = Http::recorded()[1][0]->data();
        $modelTurn = $secondRequest['contents'][1];

        $this->assertSame('model', $modelTurn['role']);
        $this->assertSame('echo', $modelTurn['parts'][0]['functionCall']['name']);
        // The signature is a SIBLING of functionCall on the same part —
        // not inside functionCall, not on another part, not fabricated.
        $this->assertSame('SIG-A', $modelTurn['parts'][0]['thoughtSignature']);
        $this->assertArrayNotHasKey('thoughtSignature', $modelTurn['parts'][0]['functionCall']);
    }

    public function test_parallel_calls_carry_the_signature_only_on_the_first_function_call_and_keep_order(): void
    {
        $this->fakeOnce($this->textCandidate('done'));

        // The history Agent builds after Gemini returned two parallel
        // calls where only the first carried a signature.
        (new GeminiProvider)->complete('system', [
            ['role' => 'user', 'content' => 'a and b'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                new ToolCall('alpha#0', 'alpha', [], 'SIG-FIRST'),
                new ToolCall('beta#1', 'beta', [], null),
            ]],
            ['role' => 'tool_result', 'tool_call_id' => 'alpha#0', 'content' => '{"r":"a"}', 'is_error' => false],
            ['role' => 'tool_result', 'tool_call_id' => 'beta#1', 'content' => '{"r":"b"}', 'is_error' => false],
        ], [
            new ToolDefinition('alpha', 'x', ['type' => 'object', 'properties' => []]),
            new ToolDefinition('beta', 'x', ['type' => 'object', 'properties' => []]),
        ]);

        $contents = Http::recorded()[0][0]->data()['contents'];
        $modelParts = $contents[1]['parts'];

        $this->assertCount(2, $modelParts);
        $this->assertSame('alpha', $modelParts[0]['functionCall']['name']);
        $this->assertSame('SIG-FIRST', $modelParts[0]['thoughtSignature']);
        $this->assertSame('beta', $modelParts[1]['functionCall']['name']);
        $this->assertArrayNotHasKey('thoughtSignature', $modelParts[1]);

        // Never transformed into FC1,FR1,FC2,FR2 — one model turn then
        // one user turn with both responses in order.
        $this->assertCount(3, $contents);
        $this->assertSame('user', $contents[2]['role']);
        $this->assertSame(['a', 'b'], [
            $contents[2]['parts'][0]['functionResponse']['response']['r'],
            $contents[2]['parts'][1]['functionResponse']['response']['r'],
        ]);
    }

    public function test_parallel_same_function_calls_keep_their_signature_position_and_argument_order(): void
    {
        $this->fakeOnce($this->textCandidate('done'));

        (new GeminiProvider)->complete('system', [
            ['role' => 'user', 'content' => 'leads 1 and 2'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                new ToolCall('get_lead#0', 'get_lead', ['id' => 1], 'SIG-ONLY-FIRST'),
                new ToolCall('get_lead#1', 'get_lead', ['id' => 2], null),
            ]],
            ['role' => 'tool_result', 'tool_call_id' => 'get_lead#0', 'content' => '{"n":"A"}', 'is_error' => false],
            ['role' => 'tool_result', 'tool_call_id' => 'get_lead#1', 'content' => '{"n":"B"}', 'is_error' => false],
        ], [new ToolDefinition('get_lead', 'x', ['type' => 'object', 'properties' => []])]);

        $modelParts = Http::recorded()[0][0]->data()['contents'][1]['parts'];

        $this->assertSame(['id' => 1], $modelParts[0]['functionCall']['args']);
        $this->assertSame('SIG-ONLY-FIRST', $modelParts[0]['thoughtSignature']);
        $this->assertSame(['id' => 2], $modelParts[1]['functionCall']['args']);
        $this->assertArrayNotHasKey('thoughtSignature', $modelParts[1]);
    }

    public function test_sequential_multi_step_calls_each_replay_their_own_signature_in_place(): void
    {
        Http::fake([
            self::URL => Http::sequence()
                ->push($this->signedFunctionCallCandidate('echo', ['value' => 'one'], 'SIG-A'), 200)
                ->push($this->signedFunctionCallCandidate('echo', ['value' => 'two'], 'SIG-B'), 200)
                ->push($this->textCandidate('Echoed one and two.'), 200),
        ]);

        $agent = new Agent(new GeminiProvider, new ToolRegistry([$this->echoTool()]), 'system prompt', maxToolIterations: 6);
        $response = $agent->respond(User::factory()->create(), 'echo one then two');

        $this->assertSame(AgentInteractionStatus::Completed, $response->status);
        $recorded = Http::recorded();
        $this->assertCount(3, $recorded);

        // Request #2: only turn 1 exists → carries SIG-A.
        $req2 = $recorded[1][0]->data()['contents'];
        $this->assertSame('SIG-A', $req2[1]['parts'][0]['thoughtSignature']);
        $this->assertSame('one', $req2[1]['parts'][0]['functionCall']['args']['value']);

        // Request #3: turn 1 still carries SIG-A, turn 2 carries SIG-B.
        $req3 = $recorded[2][0]->data()['contents'];
        $this->assertSame('model', $req3[1]['role']);
        $this->assertSame('SIG-A', $req3[1]['parts'][0]['thoughtSignature']);
        $this->assertSame('one', $req3[1]['parts'][0]['functionCall']['args']['value']);
        $this->assertSame('model', $req3[3]['role']);
        $this->assertSame('SIG-B', $req3[3]['parts'][0]['thoughtSignature']);
        $this->assertSame('two', $req3[3]['parts'][0]['functionCall']['args']['value']);

        // Neither signature moved onto a functionResponse (user) part.
        $this->assertArrayNotHasKey('thoughtSignature', $req3[2]['parts'][0]);
        $this->assertArrayNotHasKey('thoughtSignature', $req3[4]['parts'][0]);
        // SIG-B never leaked onto turn 1; SIG-A never onto turn 2.
        $this->assertNotSame('SIG-B', $req3[1]['parts'][0]['thoughtSignature']);
        $this->assertNotSame('SIG-A', $req3[3]['parts'][0]['thoughtSignature']);
    }

    public function test_text_plus_a_signed_function_call_preserves_the_function_call_signature(): void
    {
        Http::fake([
            self::URL => Http::sequence()
                ->push(['candidates' => [['content' => ['role' => 'model', 'parts' => [
                    ['text' => 'Let me check.', 'thoughtSignature' => 'TEXT-SIG'],
                    ['functionCall' => ['name' => 'echo', 'args' => ['value' => 'x']], 'thoughtSignature' => 'FC-SIG'],
                ]], 'finishReason' => 'STOP']]], 200)
                ->push($this->textCandidate('ok'), 200),
        ]);

        $agent = new Agent(new GeminiProvider, new ToolRegistry([$this->echoTool()]), 'system prompt');
        $agent->respond(User::factory()->create(), 'go');

        $modelParts = Http::recorded()[1][0]->data()['contents'][1]['parts'];

        $this->assertSame('Let me check.', $modelParts[0]['text']);
        $this->assertSame('echo', $modelParts[1]['functionCall']['name']);
        $this->assertSame('FC-SIG', $modelParts[1]['thoughtSignature']);
        // Known minor gap: the recommended-only text-part signature is
        // not preserved — and never fabricated.
        $this->assertArrayNotHasKey('thoughtSignature', $modelParts[0]);
    }

    public function test_a_gemini_provided_id_and_signature_are_both_preserved(): void
    {
        $this->fakeOnce($this->signedFunctionCallCandidate('get_lead', ['id' => 5], 'SIG-X', id: 'call_xyz'));

        $result = (new GeminiProvider)->complete('system', [['role' => 'user', 'content' => 'lead 5']], [
            new ToolDefinition('get_lead', 'x', ['type' => 'object', 'properties' => []]),
        ]);

        $this->assertSame('call_xyz', $result->toolCalls[0]->id);
        $this->assertSame('SIG-X', $result->toolCalls[0]->providerSignature);

        // And both survive the replay.
        Http::fake([self::URL => Http::response($this->textCandidate('done'), 200)]);
        (new GeminiProvider)->complete('system', [
            ['role' => 'user', 'content' => 'lead 5'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => $result->toolCalls],
            ['role' => 'tool_result', 'tool_call_id' => 'call_xyz', 'content' => '{"n":"G"}', 'is_error' => false],
        ], [new ToolDefinition('get_lead', 'x', ['type' => 'object', 'properties' => []])]);

        $part = Http::recorded()->last()[0]->data()['contents'][1]['parts'][0];
        $this->assertSame('call_xyz', $part['functionCall']['id']);
        $this->assertSame('SIG-X', $part['thoughtSignature']);
    }

    public function test_a_synthesised_id_and_signature_are_both_preserved(): void
    {
        $this->fakeOnce($this->signedFunctionCallCandidate('echo', ['value' => 'v'], 'SIG-SYNTH'));

        $result = (new GeminiProvider)->complete('system', [['role' => 'user', 'content' => 'go']], [
            new ToolDefinition('echo', 'x', ['type' => 'object', 'properties' => []]),
        ]);

        $this->assertSame('echo#0', $result->toolCalls[0]->id);
        $this->assertSame('SIG-SYNTH', $result->toolCalls[0]->providerSignature);

        Http::fake([self::URL => Http::response($this->textCandidate('done'), 200)]);
        (new GeminiProvider)->complete('system', [
            ['role' => 'user', 'content' => 'go'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => $result->toolCalls],
            ['role' => 'tool_result', 'tool_call_id' => 'echo#0', 'content' => '{"r":1}', 'is_error' => false],
        ], [new ToolDefinition('echo', 'x', ['type' => 'object', 'properties' => []])]);

        $part = Http::recorded()->last()[0]->data()['contents'][1]['parts'][0];
        $this->assertArrayNotHasKey('id', $part['functionCall']); // synthesised → omitted
        $this->assertSame('SIG-SYNTH', $part['thoughtSignature']);
    }

    public function test_a_final_text_response_after_a_signed_call_completes_cleanly(): void
    {
        Http::fake([
            self::URL => Http::sequence()
                ->push($this->signedFunctionCallCandidate('echo', ['value' => 'ping'], 'SIG'), 200)
                ->push($this->textCandidate('pong'), 200),
        ]);

        $agent = new Agent(new GeminiProvider, new ToolRegistry([$this->echoTool()]), 'system prompt');
        $response = $agent->respond(User::factory()->create(), 'echo ping');

        $this->assertSame(AgentInteractionStatus::Completed, $response->status);
        $this->assertSame('pong', $response->text);
        $this->assertSame([['name' => 'echo', 'arguments' => ['value' => 'ping']]], $response->toolsUsed);
    }

    public function test_a_thought_signature_never_appears_in_the_user_visible_response(): void
    {
        Http::fake([
            self::URL => Http::sequence()
                ->push($this->signedFunctionCallCandidate('echo', ['value' => 'ping'], 'SECRET-SIGNATURE-VALUE'), 200)
                ->push($this->textCandidate('Here you go.'), 200),
        ]);

        $agent = new Agent(new GeminiProvider, new ToolRegistry([$this->echoTool()]), 'system prompt');
        $response = $agent->respond(User::factory()->create(), 'echo ping');

        $this->assertSame('Here you go.', $response->text);
        $this->assertStringNotContainsString('SECRET-SIGNATURE-VALUE', (string) $response->text);
        $this->assertStringNotContainsString('SECRET-SIGNATURE-VALUE', json_encode($response->toolsUsed));
        $this->assertStringNotContainsString('SECRET-SIGNATURE-VALUE', json_encode($response->draft));
    }

    public function test_a_thought_signature_never_appears_in_a_provider_exception(): void
    {
        Http::fake([self::URL => Http::response(['error' => ['code' => 500, 'message' => 'internal']], 503)]);

        try {
            (new GeminiProvider)->complete('system', [
                ['role' => 'user', 'content' => 'go'],
                ['role' => 'assistant', 'content' => null, 'tool_calls' => [new ToolCall('echo#0', 'echo', [], 'SECRET-SIGNATURE-VALUE')]],
                ['role' => 'tool_result', 'tool_call_id' => 'echo#0', 'content' => '{"r":1}', 'is_error' => false],
            ], [new ToolDefinition('echo', 'x', ['type' => 'object', 'properties' => []])]);
            $this->fail('Expected AiProviderException.');
        } catch (AiProviderException $e) {
            $this->assertStringNotContainsString('SECRET-SIGNATURE-VALUE', $e->getMessage());
            $this->assertStringNotContainsString('SECRET-SIGNATURE-VALUE', (string) $e);
        }
    }

    public function test_the_max_tool_iteration_limit_still_applies_when_every_call_is_signed(): void
    {
        Http::fake([self::URL => Http::response($this->signedFunctionCallCandidate('echo', ['value' => 'again'], 'SIG'), 200)]);

        $agent = new Agent(new GeminiProvider, new ToolRegistry([$this->echoTool()]), 'system prompt', maxToolIterations: 3);
        $response = $agent->respond(User::factory()->create(), 'loop');

        $this->assertSame(AgentInteractionStatus::LimitReached, $response->status);
        Http::assertSentCount(3);
    }

    public function test_an_unsigned_call_never_gains_a_fabricated_signature_on_replay(): void
    {
        $this->fakeOnce($this->textCandidate('done'));

        (new GeminiProvider)->complete('system', [
            ['role' => 'user', 'content' => 'go'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [new ToolCall('echo#0', 'echo', [])]],
            ['role' => 'tool_result', 'tool_call_id' => 'echo#0', 'content' => '{"r":1}', 'is_error' => false],
        ], [new ToolDefinition('echo', 'x', ['type' => 'object', 'properties' => []])]);

        $part = Http::recorded()[0][0]->data()['contents'][1]['parts'][0];
        $this->assertArrayNotHasKey('thoughtSignature', $part);
        $this->assertArrayNotHasKey('thoughtSignature', $part['functionCall']);
    }

    private function echoTool(): AgentTool
    {
        return new class implements AgentTool
        {
            public function definition(): ToolDefinition
            {
                return new ToolDefinition('echo', 'Echoes the value.', ['type' => 'object', 'properties' => ['value' => ['type' => 'string']]]);
            }

            public function execute(User $actor, array $arguments): array
            {
                return ['echoed' => $arguments['value'] ?? null];
            }
        };
    }
}
