<?php

namespace Tests\Feature\Workflow;

use App\Contracts\Ai\LlmProvider;
use App\Enums\AgentIdentifier;
use App\Enums\ApprovalStatus;
use App\Enums\WorkflowStatus;
use App\Enums\WorkflowType;
use App\Models\Contact;
use App\Models\EmailAccount;
use App\Models\User;
use App\Models\WorkflowApproval;
use App\Models\WorkflowExecution;
use App\Services\Workflow\WorkflowExecutionService;
use App\Support\Ai\AiCompletionResult;
use App\Support\Ai\AiProviderException;
use App\Support\Workflow\AnalysisResult;
use App\Support\Workflow\WorkflowScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeLlmProvider;
use Tests\TestCase;

/**
 * STEP 3/12/13: the orchestrator's own responsibilities, independent of
 * any specific workflow's analyzer — idempotency, cost-control skip,
 * audit linkage, draft-to-approval conversion, and safe failure
 * handling. Since Phase 9, run() also takes an AgentIdentifier — these
 * tests use Communication (the same agent DailyFollowUpReviewJob
 * actually uses, STEP 44) except where a test is specifically about
 * behaviour that doesn't depend on which agent was chosen.
 */
class WorkflowExecutionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fake(FakeLlmProvider $provider): void
    {
        $this->app->instance(LlmProvider::class, $provider);
    }

    public function test_no_findings_skips_the_agent_entirely_and_records_a_deterministic_result(): void
    {
        $provider = new FakeLlmProvider([]);
        $this->fake($provider);

        $user = User::factory()->create();
        $execution = app(WorkflowExecutionService::class)->run(
            WorkflowType::DailyFollowUpReview,
            AgentIdentifier::Communication,
            WorkflowScope::forUser($user),
            new AnalysisResult(false, [], 'No overdue follow-ups today.'),
            'task',
        );

        $this->assertSame(WorkflowStatus::Completed, $execution->status);
        $this->assertSame('No overdue follow-ups today.', $execution->result);
        $this->assertNull($execution->agent_interaction_id);
        $this->assertCount(0, $provider->calls);
    }

    public function test_findings_invoke_the_agent_and_link_the_interaction(): void
    {
        $provider = new FakeLlmProvider([FakeLlmProvider::text('Prioritize ABC Manufacturing first.')]);
        $this->fake($provider);

        $user = User::factory()->create();
        $execution = app(WorkflowExecutionService::class)->run(
            WorkflowType::DailyFollowUpReview,
            AgentIdentifier::Communication,
            WorkflowScope::forUser($user),
            new AnalysisResult(true, ['overdue_count' => 3], ''),
            'task',
        );

        $this->assertSame(WorkflowStatus::Completed, $execution->status);
        $this->assertSame('Prioritize ABC Manufacturing first.', $execution->result);
        $this->assertNotNull($execution->agent_interaction_id);
        $this->assertSame('communication', $execution->agentInteraction->agent);
        $this->assertCount(1, $provider->calls);
    }

    public function test_the_prompt_sent_to_the_agent_uses_the_workflow_context_structure(): void
    {
        $provider = new FakeLlmProvider([FakeLlmProvider::text('ok')]);
        $this->fake($provider);

        $user = User::factory()->create();
        app(WorkflowExecutionService::class)->run(
            WorkflowType::DailyFollowUpReview,
            AgentIdentifier::Communication,
            WorkflowScope::forUser($user),
            new AnalysisResult(true, ['overdue_count' => 1], ''),
            'Identify priorities.',
        );

        $sentMessage = end($provider->calls[0]['messages'])['content'];
        $this->assertStringContainsString('WORKFLOW CONTEXT:', $sentMessage);
        $this->assertStringContainsString('Daily Follow-Up Review', $sentMessage);
        $this->assertStringContainsString('TASK:', $sentMessage);
        $this->assertStringContainsString('DATA:', $sentMessage);
        $this->assertStringContainsString('RULES:', $sentMessage);
        $this->assertStringContainsString('Do not send anything.', $sentMessage);
    }

    public function test_duplicate_execution_for_the_same_scope_and_day_is_prevented(): void
    {
        $provider = new FakeLlmProvider([FakeLlmProvider::text('a'), FakeLlmProvider::text('b')]);
        $this->fake($provider);

        $user = User::factory()->create();
        $scope = WorkflowScope::forUser($user);
        $analysis = new AnalysisResult(true, ['x' => 1], '');

        $first = app(WorkflowExecutionService::class)->run(WorkflowType::DailyFollowUpReview, AgentIdentifier::Communication, $scope, $analysis, 'task');
        $second = app(WorkflowExecutionService::class)->run(WorkflowType::DailyFollowUpReview, AgentIdentifier::Communication, $scope, $analysis, 'task');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, WorkflowExecution::count());
        $this->assertCount(1, $provider->calls);
    }

    public function test_a_different_workflow_type_for_the_same_user_is_not_treated_as_a_duplicate(): void
    {
        $provider = new FakeLlmProvider([FakeLlmProvider::text('a'), FakeLlmProvider::text('b')]);
        $this->fake($provider);

        $user = User::factory()->create();
        $scope = WorkflowScope::forUser($user);
        $analysis = new AnalysisResult(true, ['x' => 1], '');

        app(WorkflowExecutionService::class)->run(WorkflowType::DailyFollowUpReview, AgentIdentifier::Communication, $scope, $analysis, 'task');
        app(WorkflowExecutionService::class)->run(WorkflowType::OpportunityAttentionReview, AgentIdentifier::Sales, $scope, $analysis, 'task');

        $this->assertSame(2, WorkflowExecution::count());
    }

    public function test_llm_failure_marks_the_execution_failed_without_throwing(): void
    {
        $failing = new class implements LlmProvider
        {
            public function complete(string $systemPrompt, array $messages, array $tools): AiCompletionResult
            {
                throw new AiProviderException('down');
            }
        };
        $this->app->instance(LlmProvider::class, $failing);

        $user = User::factory()->create();
        $execution = app(WorkflowExecutionService::class)->run(
            WorkflowType::DailyFollowUpReview,
            AgentIdentifier::Communication,
            WorkflowScope::forUser($user),
            new AnalysisResult(true, ['x' => 1], ''),
            'task',
        );

        $this->assertSame(WorkflowStatus::Failed, $execution->status);
        $this->assertNotNull($execution->error_summary);
        $this->assertStringNotContainsString('down', $execution->error_summary);
    }

    public function test_a_produced_draft_becomes_a_persisted_approval_not_an_immediate_send(): void
    {
        $user = User::factory()->create();
        EmailAccount::factory()->create(['user_id' => $user->id]);
        $contact = Contact::factory()->create(['owner_id' => $user->id]);

        $provider = new FakeLlmProvider([
            FakeLlmProvider::toolCall('draft_email', ['recipient' => 'jamie@example.test', 'subject' => 'Hi', 'body' => 'Following up', 'contact_id' => $contact->id]),
            FakeLlmProvider::text('I prepared a follow-up draft for Jamie.'),
        ]);
        $this->fake($provider);

        $execution = app(WorkflowExecutionService::class)->run(
            WorkflowType::DailyFollowUpReview,
            AgentIdentifier::Communication,
            WorkflowScope::forUser($user),
            new AnalysisResult(true, ['leads' => [['contact_id' => $contact->id]]], ''),
            'task',
        );

        $approval = WorkflowApproval::firstOrFail();
        $this->assertSame($execution->id, $approval->workflow_execution_id);
        $this->assertSame($user->id, $approval->user_id);
        $this->assertSame('jamie@example.test', $approval->recipient);
        $this->assertSame(ApprovalStatus::Pending, $approval->status);
        $this->assertNotNull($approval->expires_at);
        $this->assertDatabaseCount('communications', 0);
    }

    public function test_the_agent_must_actually_have_the_tool_the_task_asks_it_to_use(): void
    {
        // STEP 24/44: the Performance Agent has no draft tools — if
        // asked to draft, the model could only ever hit "unknown tool",
        // never actually produce one. Confirms the tool permission
        // matrix is real, not just documented.
        $user = User::factory()->create();
        $provider = new FakeLlmProvider([
            FakeLlmProvider::toolCall('draft_email', ['recipient' => 'x@example.test', 'body' => 'Hi']),
            FakeLlmProvider::text('I do not have a way to draft a message.'),
        ]);
        $this->fake($provider);

        app(WorkflowExecutionService::class)->run(
            WorkflowType::PerformanceExceptionReview,
            AgentIdentifier::Performance,
            WorkflowScope::forUser($user),
            new AnalysisResult(true, ['exceptions' => []], ''),
            'task',
        );

        $this->assertDatabaseCount('workflow_approvals', 0);
    }
}
