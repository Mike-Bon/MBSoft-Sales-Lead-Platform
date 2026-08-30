<?php

namespace App\Support\MarketIntelligence;

/**
 * V2.2 (spec §12/§13): the result of evaluating one QualificationCriterion
 * against a prospect's evidence, with the claim it produced and the
 * evidence that produced it — machine-readable so V2.3 can consume it
 * without re-fetching anything.
 *
 *   Criterion: LOCATION = "Cebu City"      (hard)
 *   Result:    SATISFIED
 *   Claim:     "Business is located in Cebu City."
 *   Evidence:  [EvidenceItem(direct, official_company, https://…)]
 */
final readonly class CriterionEvaluation
{
    /**
     * @param  list<EvidenceItem>  $evidence
     */
    public function __construct(
        public QualificationCriterion $criterion,
        public CriterionResult $result,
        public string $claim,
        public array $evidence,
        public ?string $note = null,
    ) {}

    /** The strongest evidence backing this evaluation, or null when there is none. */
    public function strength(): ?EvidenceStrength
    {
        return EvidenceStrength::strongest(
            array_filter(array_map(fn (EvidenceItem $e) => $e->strength, $this->evidence)),
        );
    }

    public function isSatisfiedStrongly(): bool
    {
        if ($this->result !== CriterionResult::Satisfied) {
            return false;
        }

        $strength = $this->strength();

        return $strength !== null
            && $strength->rank() >= EvidenceStrength::Corroborating->rank();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'criterion' => $this->criterion->toArray(),
            'result' => $this->result->value,
            'claim' => $this->claim,
            'evidence_strength' => $this->strength()?->value,
            'evidence' => array_map(fn (EvidenceItem $e) => $e->toArray(), $this->evidence),
            'note' => $this->note,
        ];
    }
}
