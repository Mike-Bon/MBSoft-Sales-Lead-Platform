<?php

use App\Http\Controllers\Communication\CommunicationController;
use App\Http\Controllers\Communication\GoogleOAuthController;
use App\Http\Controllers\Communication\MessageTemplateController;
use App\Http\Controllers\Communication\WhatsAppNumberController;
use App\Http\Controllers\Communication\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Communications routes (Phase 6)
|--------------------------------------------------------------------------
|
| As with routes/crm.php, route middleware here keeps guests out — it is
| not the authorization mechanism itself; every controller action also
| enforces its own Policy/authorization check. The two webhooks/oauth
| callback routes at the bottom are the deliberate exceptions: they are
| never reached by a logged-in browser session, so they carry no `auth`
| middleware and authenticate themselves instead (OAuth `state`, webhook
| signature verification).
|
*/

Route::middleware(['auth', 'verified'])->name('communications.')->group(function () {
    // Every literal sub-path (compose, send, templates, whatsapp-numbers,
    // email-account) must be registered before the generic
    // `communications/{communication}` show route below — both are
    // two-segment URIs, so Laravel would otherwise match e.g.
    // `communications/templates` as show(communication: "templates")
    // since routes match in registration order.
    Route::get('communications', [CommunicationController::class, 'index'])->name('index');

    Route::get('communications/compose/email', [CommunicationController::class, 'composeEmail'])->name('compose-email');
    Route::post('communications/send/email', [CommunicationController::class, 'sendEmail'])->name('send-email');
    Route::get('communications/compose/whatsapp', [CommunicationController::class, 'composeWhatsApp'])->name('compose-whatsapp');
    Route::post('communications/send/whatsapp', [CommunicationController::class, 'sendWhatsApp'])->name('send-whatsapp');

    Route::resource('communications/templates', MessageTemplateController::class)
        ->except(['show'])
        ->parameters(['templates' => 'message_template'])
        ->names('templates');

    Route::resource('communications/whatsapp-numbers', WhatsAppNumberController::class)
        ->only(['index', 'create', 'store', 'destroy'])
        ->parameters(['whatsapp-numbers' => 'whatsappNumber'])
        ->names('whatsapp-numbers');

    Route::get('communications/email-account', [GoogleOAuthController::class, 'edit'])->name('email-account.edit');
    Route::get('communications/email-account/connect', [GoogleOAuthController::class, 'redirect'])->name('email-account.connect');
    Route::delete('communications/email-account', [GoogleOAuthController::class, 'destroy'])->name('email-account.destroy');

    Route::get('communications/{communication}', [CommunicationController::class, 'show'])->name('show');
});

// Google redirects back here after the user consents on Google's own
// hosted screen — the browser is not authenticated with Google's own
// session concept, but still carries this application's own session
// cookie, so `auth` still applies (the user must still be logged into
// this app for the `state` check in GoogleOAuthController to mean
// anything).
Route::middleware(['auth', 'verified'])->get('communications/email-account/callback', [GoogleOAuthController::class, 'callback'])
    ->name('communications.email-account.callback');

// Called directly by Meta's servers — never by a browser, never
// authenticated with this application's session.
Route::get('webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])->name('webhooks.whatsapp.verify');
Route::post('webhooks/whatsapp', [WhatsAppWebhookController::class, 'handle'])->name('webhooks.whatsapp.handle');
