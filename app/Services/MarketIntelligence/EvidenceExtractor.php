<?php

namespace App\Services\MarketIntelligence;

use App\Support\MarketIntelligence\DiscoveryCriteria;
use App\Support\MarketIntelligence\EvidenceItem;
use App\Support\MarketIntelligence\ProspectCandidate;
use App\Support\MarketIntelligence\SearchResult;
use Illuminate\Support\Str;

/**
 * V2.1: turns raw search hits + one fetched page per domain into a
 * ProspectCandidate — DETERMINISTICALLY. No LLM here. Every field is
 * either directly observed in a source (with that source recorded) or
 * left null/"missing". The signal lists below are the whole of the
 * "understanding" — plain string matching, auditable, testable.
 *
 * The LLM later interprets and presents these candidates; it must not
 * add businesses or facts the extractor did not produce (enforced by
 * the agent prompt + the tool having no other capability).
 */
final class EvidenceExtractor
{
    /** @var list<string> */
    private const ONLINE_SELLING_SIGNALS = [
        'add to cart', 'add to bag', 'add to basket', 'add to trolley', 'shop now', 'buy now',
        'order now', 'proceed to checkout', 'view cart', 'shopping cart', 'online store',
        'online shop', '/product/', '/products/', '/shop/', '/store/', '/collections/',
        'shopee.ph', 'lazada.com', 'carousell', 'tiktok.com/shop',
    ];

    /** @var list<string> */
    private const SHIPPING_SIGNALS = [
        'nationwide', 'we ship', 'we deliver', 'free shipping', 'shipping fee', 'shipping rate',
        'delivery fee', 'cash on delivery', ' cod ', 'same day delivery', 'next day delivery',
        'courier', 'lbc', 'j&t', 'jrs', 'ninja van', 'grab express', 'flash express',
        'ships to', 'delivered to your door',
    ];

    /** @var list<string> */
    private const SOCIAL_HOSTS = ['facebook.com', 'fb.com', 'instagram.com', 'tiktok.com', 'x.com', 'twitter.com'];

