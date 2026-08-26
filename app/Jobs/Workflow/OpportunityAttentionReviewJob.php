<?php

namespace App\Jobs\Workflow;

use App\Enums\WorkflowType;
use App\Models\User;
use App\Services\Workflow\Analyzers\OpportunityAttentionAnalyzer;
use App\Services\Workflow\WorkflowExecutionService;
use App\Support\Workflow\WorkflowScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * STEP 7/11: see DailyFollowUpReviewJob's docblock — same pattern,
 * different analyzer. The analyzer never predicts an outcome (STEP 7);
 * it only reports deterministic signals (closing soon, no recent
 * activity, missing expected close date).
 */
class OpportunityAttentionReviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30];

    public function __construct(public readonly int $userId) {}

    public function handle(OpportunityAttentionAnalyzer $analyzer, WorkflowExecutionService $executor): void
    {
        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        $scope = WorkflowScope::forUser($user);
        $analysis = $analyzer->analyze(
            $scope,
            stalledDays: (int) config('services.workflows.stalled_opportunity_days', 14),
            closingSoonDays: (int) config('services.workflows.closing_soon_days', 7),
        );

        $executor->run(
            WorkflowType::OpportunityAttentionReview,
            $scope,
            $analysis,
            'Identify which open opportunities most need attention and explain why, using only the signals provided (closing soon, no recent activity, missing expected close date). Use language like "needs attention" or "potential risk" — never claim an opportunity will definitely close or fail. Do not prepare a draft unless specifically warranted.',
        );
    }
}
