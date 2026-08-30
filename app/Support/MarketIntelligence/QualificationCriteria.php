<?php

namespace App\Support\MarketIntelligence;

use Illuminate\Validation\ValidationException;

/**
 * V2.2 (spec §6): the full set of criteria a batch of prospects is
 * qualified against, plus the batch size cap.
 *
 * Criteria come from exactly two places (spec §6):
 *   1. the user's V2.1 discovery request (DiscoveryCriteria) — sensible
 *      defaults are derived from it;
 *   2. explicit `hard_criteria` / `supporting_criteria` the caller
 *      passed, which override the kind of a derived criterion or add a
 *      new one.
 *
 * Nothing is invented. If neither source yields a criterion, that is a
 * validation error, not a guess.
 */
final readonly class QualificationCriteria
{
    private const MAX_CRITERIA = 12;

    /**
     * @param  list<QualificationCriterion>  $criteria
     */
    public function __construct(
        public array $criteria,
        public int $maxProspects,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function fromArray(array $input, DiscoveryCriteria $discovery, int $maxProspectsCap): self
    {
        $hardKeys = self::cleanKeyList($input['hard_criteria'] ?? []);
        $supportingKeys = self::cleanKeyList($input['supporting_criteria'] ?? []);

        /** @var array<string, QualificationCriterion> $bySignature */
        $bySignature = [];

        // 1. Defaults derived from the discovery request.
        foreach (self::derivedFromDiscovery($discovery) as $criterion) {
            $bySignature[$criterion->signature()] = $criterion;
        }

        // 2. Explicit criteria — override kind of a match, or add new.
        foreach ([[CriterionKind::Hard, $hardKeys], [CriterionKind::Supporting, $supportingKeys]] as [$kind, $keys]) {
            foreach ($keys as $raw) {
                $criterion = self::criterionFromExplicitKey($raw, $kind, $discovery);
                $existing = self::findByKey($bySignature, $criterion->key, $criterion->expected);

                if ($existing !== null) {
                    $bySignature[$existing->signature()] = $existing->withKind($kind);
                } else {
                    $bySignature[$criterion->signature()] = $criterion;
                }
            }
        }

        $criteria = array_values($bySignature);

        if ($criteria === []) {
            throw ValidationException::withMessages([
                'qualification' => 'Provide discovery criteria (location, industry, or a product) or explicit qualification criteria to evaluate against.',
            ]);
        }

        // Keep hard criteria first, then supporting, then cap.
        usort($criteria, fn (QualificationCriterion $a, QualificationCriterion $b) => ($a->isHard() ? 0 : 1) <=> ($b->isHard() ? 0 : 1));
        $criteria = array_slice($criteria, 0, self::MAX_CRITERIA);

        $requested = (int) ($input['max_results'] ?? $discovery->maxResults);
        $maxProspects = max(1, min($requested > 0 ? $requested : $discovery->maxResults, $maxProspectsCap));

        return new self($criteria, $maxProspects);
    }

    /**
     * @return list<QualificationCriterion>
     */
    public function hard(): array
    {
        return array_values(array_filter($this->criteria, fn (QualificationCriterion $c) => $c->isHard()));
    }

    /**
     * @return list<QualificationCriterion>
     */
    public function supporting(): array
    {
        return array_values(array_filter($this->criteria, fn (QualificationCriterion $c) => ! $c->isHard()));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'criteria' => array_map(fn (QualificationCriterion $c) => $c->toArray(), $this->criteria),
            'hard_count' => count($this->hard()),
            'supporting_count' => count($this->supporting()),
            'max_prospects' => $this->maxProspects,
        ];
    }

    /**
     * @return list<QualificationCriterion>
     */
    private static function derivedFromDiscovery(DiscoveryCriteria $d): array
    {
        $out = [];

        if ($d->location !== null) {
            $out[] = new QualificationCriterion(QualificationCriterion::KEY_LOCATION, CriterionKind::Hard, 'Located in '.$d->location, $d->location);
        }

        if ($d->industry !== null) {
            $out[] = new QualificationCriterion(QualificationCriterion::KEY_INDUSTRY, CriterionKind::Hard, $d->industry.' business', $d->industry);
        }

        foreach (array_slice($d->productKeywords, 0, 4) as $keyword) {
            $out[] = new QualificationCriterion(QualificationCriterion::KEY_PRODUCT, CriterionKind::Supporting, 'Sells / offers '.$keyword, $keyword);
        }

        if (in_array('own_website', $d->onlineSignals, true)) {
            $out[] = new QualificationCriterion(QualificationCriterion::KEY_OWN_WEBSITE, CriterionKind::Hard, 'Has its own website', null);
            $out[] = new QualificationCriterion(QualificationCriterion::KEY_ONLINE_SELLING, CriterionKind::Supporting, 'Sells online (cart / checkout / store)', null);
        }

        if (in_array('marketplace', $d->onlineSignals, true)) {
            $out[] = new QualificationCriterion(QualificationCriterion::KEY_MARKETPLACE, CriterionKind::Hard, 'Present on an online marketplace', null);
        }

        if (array_intersect(['facebook', 'instagram', 'tiktok'], $d->onlineSignals) !== []) {
            $out[] = new QualificationCriterion(QualificationCriterion::KEY_SOCIAL_PRESENCE, CriterionKind::Hard, 'Has a public social / business profile', null);
        }

        return $out;
    }

    private static function criterionFromExplicitKey(string $raw, CriterionKind $kind, DiscoveryCriteria $d): QualificationCriterion
    {
        $key = str_replace([' ', '-'], '_', mb_strtolower(trim($raw)));

        return match ($key) {
            QualificationCriterion::KEY_LOCATION => new QualificationCriterion($key, $kind, 'Located in '.($d->location ?? 'the requested area'), $d->location),
            QualificationCriterion::KEY_INDUSTRY => new QualificationCriterion($key, $kind, ($d->industry ?? 'Requested').' business', $d->industry),
            QualificationCriterion::KEY_ONLINE_SELLING, 'online_store', 'online_shop', 'sells_online' => new QualificationCriterion(QualificationCriterion::KEY_ONLINE_SELLING, $kind, 'Sells online (cart / checkout / store)', null),
            QualificationCriterion::KEY_OWN_WEBSITE, 'website', 'own_site' => new QualificationCriterion(QualificationCriterion::KEY_OWN_WEBSITE, $kind, 'Has its own website', null),
            QualificationCriterion::KEY_ECOMMERCE, 'e_commerce', 'cart', 'checkout' => new QualificationCriterion(QualificationCriterion::KEY_ECOMMERCE, $kind, 'Has e-commerce / ordering functionality', null),
            QualificationCriterion::KEY_SOCIAL_PRESENCE, 'social', 'facebook', 'instagram', 'tiktok' => new QualificationCriterion(QualificationCriterion::KEY_SOCIAL_PRESENCE, $kind, 'Has a public social / business profile', null),
            QualificationCriterion::KEY_SHIPPING, 'delivery', 'shipping_info', 'delivery_info' => new QualificationCriterion(QualificationCriterion::KEY_SHIPPING, $kind, 'Shows delivery / shipping information', null),
            QualificationCriterion::KEY_MARKETPLACE, 'shopee', 'lazada' => new QualificationCriterion(QualificationCriterion::KEY_MARKETPLACE, $kind, 'Present on an online marketplace', null),
            QualificationCriterion::KEY_PHYSICAL_PRODUCTS, 'physical_goods', 'physical_product' => new QualificationCriterion(QualificationCriterion::KEY_PHYSICAL_PRODUCTS, $kind, 'Sells physical (shippable) products', null),
            default => new QualificationCriterion(
                QualificationCriterion::KEY_KEYWORD,
                $kind,
                'Mentions "'.mb_substr(trim($raw), 0, 60).'"',
                mb_substr(trim($raw), 0, 60),
            ),
        };
    }

    /**
     * @param  array<string, QualificationCriterion>  $bySignature
     */
    private static function findByKey(array $bySignature, string $key, ?string $expected): ?QualificationCriterion
    {
        foreach ($bySignature as $criterion) {
            if ($criterion->key !== $key) {
                continue;
            }
            if ($expected === null || mb_strtolower($criterion->expected ?? '') === mb_strtolower($expected)) {
                return $criterion;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function cleanKeyList(mixed $value): array
    {
        $items = is_array($value) ? $value : (is_string($value) ? explode(',', $value) : []);
        $clean = [];

        foreach ($items as $item) {
            if (is_string($item) && trim($item) !== '') {
                $clean[] = mb_substr(trim($item), 0, 60);
            }
        }

        return array_values(array_slice(array_unique($clean), 0, self::MAX_CRITERIA));
    }
}
