<?php

use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Notification routes (Phase 11)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'verified'])->name('notifications.')->group(function () {
    Route::get('notifications', [NotificationController::class, 'index'])->name('index');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('read');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('read-all');
});
