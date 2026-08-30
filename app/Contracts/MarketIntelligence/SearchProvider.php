<?php

namespace App\Contracts\MarketIntelligence;

use App\Support\MarketIntelligence\SearchProviderException;
use App\Support\MarketIntelligence\SearchResult;

/**
 * V2.1: the provider-agnostic boundary for external web search. The
 * same pattern as App\Contracts\Ai\LlmProvider and the Communication
 * providers — ProspectDiscoveryService depends only on this interface;
 * swapping search vendors is one new class bound in AppServiceProvider,
 * never a change to the service or the AgentTool.
 *
 * Implementations MUST:
 *   - never follow the caller's input beyond issuing the given query;
 *   - never return more than $limit results;
 *   - throw SearchProviderException (not a raw HTTP/transport error) on
 *     any failure, so callers have exactly one failure type to handle.
 */
interface SearchProvider
{
    /**
     * @return list<SearchResult>
     *
     * @throws SearchProviderException
     */
    public function search(string $query, int $limit): array;

    /** Human-readable provider name for audit/log lines (never a credential). */
    public function name(): string;
}
