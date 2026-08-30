<?php

namespace App\Services\MarketIntelligence;

/**
 * V2.2 (spec §18/§19): the batch-wide cap on additional research done
 * while qualifying prospects. One instance per qualify() call, shared
 * across every prospect in the batch, so the total cost of a
 * qualification is bounded no matter how many prospects or unresolved
 * criteria there are.
 *
 * Not readonly — it counts down as research happens.
 */
final class QualificationResearchBudget
{
    private int $searchesUsed = 0;

    private int $fetchesUsed = 0;

    private int $providerFailures = 0;

    public function __construct(
        private readonly int $maxSearches,
        private readonly int $maxFetches,
        private readonly int $maxPerProspectSearches = 1,
        private readonly int $maxPerProspectFetches = 2,
    ) {}

    public function canSearch(int $usedForThisProspect): bool
    {
        return $this->searchesUsed < $this->maxSearches && $usedForThisProspect < $this->maxPerProspectSearches;
    }

    public function canFetch(int $usedForThisProspect): bool
    {
        return $this->fetchesUsed < $this->maxFetches && $usedForThisProspect < $this->maxPerProspectFetches;
    }

    public function recordSearch(): void
    {
        $this->searchesUsed++;
    }

    public function recordFetch(): void
    {
        $this->fetchesUsed++;
    }

    public function recordProviderFailure(): void
    {
        $this->providerFailures++;
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'additional_searches' => $this->searchesUsed,
            'additional_fetches' => $this->fetchesUsed,
            'provider_failures' => $this->providerFailures,
            'search_budget' => $this->maxSearches,
            'fetch_budget' => $this->maxFetches,
        ];
    }

    public function providerFailureCount(): int
    {
        return $this->providerFailures;
    }
}
