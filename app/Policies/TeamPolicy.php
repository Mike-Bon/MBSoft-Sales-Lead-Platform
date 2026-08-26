<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;

/**
 * Authoritative server-side authorization for team access. Never trust a
 * client-supplied team id or role value — every check here is derived
 * from the authenticated user's own stored role/team relationship.
 */
class TeamPolicy
{
    /**
     * Only the Manager may list the full team roster.
     */
    public function viewAny(User $user): bool
    {
        return $user->isManager();
    }

    /**
     * The Manager may view any team. A Team Head may view only their own
     * team. Team Members do not get a team management/detail screen in
     * this phase (they see their team context on their own profile).
     */
    public function view(User $user, Team $team): bool
    {
        if ($user->isManager()) {
            return true;
        }

        return $user->isTeamHead() && $user->team_id === $team->id;
    }

    /**
     * Only the Manager may create teams.
     */
    public function create(User $user): bool
    {
        return $user->isManager();
    }

    /**
     * Only the Manager may update a team (including assigning its Team
     * Head). A Team Head can never modify their own or another team's
     * membership/leadership.
     */
    public function update(User $user, Team $team): bool
    {
        return $user->isManager();
    }
}
