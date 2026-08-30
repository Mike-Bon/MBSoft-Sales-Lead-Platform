<?php

namespace App\Services\MarketIntelligence\Providers;

use App\Contracts\MarketIntelligence\SearchProvider;
use App\Support\MarketIntelligence\SearchProviderException;
use App\Support\MarketIntelligence\SearchResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Reference SearchProvider — the Brave Search API
 * (https://api.search.brave.com/res/v1/web/search). Chosen because it
 * has a usable free tier, a plain REST/JSON contract, an IPv4 endpoint
 * (works from Hostinger), and no per-engine CSE setup.
 *
 * Same shape as App\Services\Ai\Providers\AnthropicProvider: the Http
 * facade, a bounded timeout, and every failure normalised to one
 * exception type. The API key is read from config, never logged.
 *
 * Production requirement: set SEARCH_PROVIDER=brave and
 * BRAVE_SEARCH_API_KEY=... (see .env.example and docs/MARKET_INTELLIGENCE.md).
 */
final class BraveSearchProvider implements SearchProvider
{
    private const ENDPOINT = 'https://api.search.brave.com/res/v1/web/search';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $apiKey,
        private readonly int $timeout = 15,
        private readonly ?string $country = null,
    ) {}

    public function search(string $query, int $limit): array
    {
        if ($this->apiKey === '') {
            throw new SearchProviderException('Brave Search API key is not set.');
        }

        try {
            $response = $this->http
                ->withHeaders([
                    'X-Subscription-Token' => $this->apiKey,
                    'Accept' => 'application/json',
                ])
                ->connectTimeout(5)
                ->timeout($this->timeout)
                ->retry(1, 250, throw: false)
                ->get(self::ENDPOINT, array_filter([
                    'q' => $query,
                    'count' => max(1, min($limit, 20)),
                    'result_filter' => 'web',
                    'safesearch' => 'moderate',
                    'country' => $this->country,
                ]));
        } catch (ConnectionException $e) {
            throw new SearchProviderException('Could not reach the search provider: '.$e->getMessage(), previous: $e);
        }

        if ($response->status() === 429) {
            throw new SearchProviderException('The search provider rate limit was exceeded. Try again shortly.');
        }

        if ($response->failed()) {
            throw new SearchProviderException('Search provider returned HTTP '.$response->status().'.');
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new SearchProviderException('Search provider returned a malformed response.');
        }

        $results = [];
        foreach (data_get($json, 'web.results', []) as $row) {
            $url = (string) data_get($row, 'url', '');
            if ($url === '') {
                continue;
            }
            $results[] = new SearchResult(
                title: trim((string) data_get($row, 'title', '')),
                url: $url,
                description: trim(strip_tags((string) data_get($row, 'description', ''))),
            );
            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    public function name(): string
    {
        return 'brave';
    }
}
