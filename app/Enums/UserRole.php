<?php

namespace App\Enums;

/**
 * The fixed set of organisational roles.
 *
 * Kept intentionally small per the project constitution: roles are a
 * centrally-defined enum, not strings scattered through the app, and no
 * roles are added beyond what the current specification requires.
 */
enum UserRole: string
{
    case Manager = 'manager';
    case TeamHead = 'team_head';
    case TeamMember = 'team_member';

    public function label(): string
    {
        return match ($this) {
            self::Manager => 'Manager',
            self::TeamHead => 'Team Head',
            self::TeamMember => 'Team Member',
        };
    }
}
