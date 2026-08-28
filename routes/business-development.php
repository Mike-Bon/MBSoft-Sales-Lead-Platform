<?php

use App\Http\Controllers\BusinessDevelopmentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Business Development routes (Phase 13)
|--------------------------------------------------------------------------
|
| `auth` + `verified` keep guests out; BusinessDevelopmentController
| itself enforces the Manager/Team-Head-only rule, matching
| AgentIdentifier::BusinessDevelopment's own eligibility check. The page
| is read-only — it links out to the real CRM records for any action.
|
*/

Route::middleware(['auth', 'verified'])->name('business-development.')->group(function () {
    Route::get('business-development', [BusinessDevelopmentController::class, 'index'])->name('index');
});
