<?php

namespace App\Services\MarketIntelligence;

use App\Contracts\MarketIntelligence\SearchProvider;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\MarketIntelligence\CriterionEvaluation;
use App\Support\MarketIntelligence\CriterionResult;
use App\Support\MarketIntelligence\DiscoveryCriteria;
use App\Support\MarketIntelligence\EvidenceItem;
use App\Support\MarketIntelligence\EvidenceStrength;
use App\Support\MarketIntelligence\OutboundUrlGuard;
use App\Support\MarketIntelligence\ProspectCandidate;
use App\Support\MarketIntelligence\QualificationCriteria;
use App\Support\MarketIntelligence\QualificationCriterion;
use App\Support\MarketIntelligence\QualificationOutcome;
use App\Support\MarketIntelligence\QualifiedProspect;
use App\Support\MarketIntelligence\SearchProviderException;
use App\Support\MarketIntelligence\SearchResult;
use App\Support\MarketIntelligence\SourceQuality;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * V2.2 (spec §4/§8/§9): evaluates V2.1 ProspectCandidates against
 * explicit qualification criteria and produces a DETERMINISTIC,
 * non-numeric outcome.
 *
 * The split of responsibilities is deliberate and load-bearing:
 *   - evaluate() / evaluateCriterion() / decideOutcome() are PURE — no
 *     network, no clock, no LLM. Given the same candidate + criteria
 *     they always return the same QualifiedProspect. This is where the
 *     qualification outcome is actually decided (spec §9); the LLM only
 *     presents the result.
 *   - qualify() is the bounded IO shell: it reuses V2.1's discovery
 *     pipeline to get candidates, does a hard-capped amount of extra
 *     research for unresolved HARD criteria (spec §18), then calls the
 *     pure core, audits, and formats.
 *
 * It has NO CRM/Cost-to-Serve/send/SQL reach — same isolated Market
 * Intelligence boundary as V2.1.
 */
final class ProspectQualificationService
{
    /**
     * Things qualification structurally cannot establish from public
     * pages — always surfaced as MISSING (spec §16/§17). Never guessed.
     *
     * @var list<string>
     */
    private const BLIND_SPOTS = [
        'Actual shipment / parcel volume',
        'Current or incumbent courier / logistics provider',
        'Monthly order volume',
        'Logistics or delivery spend',
        'Decision maker and contact details',
        'Real delivery destinations and coverage',
        'Willingness to change provider / buying intent',
    ];

    /**
     * Coarse list of well-known PH locations, used ONLY to notice when a
     * source names a DIFFERENT place than the one asked for (spec §14).
     * Not a geocoder.
     *
     * @var list<string>
     */
    private const KNOWN_LOCATIONS = [
        'manila', 'quezon city', 'makati', 'taguig', 'pasig', 'mandaluyong', 'paranaque',
        'caloocan', 'las pinas', 'muntinlupa', 'marikina', 'pasay',
        'cebu', 'mandaue', 'lapu-lapu', 'davao', 'cagayan de oro', 'iloilo', 'bacolod',
        'general santos', 'zamboanga', 'butuan', 'baguio', 'angeles', 'batangas',
        'lipa', 'naga', 'legazpi', 'tacloban', 'dumaguete', 'cotabato', 'ormoc',
        'luzon', 'visayas', 'mindanao', 'metro manila', 'ncr',
    ];

    /** @var list<string> */
    private const MARKETPLACE_TOKENS = ['shopee', 'lazada', 'carousell', 'tiktok shop', 'zalora', 'amazon', 'ebay'];

    public function __construct(
        private readonly ProspectDiscoveryService $discovery,
        private readonly SearchProvider $search,
        private readonly WebEvidenceFetcher $fetcher,
        private readonly OutboundUrlGuard $guard = new OutboundUrlGuard,
    ) {}

