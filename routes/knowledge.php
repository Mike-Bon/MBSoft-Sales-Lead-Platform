<?php

use App\Http\Controllers\Knowledge\KnowledgeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Knowledge routes (Phase 10)
|--------------------------------------------------------------------------
|
| As with routes/crm.php and routes/communications.php: `auth` keeps
| guests out, KnowledgeDocumentPolicy is the actual authorization
| mechanism on every action. The literal `knowledge/create` sub-path is
| registered before the generic `knowledge/{knowledgeDocument}` show
| route for the same reason documented in routes/communications.php —
| both are two-segment URIs and Laravel matches in registration order.
|
*/

Route::middleware(['auth', 'verified'])->name('knowledge.')->group(function () {
    Route::get('knowledge', [KnowledgeController::class, 'index'])->name('index');
    Route::get('knowledge/create', [KnowledgeController::class, 'create'])->name('create');
    Route::post('knowledge', [KnowledgeController::class, 'store'])->name('store');

    Route::get('knowledge/{knowledgeDocument}', [KnowledgeController::class, 'show'])->name('show');
    Route::delete('knowledge/{knowledgeDocument}', [KnowledgeController::class, 'destroy'])->name('destroy');

    Route::post('knowledge/{knowledgeDocument}/versions', [KnowledgeController::class, 'storeVersion'])->name('versions.store');
    Route::post('knowledge/{knowledgeDocument}/versions/{knowledgeDocumentVersion}/archive', [KnowledgeController::class, 'archiveVersion'])->name('versions.archive');
});
