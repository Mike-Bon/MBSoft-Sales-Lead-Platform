<?php

use App\Http\Controllers\CostToServeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Cost-to-Serve routes (Phase 12)
|--------------------------------------------------------------------------
|
| `auth` keeps guests out; CostToServeController itself enforces the
| Manager/Team-Head-only rule (commercial economics), matching
| AgentIdentifier::CostToServe's own eligibility check.
|
*/

Route::middleware(['auth', 'verified'])->name('cost-to-serve.')->group(function () {
    Route::get('cost-to-serve', [CostToServeController::class, 'index'])->name('index');
});
