<?php

namespace App\Support\MarketIntelligence;

/**
 * V2.2 (spec §8): the non-numeric qualification outcome for one
 * prospect. It is decided by transparent application logic in
 * ProspectQualificationService from the structured criterion results —
 * NEVER by the LLM, and NEVER a number (spec §8, §9, §10).
 *
 * This is not a lead score. "How well does this candidate match what
 * was asked for?" is a different question from V2.1's discovery
 * confidence ("how well-evidenced is this candidate at all?") and from
 * the future V2.3 lead score ("how attractive is this prospect?").
 */
enum QualificationOutcome: string
{
    /** Every hard criterion satisfied by direct/corroborating evidence, none conflicting. */
    case StrongMatch = 'strong_match';

    /** Hard criteria broadly met but on weaker evidence, or one still unresolved. */
    case PossibleMatch = 'possible_match';

    /** A hard criterion is not met or is unresolved-conflicting. */
    case WeakMatch = 'weak_match';

    /** Not enough public evidence was obtainable within the research budget to decide. */
    case InsufficientEvidence = 'insufficient_evidence';

    public function label(): string
    {
        return match ($this) {
            self::StrongMatch => 'STRONG MATCH',
            self::PossibleMatch => 'POSSIBLE MATCH',
            self::WeakMatch => 'WEAK MATCH',
            self::InsufficientEvidence => 'INSUFFICIENT EVIDENCE',
        };
    }
}
