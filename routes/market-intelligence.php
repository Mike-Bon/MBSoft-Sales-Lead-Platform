<?php

use App\Http\Controllers\MarketIntelligence\ProspectLeadProposalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Market Intelligence routes (V2.5)
|--------------------------------------------------------------------------
|
| `auth` + `verified` keep guests out; ProspectLeadProposalController
| itself enforces ProspectLeadProposalPolicy (Manager / Team Head only,
| and only the proposal's own owner). The confirm POST is the ONLY path
| in the whole V2 pipeline that writes a CRM lead — it is throttled and
| goes through ProspectLeadCreationService (fingerprint + eligibility +
| fresh duplicate re-check + existing V1 LeadService).
|
*/

Route::middleware(['auth', 'verified'])->name('market-intelligence.')->group(function () {
    Route::get('market-intelligence/prospect-proposals/{proposal}', [ProspectLeadProposalController::class, 'show'])
        ->name('prospect-proposals.show');

    Route::post('market-intelligence/prospect-proposals/{proposal}/confirm', [ProspectLeadProposalController::class, 'confirm'])
        ->middleware('throttle:12,1')
        ->name('prospect-proposals.confirm');

    Route::post('market-intelligence/prospect-proposals/{proposal}/cancel', [ProspectLeadProposalController::class, 'cancel'])
        ->name('prospect-proposals.cancel');
});
