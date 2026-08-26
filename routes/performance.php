<?php

use App\Http\Controllers\Performance\PerformanceController;
use App\Http\Controllers\Performance\TargetController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Target & performance routes (Phase 4)
|--------------------------------------------------------------------------
|
| All routes below require authentication. As with routes/crm.php, every
| controller action also enforces its own authorization — route
| protection here keeps guests out, it is not the authorization
| mechanism itself.
|
*/

Route::middleware(['auth', 'verified'])->name('performance.')->group(function () {
    Route::resource('targets', TargetController::class);

    Route::prefix('performance')->group(function () {
        Route::get('/', [PerformanceController::class, 'index'])->name('index');
        Route::get('/users/{user}', [PerformanceController::class, 'individual'])->name('individual');
    });
});
