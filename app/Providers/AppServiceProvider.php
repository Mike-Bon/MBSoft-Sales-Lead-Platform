<?php

namespace App\Providers;

use App\Contracts\Communication\EmailProvider;
use App\Contracts\Communication\WhatsAppProvider;
use App\Services\Communication\Providers\GmailEmailProvider;
use App\Services\Communication\Providers\WhatsAppCloudApiProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // STEP 2 provider isolation: CommunicationService/
        // SendCommunicationJob depend only on these interfaces. Tests
        // swap in fakes by rebinding these, never by touching that code.
        $this->app->bind(EmailProvider::class, GmailEmailProvider::class);
        $this->app->bind(WhatsAppProvider::class, WhatsAppCloudApiProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
