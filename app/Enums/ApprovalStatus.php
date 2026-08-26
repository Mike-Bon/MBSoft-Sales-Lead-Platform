<?php

namespace App\Enums;

/**
 * STEP 20/39: the lifecycle of one workflow-produced approval item.
 * Expired is a distinct terminal state from Rejected — an expired
 * approval was never actively declined, it simply aged out (STEP 39).
 */
enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
        };
    }

    public function isDecided(): bool
    {
        return $this !== self::Pending;
    }
}
