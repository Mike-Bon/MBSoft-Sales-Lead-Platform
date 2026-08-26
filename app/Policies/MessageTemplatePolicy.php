<?php

namespace App\Policies;

use App\Models\MessageTemplate;
use App\Models\User;

/**
 * STEP 17/19: any authenticated user may create a template (scoped to
 * their own team, or organisation-wide only if they are a Manager — see
 * StoreMessageTemplateRequest, which derives team_id server-side, never
 * from request input); editing/removing one is restricted to its
 * creator, the Team Head of its team, or a Manager — the same
 * "own work or your team's leadership" shape as the rest of the CRM
 * (App\Policies\Concerns\AuthorizesCrmRecords), even though templates
 * aren't a CRM record and don't use that trait directly.
 */
class MessageTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, MessageTemplate $template): bool
    {
        return $user->isManager() || $template->team_id === null || $template->team_id === $user->team_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, MessageTemplate $template): bool
    {
        if ($user->isManager() || $template->created_by === $user->id) {
            return true;
        }

        return $user->isTeamHead() && $template->team_id === $user->team_id;
    }

    public function delete(User $user, MessageTemplate $template): bool
    {
        return $this->update($user, $template);
    }
}