    /**
     * @param  list<string>  $focusDomains  optional: restrict qualification to these
     *                                      domains from a prior discovery (spec §5) — the
     *                                      candidates are still re-derived deterministically,
     *                                      never reconstructed from conversation text.
     * @return array<string, mixed>
     */
    public function qualify(User $actor, DiscoveryCriteria $discoveryCriteria, QualificationCriteria $criteria, array $focusDomains = []): array
    {
        $perHour = (int) (config('services.market_intelligence.max_qualifications_per_hour') ?? 12);
        $key = 'market-intel:qualify:'.$actor->id;

        if (RateLimiter::tooManyAttempts($key, $perHour)) {
            return $this->result('rate_limited', $discoveryCriteria, $criteria, null, [
                'message' => 'You have reached the hourly limit for prospect qualification. Try again in '
                    .ceil(RateLimiter::availableIn($key) / 60).' minute(s).',
            ]);
        }
        RateLimiter::hit($key, 3600);

        $run = $this->qualifyToObjects($discoveryCriteria, $criteria, $focusDomains);

        if ($run['status'] !== 'ok') {
            return $this->result($run['status'], $discoveryCriteria, $criteria, null, array_filter([
                'message' => $run['message'] ?? null,
                'provider_failures' => $run['provider_failures'] ?: null,
            ]));
        }

        $outcomeCounts = $this->outcomeCounts($run['prospects']);

        AuditLogger::record('market_intelligence.qualification', $actor, [
            'discovery_criteria' => $discoveryCriteria->toArray(),
            'qualification_criteria' => $criteria->toArray(),
            'provider' => $this->search->name(),
            'prospect_count' => count($run['prospects']),
            'outcome_counts' => $outcomeCounts,
            'research' => $run['research'],
            'provider_failures' => count($run['provider_failures']) + $run['research']['provider_failures'],
            'status' => 'ok',
        ]);

        return $this->result('ok', $discoveryCriteria, $criteria, $run['budget'], [
            'qualified_prospects' => array_map(fn (QualifiedProspect $q) => $q->toArray(), $run['prospects']),
            'outcome_counts' => $outcomeCounts,
            'provider_failures' => $run['provider_failures'],
        ]);
    }

    /**
     * Discovery → per-criterion evaluation → bounded research → assemble,
     * returning the QualifiedProspect OBJECTS (no rate-limit, no audit —
     * the caller owns those). V2.3's ProspectScoringService reuses this
     * so it never reconstructs a prospect from conversation text and
     * never opens a second search/fetch pipeline (spec §3).
     *
     * @param  list<string>  $focusDomains
     * @return array{status: string, prospects: list<QualifiedProspect>, research: array<string, int>, budget: ?QualificationResearchBudget, provider_failures: list<array<string, string>>, message?: string}
     */
    public function qualifyToObjects(DiscoveryCriteria $discoveryCriteria, QualificationCriteria $criteria, array $focusDomains = []): array
    {
        $config = config('services.market_intelligence');
        $gathered = $this->discovery->gather($discoveryCriteria);

        if ($gathered['status'] === 'provider_unavailable') {
            return [
                'status' => 'provider_unavailable',
                'prospects' => [],
                'research' => (new QualificationResearchBudget(0, 0))->toArray(),
                'budget' => null,
                'provider_failures' => $gathered['provider_failures'],
                'message' => 'The external search service is currently unavailable. Nothing could be qualified.',
            ];
        }

        $candidates = $this->focus($gathered['candidates'], $focusDomains);

        if ($candidates === []) {
            return [
                'status' => 'no_prospects',
                'prospects' => [],
                'research' => (new QualificationResearchBudget(0, 0))->toArray(),
                'budget' => null,
                'provider_failures' => $gathered['provider_failures'],
                'message' => 'No candidate businesses were found to qualify for these criteria.',
            ];
        }

        $candidates = array_slice($candidates, 0, $criteria->maxProspects);

        $budget = new QualificationResearchBudget(
            (int) ($config['max_qualification_searches'] ?? 6),
            (int) ($config['max_qualification_fetches'] ?? 8),
        );

        $qualified = [];
        foreach ($candidates as $candidate) {
            $evaluations = $this->evaluateAll($candidate, $criteria);

            // Bounded additional research ONLY for unresolved HARD criteria.
            if ($this->hasUnresolvedHard($evaluations)) {
                $extra = $this->research($candidate, $criteria, $evaluations, $budget);
                if ($extra !== []) {
                    $candidate = $candidate->withAdditionalEvidence($extra);
                    $evaluations = $this->evaluateAll($candidate, $criteria);
                }
            }

            $qualified[] = $this->assemble($candidate, $evaluations);
        }

        return [
            'status' => 'ok',
            'prospects' => $qualified,
            'research' => $budget->toArray(),
            'budget' => $budget,
            'provider_failures' => $gathered['provider_failures'],
        ];
    }

    // ── PURE CORE ────────────────────────────────────────────────────

    /**
     * Deterministic, no IO. Public so it can be unit-tested directly
     * against hand-built candidates.
     */
    public function evaluate(ProspectCandidate $candidate, QualificationCriteria $criteria): QualifiedProspect
    {
        return $this->assemble($candidate, $this->evaluateAll($candidate, $criteria));
    }

