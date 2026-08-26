<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkflowApproval;

/**
 * STEP 20/44: only the user an approval belongs to may view, approve,
 * or reject it — mirrors WorkflowExecutionPolicy exactly.
 */
class WorkflowApprovalPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, WorkflowApproval $approval): bool
    {
        return $approval->user_id === $user->id;
    }

    public function update(User $user, WorkflowApproval $approval): bool
    {
        return $approval->user_id === $user->id;
    }
}
