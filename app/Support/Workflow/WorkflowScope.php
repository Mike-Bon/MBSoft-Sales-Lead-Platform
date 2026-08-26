<?php

namespace App\Support\Workflow;

use App\Enums\WorkflowScopeType;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * STEP 23/24: the explicit, server-derived authorization context one
 * workflow execution runs under — never implicit, never elevated.
 * `subject` is whose permitted data this is and whose approval
 * queue/dashboard will show the result; `type`/`team` describe exactly
 * how wide that permitted data is, always derived from the subject's
 * own stored role/team, never from any request input (there is no HTTP
 * request in a scheduled run at all).
 */
final readonly class WorkflowScope
{
    private function __construct(
        public User $subject,
        public WorkflowScopeType $type,
        public ?Team $team,
    ) {}

    public static function forUser(User $user): self
    {
        if ($user->isManager()) {
            return new self($user, WorkflowScopeType::Organisation, null);
        }

        if ($user->isTeamHead() && $user->team_id !== null) {
            return new self($user, WorkflowScopeType::Team, Team::find($user->team_id));
        }

        return new self($user, WorkflowScopeType::Individual, null);
    }

    /**
     * A stable, date-scoped key used for idempotency — the same
     * (workflow, scope, day) can only ever produce one execution.
     */
    public function executionKey(string $workflowValue, ?Carbon $date = null): string
    {
        $date ??= Carbon::now();
        $scopeId = match ($this->type) {
            WorkflowScopeType::Organisation => 'org',
            WorkflowScopeType::Team => 'team-'.$this->team?->id,
            WorkflowScopeType::Individual => 'user-'.$this->subject->id,
        };

        return "{$workflowValue}:{$scopeId}:{$date->toDateString()}";
    }
}
