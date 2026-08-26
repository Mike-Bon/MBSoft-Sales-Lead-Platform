<?php

namespace Tests\Feature\Ai;

use App\Contracts\Ai\LlmProvider;
use App\Enums\AgentInteractionStatus;
use App\Models\AgentInteraction;
use App\Models\User;
use App\Services\Ai\Agent;
use App\Services\Ai\AssistantService;
use App\Support\Ai\AiCompletionResult;
use App\Support\Ai\AiProviderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeLlmProvider;
use Tests\TestCase;

/**
 * STEP 28/35: AssistantService is what actually guarantees the CRM
 * survives an AI provider outage, and what writes the audit trail.
 */
class AssistantServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_successful_response_is_recorded_as_a_completed_interaction(): void
    {
        $this->app->instance(LlmProvider::class, new FakeLlmProvider([FakeLlmProvider::text('Your pipeline is healthy.')]));
        // Rebind Agent so it picks up the fake provider instance above.
        $this->app->forgetInstance(Agent::class);

        $user = User::factory()->create();
        $response = app(AssistantService::class)->respond($user, 'How is my pipeline?');

        $this->assertSame(AgentInteractionStatus::Completed, $response->status);

        $interaction = AgentInteraction::firstOrFail();
        $this->assertSame($user->id, $interaction->user_id);
        $this->assertSame(AgentInteractionStatus::Completed, $interaction->status);
        $this->assertSame('How is my pipeline?', $interaction->request);
        $this->assertSame('Your pipeline is healthy.', $interaction->response);
        $this->assertSame('crm-assistant', $interaction->agent);
        $this->assertSame('anthropic', $interaction->provider);
        $this->assertNotNull($interaction->started_at);
        $this->assertNotNull($interaction->completed_at);
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
        $this->app->forgetInstance(Agent::class);

        $user = User::factory()->create();

        // The key assertion: this must not throw. STEP 28 — the CRM
        // must remain usable even when the AI provider is down.
        $response = app(AssistantService::class)->respond($user, 'How is my pipeline?');

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
        $this->app->forgetInstance(Agent::class);

        $user = User::factory()->create();
        app(AssistantService::class)->respond($user, $longText);

        $interaction = AgentInteraction::firstOrFail();
        $this->assertLessThanOrEqual(4000, strlen($interaction->request));
        $this->assertLessThanOrEqual(4000, strlen($interaction->response));
    }
}
