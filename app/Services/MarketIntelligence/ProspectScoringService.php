<?php

namespace App\Services\MarketIntelligence;

use App\Models\User;
use App\Support\AuditLogger;
use App\Support\MarketIntelligence\CriterionEvaluation;
use App\Support\MarketIntelligence\CriterionResult;
use App\Support\MarketIntelligence\DimensionScore;
use App\Support\MarketIntelligence\DiscoveryCriteria;
use App\Support\MarketIntelligence\EvidenceItem;
use App\Support\MarketIntelligence\EvidenceStrength;
use App\Support\MarketIntelligence\QualificationCriteria;
use App\Support\MarketIntelligence\QualificationCriterion;
use App\Support\MarketIntelligence\QualificationOutcome;
use App\Support\MarketIntelligence\QualifiedProspect;
use App\Support\MarketIntelligence\ScoredProspect;
use App\Support\MarketIntelligence\ScoringModel;
use Illuminate\Support\Facades\RateLimiter;

/**
 * V2.3 (spec §1–§4, §19): transparent business-development prioritisation
 * scoring for external prospects, BEFORE they enter the CRM.
 *
 * The score is NOT a conversion probability, revenue estimate, or AI
 * opinion. It is a deterministic 100-point sum of evidence-backed
 * dimension points, decided entirely by application logic from the V2.2
 * `QualifiedProspect` structure + the config-backed `ScoringModel`.
 *
 * Two layers, and the split is load-bearing:
 *   - scoreProspect() / scoreAll() / rank() are PURE — no network, no
 *     LLM, no CRM, no clock, no randomness. Same QualifiedProspect +
 *     same ScoringModel => same ScoredProspect, always (spec §19, §23).
 *     ProspectScoringServiceTest proves the SearchProvider /
 *     WebEvidenceFetcher / Http are never touched here.
 *   - score() is the tool-facing shell: it re-derives fresh
 *     QualifiedProspects through the V2.2 pipeline (the same bounded web
 *     work qualify_prospects does — the LLM can never faithfully pass
 *     the structured objects back), then runs the pure core, ranks,
 *     audits, and formats.
 */
final class ProspectScoringService
{
    /**
     * Deterministic factor a dimension earns from the strength of the
     * evidence behind it. `null` (no evidence) → 0 (spec §15 — unknown
     * is never a penalty, it is simply no points).
     */
    private const STRENGTH_FACTOR = [
        'direct' => 1.0,
        'corroborating' => 0.9,
        'indirect' => 0.6,
        'unverified' => 0.35,
    ];

    /** @var list<string> categories that plausibly imply physical, shippable goods (spec §10) */
    private const PHYSICAL_CATEGORY_HINTS = [
        'cosmetic', 'beauty', 'skincare', 'makeup', 'apparel', 'clothing', 'fashion', 'shoe',
        'footwear', 'bag', 'accessor', 'jewel', 'watch', 'gadget', 'electronic', 'gear',
        'furniture', 'homeware', 'kitchenware', 'toy', 'book', 'stationery', 'food', 'snack',
        'beverage', 'coffee', 'grocer', 'supplement', 'pet', 'hardware', 'tool', 'auto part',
        'motorcycle part', 'sporting', 'merch', 'product', 'goods', 'retail', 'shop', 'store',
    ];

    public function __construct(
        private readonly ProspectQualificationService $qualification,
    ) {}