    /**
     * @return list<CriterionEvaluation>
     */
    private function evaluateAll(ProspectCandidate $candidate, QualificationCriteria $criteria): array
    {
        return array_map(fn (QualificationCriterion $c) => $this->evaluateCriterion($c, $candidate), $criteria->criteria);
    }

    private function evaluateCriterion(QualificationCriterion $criterion, ProspectCandidate $candidate): CriterionEvaluation
    {
        return match ($criterion->key) {
            QualificationCriterion::KEY_LOCATION => $this->evaluateLocation($criterion, $candidate),
            QualificationCriterion::KEY_OWN_WEBSITE => $this->evaluateOwnWebsite($criterion, $candidate),
            QualificationCriterion::KEY_INDUSTRY => $this->evaluateTextMatch(
                $criterion, $candidate, [EvidenceItem::TYPE_PRODUCT, EvidenceItem::TYPE_DESCRIPTION],
                $candidate->category !== null && $criterion->expected !== null
                    && Str::contains(mb_strtolower($candidate->category), mb_strtolower($criterion->expected)),
            ),
            QualificationCriterion::KEY_PRODUCT => $this->evaluateTextMatch(
                $criterion, $candidate, [EvidenceItem::TYPE_PRODUCT],
                $criterion->expected !== null && $this->listContains($candidate->observedProducts, $criterion->expected),
            ),
            QualificationCriterion::KEY_ONLINE_SELLING, QualificationCriterion::KEY_ECOMMERCE => $this->evaluateFlag(
                $criterion, $candidate, EvidenceItem::TYPE_ONLINE_SELLING, $candidate->onlineSellingEvidence,
                'Sells online (ordering / cart / storefront detected).',
                'Online ordering functionality was not observed.',
            ),
            QualificationCriterion::KEY_SHIPPING => $this->evaluateFlag(
                $criterion, $candidate, EvidenceItem::TYPE_SHIPPING, $candidate->shippingEvidence,
                'Shows delivery / shipping information (coverage and volume unknown).',
                'No delivery / shipping information was observed.',
            ),
            QualificationCriterion::KEY_SOCIAL_PRESENCE => $this->evaluateFlag(
                $criterion, $candidate, EvidenceItem::TYPE_SOCIAL_PRESENCE, $candidate->socialPresence !== [],
                'Has a public social / business profile.',
                'No public social / business profile was found.',
            ),
            QualificationCriterion::KEY_MARKETPLACE => $this->evaluateMarketplace($criterion, $candidate),
            QualificationCriterion::KEY_PHYSICAL_PRODUCTS => $this->evaluatePhysicalProducts($criterion, $candidate),
            default => $this->evaluateKeyword($criterion, $candidate),
        };
    }

    private function evaluateLocation(QualificationCriterion $criterion, ProspectCandidate $candidate): CriterionEvaluation
    {
        $expected = $criterion->expected;
        $matches = [];
        $conflicts = [];

        if ($expected !== null && $candidate->location !== null
            && $this->sameLocation($candidate->location, $expected)) {
            $matches = array_merge($matches, $this->evidenceOfType($candidate, EvidenceItem::TYPE_LOCATION));
        }

        foreach ($candidate->evidence as $item) {
            if ($item->type === EvidenceItem::TYPE_CONTRADICTION) {
                $conflicts[] = $item;
            } elseif ($item->type === EvidenceItem::TYPE_LOCATION && $expected !== null
                && $this->summaryMatchesLocation($item->summary, $expected)) {
                $matches[] = $item;
            }
        }

        $matches = $this->unique($matches);
        $conflicts = $this->unique($conflicts);

        [$result, $claim] = match (true) {
            $matches !== [] && $conflicts !== [] => [CriterionResult::Conflicting, 'Sources disagree on the location — some place the business in '.$expected.', others elsewhere.'],
            $matches !== [] => [CriterionResult::Satisfied, 'Located in '.$expected.'.'],
            $conflicts !== [] => [CriterionResult::NotSatisfied, 'Public sources place the business somewhere other than '.$expected.'.'],
            default => [CriterionResult::Unknown, 'Location could not be confirmed against '.($expected ?? 'the requested area').' from public sources.'],
        };

        return new CriterionEvaluation(
            $criterion,
            $result,
            $claim,
            array_merge($matches, $conflicts),
            $conflicts !== [] && $matches !== [] ? 'Both conflicting sources are retained below; the contradiction is unresolved.' : null,
        );
    }

