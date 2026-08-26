<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkflowExecution;

/**
 * STEP 29: a workflow execution belongs to exactly the user whose scope
 * it ran under — never visible to anyone else, not even a Manager
 * browsing a Team Head's individual insights, keeping this as narrow as
 * every other least-privilege boundary in this application.
 */
class WorkflowExecutionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, WorkflowExecution $execution): bool
    {
        return $execution->user_id === $user->id;
    }
}
