<?php

namespace Tests\Feature\Ai;

use App\Contracts\Ai\AgentTool;
use App\Enums\AgentInteractionStatus;
use App\Models\User;
use App\Services\Ai\Agent;
use App\Services\Ai\ToolRegistry;
use App\Support\Ai\ToolDefinition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeLlmProvider;
use Tests\TestCase;

/**
 * STEP 27/28: pure engine-level tests using two trivial fake tools —
 * isolates the tool-calling loop's own mechanics (limits, error
 * handling, draft surfacing, sanitized audit arguments) from any real
 * CRM tool's authorization logic, which is covered separately in
 * ToolsTest.php.
 */
class AgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_plain_text_response_with_no_tool_calls_completes_immediately(): void
    {
        $provider = new FakeLlmProvider([FakeLlmProvider::text('Hello there.')]);
        $agent = new Agent($provider, new ToolRegistry([]), 'system prompt');

        $response = $agent->respond(User::factory()->create(), 'Hi');

        $this->assertSame(AgentInteractionStatus::Completed, $response->status);
        $this->assertSame('Hello there.', $response->text);
        $this->assertSame([], $response->toolsUsed);
        $this->assertCount(1, $provider->calls);
    }

    public function test_a_tool_call_is_executed_and_its_result_fed_back_to_the_model(): void
    {
        $tool = $this->echoTool();
        $provider = new FakeLlmProvider([
            FakeLlmProvider::toolCall('echo', ['value' => 'ping']),
            FakeLlmProvider::text('The tool said pong.'),
        ]);
        $agent = new Agent($provider, new ToolRegistry([$tool]), 'system prompt');

        $response = $agent->respond(User::factory()->create(), 'Echo ping');

        $this->assertSame('The tool said pong.', $response->text);
        $this->assertSame([['name' => 'echo', 'arguments' => ['value' => 'ping']]], $response->toolsUsed);
        $this->assertCount(2, $provider->calls);

        // The second call to the provider must include the tool_result.
        $secondCallMessages = $provider->calls[1]['messages'];
        $lastMessage = end($secondCallMessages);
        $this->assertSame('tool_result', $lastMessage['role']);
        $this->assertSame(['echoed' => 'ping'], json_decode($lastMessage['content'], true));
    }

    public function test_calling_an_unregistered_tool_is_reported_as_an_error_not_a_crash(): void
    {
        $provider = new FakeLlmProvider([
            FakeLlmProvider::toolCall('delete_lead', ['lead_id' => 1]),
            FakeLlmProvider::text('I cannot do that.'),
        ]);
        $agent = new Agent($provider, new ToolRegistry([]), 'system prompt');

        $response = $agent->respond(User::factory()->create(), 'Delete lead 1');

        $this->assertSame(AgentInteractionStatus::Completed, $response->status);
        $this->assertSame('I cannot do that.', $response->text);
    }

    public function test_an_authorization_exception_from_a_tool_is_caught_and_reported_safely(): void
    {
        $tool = $this->deniedTool();
        $provider = new FakeLlmProvider([
            FakeLlmProvider::toolCall('denied', []),
            FakeLlmProvider::text('You are not allowed to see that.'),
        ]);
        $agent = new Agent($provider, new ToolRegistry([$tool]), 'system prompt');

        $response = $agent->respond(User::factory()->create(), 'Show me everything');

        $this->assertSame(AgentInteractionStatus::Completed, $response->status);

        // The tool_result the model received must never contain a raw
        // exception message or stack trace.
        $secondCallMessages = $provider->calls[1]['messages'];
        $toolResultContent = end($secondCallMessages)['content'];
        $this->assertStringNotContainsString('Exception', $toolResultContent);
        $this->assertStringContainsString('not authorized', $toolResultContent);
        $this->assertTrue(end($secondCallMessages)['is_error']);
    }

    public function test_an_unexpected_tool_exception_is_caught_logged_and_never_leaked(): void
    {
        $tool = $this->throwingTool();
        $provider = new FakeLlmProvider([
            FakeLlmProvider::toolCall('throwing', []),
            FakeLlmProvider::text('Something went wrong.'),
        ]);
        $agent = new Agent($provider, new ToolRegistry([$tool]), 'system prompt');

        $response = $agent->respond(User::factory()->create(), 'Trigger the bug');

        $this->assertSame(AgentInteractionStatus::Completed, $response->status);
        $secondCallMessages = $provider->calls[1]['messages'];
        $toolResultContent = end($secondCallMessages)['content'];
        $this->assertStringNotContainsString('super secret internal detail', $toolResultContent);
    }

    public function test_reaching_the_max_tool_iterations_stops_safely_with_a_useful_message(): void
    {
        $tool = $this->echoTool();
        // The fake keeps returning a tool call forever (no final text
        // turn queued) — simulates a runaway/looping model.
        $provider = new FakeLlmProvider([FakeLlmProvider::toolCall('echo', ['value' => 'again'])]);
        $agent = new Agent($provider, new ToolRegistry([$tool]), 'system prompt', maxToolIterations: 3);

        $response = $agent->respond(User::factory()->create(), 'Loop forever');

        $this->assertSame(AgentInteractionStatus::LimitReached, $response->status);
        $this->assertNotNull($response->text);
        $this->assertCount(3, $provider->calls);
    }

    public function test_a_draft_tool_result_is_surfaced_on_the_response(): void
    {
        $tool = $this->draftingTool();
        $provider = new FakeLlmProvider([
            FakeLlmProvider::toolCall('draft_email', ['recipient' => 'x@example.test']),
            FakeLlmProvider::text('Here is a draft.'),
        ]);
        $agent = new Agent($provider, new ToolRegistry([$tool]), 'system prompt');

        $response = $agent->respond(User::factory()->create(), 'Draft an email');

        $this->assertNotNull($response->draft);
        $this->assertTrue($response->draft['draft']);
        $this->assertSame('x@example.test', $response->draft['recipient']);
    }

    public function test_draft_tool_arguments_are_redacted_in_the_audit_trail_but_other_tools_are_not(): void
    {
        $draftTool = $this->draftingTool();
        $echoTool = $this->echoTool();
        $provider = new FakeLlmProvider([
            FakeLlmProvider::toolCall('draft_email', ['recipient' => 'x@example.test', 'subject' => 'Hi', 'body' => 'Secret pricing info']),
            FakeLlmProvider::toolCall('echo', ['value' => 'not sensitive']),
            FakeLlmProvider::text('Done.'),
        ]);
        $agent = new Agent($provider, new ToolRegistry([$draftTool, $echoTool]), 'system prompt');

        $response = $agent->respond(User::factory()->create(), 'Draft then echo');

        $draftCall = collect($response->toolsUsed)->firstWhere('name', 'draft_email');
        $this->assertSame('[redacted]', $draftCall['arguments']['subject']);
        $this->assertSame('[redacted]', $draftCall['arguments']['body']);
        $this->assertSame('x@example.test', $draftCall['arguments']['recipient']);

        $echoCall = collect($response->toolsUsed)->firstWhere('name', 'echo');
        $this->assertSame('not sensitive', $echoCall['arguments']['value']);
    }

    private function echoTool(): AgentTool
    {
        return new class implements AgentTool
        {
            public function definition(): ToolDefinition
            {
                return new ToolDefinition('echo', 'Echoes back the given value.', ['type' => 'object', 'properties' => ['value' => ['type' => 'string']]]);
            }

            public function execute(User $actor, array $arguments): array
            {
                return ['echoed' => $arguments['value'] ?? null];
            }
        };
    }

    private function deniedTool(): AgentTool
    {
        return new class implements AgentTool
        {
            public function definition(): ToolDefinition
            {
                return new ToolDefinition('denied', 'Always denies.', ['type' => 'object', 'properties' => []]);
            }

            public function execute(User $actor, array $arguments): array
            {
                throw new AuthorizationException('You are not authorized to view this record.');
            }
        };
    }

    private function throwingTool(): AgentTool
    {
        return new class implements AgentTool
        {
            public function definition(): ToolDefinition
            {
                return new ToolDefinition('throwing', 'Always throws.', ['type' => 'object', 'properties' => []]);
            }

            public function execute(User $actor, array $arguments): array
            {
                throw new \RuntimeException('super secret internal detail');
            }
        };
    }

    private function draftingTool(): AgentTool
    {
        return new class implements AgentTool
        {
            public function definition(): ToolDefinition
            {
                return new ToolDefinition('draft_email', 'Drafts an email.', ['type' => 'object', 'properties' => ['recipient' => ['type' => 'string']]]);
            }

            public function execute(User $actor, array $arguments): array
            {
                return ['draft' => true, 'channel' => 'email', 'recipient' => $arguments['recipient']];
            }
        };
    }
}