    /**
     * @param  list<SearchResult>  $results
     */
    public function build(DiscoveryCriteria $criteria, string $domain, array $results, ?FetchedPage $page): ?ProspectCandidate
    {
        $primaryResult = $results[0] ?? null;
        if ($primaryResult === null) {
            return null;
        }

        $now = now()->toIso8601String();
        $evidence = [];
        $haystackParts = [strtolower($primaryResult->title.' '.$primaryResult->description)];
        $links = [];

        if ($page !== null) {
            $haystackParts[] = strtolower($page->title.' '.$page->description.' '.$page->text);
            $links = $page->links;
            $sourceUrl = $page->url;
            $observedAt = $page->fetchedAt;

            $evidence[] = new EvidenceItem(
                EvidenceItem::TYPE_DESCRIPTION,
                'Website: "'.Str::limit($page->title !== '' ? $page->title : $page->description, 160).'"',
                $sourceUrl, $domain, $observedAt,
            );
        } else {
            $sourceUrl = $primaryResult->url;
            $observedAt = $now;
            $evidence[] = new EvidenceItem(
                EvidenceItem::TYPE_DESCRIPTION,
                'Search result: "'.Str::limit(trim($primaryResult->title.' — '.$primaryResult->description), 200).'"',
                $sourceUrl, $domain, $observedAt,
            );
        }

        $haystack = implode(' ', $haystackParts);
        $linkBlob = strtolower(implode(' ', $links).' '.$sourceUrl);

        // ── location ──────────────────────────────────────────────
        $location = null;
        if ($criteria->location !== null) {
            $tokens = array_filter(preg_split('/[\s,]+/', strtolower($criteria->location)) ?: [], fn ($t) => strlen($t) >= 3);
            foreach ($tokens as $token) {
                if (str_contains($haystack, $token)) {
                    $location = $criteria->location;
                    $evidence[] = new EvidenceItem(
                        EvidenceItem::TYPE_LOCATION,
                        'Source text mentions "'.$token.'".',
                        $sourceUrl, $domain, $observedAt,
                    );
                    break;
                }
            }
        }

        // ── product / category ───────────────────────────────────
        $observedProducts = [];
        $categoryHit = false;
        foreach (array_merge($criteria->productKeywords, $criteria->industry !== null ? [$criteria->industry] : []) as $kw) {
            if ($kw !== '' && str_contains($haystack, strtolower($kw))) {
                if ($kw === $criteria->industry) {
                    $categoryHit = true;
                } else {
                    $observedProducts[] = $kw;
                }
                $evidence[] = new EvidenceItem(
                    EvidenceItem::TYPE_PRODUCT,
                    'Source text mentions "'.$kw.'".',
                    $sourceUrl, $domain, $observedAt,
                );
            }
        }
        $observedProducts = array_values(array_unique($observedProducts));
        $category = $categoryHit ? $criteria->industry : null;

        // ── online-selling ───────────────────────────────────────
        $onlineSelling = false;
        foreach (self::ONLINE_SELLING_SIGNALS as $signal) {
            if (str_contains($haystack, $signal) || str_contains($linkBlob, $signal)) {
                $onlineSelling = true;
                $evidence[] = new EvidenceItem(
                    EvidenceItem::TYPE_ONLINE_SELLING,
                    'Source contains an online-selling indicator ("'.trim($signal, '/').'").',
                    $sourceUrl, $domain, $observedAt,
                );
                break;
            }
        }

        // ── shipping / delivery ──────────────────────────────────
        $shipping = false;
        foreach (self::SHIPPING_SIGNALS as $signal) {
            if (str_contains($haystack, trim($signal))) {
                $shipping = true;
                $evidence[] = new EvidenceItem(
                    EvidenceItem::TYPE_SHIPPING,
                    'Source mentions shipping/delivery ("'.trim($signal).'"). This is a mention only — no shipping volume or coverage is claimed.',
                    $sourceUrl, $domain, $observedAt,
                );
                break;
            }
        }

        // ── social / public business presence ────────────────────
        $social = $this->socialProfiles($links, $primaryResult->url);
        foreach ($social as $profile) {
            $evidence[] = new EvidenceItem(
                EvidenceItem::TYPE_SOCIAL_PRESENCE,
                'A public social/business profile link was found: '.$profile,
                $sourceUrl, $domain, $observedAt,
            );
        }

        // ── assemble ─────────────────────────────────────────────
        $ownDomain = ! $this->isSocialHost($domain) && ! $this->isAggregator($domain);
        $website = $ownDomain ? ($page->url ?? $primaryResult->url) : null;

        $name = $this->resolveName($page, $primaryResult, $domain);
        $missing = $this->missing($criteria, $location, $observedProducts, $categoryHit, $onlineSelling, $shipping, $social);
        $confidence = $this->confidence($website, $location, $observedProducts || $categoryHit, $onlineSelling, $shipping);
        $next = $this->nextStep($website, $location, $onlineSelling, $shipping, $social);

        return new ProspectCandidate(
            name: $name,
            website: $website,
            domain: $domain,
            location: $location,
            category: $category,
            observedProducts: $observedProducts,
            onlineSellingEvidence: $onlineSelling,
            shippingEvidence: $shipping,
            socialPresence: $social,
            evidence: array_values($evidence),
            missing: $missing,
            confidence: $confidence,
            recommendedNextStep: $next,
        );
    }

    /**
     * @param  list<string>  $links
     * @return list<string>
     */
    private function socialProfiles(array $links, string $resultUrl): array
    {
        $candidates = $links;
        if ($this->isSocialHost($this->domainOf($resultUrl))) {
            $candidates[] = $resultUrl;
        }

        $profiles = [];
        foreach ($candidates as $link) {
            $host = $this->domainOf($link);
            if (! $this->isSocialHost($host)) {
                continue;
            }
            $path = strtolower((string) parse_url($link, PHP_URL_PATH));
            if ($path === '' || $path === '/' || str_contains($path, '/sharer') || str_contains($path, '/plugins/')
                || str_contains($path, '/tr') || str_starts_with($path, '/p/') || str_contains($link, 'intent/')) {
                continue;
            }
            $profiles[] = Str::before($link, '?');
            if (count($profiles) >= 3) {
                break;
            }
        }

        return array_values(array_unique($profiles));
    }