    private function evaluateOwnWebsite(QualificationCriterion $criterion, ProspectCandidate $candidate): CriterionEvaluation
    {
        if ($candidate->website !== null) {
            $evidence = $this->evidenceFromDomain($candidate, $candidate->domain);

            return new CriterionEvaluation($criterion, CriterionResult::Satisfied, 'Operates its own website ('.$candidate->website.').', $evidence ?: $this->firstDescription($candidate));
        }

        if ($candidate->socialPresence !== []) {
            return new CriterionEvaluation(
                $criterion,
                CriterionResult::NotSatisfied,
                'No independent website was found — the business appears to operate through a social/marketplace profile only.',
                $this->evidenceOfType($candidate, EvidenceItem::TYPE_SOCIAL_PRESENCE),
            );
        }

        return new CriterionEvaluation($criterion, CriterionResult::Unknown, 'Could not confirm whether the business has its own website.', []);
    }

    /**
     * @param  list<string>  $types
     */
    private function evaluateTextMatch(QualificationCriterion $criterion, ProspectCandidate $candidate, array $types, bool $structuralHit): CriterionEvaluation
    {
        $expected = $criterion->expected;
        $evidence = [];

        foreach ($candidate->evidence as $item) {
            if (! in_array($item->type, $types, true)) {
                continue;
            }
            if ($expected === null || Str::contains(mb_strtolower($item->summary), mb_strtolower($expected))) {
                $evidence[] = $item;
            }
        }

        $evidence = $this->unique($evidence);

        if ($structuralHit || $evidence !== []) {
            return new CriterionEvaluation($criterion, CriterionResult::Satisfied, $criterion->label.' — confirmed by a source.', $evidence ?: $this->firstDescription($candidate));
        }

        return new CriterionEvaluation($criterion, CriterionResult::Unknown, $criterion->label.' — not found in any source examined.', []);
    }

    private function evaluateFlag(QualificationCriterion $criterion, ProspectCandidate $candidate, string $type, bool $flag, string $yes, string $no): CriterionEvaluation
    {
        $evidence = $this->evidenceOfType($candidate, $type);

        if ($flag || $evidence !== []) {
            return new CriterionEvaluation($criterion, CriterionResult::Satisfied, $yes, $evidence ?: $this->firstDescription($candidate));
        }

        // Absence of evidence is not evidence of absence (spec §13).
        return new CriterionEvaluation($criterion, CriterionResult::Unknown, $no.' (Absence of evidence, not confirmed absence.)', []);
    }

    private function evaluateMarketplace(QualificationCriterion $criterion, ProspectCandidate $candidate): CriterionEvaluation
    {
        $evidence = [];
        foreach ($candidate->evidence as $item) {
            $haystack = mb_strtolower($item->summary.' '.$item->sourceDomain.' '.$item->sourceUrl);
            foreach (self::MARKETPLACE_TOKENS as $token) {
                if (str_contains($haystack, $token)) {
                    $evidence[] = $item;
                    break;
                }
            }
        }
        $evidence = $this->unique($evidence);

        if ($evidence !== []) {
            return new CriterionEvaluation($criterion, CriterionResult::Satisfied, 'Present on at least one online marketplace.', $evidence);
        }

        return new CriterionEvaluation($criterion, CriterionResult::Unknown, 'No marketplace presence was observed.', []);
    }

    private function evaluatePhysicalProducts(QualificationCriterion $criterion, ProspectCandidate $candidate): CriterionEvaluation
    {
        $hasProducts = $candidate->observedProducts !== [] || $this->evidenceOfType($candidate, EvidenceItem::TYPE_PRODUCT) !== [];

        if ($hasProducts) {
            return new CriterionEvaluation(
                $criterion,
                CriterionResult::Satisfied,
                'Appears to sell physical, shippable products.',
                $this->evidenceOfType($candidate, EvidenceItem::TYPE_PRODUCT) ?: $this->firstDescription($candidate),
            );
        }

        return new CriterionEvaluation($criterion, CriterionResult::Unknown, 'Could not confirm whether the business sells physical products.', []);
    }

