<?php

namespace App\Enums;

/**
 * Manually assigned by a user. Deliberately not derived from any scoring
 * formula or model — AI-based prioritisation is Phase 7 scope.
 */
enum LeadPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Medium => 'Medium',
            self::High => 'High',
        };
    }
}
