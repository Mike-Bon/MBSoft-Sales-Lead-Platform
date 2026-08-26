<?php

use App\Http\Controllers\Crm\ActivityController;
use App\Http\Controllers\Crm\ContactController;
use App\Http\Controllers\Crm\LeadController;
use App\Http\Controllers\Crm\OpportunityController;
use App\Http\Controllers\Crm\OrganizationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CRM routes (Phase 3)
|--------------------------------------------------------------------------
|
| All routes below require authentication. As with routes/organisation.php,
| every controller action also enforces its own authorization via the
| relevant Policy — route protection here keeps guests out, it is not the
| authorization mechanism itself.
|
*/

Route::middleware(['auth', 'verified'])->name('crm.')->group(function () {
    Route::resource('organizations', OrganizationController::class);
    Route::resource('contacts', ContactController::class);
    Route::resource('leads', LeadController::class);
    Route::resource('opportunities', OpportunityController::class);
    Route::resource('activities', ActivityController::class)->only(['index', 'create', 'store']);
});