    private function evaluateKeyword(QualificationCriterion $criterion, ProspectCandidate $candidate): CriterionEvaluation
    {
        $needle = mb_strtolower($criterion->expected ?? $criterion->label);
        $evidence = [];

        foreach ($candidate->evidence as $item) {
            if (str_contains(mb_strtolower($item->summary), $needle)) {
                $evidence[] = $item;
            }
        }
        $evidence = $this->unique($evidence);

        $inName = str_contains(mb_strtolower($candidate->name.' '.($candidate->category ?? '')), $needle);

        if ($evidence !== [] || $inName) {
            return new CriterionEvaluation($criterion, CriterionResult::Satisfied, $criterion->label.' — found in a source.', $evidence ?: $this->firstDescription($candidate));
        }

        return new CriterionEvaluation($criterion, CriterionResult::Unknown, $criterion->label.' — not found in any source examined.', []);
    }

    /**
     * The deterministic decision table (spec §9). Hard criteria drive
     * the outcome; supporting signals never override a hard result and
     * only matter in the (rare) hard-criteria-absent case.
     *
     * @param  list<CriterionEvaluation>  $evaluations
     */
    private function decideOutcome(array $evaluations): QualificationOutcome
    {
        $hard = array_values(array_filter($evaluations, fn (CriterionEvaluation $e) => $e->criterion->isHard()));
        $supporting = array_values(array_filter($evaluations, fn (CriterionEvaluation $e) => ! $e->criterion->isHard()));

        if ($hard === []) {
            $satisfied = count(array_filter($supporting, fn (CriterionEvaluation $e) => $e->result === CriterionResult::Satisfied));

            return $satisfied >= 1 ? QualificationOutcome::PossibleMatch : QualificationOutcome::InsufficientEvidence;
        }

        $failed = array_filter($hard, fn (CriterionEvaluation $e) => in_array($e->result, [CriterionResult::NotSatisfied, CriterionResult::Conflicting], true));
        $unknown = array_filter($hard, fn (CriterionEvaluation $e) => $e->result === CriterionResult::Unknown);
        $satisfiedStrong = array_filter($hard, fn (CriterionEvaluation $e) => $e->isSatisfiedStrongly());

        if ($failed !== []) {
            return QualificationOutcome::WeakMatch;
        }

        if (count($unknown) === count($hard) || count($unknown) * 2 >= count($hard) + 1) {
            return QualificationOutcome::InsufficientEvidence;
        }

        if ($unknown !== []) {
            return count($satisfiedStrong) >= 1 ? QualificationOutcome::PossibleMatch : QualificationOutcome::InsufficientEvidence;
        }

        return count($satisfiedStrong) === count($hard)
            ? QualificationOutcome::StrongMatch
            : QualificationOutcome::PossibleMatch;
    }

    /**
     * @param  list<CriterionEvaluation>  $evaluations
     */
    private function assemble(ProspectCandidate $candidate, array $evaluations): QualifiedProspect
    {
        $outcome = $this->decideOutcome($evaluations);

        return new QualifiedProspect(
            candidate: $candidate,
            outcome: $outcome,
            evaluations: $evaluations,
            observed: $this->observedFacts($candidate),
            inferences: $this->inferences($candidate),
            missing: $this->missingInformation($candidate),
            recommendation: $this->recommendation($outcome),
        );
    }

    // ── BOUNDED ADDITIONAL RESEARCH (spec §18) ───────────────────────

    /**
     * @param  list<CriterionEvaluation>  $evaluations
     * @return list<EvidenceItem>
     */
    private function research(ProspectCandidate $candidate, QualificationCriteria $criteria, array $evaluations, QualificationResearchBudget $budget): array
    {
        $usedSearches = 0;
        $usedFetches = 0;

        if (! $budget->canSearch($usedSearches)) {
            return [];
        }

        $unresolvedHard = array_values(array_filter(
            $evaluations,
            fn (CriterionEvaluation $e) => $e->criterion->isHard() && $e->result === CriterionResult::Unknown,
        ));
        if ($unresolvedHard === []) {
            return [];
        }

        $hints = array_filter(array_map(fn (CriterionEvaluation $e) => $e->criterion->expected, $unresolvedHard));
        $query = trim('"'.$candidate->name.'" '.implode(' ', array_slice($hints, 0, 3)));

        try {
            $hits = $this->search->search($query, 5);
            $budget->recordSearch();
            $usedSearches++;
        } catch (SearchProviderException) {
            $budget->recordProviderFailure();

            return [];
        }

        $newEvidence = [];
        foreach ($this->pickResearchUrls($hits, $candidate) as $url) {
            if (! $budget->canFetch($usedFetches)) {
                break;
            }
            $page = $this->fetcher->fetch($url);
            $budget->recordFetch();
            $usedFetches++;

            if ($page === null) {
                continue;
            }
            $newEvidence = array_merge($newEvidence, $this->scanPage($page, $criteria, $candidate));
        }

        return $newEvidence;
    }

