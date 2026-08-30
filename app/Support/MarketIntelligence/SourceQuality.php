<?php

namespace App\Support\MarketIntelligence;

use Illuminate\Support\Str;

/**
 * V2.2 (spec §20): a simple source-quality hierarchy. It helps decide
 * evidence strength — it is NOT a ranking algorithm and it is NOT lead
 * scoring.
 *
 *   official_company > business_profile > directory > marketplace
 *     > search_result > weak
 */
enum SourceQuality: string
{
    case OfficialCompany = 'official_company';

    case BusinessProfile = 'business_profile';

    case Directory = 'directory';

    case Marketplace = 'marketplace';

    case SearchResult = 'search_result';

    case Weak = 'weak';

    /**
     * The default evidence strength a claim from this kind of source
     * carries, before any corroboration is considered.
     */
    public function baselineStrength(): EvidenceStrength
    {
        return match ($this) {
            self::OfficialCompany => EvidenceStrength::Direct,
            self::BusinessProfile, self::Directory => EvidenceStrength::Corroborating,
            self::Marketplace, self::SearchResult => EvidenceStrength::Indirect,
            self::Weak => EvidenceStrength::Unverified,
        };
    }

    /**
     * Classify a source by the domain it came from, relative to the
     * prospect's own domain. Deterministic, no network.
     */
    public static function classify(string $sourceDomain, ?string $ownDomain, bool $fromFetchedPage): self
    {
        $sourceDomain = strtolower($sourceDomain);

        if ($ownDomain !== null && $sourceDomain === strtolower($ownDomain)) {
            return $fromFetchedPage ? self::OfficialCompany : self::SearchResult;
        }

        foreach (self::SOCIAL_HOSTS as $host) {
            if ($sourceDomain === $host || Str::endsWith($sourceDomain, '.'.$host)) {
                return self::BusinessProfile;
            }
        }

        foreach (self::MARKETPLACE_HOSTS as $host) {
            if ($sourceDomain === $host || Str::endsWith($sourceDomain, '.'.$host)) {
                return self::Marketplace;
            }
        }

        foreach (self::DIRECTORY_HOSTS as $host) {
            if ($sourceDomain === $host || Str::endsWith($sourceDomain, '.'.$host)) {
                return self::Directory;
            }
        }

        return $fromFetchedPage ? self::Directory : self::SearchResult;
    }

    /** @var list<string> */
    private const SOCIAL_HOSTS = ['facebook.com', 'fb.com', 'instagram.com', 'tiktok.com', 'x.com', 'twitter.com', 'linkedin.com'];

    /** @var list<string> */
    private const MARKETPLACE_HOSTS = ['shopee.ph', 'shopee.com', 'lazada.com.ph', 'lazada.com', 'carousell.ph', 'carousell.com', 'amazon.com', 'ebay.com'];

    /** @var list<string> */
    private const DIRECTORY_HOSTS = ['yellowpages.com', 'yellow-pages.ph', 'bizbuysell.com', 'clutch.co', 'goodfirms.co'];
}
