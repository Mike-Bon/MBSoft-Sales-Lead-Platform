<?php

namespace App\Policies\Concerns;

use App\Models\User;

/**
 * Shared team/ownership authorization rules for every CRM entity
 * (Organization, Contact, Lead, Opportunity, Activity). Kept as one
 * trait rather than duplicating the same five methods in five policy
 * classes.
 *
 * The rule, consistently applied everywhere:
 *   - Manager: unrestricted.
 *   - Team Head: full access to any record whose team_id matches their
 *     own team (view, create, update, delete/archive).
 *   - Team Member: can view any record in their team, but may only
 *     update/delete records they personally own — never another
 *     teammate's record, and never another team's record at all.
 *
 * A record with a null team_id (an organisation-wide/Manager-personal
 * record) is visible only to the Manager and to its own owner.
 */
trait AuthorizesCrmRecords
{
    /**
     * @param  object{team_id: ?int}  $record
     */
    protected function canView(User $user, object $record, string $ownerAttribute = 'owner_id'): bool
    {
        if ($user->isManager()) {
            return true;
        }

        if ($record->team_id !== null) {
            return $record->team_id === $user->team_id;
        }

        return $this->ownerId($record, $ownerAttribute) === $user->id;
    }

    /**
     * @param  object{team_id: ?int}  $record
     */
    protected function canManage(User $user, object $record, string $ownerAttribute = 'owner_id'): bool
    {
        if ($user->isManager()) {
            return true;
        }

        if ($record->team_id === null) {
            return $this->ownerId($record, $ownerAttribute) === $user->id;
        }

        if ($record->team_id !== $user->team_id) {
            return false;
        }

        if ($user->isTeamHead()) {
            return true;
        }

        // Team Member: only their own records within their own team.
        return $this->ownerId($record, $ownerAttribute) === $user->id;
    }

    /**
     * Delete/archive is intentionally more conservative than update:
     * Manager or the Team Head of the record's team only. A Team Member
     * can edit their own leads but not remove CRM records.
     *
     * @param  object{team_id: ?int}  $record
     */
    protected function canDelete(User $user, object $record): bool
    {
        if ($user->isManager()) {
            return true;
        }

        return $user->isTeamHead() && $record->team_id === $user->team_id;
    }

    private function ownerId(object $record, string $attribute = 'owner_id'): ?int
    {
        return $record->{$attribute} ?? null;
    }
}
