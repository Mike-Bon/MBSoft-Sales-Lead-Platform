<?php

namespace App\Services\MarketIntelligence\Providers;

use App\Contracts\MarketIntelligence\SearchProvider;
use App\Support\MarketIntelligence\SearchProviderException;

/**
 * The default binding when no external search provider is configured
 * (SEARCH_PROVIDER unset / no API key). Every call fails with the one
 * exception type callers already handle, so an unconfigured environment
 * degrades to a clear "not available" message rather than a 500 — and
 * automated tests never touch a real provider.
 */
final class NullSearchProvider implements SearchProvider
{
    public function search(string $query, int $limit): array
    {
        throw new SearchProviderException('External prospect discovery is not configured on this environment.');
    }

    public function name(): string
    {
        return 'none';
    }
}