    /**
     * Tool-facing entry point. Re-derives QualifiedProspects (bounded web
     * work, owned by the V2.2 pipeline), scores them with the pure core,
     * ranks, audits, and formats.
     *
     * @param  list<string>  $focusDomains
     * @return array<string, mixed>
     */
    public function score(User $actor, DiscoveryCriteria $discovery, QualificationCriteria $criteria, ScoringModel $model, array $focusDomains = []): array
    {
        $perHour = (int) (config('services.market_intelligence.scoring.max_scorings_per_hour') ?? 12);
        $key = 'market-intel:score:'.$actor->id;

        if (RateLimiter::tooManyAttempts($key, $perHour)) {
            return $this->result('rate_limited', $model, [
                'message' => 'You have reached the hourly limit for prospect scoring. Try again in '
                    .ceil(RateLimiter::availableIn($key) / 60).' minute(s).',
            ]);
        }
        RateLimiter::hit($key, 3600);

        $run = $this->qualification->qualifyToObjects($discovery, $criteria, $focusDomains);

        if ($run['status'] !== 'ok') {
            return $this->result($run['status'], $model, array_filter([
                'message' => $run['message'] ?? 'Nothing could be scored.',
                'provider_failures' => $run['provider_failures'] ?: null,
            ]));
        }

        $scored = $this->rank($this->scoreAll($run['prospects'], $model));

        $priorityCounts = $this->priorityCounts($scored);
        $scoreRange = $this->scoreRange($scored);

        AuditLogger::record('market_intelligence.scoring', $actor, [
            'scoring_model' => $model->version,
            'config_valid' => $model->configValid,
            'discovery_criteria' => $discovery->toArray(),
            'qualification_criteria' => $criteria->toArray(),
            'prospect_count' => count($scored),
            'priority_distribution' => $priorityCounts,
            'score_range' => $scoreRange,
            'outcome_distribution' => $this->outcomeCounts($scored),
            'status' => 'ok',
        ]);

        return $this->result('ok', $model, [
            'scored_prospects' => array_map(fn (ScoredProspect $s) => $s->toArray(), $scored),
            'priority_distribution' => $priorityCounts,
            'score_range' => $scoreRange,
            'provider_failures' => $run['provider_failures'],
        ]);
    }

    // ── PURE CORE (no network, no LLM, no CRM, no clock) ─────────────

    /**
     * @param  list<QualifiedProspect>  $prospects
     * @return list<ScoredProspect>
     */
    public function scoreAll(array $prospects, ScoringModel $model): array
    {
        return array_map(fn (QualifiedProspect $p) => $this->scoreProspect($p, $model), $prospects);
    }

    public function scoreProspect(QualifiedProspect $prospect, ScoringModel $model): ScoredProspect
    {
        $dimensions = [
            $this->industryFit($prospect, $model),
            $this->geographyFit($prospect, $model),
            $this->onlineSelling($prospect, $model),
            $this->physicalProductRelevance($prospect, $model),
            $this->shippingSignals($prospect, $model),
            $this->digitalActivity($prospect, $model),
        ];
        $dimensions[] = $this->evidenceQuality($dimensions, $prospect, $model);

        $raw = array_sum(array_map(fn (DimensionScore $d) => $d->pointsAwarded, $dimensions));
        $raw = max(0, min(100, $raw));

        $cap = $model->capFor($prospect->outcome);
        $total = min($raw, $cap);
        $cappedBy = $raw > $cap
            ? 'qualification outcome '.$prospect->outcome->label().' (ceiling '.$cap.')'
            : null;

        return new ScoredProspect(
            prospect: $prospect,
            model: $model,
            rawScore: $raw,
            totalScore: $total,
            cappedBy: $cappedBy,
            priority: $model->bandFor($total),
            dimensions: $dimensions,
        );
    }

    /**
     * Deterministic ranking + tie-break (spec §22):
     *   1. total score, descending
     *   2. qualification outcome strength, descending
     *   3. evidence-quality points, descending
     *   4. domain/business, alphabetical ascending (stable final tie-break)
     *
     * @param  list<ScoredProspect>  $scored
     * @return list<ScoredProspect>
     */
    public function rank(array $scored): array
    {
        usort($scored, function (ScoredProspect $a, ScoredProspect $b) {
            return [$b->totalScore, $this->outcomeRank($b->outcome()), $b->dimension('evidence_quality')?->pointsAwarded ?? 0]
                <=> [$a->totalScore, $this->outcomeRank($a->outcome()), $a->dimension('evidence_quality')?->pointsAwarded ?? 0]
                ?: strcmp($a->identityKey(), $b->identityKey());
        });

        return array_values($scored);
    }

