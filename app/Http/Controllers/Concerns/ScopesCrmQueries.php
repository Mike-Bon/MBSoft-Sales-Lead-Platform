<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Query-level scoping for CRM index pages — defense in depth alongside
 * (never a replacement for) the per-record Policy checks on show/edit/
 * update/delete. Mirrors App\Policies\Concerns\AuthorizesCrmRecords
 * exactly: a record is visible if it belongs to the viewer's own team,
 * or (for a team-less/organisation-wide record) if the viewer owns it.
 * Managers are never scoped.
 */
trait ScopesCrmQueries
{
    protected function scopeToUser(Builder $query, User $user, string $ownerColumn = 'owner_id'): Builder
    {
        if ($user->isManager()) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($user, $ownerColumn) {
            $query->where('team_id', $user->team_id)
                ->orWhere(function (Builder $query) use ($user, $ownerColumn) {
                    $query->whereNull('team_id')->where($ownerColumn, $user->id);
                });
        });
    }
}
