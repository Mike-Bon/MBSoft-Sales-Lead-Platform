<?php

namespace App\Enums;

/**
 * V2.5: the lifecycle of one prospect → CRM lead proposal. Mirrors
 * ApprovalStatus (Phase 8) — a proposal persists until a human reviews
 * it, and moving off `Pending` is a one-way, one-time transition.
 */
enum ProspectProposalStatus: string
{
    /** Prepared, awaiting the human's explicit "Create Lead" confirmation. */
    case Pending = 'pending';

    /** The human confirmed; a Lead (and Organization) was created. Terminal. */
    case Confirmed = 'confirmed';

    /** The human cancelled the proposal. Terminal. Nothing was created. */
    case Cancelled = 'cancelled';

    /** A newer proposal was prepared for the same prospect by the same user. Terminal. */
    case Superseded = 'superseded';

    /** The proposal aged out before it was confirmed. Terminal. */
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Confirmed => 'Confirmed — lead created',
            self::Cancelled => 'Cancelled',
            self::Superseded => 'Superseded',
            self::Expired => 'Expired',
        };
    }

    public function isDecided(): bool
    {
        return $this !== self::Pending;
    }
}
