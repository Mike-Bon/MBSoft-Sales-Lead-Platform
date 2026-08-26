<?php

namespace Tests\Feature\Workflow;

use App\Contracts\Ai\LlmProvider;
use App\Enums\WorkflowType;
use App\Jobs\Workflow\DailyFollowUpReviewJob;
use App\Jobs\Workflow\OpportunityAttentionReviewJob;
use App\Jobs\Workflow\PerformanceExceptionReviewJob;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkflowExecution;
use App\Services\Ai\Agent;
use App\Services\Workflow\Analyzers\DailyFollowUpAnalyzer;
use App\Services\Workflow\Analyzers\OpportunityAttentionAnalyzer;
use App\Services\Workflow\Analyzers\PerformanceExceptionAnalyzer;
use App\Services\Workflow\WorkflowExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeLlmProvider;
use Tests\TestCase;

/**
 * STEP 10/11/46: the queued jobs themselves, and the command that
 * dispatches one per (workflow type × user).
 */
class JobsAndCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_follow_up_review_job_creates_a_scoped_execution(): void
    {
        $this->app->instance(LlmProvider::class, new FakeLlmProvider([]));
        $this->app->forgetInstance(Agent::class);

        $user = User::factory()->create();
        Lead::factory()->create(['owner_id' => $user->id, 'next_follow_up_at' => now()->subDay()]);

        (new DailyFollowUpReviewJob($user->id))->handle(app(DailyFollowUpAnalyzer::class), app(WorkflowExecutionService::class));

        $execution = WorkflowExecution::firstOrFail();
        $this->assertSame(WorkflowType::DailyFollowUpReview, $execution->workflow);
        $this->assertSame($user->id, $execution->user_id);
    }

    public function test_a_missing_user_is_handled_gracefully(): void
    {
        $this->app->instance(LlmProvider::class, new FakeLlmProvider([]));
        $this->app->forgetInstance(Agent::class);

        (new DailyFollowUpReviewJob(999_999))->handle(app(DailyFollowUpAnalyzer::class), app(WorkflowExecutionService::class));

        $this->assertDatabaseCount('workflow_executions', 0);
    }

    public function test_opportunity_and_performance_jobs_also_produce_a_scoped_execution(): void
    {
        $this->app->instance(LlmProvider::class, new FakeLlmProvider([]));
        $this->app->forgetInstance(Agent::class);

        $user = User::factory()->create();

        (new OpportunityAttentionReviewJob($user->id))->handle(app(OpportunityAttentionAnalyzer::class), app(WorkflowExecutionService::class));
        (new PerformanceExceptionReviewJob($user->id))->handle(app(PerformanceExceptionAnalyzer::class), app(WorkflowExecutionService::class));

        $this->assertSame(2, WorkflowExecution::count());
        $this->assertTrue(WorkflowExecution::where('workflow', WorkflowType::OpportunityAttentionReview->value)->exists());
        $this->assertTrue(WorkflowExecution::where('workflow', WorkflowType::PerformanceExceptionReview->value)->exists());
    }

    public function test_the_daily_command_dispatches_one_job_per_workflow_per_user(): void
    {
        Queue::fake();

        $manager = User::factory()->manager()->create();
        $team = Team::factory()->create();
        $teamHead = User::factory()->teamHead($team)->create();

        $this->artisan('workflows:run-daily')->assertSuccessful();

        Queue::assertPushed(DailyFollowUpReviewJob::class, 2);
        Queue::assertPushed(OpportunityAttentionReviewJob::class, 2);
        Queue::assertPushed(PerformanceExceptionReviewJob::class, 2);
        Queue::assertPushed(DailyFollowUpReviewJob::class, fn ($job) => $job->userId === $manager->id);
        Queue::assertPushed(DailyFollowUpReviewJob::class, fn ($job) => $job->userId === $teamHead->id);
    }

    public function test_a_disabled_workflow_is_never_dispatched(): void
    {
        Queue::fake();
        config(['services.workflows.performance_exception_review.enabled' => false]);

        User::factory()->manager()->create();

        $this->artisan('workflows:run-daily')->assertSuccessful();

        Queue::assertPushed(DailyFollowUpReviewJob::class);
        Queue::assertNotPushed(PerformanceExceptionReviewJob::class);
    }

    public function test_a_job_dispatched_twice_for_the_same_day_still_only_produces_one_execution(): void
    {
        // Simulates a queue retry / duplicate dispatch — the job itself
        // runs twice, but WorkflowExecutionService's idempotency key
        // ensures only one execution/approval set exists (STEP 12).
        $this->app->instance(LlmProvider::class, new FakeLlmProvider([]));
        $this->app->forgetInstance(Agent::class);

        $user = User::factory()->create();
        Lead::factory()->create(['owner_id' => $user->id, 'next_follow_up_at' => now()->subDay()]);

        $analyzer = app(DailyFollowUpAnalyzer::class);
        $executor = app(WorkflowExecutionService::class);

        (new DailyFollowUpReviewJob($user->id))->handle($analyzer, $executor);
        (new DailyFollowUpReviewJob($user->id))->handle($analyzer, $executor);

        $this->assertSame(1, WorkflowExecution::count());
    }
}
