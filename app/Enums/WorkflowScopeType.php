<?php

namespace App\Enums;

/**
 * STEP 23/24: every workflow execution has an explicit, server-derived
 * scope — never "god mode". Organisation only ever applies to a
 * Manager; Team only to that team's Head/Members; Individual is always
 * exactly one user's own permitted data.
 */
enum WorkflowScopeType: string
{
    case Organisation = 'organisation';
    case Team = 'team';
    case Individual = 'individual';

    public function label(): string
    {
        return match ($this) {
            self::Organisation => 'Organisation',
            self::Team => 'Team',
            self::Individual => 'Individual',
        };
    }
}
