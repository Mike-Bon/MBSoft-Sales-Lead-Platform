<?php

namespace App\Policies;

use App\Models\Activity;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCrmRecords;

/**
 * No update/delete: activities are immutable facts once recorded (see
 * App\Models\Activity) and have no edit/delete route in this phase.
 */
class ActivityPolicy
{
    use AuthorizesCrmRecords;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Activity $activity): bool
    {
        return $this->canView($user, $activity, 'user_id');
    }

    public function create(User $user): bool
    {
        return true;
    }
}
