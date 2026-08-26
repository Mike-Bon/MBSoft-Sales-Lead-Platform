<?php

namespace App\Enums;

/**
 * A computed classification (never stored) of a lead's next_follow_up_at,
 * relative to "now" in the application's configured timezone. Purely
 * informational for this phase — no notifications, no scheduling.
 */
enum FollowUpStatus: string
{
    case Overdue = 'overdue';
    case DueToday = 'due_today';
    case Upcoming = 'upcoming';
    case NotSet = 'not_set';

    public function label(): string
    {
        return match ($this) {
            self::Overdue => 'Overdue',
            self::DueToday => 'Due Today',
            self::Upcoming => 'Upcoming',
            self::NotSet => 'No Follow-up Set',
        };
    }
}