    // ── DIMENSIONS ──────────────────────────────────────────────────

    private function industryFit(QualifiedProspect $p, ScoringModel $model): DimensionScore
    {
        $max = $model->weightFor('industry_fit');
        $evaluation = $this->evaluationFor($p, QualificationCriterion::KEY_INDUSTRY)
            ?? $this->evaluationFor($p, QualificationCriterion::KEY_KEYWORD);

        if ($evaluation === null) {
            if ($p->candidate->category !== null) {
                return $this->dimension('industry_fit', 'Industry / category fit', $max, 0.5,
                    'Category "'.$p->candidate->category.'" was matched in a source (no explicit industry criterion was set).',
                    $this->evidenceOfTypes($p, [EvidenceItem::TYPE_PRODUCT, EvidenceItem::TYPE_DESCRIPTION]));
            }

            return $this->dimension('industry_fit', 'Industry / category fit', $max, 0.0,
                'No industry / category criterion was requested and no category evidence exists.', [], 'not requested');
        }

        return $this->fitDimension('industry_fit', 'Industry / category fit', $max, $evaluation);
    }

    private function geographyFit(QualifiedProspect $p, ScoringModel $model): DimensionScore
    {
        $max = $model->weightFor('geography_fit');
        $evaluation = $this->evaluationFor($p, QualificationCriterion::KEY_LOCATION);

        if ($evaluation === null) {
            if ($p->candidate->location !== null) {
                return $this->dimension('geography_fit', 'Geographic fit', $max, 0.4,
                    'Location "'.$p->candidate->location.'" was observed but was not a stated criterion.',
                    $this->evidenceOfTypes($p, [EvidenceItem::TYPE_LOCATION]), 'not a stated criterion');
            }

            return $this->dimension('geography_fit', 'Geographic fit', $max, 0.0,
                'Geography was not a requested criterion.', [], 'not requested');
        }

        return $this->fitDimension('geography_fit', 'Geographic fit', $max, $evaluation);
    }

    private function onlineSelling(QualifiedProspect $p, ScoringModel $model): DimensionScore
    {
        $max = $model->weightFor('online_selling');
        $strength = $this->strongestSatisfied($p, [
            QualificationCriterion::KEY_ONLINE_SELLING,
            QualificationCriterion::KEY_ECOMMERCE,
            QualificationCriterion::KEY_MARKETPLACE,
        ]);
        $evidence = $this->evidenceOfTypes($p, [EvidenceItem::TYPE_ONLINE_SELLING, EvidenceItem::TYPE_MARKETPLACE]);

        if ($strength === null && $p->candidate->onlineSellingEvidence) {
            $strength = EvidenceStrength::Indirect;
        }

        if ($strength === null) {
            return $this->dimension('online_selling', 'Online selling', $max, 0.0,
                'No online-selling evidence — a website alone is not online selling.', [], 'no evidence');
        }

        return $this->dimension('online_selling', 'Online selling', $max, $this->factor($strength),
            'Online ordering / cart / storefront or marketplace selling was observed ('.$strength->value.' evidence).', $evidence);
    }

