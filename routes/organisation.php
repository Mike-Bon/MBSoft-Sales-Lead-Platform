<?php

use App\Http\Controllers\Organisation\CompanySettingsController;
use App\Http\Controllers\Organisation\TeamController;
use App\Http\Controllers\Organisation\UserController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Organisation routes (Phase 2)
|--------------------------------------------------------------------------
|
| All routes below require authentication. Every controller action also
| enforces its own authorization via UserPolicy/TeamPolicy — route
| protection here is not a substitute for that, it just keeps guests out.
|
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('profile', [ProfileController::class, 'show'])->name('organisation.profile');

    // Company branding (name + logo shown on the login page and sidebar).
    // Manager-only — enforced in CompanySettingsController, not here.
    Route::prefix('company')->name('organisation.company.')->group(function () {
        Route::get('/', [CompanySettingsController::class, 'edit'])->name('edit');
        Route::post('/', [CompanySettingsController::class, 'update'])->name('update');
    });

    Route::prefix('teams')->name('organisation.teams.')->group(function () {
        Route::get('/', [TeamController::class, 'index'])->name('index');
        Route::get('/create', [TeamController::class, 'create'])->name('create');
        Route::post('/', [TeamController::class, 'store'])->name('store');
        Route::get('/{team}', [TeamController::class, 'show'])->name('show');
        Route::get('/{team}/edit', [TeamController::class, 'edit'])->name('edit');
        Route::put('/{team}', [TeamController::class, 'update'])->name('update');
        Route::put('/{team}/head', [TeamController::class, 'assignHead'])->name('assign-head');
    });

    Route::prefix('users')->name('organisation.users.')->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index');
        Route::get('/create', [UserController::class, 'create'])->name('create');
        Route::post('/', [UserController::class, 'store'])->name('store');
        Route::get('/{user}/edit', [UserController::class, 'edit'])->name('edit');
        Route::put('/{user}', [UserController::class, 'update'])->name('update');
    });
});
