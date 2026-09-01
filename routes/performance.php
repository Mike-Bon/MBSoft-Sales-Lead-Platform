<?php

use App\Http\Controllers\Performance\FiscalActualEntryController;
use App\Http\Controllers\Performance\FiscalActualImportController;
use App\Http\Controllers\Performance\FiscalPerformanceController;
use App\Http\Controllers\Performance\PerformanceController;
use App\Http\Controllers\Performance\TargetController;
use App\Http\Controllers\Performance\TeamPerformanceController;
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

        // FY2026 Fiscal Performance extension: OPERATIONAL fiscal-year
        // performance from the corporate workbook — a separate screen,
        // never a replacement for the pipeline performance above.
        Route::get('/fiscal', [FiscalPerformanceController::class, 'index'])->name('fiscal.index');

        // Fiscal Performance Data Entry & Import UI — Manager-only
        // maintenance of OPERATIONAL actuals, reached from the fiscal
        // screen. Every action re-enforces PerformanceAuthorizer /
        // PerformanceImportPolicy server-side.
        Route::prefix('fiscal/actuals')->name('fiscal.actuals.')->group(function () {
            Route::get('/', [FiscalActualImportController::class, 'index'])->name('index');
            Route::get('/history', [FiscalActualImportController::class, 'history'])->name('history');
            Route::get('/template', [FiscalActualImportController::class, 'template'])->name('template');

            Route::get('/import', [FiscalActualImportController::class, 'create'])->name('import.create');
            Route::post('/import', [FiscalActualImportController::class, 'store'])->name('import.store');
            Route::get('/import/{import}', [FiscalActualImportController::class, 'show'])->name('import.show');
            Route::post('/import/{import}/confirm', [FiscalActualImportController::class, 'confirm'])->name('import.confirm');
            Route::post('/import/{import}/cancel', [FiscalActualImportController::class, 'cancel'])->name('import.cancel');

            Route::get('/entry', [FiscalActualEntryController::class, 'create'])->name('entry.create');
            Route::post('/entry', [FiscalActualEntryController::class, 'store'])->name('entry.store');
        });
    });

    // STEP 14: the team performance drill-down (Manager: any team; Team
    // Head: only their own — enforced by PerformanceAuthorizer, not by
    // this route).
    Route::get('/teams/{team}/performance', [TeamPerformanceController::class, 'show'])->name('teams.show');
});
