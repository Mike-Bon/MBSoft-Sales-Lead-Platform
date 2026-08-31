<?php

namespace App\Support\MarketIntelligence;

/**
 * V2.4 (spec §14): the deterministic outcome of checking an external
 * prospect against the CRM records the actor is authorised to see. It
 * is derived from an explicit combination of match signals, never from
 * an opaque confidence number and never by the LLM.
 */
enum DuplicateStatus: string
{
    /** Normalised domain match plus a compatible business name. */
    case ExactDuplicate = 'exact_duplicate';

    /** Normalised domain match alone, or a distinctive name match with corroboration. */
    case LikelyDuplicate = 'likely_duplicate';

    /** A distinctive name match (exact or close) without stronger corroboration. */
    case PossibleDuplicate = 'possible_duplicate';

    /** No meaningful identity match in the actor's authorised CRM view. */
    case NoMatch = 'no_match';

    public function label(): string
    {
        return match ($this) {
            self::ExactDuplicate => 'EXACT DUPLICATE',
            self::LikelyDuplicate => 'LIKELY DUPLICATE',
            self::PossibleDuplicate => 'POSSIBLE DUPLICATE',
            self::NoMatch => 'NO MATCH',
        };
    }

    /** Higher = stronger. Used to pick the overall status and to order candidates. */
    public function rank(): int
    {
        return match ($this) {
            self::ExactDuplicate => 3,
            self::LikelyDuplicate => 2,
            self::PossibleDuplicate => 1,
            self::NoMatch => 0,
        };
    }
}
