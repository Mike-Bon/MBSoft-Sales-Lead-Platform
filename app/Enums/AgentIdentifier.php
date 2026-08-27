<?php

namespace App\Enums;

/**
 * STEP 3/6: the three specialized agents — a closed set (STEP 3 forbids
 * adding more without the application requiring it). Used everywhere an
 * agent must be referenced, instead of a bare string, so a typo'd
 * identifier is a compile-time/static-analysis error, not a silent
 * routing bug.
 */
enum AgentIdentifier: string
{
    case Sales = 'sales';
    case Performance = 'performance';
    case Communication = 'communication';

    public function label(): string
    {
        return match ($this) {
            self::Sales => 'Sales Intelligence',
            self::Performance => 'Performance & Management',
            self::Communication => 'Communication & Follow-Up',
        };
    }
}
