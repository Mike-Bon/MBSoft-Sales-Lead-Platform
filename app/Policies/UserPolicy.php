<?php

namespace App\Policies;

use App\Models\User;

/**
 * Authoritative server-side authorization for user/organisational-structure
 * management. Role and team assignment are Manager-only actions; a user's
 * own profile editing (name/email/password) is handled separately by
 * Phase 1's Livewire settings pages and does not go through this policy.
 */
class UserPolicy
{
    /**
     * Only the Manager may list all users.
     */
    public function viewAny(User $user): bool
    {
        return $user->isManager();
    }

    /**
     * The Manager may view any user. Anyone may view their own record.
     */
    public function view(User $user, User $target): bool
    {
        return $user->isManager() || $user->is($target);
    }

    /**
     * Only the Manager may create user accounts.
     */
    public function create(User $user): bool
    {
        return $user->isManager();
    }

    /**
     * Only the Manager may change another user's role or team. This is the
     * single gate that guarantees a Team Head can never promote themselves,
     * assign themselves to another team, or modify another team's
     * membership: they simply never pass this check.
     */
    public function update(User $user, User $target): bool
    {
        return $user->isManager();
    }
}
