<?php

namespace Tests\Feature\Ai;

use App\Contracts\Ai\LlmProvider;
use App\Enums\AgentIdentifier;
use App\Enums\AgentInteractionStatus;
use App\Models\AgentInteraction;
use App\Models\User;
use App\Services\Ai\AssistantService;
use App\Services\Ai\Providers\AnthropicProvider;
use App\Services\Ai\Providers\GeminiProvider;
use App\Services\Ai\Providers\MisconfiguredLlmProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * V2.0.0: LLM_PROVIDER selects the concrete LlmProvider in
 * AppServiceProvider. Gemini is the default; Anthropic is a supported
 * fallback; anything else must fail safely without silently swapping in
 * a working provider.
 */
class LlmProviderSelectionTest extends TestCase
{
    use RefreshDatabase;

    private function rebind(): void
    {
        // Re-run the container binding closure with the current config.
        $this->app->forgetInstance(LlmProvider::class);
    }

    public function test_gemini_is_the_default_provider(): void
    {
        config(['services.llm.provider' => 'gemini']);
        $this->rebind();

        $this->assertInstanceOf(GeminiProvider::class, $this->app->make(LlmProvider::class));
    }

    public function test_anthropic_is_selectable_as_a_fallback(): void
    {
        config(['services.llm.provider' => 'anthropic']);
        $this->rebind();

        $this->assertInstanceOf(AnthropicProvider::class, $this->app->make(LlmProvider::class));
    }

    public function test_an_unknown_provider_binds_the_misconfigured_provider_not_a_working_one(): void
    {
        config(['services.llm.provider' => 'openai']);
        $this->rebind();

        $provider = $this->app->make(LlmProvider::class);

        $this->assertInstanceOf(MisconfiguredLlmProvider::class, $provider);
        $this->assertNotInstanceOf(GeminiProvider::class, $provider);
        $this->assertNotInstanceOf(AnthropicProvider::class, $provider);
    }

    public function test_an_unknown_provider_fails_the_assistant_safely_and_leaves_the_crm_working(): void
    {
        config(['services.llm.provider' => 'openai']);
        $this->rebind();
        Http::preventStrayRequests();

        $user = User::factory()->create();
        $response = app(AssistantService::class)->respond(AgentIdentifier::Sales, $user, 'How is my pipeline?');

        // Same safe failure as a missing key: no throw, recorded as failed.
        $this->assertSame(AgentInteractionStatus::Failed, $response->status);
        $this->assertStringContainsString('temporarily unavailable', $response->text);
        $this->assertSame(AgentInteractionStatus::Failed, AgentInteraction::firstOrFail()->status);
    }

    public function test_the_misconfigured_provider_never_reaches_a_real_api(): void
    {
        config(['services.llm.provider' => 'not-a-provider']);
        $this->rebind();
        Http::fake();

        $user = User::factory()->create();
        app(AssistantService::class)->respond(AgentIdentifier::Sales, $user, 'hello');

        Http::assertNothingSent();
    }
}
