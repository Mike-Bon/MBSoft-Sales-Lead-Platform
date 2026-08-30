<?php

namespace App\Services\MarketIntelligence;

use App\Support\MarketIntelligence\OutboundUrlGuard;
use App\Support\MarketIntelligence\UnsafeUrlException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Str;
use Throwable;

/**
 * V2.1: fetches ONE public web page as plain-text evidence, under hard
 * limits (spec §5/§14).
 *
 *   - OutboundUrlGuard::assertSafe() on the initial URL and on every
 *     redirect hop — redirects are followed manually, max 2.
 *   - text/html (or text/*) responses only; anything else is refused.
 *   - response body capped; only the first ~40k characters of extracted
 *     text are kept.
 *   - short connect + total timeouts; one attempt, no retry (a slow or
 *     hostile site must not hold a discovery call open).
 *
 * Returns a FetchedPage or null on any failure — callers degrade to
 * search-snippet-only evidence for that candidate, never an exception.
 */
final class WebEvidenceFetcher
{
    private const MAX_REDIRECTS = 2;

    private const MAX_BODY_BYTES = 2_000_000;

    private const KEEP_TEXT_CHARS = 40_000;

    private const USER_AGENT = 'MBSoftProspectResearchBot/1.0 (+https://app.mbsoft.online; contact your MBSoft administrator)';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly OutboundUrlGuard $guard,
    ) {}

    public function fetch(string $url): ?FetchedPage
    {
        $timeout = (int) config('services.market_intelligence.fetch_timeout', 8);

        try {
            $current = $this->guard->assertSafe($url);

            for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
                $response = $this->http
                    ->withHeaders(['User-Agent' => self::USER_AGENT, 'Accept' => 'text/html,application/xhtml+xml'])
                    ->connectTimeout(5)
                    ->timeout($timeout)
                    ->withoutRedirecting()
                    ->get($current);

                if ($response->redirect()) {
                    $location = (string) $response->header('Location');
                    if ($location === '') {
                        return null;
                    }
                    $current = $this->guard->assertSafe($this->absolutize($current, $location));

                    continue;
                }

                if ($response->failed()) {
                    return null;
                }

                $contentType = strtolower((string) $response->header('Content-Type'));
                if ($contentType !== '' && ! str_starts_with($contentType, 'text/')) {
                    return null;
                }

                $length = (int) $response->header('Content-Length');
                if ($length > self::MAX_BODY_BYTES) {
                    return null;
                }

                $body = substr($response->body(), 0, self::MAX_BODY_BYTES);

                return $this->extract($current, $body);
            }

            return null;
        } catch (UnsafeUrlException) {
            // Deliberately swallowed here — the tool/service reports
            // "could not safely examine this source". Tests assert the
            // guard itself throws.
            return null;
        } catch (Throwable) {
            return null;
        }
    }

    private function extract(string $url, string $html): FetchedPage
    {
        $title = '';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5));
        }

        $description = '';
        if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)["\']/i', $html, $m)) {
            $description = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
        }

        // Social / external profile links, before stripping tags.
        $links = [];
        if (preg_match_all('/href=["\']([^"\']+)["\']/i', $html, $m)) {
            $links = array_values(array_unique($m[1]));
        }

        // Body text: drop script/style/noscript, strip tags, collapse.
        $text = preg_replace('/<(script|style|noscript)[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        $text = mb_substr($text, 0, self::KEEP_TEXT_CHARS);

        return new FetchedPage(
            url: $url,
            title: mb_substr($title, 0, 300),
            description: mb_substr($description, 0, 500),
            text: $text,
            links: array_slice($links, 0, 400),
            fetchedAt: now()->toIso8601String(),
        );
    }

    private function absolutize(string $base, string $location): string
    {
        if (Str::startsWith($location, ['http://', 'https://'])) {
            return $location;
        }

        $b = parse_url($base);
        if (! isset($b['scheme'], $b['host'])) {
            return $location;
        }

        $origin = $b['scheme'].'://'.$b['host'].(isset($b['port']) ? ':'.$b['port'] : '');

        return $origin.'/'.ltrim($location, '/');
    }
}
