<?php

namespace App\Support\MarketIntelligence;

/**
 * V2.2 (spec §13): the outcome of evaluating ONE qualification criterion
 * against the evidence gathered for a prospect. Deliberately four
 * states, not a boolean — "we found no evidence either way" is a
 * genuinely different answer from "we found evidence it is false"
 * (spec §13: absence of evidence is not evidence of absence).
 */
enum CriterionResult: string
{
    /** A source actually shows the criterion is met. */
    case Satisfied = 'satisfied';

    /** A source actually shows the criterion is NOT met. */
    case NotSatisfied = 'not_satisfied';

    /** No usable evidence either way within the research budget. */
    case Unknown = 'unknown';

    /** Sources disagree and the conflict was not resolved (spec §14). */
    case Conflicting = 'conflicting';
}
