<?php

namespace App\Enums;

/**
 * STEP 14: only the states this implementation actually needs. No
 * "cancelled" — nothing in Phase 8 lets a user cancel a running
 * workflow execution (they can only act on what it produced).
 */
enum WorkflowStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }
}
