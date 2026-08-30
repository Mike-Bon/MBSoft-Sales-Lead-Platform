<?php

namespace Tests\Support;

use App\Contracts\MarketIntelligence\SearchProvider;
use App\Support\MarketIntelligence\SearchProviderException;
use App\Support\MarketIntelligence\SearchResult;

/**
 * A deterministic stand-in for a real external web-search provider
 * (V2.1: automated tests must never call a live search API — spec §16).
 *
 * Configure it with a fixed list of SearchResult rows to return for
 * every query, or with a Throwable to raise (to exercise the
 * provider-unavailable / rate-limited / malformed paths). It records
 * every query it received so a test can assert the service built
 * deterministic queries from the criteria and never forwarded raw user
 * text or an LLM-authored string.
 */
class FakeSearchProvider implements SearchProvider
{
    /** @var list<string> */
    public array $queries = [];

    /**
     * @param  list<SearchResult>  $results
     */
    public function __construct(
        private array $results = [],
        private ?\Throwable $throw = null,
        private string $name = 'fake',
    ) {}

    /**
     * @param  list<array{title?: string, url: string, description?: string}>  $rows
     */
    public static function withRows(array $rows): self
    {
        return new self(array_map(
            fn (array $row) => new SearchResult($row['title'] ?? '', $row['url'], $row['description'] ?? ''),
            $rows,
        ));
    }

    public static function failing(?\Throwable $throw = null): self
    {
        return new self([], $throw ?? new SearchProviderException('Search provider is unavailable.'));
    }

    public function search(string $query, int $limit): array
    {
        $this->queries[] = $query;

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return array_slice($this->results, 0, $limit);
    }

    public function name(): string
    {
        return $this->name;
    }
}
