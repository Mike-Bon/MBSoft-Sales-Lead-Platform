<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Providers\AnthropicProvider;
use App\Support\Ai\AiProviderException;
use App\Support\Ai\ToolCall;
use App\Support\Ai\ToolDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * STEP 28: drives the real wire-format translation code in
 * AnthropicProvider against Http::fake() — no request ever reaches
 * api.anthropic.com.
 */
class AnthropicProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.llm.api_key' => 'test-key', 'services.llm.model' => 'claude-sonnet-4-5-20250929']);
    }

    public function test_a_text_only_response_is_parsed_correctly(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Your pipeline is $50,000.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 120, 'output_tokens' => 15],
            ], 200),
        ]);

        $result = (new AnthropicProvider)->complete('system prompt', [['role' => 'user', 'content' => 'How is my pipeline?']], []);

        $this->assertSame('Your pipeline is $50,000.', $result->text);
        $this->assertSame([], $result->toolCalls);
        $this->assertSame(120, $result->usage['input_tokens']);
        $this->assertSame(15, $result->usage['output_tokens']);
    }

    public function test_a_tool_use_response_is_parsed_into_tool_calls(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [
                    ['type' => 'text', 'text' => "I'll check that."],
                    ['type' => 'tool_use', 'id' => 'toolu_123', 'name' => 'search_leads', 'input' => ['status' => 'new']],
                ],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 50, 'output_tokens' => 20],
            ], 200),
        ]);

        $result = (new AnthropicProvider)->complete('system prompt', [['role' => 'user', 'content' => 'Show me new leads']], [
            new ToolDefinition('search_leads', 'Search leads.', ['type' => 'object', 'properties' => []]),
        ]);

        $this->assertCount(1, $result->toolCalls);
        $this->assertSame('search_leads', $result->toolCalls[0]->name);
        $this->assertSame(['status' => 'new'], $result->toolCalls[0]->arguments);
        $this->assertSame('tool_use', $result->stopReason);
    }

    public function test_the_request_carries_the_system_prompt_separately_from_messages(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => 'ok']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ], 200),
        ]);

        (new AnthropicProvider)->complete('YOU ARE A CONSTRAINED ASSISTANT', [['role' => 'user', 'content' => 'hi']], []);

        Http::assertSent(function ($request) {
            return $request['system'] === 'YOU ARE A CONSTRAINED ASSISTANT'
                && collect($request['messages'])->pluck('content')->doesntContain('YOU ARE A CONSTRAINED ASSISTANT');
        });
    }

    public function test_consecutive_tool_results_are_merged_into_a_single_wire_message(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => 'ok']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ], 200),
        ]);

        (new AnthropicProvider)->complete('system', [
            ['role' => 'user', 'content' => 'hi'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                new ToolCall('t1', 'tool_a', []),
                new ToolCall('t2', 'tool_b', []),
            ]],
            ['role' => 'tool_result', 'tool_call_id' => 't1', 'content' => '{"a":1}', 'is_error' => false],
            ['role' => 'tool_result', 'tool_call_id' => 't2', 'content' => '{"b":2}', 'is_error' => false],
        ], []);

        Http::assertSent(function ($request) {
            $toolResultMessages = collect($request['messages'])->filter(
                fn ($m) => is_array($m['content'] ?? null) && ($m['content'][0]['type'] ?? null) === 'tool_result'
            );

            return $toolResultMessages->count() === 1 && count($toolResultMessages->first()['content']) === 2;
        });
    }

    public function test_an_http_error_response_throws_an_ai_provider_exception(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'error' => ['message' => 'Invalid API key'],
            ], 401),
        ]);

        $this->expectException(AiProviderException::class);

        (new AnthropicProvider)->complete('system', [['role' => 'user', 'content' => 'hi']], []);
    }

    public function test_a_connection_failure_throws_an_ai_provider_exception(): void
    {
        Http::fake(function () {
            throw new ConnectionException('timed out');
        });

        $this->expectException(AiProviderException::class);

        (new AnthropicProvider)->complete('system', [['role' => 'user', 'content' => 'hi']], []);
    }

    public function test_a_missing_api_key_throws_immediately_without_a_request(): void
    {
        config(['services.llm.api_key' => null]);
        Http::fake();

        $this->expectException(AiProviderException::class);

        (new AnthropicProvider)->complete('system', [['role' => 'user', 'content' => 'hi']], []);

        Http::assertNothingSent();
    }

    public function test_no_api_key_is_ever_present_in_the_completion_result(): void
    {
        config(['services.llm.api_key' => 'super-secret-anthropic-key']);
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => 'ok']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ], 200),
        ]);

        $result = (new AnthropicProvider)->complete('system', [['role' => 'user', 'content' => 'hi']], []);

        $this->assertStringNotContainsString('super-secret-anthropic-key', json_encode($result));
    }
}
