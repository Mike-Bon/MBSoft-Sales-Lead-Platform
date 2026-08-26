<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The single place that turns a request's "owner_id"/"team_id" into a
 * validated, authorized assignment. Every CRM create/update path
 * (Organization, Contact, Lead, Opportunity) goes through this — STEP 14
 * is explicit that the server, never the client, must decide what a user
 * is allowed to assign.
 *
 * Rules:
 *   - Manager: free choice of any existing user as owner and any existing
 *     team (or none, for an organisation-wide/personal record).
 *   - Team Head: team_id is always forced to their own team, regardless
 *     of what was requested. owner_id must be a member of that same team
 *     (or themselves); anything else is denied.
 *   - Team Member: team_id is always forced to their own team. owner_id
 *     is always forced to themselves — a Team Member can never assign a
 *     record to a teammate.
 */
class CrmAssignmentService
{
    /**
     * @return array{owner_id: ?int, team_id: ?int}
     */
    public function resolve(User $actor, ?int $requestedOwnerId, ?int $requestedTeamId): array
    {
        if ($actor->isManager()) {
            return $this->resolveForManager($requestedOwnerId, $requestedTeamId);
        }

        if ($actor->isTeamHead()) {
            return $this->resolveForTeamHead($actor, $requestedOwnerId);
        }

        // Team Member: no discretion at all.
        return ['owner_id' => $actor->id, 'team_id' => $actor->team_id];
    }

    /**
     * Same as resolve(), but for entities where owner_id can never be
     * null in the database (Lead, Opportunity: "a lead/opportunity
     * belongs to one accountable owner at a time"). A Manager who
     * doesn't pick an owner becomes the owner themselves, rather than
     * leaving the record unassigned.
     *
     * @return array{owner_id: int, team_id: ?int}
     */
    public function resolveRequiringOwner(User $actor, ?int $requestedOwnerId, ?int $requestedTeamId): array
    {
        $assignment = $this->resolve($actor, $requestedOwnerId, $requestedTeamId);
        $assignment['owner_id'] ??= $actor->id;

        return $assignment;
    }

    /**
     * @return array{owner_id: ?int, team_id: ?int}
     */
    private function resolveForManager(?int $requestedOwnerId, ?int $requestedTeamId): array
    {
        if ($requestedTeamId !== null && ! Team::whereKey($requestedTeamId)->exists()) {
            throw ValidationException::withMessages(['team_id' => 'The selected team does not exist.']);
        }

        if ($requestedOwnerId !== null && ! User::whereKey($requestedOwnerId)->exists()) {
            throw ValidationException::withMessages(['owner_id' => 'The selected owner does not exist.']);
        }

        return ['owner_id' => $requestedOwnerId, 'team_id' => $requestedTeamId];
    }

    /**
     * @return array{owner_id: ?int, team_id: ?int}
     */
    private function resolveForTeamHead(User $actor, ?int $requestedOwnerId): array
    {
        $ownerId = $requestedOwnerId ?? $actor->id;

        if ($ownerId !== $actor->id) {
            $owner = User::find($ownerId);

            if (! $owner || $owner->team_id !== $actor->team_id || $owner->role === UserRole::Manager) {
                throw ValidationException::withMessages([
                    'owner_id' => 'You can only assign records to a member of your own team.',
                ]);
            }
        }

        return ['owner_id' => $ownerId, 'team_id' => $actor->team_id];
    }
}