    /**
     * @param  list<SearchResult>  $hits
     * @return list<string>
     */
    private function pickResearchUrls(array $hits, ProspectCandidate $candidate): array
    {
        $own = [];
        $other = [];

        foreach ($hits as $hit) {
            $domain = $this->registrableDomain($hit->url);
            if ($domain === '' || $this->guard->isObviouslyUnsafeHost($domain)) {
                continue;
            }
            if ($candidate->domain !== null && $domain === $candidate->domain) {
                $own[] = $hit->url;
            } else {
                $other[] = $hit->url;
            }
        }

        return array_slice(array_merge(array_unique($own), array_unique($other)), 0, 2);
    }

    /**
     * Deterministic scan of a fetched page for the criteria that matter.
     * Produces EvidenceItems (with strength/source-quality) — never a
     * decision.
     *
     * @return list<EvidenceItem>
     */
    private function scanPage(FetchedPage $page, QualificationCriteria $criteria, ProspectCandidate $candidate): array
    {
        $domain = $this->registrableDomain($page->url);
        $isOwn = $candidate->domain !== null && $domain === $candidate->domain;
        $quality = SourceQuality::classify($domain, $candidate->domain, fromFetchedPage: true);
        $strength = $isOwn ? EvidenceStrength::Direct : EvidenceStrength::Corroborating;
        $haystack = mb_strtolower($page->title.' '.$page->description.' '.$page->text);
        $out = [];

        foreach ($criteria->criteria as $criterion) {
            match ($criterion->key) {
                QualificationCriterion::KEY_LOCATION => $out = array_merge($out, $this->scanLocation($criterion, $haystack, $page, $domain, $strength, $quality)),
                QualificationCriterion::KEY_INDUSTRY, QualificationCriterion::KEY_PRODUCT, QualificationCriterion::KEY_KEYWORD => $out = array_merge($out, $this->scanText($criterion, $haystack, $page, $domain, EvidenceItem::TYPE_PRODUCT, $strength, $quality)),
                QualificationCriterion::KEY_ONLINE_SELLING, QualificationCriterion::KEY_ECOMMERCE => $out = array_merge($out, $this->scanTokens($haystack, ['add to cart', 'checkout', 'shopping cart', 'buy now', 'add to bag', '/product/', '/products/'], EvidenceItem::TYPE_ONLINE_SELLING, 'online ordering / cart', $page, $domain, $strength, $quality)),
                QualificationCriterion::KEY_SHIPPING => $out = array_merge($out, $this->scanTokens($haystack, ['nationwide', 'we ship', 'we deliver', 'shipping', 'delivery', 'cash on delivery', 'courier'], EvidenceItem::TYPE_SHIPPING, 'shipping / delivery information', $page, $domain, $strength, $quality)),
                QualificationCriterion::KEY_MARKETPLACE => $out = array_merge($out, $this->scanTokens($haystack.' '.mb_strtolower(implode(' ', $page->links)), self::MARKETPLACE_TOKENS, EvidenceItem::TYPE_MARKETPLACE, 'a marketplace reference', $page, $domain, $strength, $quality)),
                default => null,
            };
        }

        return $out;
    }

