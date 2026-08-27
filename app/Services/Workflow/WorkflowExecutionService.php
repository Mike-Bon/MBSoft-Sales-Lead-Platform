<?php

namespace App\Services\Workflow;

use App\Enums\AgentIdentifier;
use App\Enums\AgentInteractionStatus;
use App\Enums\WorkflowStatus;
use App\Enums\WorkflowType;
use App\Models\AgentInteraction;
use App\Models\WorkflowApproval;
use App\Models\WorkflowExecution;
use App\Notifications\WorkflowApprovalPendingNotification;
use App\Services\Ai\AssistantService;
use App\Support\Workflow\AnalysisResult;
use App\Support\Workflow\WorkflowScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * STEP 3/13/44: the central orchestrator every Phase 8 workflow job
 * calls. Reuses AssistantService::respond() verbatim — the exact same
 * Phase 9 Agent engine, ToolRegistry, LlmProvider, and AgentInteraction
 * audit model, never a second, parallel AI pipeline. Since Phase 9, the
 * caller specifies WHICH of the three specialized agents this workflow
 * uses (STEP 44's explicit mapping: Daily Follow-Up Review →
 * Communication Agent, Opportunity Attention Review → Sales Agent,
 * Performance Exception Review → Performance Agent) — this class itself
 * has no opinion on that mapping, it only executes whichever agent it's
 * told to. This class's own responsibilities remain: idempotent
 * execution recording (STEP 12), skipping the agent entirely when
 * deterministic analysis found nothing (STEP 36 cost control), and
 * turning a produced draft into a persisted WorkflowApproval (STEP
 * 19/20) rather than an ephemeral session draft.
 */
class WorkflowExecutionService
{
    public function __construct(private readonly AssistantService $assistant) {}

    public function run(WorkflowType $type, AgentIdentifier $agentId, WorkflowScope $scope, AnalysisResult $analysis, string $task, string $trigger = 'scheduled'): WorkflowExecution
    {
        $executionKey = $scope->executionKey($type->value);

        if ($existing = WorkflowExecution::where('execution_key', $executionKey)->first()) {
            // Idempotency (STEP 12): the same (workflow, scope, day) was
            // already recorded — never re-run it, never create a second
            // execution or a second approval.
            return $existing;
        }

        $execution = new WorkflowExecution;
        $execution->workflow = $type;
        $execution->trigger = $trigger;
        $execution->status = WorkflowStatus::Running;
        $execution->user_id = $scope->subject->id;
        $execution->scope_type = $scope->type;
        $execution->scope_team_id = $scope->team?->id;
        $execution->execution_key = $executionKey;
        $execution->findings = $analysis->findings;
        $execution->started_at = now();

        try {
            $execution->save();
        } catch (UniqueConstraintViolationException) {
            // Lost a race with a concurrent dispatch of the same job
            // (e.g. a retried queue message) — the row that won owns
            // this execution; return it rather than duplicating work.
            return WorkflowExecution::where('execution_key', $executionKey)->firstOrFail();
        }

        if (! $analysis->hasFindings) {
            $execution->status = WorkflowStatus::Completed;
            $execution->result = $analysis->noFindingsMessage;
            $execution->completed_at = now();
            $execution->save();

            return $execution;
        }

        $this->completeWithAgent($execution, $type, $agentId, $scope, $analysis, $task);

        return $execution;
    }

    private function completeWithAgent(WorkflowExecution $execution, WorkflowType $type, AgentIdentifier $agentId, WorkflowScope $scope, AnalysisResult $analysis, string $task): void
    {
        $message = WorkflowPromptBuilder::build($type, $task, $analysis->findings);
        $lastInteractionIdBefore = (int) (AgentInteraction::max('id') ?? 0);

        try {
            $response = $this->assistant->respond($agentId, $scope->subject, $message, []);
        } catch (\Throwable $e) {
            Log::error('Workflow execution failed', ['workflow' => $type->value, 'execution_id' => $execution->id, 'exception' => $e->getMessage()]);
            $execution->status = WorkflowStatus::Failed;
            $execution->error_summary = 'The workflow could not complete.';
            $execution->completed_at = now();
            $execution->save();

            return;
        }

        $interaction = AgentInteraction::where('id', '>', $lastInteractionIdBefore)
            ->where('user_id', $scope->subject->id)
            ->orderBy('id')
            ->first();

        $isFailed = $response->status === AgentInteractionStatus::Failed;

        $execution->agent_interaction_id = $interaction?->id;
        $execution->status = $isFailed ? WorkflowStatus::Failed : WorkflowStatus::Completed;
        // AssistantService already caught and swallowed any provider
        // failure (STEP 28) — $response->text here is already its own
        // safe, generic message, never a raw exception. Surface it as
        // this execution's error_summary too, rather than leaving the
        // column null on a Failed row.
        $execution->result = $isFailed ? null : $response->text;
        $execution->error_summary = $isFailed ? $response->text : null;
        $execution->completed_at = now();
        $execution->save();

        if (($response->draft['draft'] ?? false) === true) {
            $this->createApproval($execution, $response->draft);
        }
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function createApproval(WorkflowExecution $execution, array $draft): WorkflowApproval
    {
        return DB::transaction(function () use ($execution, $draft) {
            $approval = new WorkflowApproval;
            $approval->workflow_execution_id = $execution->id;
            $approval->user_id = $execution->user_id;
            $approval->channel = $draft['channel'];
            $approval->recipient = $draft['recipient'];
            $approval->subject = $draft['subject'] ?? null;
            $approval->body = $draft['body'];
            $approval->organization_id = $draft['organization_id'] ?? null;
            $approval->contact_id = $draft['contact_id'] ?? null;
            $approval->lead_id = $draft['lead_id'] ?? null;
            $approval->opportunity_id = $draft['opportunity_id'] ?? null;
            $approval->whatsapp_number_id = $draft['whatsapp_number_id'] ?? null;
            $approval->expires_at = now()->addDays((int) config('services.workflows.approval_ttl_days', 3));
            $approval->save();

            // Phase 11: a database-only notification (no external I/O),
            // so writing it inside this same transaction keeps the
            // approval and its notification atomic — never one without
            // the other.
            $approval->user->notify(new WorkflowApprovalPendingNotification($approval));

            return $approval;
        });
    }
}
