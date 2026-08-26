<?php

namespace App\Policies;

use App\Enums\TargetType;
use App\Models\Target;
use App\Models\User;

/**
 * STEP 7's CAN lists give the Manager every create/update verb for
 * targets, and give Team Head/Team Member view-only verbs with none of
 * their own — "Do not allow Team Heads to manipulate another team's
 * targets" and "Do not allow Team Members to create or modify targets"
 * are the only mutation-related rules for non-Managers, and neither
 * grants mutation, it only further restricts it. So: only the Manager
 * ever creates or updates a target, for any target type.
 */
class TargetPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Target $target): bool
    {
        if ($user->isManager()) {
            return true;
        }

        return match ($target->target_type) {
            TargetType::Manager => false,
            TargetType::Team => $user->team_id === $target->team_id,
            TargetType::Individual => $user->id === $target->owner_id
                || ($user->isTeamHead() && $target->team_id !== null && $user->team_id === $target->team_id),
        };
    }

    public function create(User $user): bool
    {
        return $user->isManager();
    }

    public function update(User $user, Target $target): bool
    {
        return $user->isManager();
    }
}