    /**
     * @return list<EvidenceItem>
     */
    private function scanLocation(QualificationCriterion $criterion, string $haystack, FetchedPage $page, string $domain, EvidenceStrength $strength, SourceQuality $quality): array
    {
        $expected = $criterion->expected;
        if ($expected === null) {
            return [];
        }

        $out = [];
        $expectedMatched = false;

        foreach ($this->locationTokens($expected) as $token) {
            if (str_contains($haystack, $token)) {
                $expectedMatched = true;
                $out[] = new EvidenceItem(
                    EvidenceItem::TYPE_LOCATION,
                    'Source text names the area "'.$token.'".',
                    $page->url, $domain, $page->fetchedAt, $strength, $quality,
                );
                break;
            }
        }

        if (! $expectedMatched) {
            foreach (self::KNOWN_LOCATIONS as $other) {
                if ($this->sameLocation($other, $expected)) {
                    continue;
                }
                if (str_contains($haystack, $other)) {
                    $out[] = new EvidenceItem(
                        EvidenceItem::TYPE_CONTRADICTION,
                        'Source names a different location, "'.$other.'", and does not mention '.$expected.'.',
                        $page->url, $domain, $page->fetchedAt, $strength, $quality,
                    );
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * @return list<EvidenceItem>
     */
    private function scanText(QualificationCriterion $criterion, string $haystack, FetchedPage $page, string $domain, string $type, EvidenceStrength $strength, SourceQuality $quality): array
    {
        $needle = mb_strtolower($criterion->expected ?? '');
        if ($needle === '' || ! str_contains($haystack, $needle)) {
            return [];
        }

        return [new EvidenceItem(
            $type,
            'Source text mentions "'.$needle.'".',
            $page->url, $domain, $page->fetchedAt, $strength, $quality,
        )];
    }

    /**
     * @param  list<string>  $tokens
     * @return list<EvidenceItem>
     */
    private function scanTokens(string $haystack, array $tokens, string $type, string $label, FetchedPage $page, string $domain, EvidenceStrength $strength, SourceQuality $quality): array
    {
        foreach ($tokens as $token) {
            if (str_contains($haystack, $token)) {
                return [new EvidenceItem(
                    $type,
                    'Source contains '.$label.' ("'.trim($token, '/').'").',
                    $page->url, $domain, $page->fetchedAt, $strength, $quality,
                )];
            }
        }

        return [];
    }

    // ── DERIVED PRESENTATION DATA (deterministic) ────────────────────

    /**
     * @return list<string>
     */
    private function observedFacts(ProspectCandidate $c): array
    {
        $facts = [];
        if ($c->location !== null) {
            $facts[] = 'Location: '.$c->location;
        }
        if ($c->category !== null) {
            $facts[] = 'Category: '.$c->category;
        }
        foreach ($c->observedProducts as $product) {
            $facts[] = 'Product / category mentioned: '.$product;
        }
        if ($c->onlineSellingEvidence) {
            $facts[] = 'Online ordering / storefront functionality observed';
        }
        if ($c->shippingEvidence) {
            $facts[] = 'Delivery / shipping information present on a source';
        }
        if ($c->socialPresence !== []) {
            $facts[] = 'Public social / business profile(s): '.implode(', ', array_slice($c->socialPresence, 0, 3));
        }
        if ($c->website !== null) {
            $facts[] = 'Own website: '.$c->website;
        }

        return $facts;
    }

    /**
     * Fixed, deterministic inferences — each stated AS an inference and
     * only emitted when its observed preconditions hold (spec §16).
     *
     * @return list<string>
     */
    private function inferences(ProspectCandidate $c): array
    {
        $out = [];

        if ($c->onlineSellingEvidence && ($c->observedProducts !== [] || $c->category !== null)) {
            $out[] = 'Selling physical products online creates a plausible parcel-delivery requirement (actual volume unknown).';
        }
        if ($c->shippingEvidence && $c->onlineSellingEvidence) {
            $out[] = 'The business already references delivery, suggesting an existing fulfilment process (scale and current provider unknown).';
        }
        if (count($c->socialPresence) >= 1 && $c->website !== null) {
            $out[] = 'Maintains an active multi-channel public presence (own site plus social profile).';
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function missingInformation(ProspectCandidate $c): array
    {
        return array_values(array_unique(array_merge($c->missing, self::BLIND_SPOTS)));
    }

    private function recommendation(QualificationOutcome $outcome): string
    {
        return match ($outcome) {
            QualificationOutcome::StrongMatch => 'Worth further business-development research (shipment volume, incumbent courier, decision maker).',
            QualificationOutcome::PossibleMatch => 'Worth a closer look — confirm the unresolved or weakly-evidenced criteria before pursuing.',
            QualificationOutcome::WeakMatch => 'Likely not a fit for the stated criteria; only pursue if the requirements change.',
            QualificationOutcome::InsufficientEvidence => 'Not enough public evidence to qualify — manual research required before any decision.',
        };
    }

    // ── HELPERS ──────────────────────────────────────────────────────

    /**
     * @param  list<ProspectCandidate>  $candidates
     * @param  list<string>  $focusDomains
     * @return list<ProspectCandidate>
     */
    private function focus(array $candidates, array $focusDomains): array
    {
        if ($focusDomains === []) {
            return $candidates;
        }

        $wanted = array_map(fn (string $d) => $this->registrableDomain($d), $focusDomains);

        $filtered = array_values(array_filter(
            $candidates,
            fn (ProspectCandidate $c) => $c->domain !== null && in_array($c->domain, $wanted, true),
        ));

        // If the focus list matched nothing, fall back to the full set
        // rather than silently returning zero prospects.
        return $filtered !== [] ? $filtered : $candidates;
    }

    /**
     * @param  list<CriterionEvaluation>  $evaluations
     */
    private function hasUnresolvedHard(array $evaluations): bool
    {
        foreach ($evaluations as $evaluation) {
            if ($evaluation->criterion->isHard() && $evaluation->result === CriterionResult::Unknown) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<QualifiedProspect>  $qualified
     * @return array<string, int>
     */
    private function outcomeCounts(array $qualified): array
    {
        $counts = [];
        foreach (QualificationOutcome::cases() as $case) {
            $counts[$case->value] = 0;
        }
        foreach ($qualified as $q) {
            $counts[$q->outcome->value]++;
        }

        return $counts;
    }

    /**
     * @return list<EvidenceItem>
     */
    private function evidenceOfType(ProspectCandidate $candidate, string $type): array
    {
        return $this->unique(array_values(array_filter($candidate->evidence, fn (EvidenceItem $e) => $e->type === $type)));
    }

    /**
     * @return list<EvidenceItem>
     */
    private function evidenceFromDomain(ProspectCandidate $candidate, ?string $domain): array
    {
        if ($domain === null) {
            return [];
        }

        return $this->unique(array_values(array_filter($candidate->evidence, fn (EvidenceItem $e) => $e->sourceDomain === $domain)));
    }

    /**
     * @return list<EvidenceItem>
     */
    private function firstDescription(ProspectCandidate $candidate): array
    {
        foreach ($candidate->evidence as $item) {
            if ($item->type === EvidenceItem::TYPE_DESCRIPTION) {
                return [$item];
            }
        }

        return $candidate->evidence === [] ? [] : [$candidate->evidence[0]];
    }

    /**
     * @param  list<EvidenceItem>  $items
     * @return list<EvidenceItem>
     */
    private function unique(array $items): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            $sig = $item->type.'|'.$item->sourceUrl.'|'.$item->summary;
            if (isset($seen[$sig])) {
                continue;
            }
            $seen[$sig] = true;
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param  list<string>  $haystack
     */
    private function listContains(array $haystack, string $needle): bool
    {
        foreach ($haystack as $item) {
            if (Str::contains(mb_strtolower($item), mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    /** @var list<string> too-generic to identify a place on their own */
    private const LOCATION_STOPWORDS = ['city', 'town', 'province', 'metro', 'municipality', 'barangay', 'district', 'area', 'region', 'the', 'and', 'philippines'];

    /**
     * @return list<string>
     */
    private function locationTokens(string $location): array
    {
        return array_values(array_filter(
            preg_split('/[\s,]+/', mb_strtolower($location)) ?: [],
            fn ($t) => strlen($t) >= 3 && ! in_array($t, self::LOCATION_STOPWORDS, true),
        ));
    }

    private function summaryMatchesLocation(string $summary, string $expected): bool
    {
        $summary = mb_strtolower($summary);
        foreach ($this->locationTokens($expected) as $token) {
            if (str_contains($summary, $token)) {
                return true;
            }
        }

        return false;
    }

    private function sameLocation(string $a, string $b): bool
    {
        $a = mb_strtolower(trim($a));
        $b = mb_strtolower(trim($b));

        return $a === $b || str_contains($a, $b) || str_contains($b, $a);
    }

    private function registrableDomain(string $url): string
    {
        $host = strtolower((string) parse_url(Str::startsWith($url, 'http') ? $url : 'https://'.$url, PHP_URL_HOST));

        return Str::startsWith($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function result(string $status, DiscoveryCriteria $discovery, QualificationCriteria $criteria, ?QualificationResearchBudget $budget, array $extra): array
    {
        return array_merge([
            'status' => $status,
            'discovery_criteria' => $discovery->toArray(),
            'qualification_criteria' => $criteria->toArray(),
            'research_budget' => $budget?->toArray(),
            'qualified_prospects' => [],
            'notice' => 'Qualification is EVIDENCE-GROUNDED RESEARCH against the stated criteria — not a numeric rating and not a CRM action. '
                .'Nothing has been added to the CRM. Every result must trace to a listed source; unknown information stays unknown. '
                .'The outcome (strong / possible / weak / insufficient) is decided by the application from the criterion results, not by the assistant.',
        ], array_filter($extra, fn ($v) => $v !== null));
    }
}
