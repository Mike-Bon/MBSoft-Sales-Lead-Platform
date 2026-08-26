<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Phase 8: the only scheduler entry point for controlled agentic
// workflows — WHEN they run, not WHAT they do (STEP 4/10). Configurable
// via WORKFLOW_RUN_AT rather than a hard-coded time. ->withoutOverlapping()
// prevents two overlapping runs of this command itself; per-execution
// idempotency (execution_key) is still WorkflowExecutionService's job,
// since individual jobs from this run could still be retried
// independently by the queue.
Schedule::command('workflows:run-daily')
    ->dailyAt(config('services.workflows.run_at', '08:00'))
    ->withoutOverlapping()
    ->onOneServer();