    private function resolveName(?FetchedPage $page, SearchResult $result, string $domain): string
    {
        $raw = $page !== null && $page->title !== '' ? $page->title : $result->title;
        $raw = trim(preg_replace('/\s*[|\-–—:]\s*(home|homepage|official (site|store|website)|welcome).*$/i', '', $raw) ?? $raw);
        $raw = trim(preg_replace('/\s*[|\-–—:].*$/', '', $raw) ?? $raw);
        $raw = trim($raw);

        if (mb_strlen($raw) >= 2 && mb_strlen($raw) <= 80) {
            return $raw;
        }

        return Str::of($domain)->before('.')->replace(['-', '_'], ' ')->title()->value();
    }

    /**
     * @param  list<string>  $observedProducts
     * @param  list<string>  $social
     * @return list<string>
     */
    private function missing(DiscoveryCriteria $c, ?string $location, array $observedProducts, bool $categoryHit, bool $online, bool $shipping, array $social): array
    {
        $missing = [];
        if ($c->location !== null && $location === null) {
            $missing[] = 'Location not confirmed in any source.';
        }
        if (($c->industry !== null || $c->productKeywords !== []) && ! $categoryHit && $observedProducts === []) {
            $missing[] = 'No product/category match found in any source.';
        }
        if (in_array('own_website', $c->onlineSignals, true) && ! $online) {
            $missing[] = 'No online-selling indicator (cart/checkout/store) observed.';
        }
        foreach (['facebook', 'instagram', 'tiktok'] as $sig) {
            if (in_array($sig, $c->onlineSignals, true) && $social === []) {
                $missing[] = 'No public '.ucfirst($sig).' presence found.';
                break;
            }
        }
        if (! $shipping) {
            $missing[] = 'No shipping/delivery information observed.';
        }

        return array_values(array_unique($missing));
    }

    private function confidence(?string $website, ?string $location, bool $product, bool $online, bool $shipping): string
    {
        $signals = (int) ($location !== null) + (int) $product + (int) $online + (int) $shipping;

        if ($website !== null && $signals >= 3) {
            return ProspectCandidate::CONFIDENCE_HIGH;
        }
        if ($website !== null && $signals >= 1 && ($location !== null || $product)) {
            return ProspectCandidate::CONFIDENCE_MEDIUM;
        }

        return ProspectCandidate::CONFIDENCE_LOW;
    }

    /**
     * @param  list<string>  $social
     */
    private function nextStep(?string $website, ?string $location, bool $online, bool $shipping, array $social): string
    {
        return match (true) {
            $website === null && $social !== [] => 'Open the public social page to confirm the business is active and check its location.',
            $website !== null && ! $online => 'Visit the website to confirm whether they sell online — no cart/checkout was detected.',
            $location === null => 'Confirm the business location before considering it a prospect.',
            ! $shipping => 'Confirm their delivery/shipping arrangements — no shipping information was found.',
            default => 'Review the website and confirm fit before this is considered for the CRM.',
        };
    }

    private function domainOf(string $url): string
    {
        $host = strtolower((string) parse_url(Str::startsWith($url, 'http') ? $url : 'https://'.$url, PHP_URL_HOST));

        return Str::startsWith($host, 'www.') ? substr($host, 4) : $host;
    }

    private function isSocialHost(string $host): bool
    {
        foreach (self::SOCIAL_HOSTS as $s) {
            if ($host === $s || str_ends_with($host, '.'.$s)) {
                return true;
            }
        }

        return false;
    }

    private function isAggregator(string $host): bool
    {
        return in_array($host, [
            'google.com', 'bing.com', 'duckduckgo.com', 'wikipedia.org', 'youtube.com',
            'yelp.com', 'tripadvisor.com', 'reddit.com', 'pinterest.com', 'linkedin.com',
            'amazon.com', 'ebay.com',
        ], true);
    }
}
