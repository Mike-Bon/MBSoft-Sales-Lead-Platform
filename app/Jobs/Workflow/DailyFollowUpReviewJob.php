<?php

namespace App\Jobs\Workflow;

use App\Enums\AgentIdentifier;
use App\Enums\WorkflowType;
use App\Models\User;
use App\Services\Workflow\Analyzers\DailyFollowUpAnalyzer;
use App\Services\Workflow\WorkflowExecutionService;
use App\Support\Workflow\WorkflowScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * STEP 6/11/44: one execution, scoped to one user's permitted follow-up
 * records (Manager: organisation-wide; Team Head: their team;
 * everyone else: their own). Deterministic filtering
 * (DailyFollowUpAnalyzer) happens before the agent is ever involved —
 * see WorkflowExecutionService. Uses the Communication & Follow-Up
 * Agent (STEP 44's explicit mapping) — this is a review of who to
 * follow up with, which is exactly that agent's specialty, and it's the
 * only one of the three with drafting tools.
 */
class DailyFollowUpReviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30];

    public function __construct(public readonly int $userId) {}

    public function handle(DailyFollowUpAnalyzer $analyzer, WorkflowExecutionService $executor): void
    {
        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        $scope = WorkflowScope::forUser($user);
        $analysis = $analyzer->analyze($scope);

        $executor->run(
            WorkflowType::DailyFollowUpReview,
            AgentIdentifier::Communication,
            $scope,
            $analysis,
            'Identify which overdue/due-today follow-ups are the highest priority and explain why, in a short, concise summary. If a genuinely warranted follow-up message would help, you may prepare one draft with draft_email or draft_whatsapp — otherwise just summarize.',
        );
    }
}
