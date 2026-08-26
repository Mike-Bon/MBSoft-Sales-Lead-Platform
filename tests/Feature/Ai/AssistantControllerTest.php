<?php

namespace Tests\Feature\Ai;

use App\Contracts\Ai\LlmProvider;
use App\Models\AgentInteraction;
use App\Models\EmailAccount;
use App\Models\User;
use App\Services\Ai\Agent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeLlmProvider;
use Tests\TestCase;

/**
 * STEP 38: authentication, validation, and the draft/session flow at the
 * HTTP layer. Provider calls are always faked here too — see STEP 28.
 */
class AssistantControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unauthenticated_user_cannot_reach_the_assistant(): void
    {
        $this->get('/assistant')->assertRedirect('/login');
        $this->post('/assistant/messages', ['message' => 'hi'])->assertRedirect('/login');
    }

    public function test_an_authenticated_user_can_view_the_assistant_page(): void
    {
        $this->actingAs(User::factory()->create())->get('/assistant')->assertOk();
    }

    public function test_a_message_over_the_configured_length_is_rejected(): void
    {
        config(['services.ai.max_message_length' => 50]);
        $user = User::factory()->create();

        $this->actingAs($user)->post('/assistant/messages', ['message' => str_repeat('a', 51)])
            ->assertSessionHasErrors('message');
    }

    public function test_an_empty_message_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/assistant/messages', ['message' => ''])
            ->assertSessionHasErrors('message');
    }

    public function test_sending_a_message_appends_to_the_conversation_and_records_an_interaction(): void
    {
        $this->app->instance(LlmProvider::class, new FakeLlmProvider([FakeLlmProvider::text('Here is your answer.')]));
        $this->app->forgetInstance(Agent::class);

        $user = User::factory()->create();

        $this->actingAs($user)->post('/assistant/messages', ['message' => 'How is my pipeline?'])
            ->assertRedirect(route('assistant.show'));

        $this->actingAs($user)->get('/assistant')
            ->assertOk()
            ->assertSee('How is my pipeline?')
            ->assertSee('Here is your answer.');

        $this->assertDatabaseCount('agent_interactions', 1);
        $this->assertSame($user->id, AgentInteraction::first()->user_id);
    }

    public function test_a_draft_response_populates_the_pending_draft_and_a_new_message_clears_it(): void
    {
        $user = User::factory()->create();
        EmailAccount::factory()->create(['user_id' => $user->id]);

        $this->app->instance(LlmProvider::class, new FakeLlmProvider([
            FakeLlmProvider::toolCall('draft_email', ['recipient' => 'x@example.test', 'subject' => 'Hi', 'body' => 'Hello']),
            FakeLlmProvider::text('Here is a draft.'),
        ]));
        $this->app->forgetInstance(Agent::class);

        $this->actingAs($user)->post('/assistant/messages', ['message' => 'Draft an email to x@example.test']);

        $response = $this->actingAs($user)->get('/assistant');
        $response->assertOk()->assertSee('x@example.test');

        // A second, unrelated message must clear the old pending draft —
        // it must never be actionable against a later, different turn.
        $this->app->instance(LlmProvider::class, new FakeLlmProvider([FakeLlmProvider::text('Sure, noted.')]));
        $this->app->forgetInstance(Agent::class);

        $this->actingAs($user)->post('/assistant/messages', ['message' => 'Never mind']);

        $secondResponse = $this->actingAs($user)->get('/assistant');
        $secondResponse->assertOk();
        $this->assertNull(session('assistant.pending_draft'));
    }

    public function test_dismissing_a_draft_clears_it_without_sending_anything(): void
    {
        $user = User::factory()->create();
        session(['assistant.pending_draft' => ['draft' => true, 'channel' => 'email', 'recipient' => 'x@example.test', 'body' => 'Hi']]);

        $this->actingAs($user)->post('/assistant/dismiss-draft')->assertRedirect(route('assistant.show'));

        $this->assertNull(session('assistant.pending_draft'));
        $this->assertDatabaseCount('communications', 0);
    }

    public function test_starting_a_new_conversation_clears_the_session(): void
    {
        $user = User::factory()->create();
        session(['assistant.conversation' => [['role' => 'user', 'content' => 'hi']]]);

        $this->actingAs($user)->post('/assistant/new')->assertRedirect(route('assistant.show'));

        $this->assertSame([], session('assistant.conversation', []));
    }

    public function test_logging_out_clears_the_conversation_so_the_next_user_on_the_same_browser_never_sees_it(): void
    {
        $this->app->instance(LlmProvider::class, new FakeLlmProvider([FakeLlmProvider::text('Answer for user A.')]));
        $this->app->forgetInstance(Agent::class);

        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $this->actingAs($userA)->post('/assistant/messages', ['message' => "What's my pipeline?"]);
        $this->assertDatabaseHas('agent_interactions', ['user_id' => $userA->id]);

        // The real logout flow (Session::invalidate()) fully flushes
        // session data — simulated here via the actual logout route
        // rather than the test harness's actingAs() shortcut, which
        // does not by itself touch the session.
        $this->post('/logout');

        $this->actingAs($userB)->get('/assistant')->assertDontSee('Answer for user A.');
    }
}
