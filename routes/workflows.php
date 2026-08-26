<?php

use App\Http\Controllers\Workflow\ApprovalController;
use App\Http\Controllers\Workflow\WorkflowActivityController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Workflow routes (Phase 8)
|--------------------------------------------------------------------------
|
| Same pattern as every other route group in this application — `auth`
| middleware keeps guests out; every controller action separately
| enforces its own Policy.
|
*/

Route::middleware(['auth', 'verified'])->name('workflows.')->group(function () {
    Route::get('workflows', [WorkflowActivityController::class, 'index'])->name('index');
    Route::get('workflows/approvals', [ApprovalController::class, 'index'])->name('approvals.index');
    Route::post('workflows/approvals/{approval}/reject', [ApprovalController::class, 'reject'])->name('approvals.reject');
    Route::get('workflows/{workflowExecution}', [WorkflowActivityController::class, 'show'])->name('show');
});