    private function physicalProductRelevance(QualifiedProspect $p, ScoringModel $model): DimensionScore
    {
        $max = $model->weightFor('physical_product_relevance');
        $strength = $this->strongestSatisfied($p, [
            QualificationCriterion::KEY_PRODUCT,
            QualificationCriterion::KEY_PHYSICAL_PRODUCTS,
        ]);
        $evidence = $this->evidenceOfTypes($p, [EvidenceItem::TYPE_PRODUCT]);

        if ($strength === null && $p->candidate->observedProducts !== []) {
            $strength = EvidenceStrength::Indirect;
        }
        if ($strength === null && $this->looksPhysical($p->candidate->category)) {
            $strength = EvidenceStrength::Unverified;
        }

        if ($strength === null) {
            $note = $p->candidate->category !== null
                ? 'Category "'.$p->candidate->category.'" does not clearly indicate physical, shippable products.'
                : 'no physical-product evidence';

            return $this->dimension('physical_product_relevance', 'Physical-product / parcel relevance', $max, 0.0,
                'No evidence of physical, shippable products (relevance, not volume).', [], $note);
        }

        return $this->dimension('physical_product_relevance', 'Physical-product / parcel relevance', $max, $this->factor($strength),
            'Sells physical products that plausibly require delivery ('.$strength->value.' evidence). This is relevance, not parcel volume.', $evidence);
    }

    private function shippingSignals(QualifiedProspect $p, ScoringModel $model): DimensionScore
    {
        $max = $model->weightFor('shipping_signals');
        $evaluation = $this->evaluationFor($p, QualificationCriterion::KEY_SHIPPING);
        $strength = $evaluation !== null && $evaluation->result === CriterionResult::Satisfied ? $evaluation->strength() : null;
        $evidence = $this->evidenceOfTypes($p, [EvidenceItem::TYPE_SHIPPING]);

        if ($strength === null && $p->candidate->shippingEvidence) {
            $strength = EvidenceStrength::Indirect;
        }

        if ($strength === null) {
            return $this->dimension('shipping_signals', 'Shipping / delivery signals', $max, 0.0,
                'No delivery / shipping information was observed.', [], 'no evidence');
        }

        return $this->dimension('shipping_signals', 'Shipping / delivery signals', $max, $this->factor($strength),
            'A shipping / delivery statement was observed ('.$strength->value.' evidence). No coverage or volume is claimed.', $evidence);
    }

    private function digitalActivity(QualifiedProspect $p, ScoringModel $model): DimensionScore
    {
        $max = $model->weightFor('digital_activity');
        $candidate = $p->candidate;

        $parts = [];
        $evidence = [];

        if ($candidate->website !== null) {
            $parts['own website'] = (int) round($max * 0.4);
            $evidence = array_merge($evidence, $this->evidenceFromDomain($p, $candidate->domain));
        }
        if ($candidate->onlineSellingEvidence || $this->evidenceOfTypes($p, [EvidenceItem::TYPE_PRODUCT]) !== []) {
            $parts['product catalogue / storefront'] = (int) round($max * 0.3);
        }
        if ($candidate->socialPresence !== [] || $this->strongestSatisfied($p, [QualificationCriterion::KEY_SOCIAL_PRESENCE]) !== null) {
            $parts['public social / business profile'] = (int) round($max * 0.2);
            $evidence = array_merge($evidence, $this->evidenceOfTypes($p, [EvidenceItem::TYPE_SOCIAL_PRESENCE]));
        }
        if ($this->evidenceOfTypes($p, [EvidenceItem::TYPE_MARKETPLACE]) !== [] || $this->strongestSatisfied($p, [QualificationCriterion::KEY_MARKETPLACE]) !== null) {
            $parts['marketplace presence'] = (int) round($max * 0.1);
            $evidence = array_merge($evidence, $this->evidenceOfTypes($p, [EvidenceItem::TYPE_MARKETPLACE]));
        }

        $points = min($max, array_sum($parts));

        if ($points === 0) {
            return $this->dimension('digital_activity', 'Digital / business activity', $max, 0.0,
                'No observable digital-activity signals.', [], 'no evidence');
        }

        return $this->dimension('digital_activity', 'Digital / business activity', $max, $points / max(1, $max),
            'Observed: '.implode(', ', array_keys($parts)).'.', $this->dedupeEvidence($evidence), null, $points);
    }

