<?php

namespace App\Services\MarketIntelligence;

use App\Contracts\MarketIntelligence\SearchProvider;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\MarketIntelligence\DiscoveryCriteria;
use App\Support\MarketIntelligence\EvidenceItem;
use App\Support\MarketIntelligence\OutboundUrlGuard;
use App\Support\MarketIntelligence\ProspectCandidate;
use App\Support\MarketIntelligence\SearchProviderException;
use App\Support\MarketIntelligence\SearchResult;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * V2.1: the one place external prospect discovery is orchestrated
 * (spec §5). Every external effect is bounded here and nowhere else:
 *
 *   - per-user hourly cap (cache-backed RateLimiter);
 *   - <= max_searches deterministic queries built from the criteria
 *     (never from raw user text, never from an LLM);
 *   - <= max_fetches page fetches, each behind WebEvidenceFetcher +
 *     OutboundUrlGuard;
 *   - <= max_results candidates returned, each with >= 1 evidence item
 *     carrying a real source URL (candidates with no evidence are
 *     dropped — spec §7).
 *
 * It reads NO CRM data, has NO write path, and returns a plain array
 * the AgentTool passes straight back to the model. Provider failures
 * become a safe status, never an exception that reaches the assistant.
 */
final class ProspectDiscoveryService
{
    public function __construct(
        private readonly SearchProvider $search,
        private readonly WebEvidenceFetcher $fetcher,
        private readonly EvidenceExtractor $extractor,
        private readonly OutboundUrlGuard $guard = new OutboundUrlGuard,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function discover(User $actor, DiscoveryCriteria $criteria): array
    {
        $perHour = (int) (config('services.market_intelligence.max_discoveries_per_hour') ?? 12);
        $key = 'market-intel:discover:'.$actor->id;

        if (RateLimiter::tooManyAttempts($key, $perHour)) {
            return $this->result('rate_limited', $criteria, [], [], 0, [
                'message' => 'You have reached the hourly limit for prospect discovery. Try again in '
                    .ceil(RateLimiter::availableIn($key) / 60).' minute(s).',
            ]);
        }
        RateLimiter::hit($key, 3600);

        $gathered = $this->gather($criteria);

        if ($gathered['status'] === 'provider_unavailable') {
            return $this->result('provider_unavailable', $criteria, $gathered['queries'], $gathered['provider_failures'], 0, [
                'message' => 'The external search service is currently unavailable. No prospects could be researched.',
            ]);
        }

        /** @var list<ProspectCandidate> $prospects */
        $prospects = $gathered['candidates'];
        $status = $prospects === [] ? 'no_results' : 'ok';

        AuditLogger::record('market_intelligence.discovery', $actor, [
            'criteria' => $criteria->toArray(),
            'provider' => $this->search->name(),
            'queries' => count($gathered['queries']),
            'sources_examined' => $gathered['sources_examined'],
            'result_count' => count($prospects),
            'provider_failures' => count($gathered['provider_failures']),
            'status' => $status,
        ]);

        return $this->result($status, $criteria, $gathered['queries'], $gathered['provider_failures'], $gathered['sources_examined'], [
            'prospects' => array_map(fn (ProspectCandidate $c) => $c->toArray(), $prospects),
            'message' => $prospects === []
                ? 'No candidate businesses with supporting evidence were found for these criteria. Try broadening the location or product terms.'
                : null,
        ]);
    }

    /**
     * The shared search → group → fetch → extract pipeline, WITHOUT
     * rate-limiting or auditing (those belong to the tool-facing entry
     * points). V2.2's ProspectQualificationService reuses this so
     * qualification never invents a candidate or opens a second search
     * pipeline (spec §3/§5).
     *
     * @return array{status: string, candidates: list<ProspectCandidate>, queries: list<string>, provider_failures: list<array<string, string>>, sources_examined: int}
     */
    public function gather(DiscoveryCriteria $criteria): array
    {
        $config = config('services.market_intelligence');
        $queries = $this->buildQueries($criteria, (int) ($config['max_searches'] ?? 3));
        $perQuery = max(3, min(10, (int) ($config['results_per_search'] ?? 8)));

        $hits = [];
        $providerFailures = [];

        foreach ($queries as $query) {
            try {
                foreach ($this->search->search($query, $perQuery) as $hit) {
                    $hits[] = $hit;
                }
            } catch (SearchProviderException $e) {
                $providerFailures[] = ['query' => $query, 'error' => $e->getMessage()];
            }
        }

        if ($hits === [] && $providerFailures !== []) {
            return ['status' => 'provider_unavailable', 'candidates' => [], 'queries' => $queries, 'provider_failures' => $providerFailures, 'sources_examined' => 0];
        }

        [$byDomain, $sourcesExamined] = $this->groupAndFetch($hits, $criteria, (int) ($config['max_fetches'] ?? 12));

        $candidates = [];
        foreach ($byDomain as $domain => $bucket) {
            $candidate = $this->extractor->build($criteria, $domain, $bucket['results'], $bucket['page']);

            // Drop candidates whose only "evidence" is the description
            // stub with nothing corroborating — spec §7.
            if ($candidate === null || $this->isThin($candidate)) {
                continue;
            }

            $candidates[] = $candidate;
            if (count($candidates) >= $criteria->maxResults) {
                break;
            }
        }

        return ['status' => 'ok', 'candidates' => $candidates, 'queries' => $queries, 'provider_failures' => $providerFailures, 'sources_examined' => $sourcesExamined];
    }

    /**
     * @return list<string>
     */
    private function buildQueries(DiscoveryCriteria $c, int $max): array
    {
        $subject = trim(($c->industry ?? '').' '.implode(' ', array_slice($c->productKeywords, 0, 3)));
        $subject = $subject !== '' ? $subject : 'businesses';
        $loc = $c->location !== null ? ' '.$c->location : '';
        $exclude = implode(' ', array_map(fn ($k) => '-'.$k, array_slice($c->excludeKeywords, 0, 3)));

        $queries = [];
        $queries[] = trim($subject.$loc.' business'.($exclude !== '' ? ' '.$exclude : ''));

        if (array_intersect(['own_website', 'marketplace'], $c->onlineSignals) || $c->onlineSignals === []) {
            $queries[] = trim($subject.$loc.' online shop');
        }
        if (array_intersect(['facebook', 'instagram', 'tiktok'], $c->onlineSignals)) {
            $queries[] = trim($subject.$loc.' facebook page');
        }

        return array_values(array_slice(array_unique(array_filter($queries)), 0, max(1, $max)));
    }

    /**
     * @param  list<SearchResult>  $hits
     * @return array{0: array<string, array{results: list<SearchResult>, page: ?FetchedPage}>, 1: int}
     */
    private function groupAndFetch(array $hits, DiscoveryCriteria $criteria, int $maxFetches): array
    {
        $byDomain = [];

        foreach ($hits as $hit) {
            $domain = $this->registrableDomain($hit->url);
            if ($domain === '' || $this->isNoise($domain) || $this->guard->isObviouslyUnsafeHost($domain)) {
                continue;
            }
            $byDomain[$domain] ??= ['results' => [], 'page' => null];
            if (count($byDomain[$domain]['results']) < 3) {
                $byDomain[$domain]['results'][] = $hit;
            }
        }

        // Cap unique domains examined to a small multiple of the request.
        $byDomain = array_slice($byDomain, 0, max($criteria->maxResults * 2, 12), preserve_keys: true);

        $fetched = 0;
        foreach ($byDomain as $domain => &$bucket) {
            if ($fetched >= $maxFetches) {
                break;
            }
            $url = $bucket['results'][0]->url;
            // Don't fetch social hosts — treat them as presence evidence only.
            if ($this->isSocialDomain($domain)) {
                continue;
            }
            $bucket['page'] = $this->fetcher->fetch($url);
            $fetched++;
        }
        unset($bucket);

        return [$byDomain, count($byDomain)];
    }

    private function isThin(ProspectCandidate $c): bool
    {
        $substantive = array_filter(
            $c->evidence,
            fn ($e) => $e->type !== EvidenceItem::TYPE_DESCRIPTION,
        );

        // Keep it if there is any corroborating signal, OR it has an own
        // website plus a name we could resolve from a real page.
        return $substantive === [] && $c->website === null;
    }

    private function registrableDomain(string $url): string
    {
        $host = strtolower((string) parse_url(Str::startsWith($url, 'http') ? $url : 'https://'.$url, PHP_URL_HOST));

        return Str::startsWith($host, 'www.') ? substr($host, 4) : $host;
    }

    private function isSocialDomain(string $domain): bool
    {
        foreach (['facebook.com', 'fb.com', 'instagram.com', 'tiktok.com', 'x.com', 'twitter.com'] as $s) {
            if ($domain === $s || str_ends_with($domain, '.'.$s)) {
                return true;
            }
        }

        return false;
    }

    private function isNoise(string $domain): bool
    {
        return in_array($domain, [
            'google.com', 'bing.com', 'duckduckgo.com', 'wikipedia.org', 'wikimedia.org',
            'youtube.com', 'reddit.com', 'quora.com', 'pinterest.com', 'linkedin.com',
            'yelp.com', 'tripadvisor.com', 'yellowpages.com', 'amazon.com', 'ebay.com',
        ], true);
    }

    /**
     * @param  list<string>  $queries
     * @param  list<array<string, string>>  $providerFailures
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function result(string $status, DiscoveryCriteria $criteria, array $queries, array $providerFailures, int $sourcesExamined, array $extra): array
    {
        return array_merge([
            'status' => $status,
            'criteria' => $criteria->toArray(),
            'searched_queries' => $queries,
            'sources_examined' => $sourcesExamined,
            'provider_failures' => $providerFailures,
            'prospects' => [],
            'notice' => 'These are RESEARCH candidates from public web sources — not CRM records. '
                .'Nothing has been added to the CRM. Every claim must be traceable to a listed source; '
                .'anything not supported by a source is unknown.',
        ], array_filter($extra, fn ($v) => $v !== null));
    }
}
