<?php

namespace App\Support\MarketIntelligence;

/**
 * V2.2 (spec §11): how strongly one piece of evidence supports a claim.
 * A simple four-level classification — NOT a statistical confidence
 * model.
 *
 *  - DIRECT:        the primary/authoritative source explicitly states it
 *                   (e.g. the company's own website lists a Cebu City address).
 *  - CORROBORATING: an independent legitimate public source also states it
 *                   (e.g. a reputable directory also identifies Cebu).
 *  - INDIRECT:      only a search snippet / secondary mention suggests it;
 *                   the primary source could not be fetched or did not say it.
 *  - UNVERIFIED:    a weak or inaccessible source makes a claim that could
 *                   not be confirmed.
 */
enum EvidenceStrength: string
{
    case Direct = 'direct';

    case Corroborating = 'corroborating';

    case Indirect = 'indirect';

    case Unverified = 'unverified';

    /** Higher = stronger. Used only to pick the strongest item for a claim. */
    public function rank(): int
    {
        return match ($this) {
            self::Direct => 4,
            self::Corroborating => 3,
            self::Indirect => 2,
            self::Unverified => 1,
        };
    }

    /**
     * The strongest of a set (null when the set is empty).
     *
     * @param  iterable<self>  $strengths
     */
    public static function strongest(iterable $strengths): ?self
    {
        $best = null;

        foreach ($strengths as $strength) {
            if ($best === null || $strength->rank() > $best->rank()) {
                $best = $strength;
            }
        }

        return $best;
    }
}