    /**
     * Confidence IN THE EVIDENCE (spec §13) — deliberately small (5 pts).
     * Half from the average evidence strength of the point-earning
     * dimensions, half from V2.1 discovery confidence.
     *
     * @param  list<DimensionScore>  $businessDimensions
     */
    private function evidenceQuality(array $businessDimensions, QualifiedProspect $p, ScoringModel $model): DimensionScore
    {
        $max = $model->weightFor('evidence_quality');
        $awarded = array_values(array_filter($businessDimensions, fn (DimensionScore $d) => $d->pointsAwarded > 0));

        if ($awarded === []) {
            return $this->dimension('evidence_quality', 'Evidence quality', $max, 0.0,
                'No dimension earned points, so there is no evidence quality to assess.', [], 'no points awarded');
        }

        $avgStrength = array_sum(array_map(fn (DimensionScore $d) => $d->factor, $awarded)) / count($awarded);
        $confFactor = match ($p->candidate->confidence) {
            'high' => 1.0,
            'medium' => 0.6,
            'low' => 0.35,
            default => 0.5,
        };

        $factor = 0.5 * $avgStrength + 0.5 * $confFactor;

        return $this->dimension('evidence_quality', 'Evidence quality', $max, $factor,
            'Blended from the strength of the evidence behind the scored dimensions and the V2.1 discovery confidence ('
                .$p->candidate->confidence.'). Measures confidence in the evidence, not attractiveness of the prospect.', []);
    }

    // ── DIMENSION HELPERS ───────────────────────────────────────────

    private function fitDimension(string $key, string $label, int $max, CriterionEvaluation $evaluation): DimensionScore
    {
        return match ($evaluation->result) {
            CriterionResult::Satisfied => $this->dimension($key, $label, $max, $this->factor($evaluation->strength()),
                $evaluation->claim.' ('.($evaluation->strength()?->value ?? 'unrated').' evidence).', $evaluation->evidence),
            CriterionResult::Unknown => $this->dimension($key, $label, $max, 0.0,
                'Could not be confirmed from public sources — no points (unknown is not a penalty).', [], 'unknown'),
            CriterionResult::NotSatisfied => $this->dimension($key, $label, $max, 0.0,
                'A source indicates this criterion is not met.', $evaluation->evidence, 'not satisfied'),
            CriterionResult::Conflicting => $this->dimension($key, $label, $max, 0.0,
                'Sources conflict on this criterion — no points can be awarded until it is resolved.', $evaluation->evidence, 'conflicting'),
        };
    }

    /**
     * @param  list<EvidenceItem>  $evidence
     */
    private function dimension(string $key, string $label, int $max, float $factor, string $reason, array $evidence = [], ?string $note = null, ?int $points = null): DimensionScore
    {
        $factor = max(0.0, min(1.0, $factor));

        return new DimensionScore(
            key: $key,
            label: $label,
            pointsAwarded: $points ?? (int) round($max * $factor),
            maxPoints: $max,
            factor: $factor,
            reason: $reason,
            evidence: $this->dedupeEvidence($evidence),
            note: $note,
        );
    }

    private function factor(?EvidenceStrength $strength): float
    {
        return $strength === null ? 0.0 : (self::STRENGTH_FACTOR[$strength->value] ?? 0.0);
    }

    private function evaluationFor(QualifiedProspect $p, string $key): ?CriterionEvaluation
    {
        foreach ($p->evaluations as $evaluation) {
            if ($evaluation->criterion->key === $key) {
                return $evaluation;
            }
        }

        return null;
    }

    /**
     * Strongest evidence among the SATISFIED evaluations for any of the
     * given criterion keys.
     *
     * @param  list<string>  $keys
     */
    private function strongestSatisfied(QualifiedProspect $p, array $keys): ?EvidenceStrength
    {
        $strengths = [];
        foreach ($p->evaluations as $evaluation) {
            if (in_array($evaluation->criterion->key, $keys, true) && $evaluation->result === CriterionResult::Satisfied) {
                $strength = $evaluation->strength();
                if ($strength !== null) {
                    $strengths[] = $strength;
                }
            }
        }

        return EvidenceStrength::strongest($strengths);
    }

