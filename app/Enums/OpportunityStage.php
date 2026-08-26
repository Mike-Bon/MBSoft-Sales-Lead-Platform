<?php

namespace App\Enums;

enum OpportunityStage: string
{
    case Qualification = 'qualification';
    case Proposal = 'proposal';
    case Negotiation = 'negotiation';
    case ClosedWon = 'closed_won';
    case ClosedLost = 'closed_lost';

    public function label(): string
    {
        return match ($this) {
            self::Qualification => 'Qualification',
            self::Proposal => 'Proposal',
            self::Negotiation => 'Negotiation',
            self::ClosedWon => 'Closed Won',
            self::ClosedLost => 'Closed Lost',
        };
    }

    /**
     * CLOSED_WON and CLOSED_LOST are terminal: reopening one is an
     * intentional, explicit action (moving back to an open stage), never
     * an implicit side effect of another change.
     */
    public function isClosed(): bool
    {
        return in_array($this, [self::ClosedWon, self::ClosedLost], true);
    }

    public function isWon(): bool
    {
        return $this === self::ClosedWon;
    }

    public function isLost(): bool
    {
        return $this === self::ClosedLost;
    }
}
