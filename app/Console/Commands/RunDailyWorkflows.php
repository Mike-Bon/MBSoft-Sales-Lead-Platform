<?php

namespace App\Console\Commands;

use App\Jobs\Workflow\DailyFollowUpReviewJob;
use App\Jobs\Workflow\OpportunityAttentionReviewJob;
use App\Jobs\Workflow\PerformanceExceptionReviewJob;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * STEP 10/11: dispatches one queued job per (workflow type × user) —
 * every Manager, Team Head, and Team Member gets their own scoped
 * execution of every enabled workflow (STEP 23: never a single
 * org-wide "god mode" run standing in for everyone's permitted view).
 * The actual analysis/AI work happens in the queue, not in this command
 * — the scheduler only decides WHEN, never WHAT (STEP 4).
 */
class RunDailyWorkflows extends Command
{
    protected $signature = 'workflows:run-daily';

    protected $description = 'Dispatch the daily Phase 8 workflow reviews (Follow-Up, Opportunity Attention, Performance Exception) for every user, scoped to their own authorization.';

    public function handle(): int
    {
        $users = User::whereNotNull('role')->get(['id', 'role', 'team_id']);

        $dispatched = 0;

        foreach ($users as $user) {
            if (config('services.workflows.daily_follow_up_review.enabled', true)) {
                DailyFollowUpReviewJob::dispatch($user->id);
                $dispatched++;
            }

            if (config('services.workflows.opportunity_attention_review.enabled', true)) {
                OpportunityAttentionReviewJob::dispatch($user->id);
                $dispatched++;
            }

            if (config('services.workflows.performance_exception_review.enabled', true)) {
                PerformanceExceptionReviewJob::dispatch($user->id);
                $dispatched++;
            }
        }

        $this->info("Dispatched {$dispatched} workflow job(s) for {$users->count()} user(s).");

        return self::SUCCESS;
    }
}
