<?php

namespace App\Enums;

/**
 * The organisational level a target applies to. Not hard-coded to a
 * specific number of teams/users — a Team target references a `teams`
 * row, an Individual target references a `users` row, so the hierarchy
 * scales to any number of teams or salespeople without code changes.
 */
enum TargetType: string
{
    case Manager = 'manager';
    case Team = 'team';
    case Individual = 'individual';

    public function label(): string
    {
        return match ($this) {
            self::Manager => 'Manager (Organisation-wide)',
            self::Team => 'Team',
            self::Individual => 'Individual',
        };
    }
}
