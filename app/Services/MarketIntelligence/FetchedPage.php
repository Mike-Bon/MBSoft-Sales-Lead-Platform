<?php

namespace App\Services\MarketIntelligence;

/**
 * The minimised result of fetching one public page — enough to extract
 * evidence, nothing more. Full HTML is never retained (spec §8:
 * "minimise stored external content").
 */
final readonly class FetchedPage
{
    /**
     * @param  list<string>  $links
     */
    public function __construct(
        public string $url,
        public string $title,
        public string $description,
        public string $text,
        public array $links,
        public string $fetchedAt,
    ) {}
}
