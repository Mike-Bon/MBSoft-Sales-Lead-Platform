<?php

namespace App\Support\MarketIntelligence;

/**
 * V2.2 (spec §31): a V2.1 ProspectCandidate after qualification — the
 * exact structured hand-off V2.3 (transparent lead scoring) will consume
 * WITHOUT touching the web again.
 *
 * It carries:
 *   - the qualification outcome (deterministic, non-numeric);
 *   - every criterion evaluation (hard/supporting, result, claim,
 *     evidence, evidence strength);
 *   - the observed facts, the deterministic inferences drawn from them,
 *     and the information qualification structurally cannot establish;
 *   - the underlying candidate, so discovery provenance and V2.1
 *     discovery confidence travel with it.
 *
 * `discovery_confidence` and `qualification_outcome` are DIFFERENT
 * concepts and both are kept (spec §10). No numeric score exists.
 */
final readonly class QualifiedProspect
{
    /**
     * @param  list<CriterionEvaluation>  $evaluations
     * @param  list<string>  $observed
     * @param  list<string>  $inferences
     * @param  list<string>  $missing
     */
    public function __construct(
        public ProspectCandidate $candidate,
        public QualificationOutcome $outcome,
        public array $evaluations,
        public array $observed,
        public array $inferences,
        public array $missing,
        public string $recommendation,
    ) {}

    /**
     * @return list<CriterionEvaluation>
     */
    public function hardEvaluations(): array
    {
        return array_values(array_filter($this->evaluations, fn (CriterionEvaluation $e) => $e->criterion->isHard()));
    }

    /**
     * @return list<CriterionEvaluation>
     */
    public function supportingEvaluations(): array
    {
        return array_values(array_filter($this->evaluations, fn (CriterionEvaluation $e) => ! $e->criterion->isHard()));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'business' => $this->candidate->name,
            'website' => $this->candidate->website,
            'domain' => $this->candidate->domain,
            'qualification_outcome' => $this->outcome->value,
            'qualification_outcome_label' => $this->outcome->label(),
            'hard_criteria' => array_map(fn (CriterionEvaluation $e) => $e->toArray(), $this->hardEvaluations()),
            'supporting_signals' => array_map(fn (CriterionEvaluation $e) => $e->toArray(), $this->supportingEvaluations()),
            'observed' => $this->observed,
            'inference' => $this->inferences,
            'missing_information' => $this->missing,
            'recommendation' => $this->recommendation,
            'discovery_confidence' => $this->candidate->confidence,
            'sources' => $this->sources(),
        ];
    }

    /**
     * Every distinct source URL that backs any evaluation or the
     * candidate itself — flat, for a "SOURCES" section and for V2.3.
     *
     * @return list<array<string, string|null>>
     */
    private function sources(): array
    {
        $seen = [];
        $out = [];

        $items = $this->candidate->evidence;
        foreach ($this->evaluations as $evaluation) {
            $items = array_merge($items, $evaluation->evidence);
        }

        foreach ($items as $item) {
            if (isset($seen[$item->sourceUrl])) {
                continue;
            }
            $seen[$item->sourceUrl] = true;
            $out[] = [
                'url' => $item->sourceUrl,
                'domain' => $item->sourceDomain,
                'source_quality' => $item->sourceQuality?->value,
                'observed_at' => $item->observedAt,
            ];
        }

        return $out;
    }
}
