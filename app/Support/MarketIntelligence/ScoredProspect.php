<?php

namespace App\Support\MarketIntelligence;

/**
 * V2.3 (spec §21/§37): a V2.2 QualifiedProspect after transparent
 * scoring. Carries the full breakdown, the priority band, and the
 * scoring model/version — and preserves everything V2.4 (CRM duplicate
 * detection) will need to identify the prospect WITHOUT re-scoring or
 * touching the web.
 *
 * The three concepts stay distinct (spec §10):
 *   - `discovery_confidence` — how well-evidenced is the candidate at all
 *   - `qualification_outcome` — how well does it match the requested criteria
 *   - `total_score` / `priority` — how much business-development attention it deserves
 */
final readonly class ScoredProspect
{
    /**
     * @param  list<DimensionScore>  $dimensions
     */
    public function __construct(
        public QualifiedProspect $prospect,
        public ScoringModel $model,
        public int $rawScore,
        public int $totalScore,
        public ?string $cappedBy,
        public ScorePriority $priority,
        public array $dimensions,
    ) {}

    public function outcome(): QualificationOutcome
    {
        return $this->prospect->outcome;
    }

    /** Stable identity for deterministic tie-breaking (spec §22). */
    public function identityKey(): string
    {
        return mb_strtolower($this->prospect->candidate->domain ?? $this->prospect->candidate->name);
    }

    public function dimension(string $key): ?DimensionScore
    {
        foreach ($this->dimensions as $dimension) {
            if ($dimension->key === $key) {
                return $dimension;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $candidate = $this->prospect->candidate;

        return [
            'business' => $candidate->name,
            'website' => $candidate->website,
            'domain' => $candidate->domain,
            'qualification_outcome' => $this->prospect->outcome->value,
            'qualification_outcome_label' => $this->prospect->outcome->label(),
            'discovery_confidence' => $candidate->confidence,
            'total_score' => $this->totalScore,
            'max_score' => $this->model->maxScore(),
            'raw_score' => $this->rawScore,
            'capped_by' => $this->cappedBy,
            'priority' => $this->priority->value,
            'priority_label' => $this->priority->label(),
            'scoring_model' => $this->model->version,
            'breakdown' => array_map(fn (DimensionScore $d) => $d->toArray(), $this->dimensions),
            'missing_information' => $this->prospect->missing,
            'recommendation' => $this->prospect->recommendation,
            'sources' => $this->prospect->toArray()['sources'],
            // spec §37: everything V2.4 needs to run a CRM duplicate check
            // WITHOUT re-scoring — no CRM lookup is performed here.
            'identity' => [
                'business' => $candidate->name,
                'website' => $candidate->website,
                'domain' => $candidate->domain,
                'public_profiles' => $candidate->socialPresence,
                'source_domains' => $this->sourceDomains(),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function sourceDomains(): array
    {
        $domains = [];
        foreach ($this->prospect->toArray()['sources'] as $source) {
            if (is_string($source['domain'] ?? null) && ! in_array($source['domain'], $domains, true)) {
                $domains[] = $source['domain'];
            }
        }

        return $domains;
    }
}
