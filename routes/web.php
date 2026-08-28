<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// V1 deployment-readiness: the public root has no marketing page — send
// visitors straight to sign-in. welcome.blade.php is retained, unused.
Route::get('/', fn () => redirect()->route('login'))->name('home');

Route::get('dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});

require __DIR__.'/auth.php';
require __DIR__.'/organisation.php';
require __DIR__.'/crm.php';
require __DIR__.'/performance.php';
require __DIR__.'/communications.php';
require __DIR__.'/ai.php';
require __DIR__.'/workflows.php';
require __DIR__.'/knowledge.php';
require __DIR__.'/notifications.php';
require __DIR__.'/cost-to-serve.php';
require __DIR__.'/business-development.php';
