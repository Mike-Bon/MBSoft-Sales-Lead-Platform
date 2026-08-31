<?php

use App\Http\Controllers\Ai\AssistantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AI Assistant routes (Phase 7)
|--------------------------------------------------------------------------
|
| Authenticated + rate-limited, exactly like every other route group in
| this application — STEP 37: no public AI endpoint exists.
|
*/

Route::middleware(['auth', 'verified'])->name('assistant.')->group(function () {
    Route::get('assistant', [AssistantController::class, 'show'])->name('show');
    Route::post('assistant/messages', [AssistantController::class, 'sendMessage'])->middleware('throttle:20,1')->name('send-message');
    Route::post('assistant/new', [AssistantController::class, 'newConversation'])->name('new-conversation');
    Route::post('assistant/dismiss-draft', [AssistantController::class, 'dismissDraft'])->name('dismiss-draft');

    // V2.0.3: lightweight polling target for an async Market Intelligence
    // research run. Owner-only (ProspectResearchRunPolicy) — the run id
    // is never an authorization surface.
    Route::get('assistant/research/{researchRun}/status', [AssistantController::class, 'researchStatus'])
        ->middleware('throttle:120,1')
        ->name('research.status');
});
