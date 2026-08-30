<?php

namespace App\Support\MarketIntelligence;

/**
 * One web-search hit as returned by a SearchProvider: a title, the URL,
 * and the provider's short snippet/description. This is the ONLY shape
 * the rest of Market Intelligence knows about — provider wire formats
 * never leak past the adapter.
 */
final readonly class SearchResult
{
    public function __construct(
        public string $title,
        public string $url,
        public string $description,
    ) {}
}
