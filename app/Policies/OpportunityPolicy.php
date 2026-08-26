<?php

namespace App\Policies;

use App\Models\Opportunity;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCrmRecords;

class OpportunityPolicy
{
    use AuthorizesCrmRecords;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Opportunity $opportunity): bool
    {
        return $this->canView($user, $opportunity);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Opportunity $opportunity): bool
    {
        return $this->canManage($user, $opportunity);
    }

    public function delete(User $user, Opportunity $opportunity): bool
    {
        return $this->canDelete($user, $opportunity);
    }
}
