<?php

use App\Http\Controllers\CostToServeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Cost-to-Serve routes (Phase 12 / Phase 12A)
|--------------------------------------------------------------------------
|
| `auth` keeps guests out; CostToServeController itself enforces the
| actual policy — Manager-only, and (for `index` specifically, never
| for `settings`/`update`) only while the global switch is on. See
| CostToServeAccessService and the controller's own docblock for why
| feature access and feature administration are deliberately checked
| separately.
|
*/

Route::middleware(['auth', 'verified'])->name('cost-to-serve.')->group(function () {
    Route::get('cost-to-serve', [CostToServeController::class, 'index'])->name('index');
    Route::get('cost-to-serve/settings', [CostToServeController::class, 'settings'])->name('settings');
    Route::post('cost-to-serve/settings', [CostToServeController::class, 'updateSettings'])->name('settings.update');
});
