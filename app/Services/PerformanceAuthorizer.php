<?php

namespace App\Services;

use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Authorization for performance data (STEP 19). Not a Policy tied to a
 * single Eloquent model — a team/individual/organisation performance
 * snapshot is a computed view over Opportunity + Target, not a model
 * instance of its own — so this is the equivalent gate, following the
 * same rules as Phase 3's CRM policies (App\Policies\Concerns\
 * AuthorizesCrmRecords): Manager unrestricted; Team Head/Member limited
 * to their own team; Team Member limited to their own individual data
 * within it. Never trust a client-supplied team/user id — every check
 * here is derived from the authenticated actor's own stored role/team.
 */
class PerformanceAuthorizer
{
    public function canViewOrganisation(User $actor): bool
    {
        return $actor->isManager();
    }

    public function canViewTeam(User $actor, Team $team): bool
    {
        if ($actor->isManager()) {
            return true;
        }

        return $actor->team_id === $team->id;
    }

    public function canViewIndividual(User $actor, User $target): bool
    {
        if ($actor->isManager() || $actor->is($target)) {
            return true;
        }

        return $actor->isTeamHead() && $target->team_id !== null && $actor->team_id === $target->team_id;
    }

    /**
     * @throws AuthorizationException
     */
    public function authorizeOrganisation(User $actor): void
    {
        if (! $this->canViewOrganisation($actor)) {
            throw new AuthorizationException('This action is unauthorized.');
        }
    }

    /**
     * @throws AuthorizationException
     */
    public function authorizeTeam(User $actor, Team $team): void
    {
        if (! $this->canViewTeam($actor, $team)) {
            throw new AuthorizationException('This action is unauthorized.');
        }
    }

    /**
     * @throws AuthorizationException
     */
    public function authorizeIndividual(User $actor, User $target): void
    {
        if (! $this->canViewIndividual($actor, $target)) {
            throw new AuthorizationException('This action is unauthorized.');
        }
    }
}
