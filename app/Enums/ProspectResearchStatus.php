<?php

namespace App\Enums;

/**
 * V2.0.3: lifecycle of one user-initiated Market Intelligence research
 * run. Same string-backed, minimal-states shape as WorkflowStatus /
 * AgentInteractionStatus — no "cancelled" (nothing lets a user abort a
 * running research job; they can only act on what it produced).
 */
enum ProspectResearchStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }
}
