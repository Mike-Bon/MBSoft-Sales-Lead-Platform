<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // The WhatsApp Cloud API webhook is called directly by Meta, not
        // from a browser session — it carries no Laravel CSRF token and
        // is authenticated instead by its own HMAC signature
        // verification (see WhatsAppWebhookController::hasValidSignature,
        // STEP 14).
        $middleware->validateCsrfTokens(except: [
            'webhooks/whatsapp',
        ]);

        // Phase 11: baseline security response headers on every web
        // response (see App\Http\Middleware\SecurityHeaders — defense in
        // depth, never a substitute for server-side authorization).
        $middleware->web(append: [
            SecurityHeaders::class,
        ]);

        // Phase 11: no proxy is trusted unless TRUSTED_PROXIES is set in
        // that environment's own .env (comma-separated IPs/CIDRs, or
        // "*" for a single trusted edge such as a managed load balancer
        // that is the only way to reach the app). Left unset, requests
        // are evaluated as if they arrived directly — the safe default
        // for local/testing. See docs/DEPLOYMENT.md.
        $trustedProxies = trim((string) env('TRUSTED_PROXIES', ''));
        $middleware->trustProxies(at: $trustedProxies === '*' ? '*' : array_filter(explode(',', $trustedProxies)));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
