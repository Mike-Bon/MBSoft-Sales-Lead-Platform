<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 11: baseline defense-in-depth response headers, applied to
 * every web response. None of these replace an actual authorization
 * check — they are the browser-side hardening CLAUDE.md's security
 * section expects alongside (never instead of) server-side enforcement.
 *
 * HSTS is only ever added when the request actually arrived over HTTPS
 * — sending it over plain HTTP would be a no-op at best and misleading
 * in local/staging environments that intentionally run without TLS.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), camera=(), microphone=()');

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
