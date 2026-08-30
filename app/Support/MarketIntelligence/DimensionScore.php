<?php

namespace App\Support\MarketIntelligence;

/**
 * V2.3 (spec §17/§21): one line of a prospect's score breakdown. Every
 * awarded point is explainable — the dimension, the points, the maximum,
 * the deterministic factor that produced them, a plain-language reason,
 * the exact evidence behind it, and (when relevant) a note about what
 * was unknown / conflicting / not requested.
 */
final readonly class DimensionScore
{
    /**
     * @param  list<EvidenceItem>  $evidence
     */
    public function __construct(
        public string $key,
        public string $label,
        public int $pointsAwarded,
        public int $maxPoints,
        public float $factor,
        public string $reason,
        public array $evidence = [],
        public ?string $note = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'points_awarded' => $this->pointsAwarded,
            'max_points' => $this->maxPoints,
            'factor' => round($this->factor, 2),
            'reason' => $this->reason,
            'evidence' => array_map(fn (EvidenceItem $e) => $e->toArray(), $this->evidence),
            'note' => $this->note,
        ];
    }
}
