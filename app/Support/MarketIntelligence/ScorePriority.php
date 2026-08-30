<?php

namespace App\Support\MarketIntelligence;

/**
 * V2.3 (spec §18): the priority band a prospect's total score falls in.
 * Deterministic, derived from configurable thresholds in ScoringModel.
 * NOT a conversion probability and NOT a lead score itself.
 */
enum ScorePriority: string
{
    case High = 'high';

    case Medium = 'medium';

    case Low = 'low';

    public function label(): string
    {
        return match ($this) {
            self::High => 'HIGH',
            self::Medium => 'MEDIUM',
            self::Low => 'LOW',
        };
    }
}
