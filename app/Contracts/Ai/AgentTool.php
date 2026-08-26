<?php

namespace App\Contracts\Ai;

use App\Models\User;
use App\Support\Ai\ToolDefinition;

/**
 * STEP 7/25: one narrowly scoped capability the agent may call. Every
 * implementation MUST:
 *   1. Accept only the specific parameters it declares.
 *   2. Call an existing application service/policy/authorization check
 *      — never query the database directly, never bypass a Policy.
 *   3. Execute strictly within the given $actor's authorization —
 *      never trust an actor/team/scope value supplied in $arguments;
 *      re-derive the authorized scope from $actor every time, exactly
 *      like every controller in this application already does.
 *   4. Return only the fields needed to answer — never a raw Eloquent
 *      model, never an unrelated internal/audit field (STEP 22 data
 *      minimization).
 *
 * A denied/invalid request should throw (AuthorizationException,
 * ValidationException) — AssistantService catches these and turns them
 * into a safe tool_result the model can relay, never a raw stack trace.
 */
interface AgentTool
{
    public function definition(): ToolDefinition;

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(User $actor, array $arguments): array;
}
