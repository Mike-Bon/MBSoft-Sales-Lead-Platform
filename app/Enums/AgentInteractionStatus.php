<?php

namespace App\Enums;

/**
 * STEP 35: the outcome of one assistant request/response cycle, for
 * audit purposes. Not a business-data enum — purely operational.
 */
enum AgentInteractionStatus: string
{
    case Completed = 'completed';
    case Failed = 'failed';
    case LimitReached = 'limit_reached';

    public function label(): string
    {
        return match ($this) {
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::LimitReached => 'Limit reached',
        };
    }
}
