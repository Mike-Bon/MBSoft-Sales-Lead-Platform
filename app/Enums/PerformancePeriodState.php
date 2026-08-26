<?php

namespace App\Enums;

/**
 * Where "now" sits relative to a performance calculation's period.
 * Drives how run rate / required run rate are displayed — see
 * App\Services\PerformanceService and docs/PERFORMANCE.md.
 */
enum PerformancePeriodState: string
{
    case Future = 'future';
    case Current = 'current';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Future => 'Upcoming',
            self::Current => 'In progress',
            self::Completed => 'Completed',
        };
    }
}
