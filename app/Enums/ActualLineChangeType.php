<?php

namespace App\Enums;

/**
 * What one write to a performance_actual_lines row did. "unchanged" is
 * never persisted as a revision — a no-op submission records nothing.
 */
enum ActualLineChangeType: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Unchanged = 'unchanged';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'New value',
            self::Updated => 'Updated',
            self::Unchanged => 'No change',
        };
    }
}
