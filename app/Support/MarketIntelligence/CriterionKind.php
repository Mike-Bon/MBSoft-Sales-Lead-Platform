<?php

namespace App\Support\MarketIntelligence;

/**
 * V2.2 (spec §7): a qualification criterion is either a HARD requirement
 * or a SUPPORTING signal.
 *
 * A prospect that clearly fails a hard criterion can never be a
 * STRONG_MATCH, no matter how many supporting signals it has. Supporting
 * signals add colour and, in the rare hard-criteria-absent case, are the
 * only thing left to judge on — they never override a hard result.
 */
enum CriterionKind: string
{
    case Hard = 'hard';

    case Supporting = 'supporting';
}
