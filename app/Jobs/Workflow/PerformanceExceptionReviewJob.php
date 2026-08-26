<?php

namespace App\Jobs\Workflow;

use App\Enums\WorkflowType;
use App\Models\User;
use App\Services\Workflow\Analyzers\PerformanceExceptionAnalyzer;
use App\Services\Workflow\WorkflowExecutionService;
use App\Support\Workflow\WorkflowScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * STEP 8/13: see DailyFollowUpReviewJob's docblock. Every number in the
 * analysis comes from PerformanceService — the agent explains them, it
 * never recalculates them (STEP 8/38).
 */
class PerformanceExceptionReviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30];

    public function __construct(public readonly int $userId) {}

    public function handle(PerformanceExceptionAnalyzer $analyzer, WorkflowExecutionService $executor): void
    {
        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        $scope = WorkflowScope::forUser($user);
        $analysis = $analyzer->analyze($scope);

        $executor->run(
            WorkflowType::PerformanceExceptionReview,
            $scope,
            $analysis,
            'Explain the performance exceptions listed below in a short, concise summary — every number is already calculated and authoritative; do not recompute or adjust any of them. Do not prepare a draft for this workflow.',
        );
    }
}
