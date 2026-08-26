<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCrmRecords;

class OrganizationPolicy
{
    use AuthorizesCrmRecords;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Organization $organization): bool
    {
        return $this->canView($user, $organization);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Organization $organization): bool
    {
        return $this->canManage($user, $organization);
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $this->canDelete($user, $organization);
    }
}