    /**
     * @param  list<string>  $types
     * @return list<EvidenceItem>
     */
    private function evidenceOfTypes(QualifiedProspect $p, array $types): array
    {
        $items = $p->candidate->evidence;
        foreach ($p->evaluations as $evaluation) {
            $items = array_merge($items, $evaluation->evidence);
        }

        return $this->dedupeEvidence(array_values(array_filter($items, fn (EvidenceItem $e) => in_array($e->type, $types, true))));
    }

    /**
     * @return list<EvidenceItem>
     */
    private function evidenceFromDomain(QualifiedProspect $p, ?string $domain): array
    {
        if ($domain === null) {
            return [];
        }

        $items = $p->candidate->evidence;
        foreach ($p->evaluations as $evaluation) {
            $items = array_merge($items, $evaluation->evidence);
        }

        return $this->dedupeEvidence(array_values(array_filter($items, fn (EvidenceItem $e) => $e->sourceDomain === $domain)));
    }

    /**
     * De-duplicate evidence so the same fact is never shown — or counted
     * toward a dimension — twice (spec §6).
     *
     * @param  list<EvidenceItem>  $items
     * @return list<EvidenceItem>
     */
    private function dedupeEvidence(array $items): array
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

        return array_slice($out, 0, 6);
    }

    private function looksPhysical(?string $category): bool
    {
        if ($category === null) {
            return false;
        }
        $category = mb_strtolower($category);
        foreach (self::PHYSICAL_CATEGORY_HINTS as $hint) {
            if (str_contains($category, $hint)) {
                return true;
            }
        }

        return false;
    }

    private function outcomeRank(QualificationOutcome $outcome): int
    {
        return match ($outcome) {
            QualificationOutcome::StrongMatch => 3,
            QualificationOutcome::PossibleMatch => 2,
            QualificationOutcome::WeakMatch => 1,
            QualificationOutcome::InsufficientEvidence => 0,
        };
    }

    // ── AGGREGATES / FORMATTING ─────────────────────────────────────

    /**
     * @param  list<ScoredProspect>  $scored
     * @return array<string, int>
     */
    private function priorityCounts(array $scored): array
    {
        $counts = ['high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($scored as $s) {
            $counts[$s->priority->value]++;
        }

        return $counts;
    }

    /**
     * @param  list<ScoredProspect>  $scored
     * @return array<string, int>
     */
    private function outcomeCounts(array $scored): array
    {
        $counts = [];
        foreach (QualificationOutcome::cases() as $case) {
            $counts[$case->value] = 0;
        }
        foreach ($scored as $s) {
            $counts[$s->outcome()->value]++;
        }

        return $counts;
    }

    /**
     * @param  list<ScoredProspect>  $scored
     * @return array{min: int, max: int}|null
     */
    private function scoreRange(array $scored): ?array
    {
        if ($scored === []) {
            return null;
        }
        $totals = array_map(fn (ScoredProspect $s) => $s->totalScore, $scored);

        return ['min' => min($totals), 'max' => max($totals)];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function result(string $status, ScoringModel $model, array $extra): array
    {
        return array_merge([
            'status' => $status,
            'scoring_model' => $model->toArray(),
            'scored_prospects' => [],
            'notice' => 'This is a TRANSPARENT business-development prioritisation score computed by the application from '
                .'the qualification evidence — not a conversion probability, revenue estimate, or AI opinion, and not a CRM action. '
                .'Nothing has been added to the CRM. Every point traces to a listed dimension and its evidence; unknown information '
                .'earns no points and is listed separately. The assistant does not choose the points, the weights, or the priority.',
        ], array_filter($extra, fn ($v) => $v !== null));
    }
}
