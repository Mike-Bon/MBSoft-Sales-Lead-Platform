<?php

namespace App\Support\Ai;

use App\Enums\AgentIdentifier;
use App\Enums\WorkflowType;
use App\Services\Ai\ToolRegistry;

/**
 * STEP 5: the common agent contract — every specialized agent is
 * exactly one of these, holding its identifier, name, purpose, system
 * instructions, allowed tools (STEP 24's permission matrix, expressed
 * as this agent's own ToolRegistry instance), which Phase 8 workflow
 * types it may be used for, and its own execution limit. Adding a
 * future agent means constructing one more AgentDefinition and
 * registering it — App\Services\Ai\Agent (the engine), LlmProvider, and
 * every AgentTool stay untouched (STEP 59).
 */
final readonly class AgentDefinition
{
    /**
     * @param  list<WorkflowType>  $allowedWorkflows
     */
    public function __construct(
        public AgentIdentifier $identifier,
        public string $name,
        public string $purpose,
        public string $systemPrompt,
        public ToolRegistry $tools,
        public array $allowedWorkflows,
        public int $maxToolIterations,
    ) {}
}
