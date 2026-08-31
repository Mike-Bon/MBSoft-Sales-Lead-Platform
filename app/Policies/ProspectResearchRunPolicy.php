<?php

namespace App\Policies;

use App\Models\ProspectResearchRun;
use App\Models\User;

/**
 * V2.0.3: a Market Intelligence research run is visible only to the user
 * who initiated it — never to anyone else, not even a Manager. Same
 * least-privilege boundary as WorkflowExecutionPolicy. The run id in the
 * status URL is therefore not an authorization surface: ownership is
 * checked here on every read.
 */
class ProspectResearchRunPolicy
{
    public function view(User $user, ProspectResearchRun $run): bool
    {
        return $run->user_id === $user->id;
    }
}
