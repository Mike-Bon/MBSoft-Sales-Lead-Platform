<?php

namespace Tests\Feature\MarketIntelligence;

use App\Contracts\Ai\LlmProvider;
use App\Contracts\MarketIntelligence\SearchProvider;
use App\Enums\ProspectResearchStatus;
use App\Jobs\MarketIntelligence\ProspectResearchJob;
use App\Models\AgentInteraction;
use App\Models\ProspectResearchRun;
use App\Models\User;
use App\Services\Ai\AssistantService;
use App\Support\Ai\AiCompletionResult;
use App\Support\Ai\AiProviderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeLlmProvider;
use Tests\Support\FakeSearchProvider;
use Tests\TestCase;

/**
 * V2.0.3: user-initiated Market Intelligence runs asynchronously via
 * ProspectResearchJob on a dedicated queue. Every other assistant agent
 * stays synchronous. The HTTP request must do NO Gemini / Brave /
 * page-fetch work.
 */
class AsyncResearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SearchProvider::class, FakeSearchProvider::withRows([]));
    }

    private function submit(User $user, string $message, ?string $submissionId = null, ?string $agent = 'market_intelligence'): TestResponse
    {
        return $this->actingAs($user)->post('/assistant/messages', array_filter([
            'message' => $message,
            'agent' => $agent,
            'submission_id' => $submissionId ?? (string) Str::uuid(),
        ], fn ($v) => $v !== null));
    }

    // ── the HTTP path ───────────────────────────────────────────────

    public function test_an_mi_post_dispatches_a_job_and_never_runs_the_agent_synchronously(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        $manager = User::factory()->manager()->create();

        $this->submit($manager, 'Find 3 cosmetics sellers in Cebu that sell online.')
            ->assertRedirect(route('assistant.show'));

        Queue::assertPushed(ProspectResearchJob::class, 1);
        $this->assertDatabaseCount('agent_interactions', 0);
        Http::assertNothingSent();
    }

    public function test_an_mi_post_returns_immediately_with_a_pending_turn(): void
    {
        Queue::fake();
        $manager = User::factory()->manager()->create();

        $this->submit($manager, 'Find cosmetics sellers in Cebu.');

        $run = ProspectResearchRun::firstOrFail();
        $this->assertSame(ProspectResearchStatus::Queued, $run->status);

        $this->actingAs($manager)->get('/assistant')
            ->assertOk()
            ->assertSee('Market Intelligence research is queued');
    }

    public function test_a_non_market_intelligence_request_stays_synchronous(): void
    {
        Queue::fake();
        $this->app->instance(LlmProvider::class, new FakeLlmProvider([FakeLlmProvider::text('Your pipeline is healthy.')]));
        $user = User::factory()->create();

        $this->actingAs($user)->post('/assistant/messages', [
            'message' => 'How is my sales pipeline looking this month?',
            'submission_id' => (string) Str::uuid(),
        ])->assertRedirect(route('assistant.show'));

        Queue::assertNothingPushed();
        $this->assertDatabaseHas('agent_interactions', ['user_id' => $user->id]);
        $this->assertDatabaseCount('prospect_research_runs', 0);
    }

    public function test_a_manager_and_a_team_head_can_initiate_research(): void
    {
        Queue::fake();

        $this->submit(User::factory()->manager()->create(), 'Find cosmetics sellers in Cebu.');
        $this->submit(User::factory()->teamHead()->create(), 'Find apparel makers in Davao.');

        Queue::assertPushed(ProspectResearchJob::class, 2);
        $this->assertDatabaseCount('prospect_research_runs', 2);
    }

    public function test_a_team_member_explicitly_selecting_mi_is_rejected_and_no_job_is_queued(): void
    {
        Queue::fake();

        $this->submit(User::factory()->teamMember()->create(), 'Find cosmetics sellers in Cebu.')
            ->assertSessionHasErrors('agent');

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('prospect_research_runs', 0);
    }

    public function test_a_team_members_discovery_question_falls_back_to_sales_and_stays_synchronous(): void
    {
        Queue::fake();
        $this->app->instance(LlmProvider::class, new FakeLlmProvider([FakeLlmProvider::text('ok')]));
        $member = User::factory()->teamMember()->create();

        $this->submit($member, 'Find businesses in Cebu City that sell cosmetics online.', agent: null)
            ->assertRedirect(route('assistant.show'));

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('prospect_research_runs', 0);
        $this->assertSame('sales', AgentInteraction::firstOrFail()->agent);
    }

    // ── idempotency ─────────────────────────────────────────────────

    public function test_a_duplicate_submission_does_not_create_or_dispatch_a_second_run(): void
    {
        Queue::fake();
        $manager = User::factory()->manager()->create();
        $token = (string) Str::uuid();

        $this->submit($manager, 'Find cosmetics sellers in Cebu.', $token);
        $this->submit($manager, 'Find cosmetics sellers in Cebu.', $token);
        $this->submit($manager, 'Find cosmetics sellers in Cebu.', $token);

        Queue::assertPushed(ProspectResearchJob::class, 1);
        $this->assertDatabaseCount('prospect_research_runs', 1);
    }

    public function test_a_fresh_submission_token_creates_a_new_run_even_for_the_same_question(): void
    {
        Queue::fake();
        $manager = User::factory()->manager()->create();

        $this->submit($manager, 'Find cosmetics sellers in Cebu.', (string) Str::uuid());
        $this->submit($manager, 'Find cosmetics sellers in Cebu.', (string) Str::uuid());

        Queue::assertPushed(ProspectResearchJob::class, 2);
        $this->assertDatabaseCount('prospect_research_runs', 2);
    }

    public function test_two_users_submitting_the_same_token_string_get_separate_runs(): void
    {
        Queue::fake();
        $token = (string) Str::uuid();

        $this->submit(User::factory()->manager()->create(), 'Find cosmetics sellers in Cebu.', $token);
        $this->submit(User::factory()->teamHead()->create(), 'Find cosmetics sellers in Cebu.', $token);

        Queue::assertPushed(ProspectResearchJob::class, 2);
        $this->assertDatabaseCount('prospect_research_runs', 2);
    }

    // ── the job ─────────────────────────────────────────────────────

    private function runJob(ProspectResearchRun $run): void
    {
        (new ProspectResearchJob($run->id))->handle(app(AssistantService::class));
    }

    public function test_the_job_transitions_queued_to_running_to_completed(): void
    {
        Http::preventStrayRequests();
        $this->app->instance(LlmProvider::class, new FakeLlmProvider([FakeLlmProvider::text('Here are 3 prospects scored on public evidence.')]));
        $manager = User::factory()->manager()->create();
        $run = ProspectResearchRun::factory()->for($manager)->create();

        $this->assertSame(ProspectResearchStatus::Queued, $run->status);

        $this->runJob($run);
        $run->refresh();

        $this->assertSame(ProspectResearchStatus::Completed, $run->status);
        $this->assertSame('Here are 3 prospects scored on public evidence.', $run->result);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->completed_at);
    }

    public function test_the_job_executes_the_agent_as_the_original_requesting_user(): void
    {
        Http::preventStrayRequests();
        $this->app->instance(LlmProvider::class, new FakeLlmProvider([FakeLlmProvider::text('done')]));
        $manager = User::factory()->manager()->create();
        $run = ProspectResearchRun::factory()->for($manager)->create();

        $this->runJob($run);

        // AssistantService::record() writes the audit row as the actor.
        $this->assertDatabaseHas('agent_interactions', [
            'user_id' => $manager->id,
            'agent' => 'market_intelligence',
        ]);
        $this->assertSame(AgentInteraction::firstOrFail()->id, $run->fresh()->agent_interaction_id);
    }

    public function test_a_failed_agent_response_maps_to_a_failed_run_with_a_safe_summary(): void
    {
        $this->app->instance(LlmProvider::class, new class implements LlmProvider
        {
            public function complete(string $systemPrompt, array $messages, array $tools): AiCompletionResult
            {
                throw new AiProviderException('raw provider detail that must not surface');
            }
        });
        $run = ProspectResearchRun::factory()->for(User::factory()->manager())->create();

        $this->runJob($run);
        $run->refresh();

        $this->assertSame(ProspectResearchStatus::Failed, $run->status);
        $this->assertNull($run->result);
        $this->assertNotNull($run->error_summary);
        $this->assertStringNotContainsString('raw provider detail', $run->error_summary);
    }

    public function test_an_uncaught_job_failure_marks_the_run_failed_via_the_failed_hook(): void
    {
        $run = ProspectResearchRun::factory()->running()->for(User::factory()->manager())->create();

        (new ProspectResearchJob($run->id))->failed(new \RuntimeException('super secret stack detail'));
        $run->refresh();

        $this->assertSame(ProspectResearchStatus::Failed, $run->status);
        $this->assertStringNotContainsString('secret stack detail', (string) $run->error_summary);
    }

    public function test_the_job_never_runs_a_non_queued_run_twice(): void
    {
        Http::preventStrayRequests();
        $this->app->instance(LlmProvider::class, new FakeLlmProvider([FakeLlmProvider::text('should not overwrite')]));
        $run = ProspectResearchRun::factory()->completed()->for(User::factory()->manager())->create();
        $original = $run->result;

        $this->runJob($run);

        $this->assertSame($original, $run->fresh()->result);
        $this->assertDatabaseCount('agent_interactions', 0);
    }

    // ── job configuration ───────────────────────────────────────────

    public function test_the_job_is_single_attempt_with_overlap_protection_on_its_own_queue(): void
    {
        $job = new ProspectResearchJob(1);

        $this->assertSame(1, $job->tries);
        $this->assertSame('market-intelligence', $job->connection);
        $this->assertSame('market-intelligence', $job->queue);
        $this->assertContainsOnlyInstancesOf(WithoutOverlapping::class, $job->middleware());
    }

    public function test_the_mi_queue_retry_after_safely_exceeds_the_job_timeout(): void
    {
        $job = new ProspectResearchJob(1);
        $retryAfter = (int) config('queue.connections.market-intelligence.retry_after');

        $this->assertGreaterThan($job->timeout, $retryAfter);
        // A comfortable margin, not merely one second larger.
        $this->assertGreaterThanOrEqual(300, $retryAfter - $job->timeout);
    }

    // ── status endpoint / authorization ─────────────────────────────

    public function test_the_owner_can_poll_the_status_endpoint(): void
    {
        $manager = User::factory()->manager()->create();
        $run = ProspectResearchRun::factory()->running()->for($manager)->create();

        $this->actingAs($manager)->getJson(route('assistant.research.status', $run))
            ->assertOk()
            ->assertExactJson(['status' => 'running', 'done' => false]);
    }

    public function test_polling_reports_done_for_a_terminal_run(): void
    {
        $manager = User::factory()->manager()->create();
        $run = ProspectResearchRun::factory()->completed()->for($manager)->create();

        $this->actingAs($manager)->getJson(route('assistant.research.status', $run))
            ->assertOk()
            ->assertJson(['status' => 'completed', 'done' => true]);
    }

    public function test_another_user_cannot_read_someone_elses_run(): void
    {
        $run = ProspectResearchRun::factory()->for(User::factory()->manager())->create();
        $intruder = User::factory()->manager()->create();

        $this->actingAs($intruder)->getJson(route('assistant.research.status', $run))->assertForbidden();
    }

    public function test_an_unauthenticated_visitor_cannot_read_a_run(): void
    {
        $run = ProspectResearchRun::factory()->for(User::factory()->manager())->create();

        $this->getJson(route('assistant.research.status', $run))->assertUnauthorized();
    }

    // ── settling the result back into the conversation ──────────────

    public function test_a_completed_run_renders_its_result_in_the_conversation(): void
    {
        $manager = User::factory()->manager()->create();
        $run = ProspectResearchRun::factory()->completed()->for($manager)->create([
            'result' => 'PROSPECT REPORT: three businesses evaluated.',
        ]);
        session(['assistant.conversation' => [
            ['role' => 'user', 'content' => 'Find cosmetics sellers in Cebu.'],
            ['role' => 'assistant', 'agent' => 'market_intelligence', 'agent_label' => 'Market Intelligence', 'content' => null, 'tools_used' => [], 'status' => 'queued', 'research_run_id' => $run->id],
        ]]);

        $this->actingAs($manager)->get('/assistant')
            ->assertOk()
            ->assertSee('PROSPECT REPORT: three businesses evaluated.')
            ->assertDontSee('research is queued');
    }

    public function test_a_failed_run_renders_a_safe_failure_message_and_the_form_is_still_usable(): void
    {
        $manager = User::factory()->manager()->create();
        $run = ProspectResearchRun::factory()->failed()->for($manager)->create();
        session(['assistant.conversation' => [
            ['role' => 'user', 'content' => 'Find cosmetics sellers in Cebu.'],
            ['role' => 'assistant', 'agent' => 'market_intelligence', 'agent_label' => 'Market Intelligence', 'content' => null, 'tools_used' => [], 'status' => 'running', 'research_run_id' => $run->id],
        ]]);

        $this->actingAs($manager)->get('/assistant')
            ->assertOk()
            ->assertSee('could not be completed')
            ->assertSee('name="message"', false);
    }

    public function test_the_conversation_no_longer_lists_a_pending_run_once_it_is_terminal(): void
    {
        $manager = User::factory()->manager()->create();
        $run = ProspectResearchRun::factory()->completed()->for($manager)->create();
        session(['assistant.conversation' => [
            ['role' => 'assistant', 'agent' => 'market_intelligence', 'content' => null, 'tools_used' => [], 'status' => 'running', 'research_run_id' => $run->id],
        ]]);

        // Second GET: the settled turn is terminal, so no poller is emitted.
        $this->actingAs($manager)->get('/assistant');
        $this->actingAs($manager)->get('/assistant')
            ->assertOk()
            ->assertDontSee('setInterval(() => check()', false);
    }
}
