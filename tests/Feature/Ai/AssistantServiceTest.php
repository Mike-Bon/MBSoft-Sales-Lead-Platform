<?php

namespace Tests\Feature\Ai;

use App\Contracts\Ai\LlmProvider;
use App\Enums\AgentIdentifier;
use App\Enums\AgentInteractionStatus;
use App\Models\AgentInteraction;
use App\Models\User;
use App\Services\Ai\AssistantService;
use App\Support\Ai\AiCompletionResult;
use App\Support\Ai\AiProviderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeLlmProvider;
use Tests\TestCase;

/**
 * STEP 28/35: AssistantService is what actually guarantees the CRM
 * survives an AI provider outage, and what writes the audit trail — now
 * parametrized by which of the three Phase 9 specialized agents is
 * being invoked (AssistantService constructs a fresh Agent engine per
 * call from the requested AgentDefinition, so no singleton needs
 * resetting between fakes).
 */
class AssistantServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_successful_response_is_recorded_as_a_completed_interaction(): void
    {
        $this->app->instance(LlmProvider::class, new FakeLlmProvider([FakeLlmProvider::text('Your pipeline is healthy.')]));

        $user = User::factory()->create();
        $response = app(AssistantService::class)->respond(AgentIdentifier::Sales, $user, 'How is my pipeline?');

        $this->assertSame(AgentInteractionStatus::Completed, $response->status);

        $interaction = AgentInteraction::firstOrFail();
        $this->assertSame($user->id, $interaction->user_id);
        $this->assertSame(AgentInteractionStatus::Completed, $interaction->status);
        $this->assertSame('sales', $interaction->agent);
        $this->assertSame('How is my pipeline?', $interaction->request);
        $this->assertSame('Your pipeline is healthy.', $interaction->response);
        // The audit row records the provider/model actually configured
        // (V2.0.0: driven by LLM_PROVIDER / LLM_MODEL, not hard-coded).
        $this->assertSame(config('services.llm.provider'), $interaction->provider);
        $this->assertSame(config('services.llm.model'), $interaction->model);
        $this->assertNotNull($interaction->started_at);
        $this->assertNotNull($interaction->completed_at);
    }

    public function test_the_agent_column_reflects_whichever_specialized_agent_was_invoked(): void
    {
        $this->app->instance(LlmProvider::class, new FakeLlmProvider([FakeLlmProvider::text('62%.')]));

        $user = User::factory()->create();
        app(AssistantService::class)->respond(AgentIdentifier::Performance, $user, 'How are we doing against target?');

        $this->assertSame('performance', AgentInteraction::firstOrFail()->agent);
    }

    public function test_a_provider_failure_does_not_throw_and_is_recorded_as_failed(): void
    {
        $failingProvider = new class implements LlmProvider
        {
            public function complete(string $systemPrompt, array $messages, array $tools): AiCompletionResult
            {
                throw new AiProviderException('Provider unreachable.');
            }
        };
        $this->app->instance(LlmProvider::class, $failingProvider);

        $user = User::factory()->create();

        // The key assertion: this must not throw. STEP 28 — the CRM
        // must remain usable even when the AI provider is down.
        $response = app(AssistantService::class)->respond(AgentIdentifier::Sales, $user, 'How is my pipeline?');

        $this->assertSame(AgentInteractionStatus::Failed, $response->status);
        $this->assertStringContainsString('temporarily unavailable', $response->text);

        $interaction = AgentInteraction::firstOrFail();
        $this->assertSame(AgentInteractionStatus::Failed, $interaction->status);
        $this->assertNotNull($interaction->error_summary);
        // Never a raw exception message in the stored audit row.
        $this->assertStringNotContainsString('Provider unreachable', $interaction->error_summary);
    }

    public function test_a_long_message_and_response_are_bounded_in_the_audit_row(): void
    {
        $longText = str_repeat('a', 5000);
        $this->app->instance(LlmProvider::class, new FakeLlmProvider([FakeLlmProvider::text($longText)]));

        $user = User::factory()->create();
        app(AssistantService::class)->respond(AgentIdentifier::Sales, $user, $longText);

        $interaction = AgentInteraction::firstOrFail();
        $this->assertLessThanOrEqual(4000, strlen($interaction->request));
        $this->assertLessThanOrEqual(4000, strlen($interaction->response));
    }
}
