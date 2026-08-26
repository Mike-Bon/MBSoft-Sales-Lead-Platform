<?php

namespace App\Services\Ai;

use App\Contracts\Ai\AgentTool;
use App\Support\Ai\ToolDefinition;

/**
 * The set of tools available to one agent. Deliberately takes its tool
 * list as a constructor argument rather than auto-discovering every
 * AgentTool in the application — a future second agent (not built in
 * Phase 7 — see CLAUDE.md's "no multi-agent architecture") would get its
 * own ToolRegistry instance with a different, independently-scoped tool
 * list, without this class, App\Services\Ai\Agent, LlmProvider, or any
 * AgentTool implementation ever needing to change.
 */
final class ToolRegistry
{
    /**
     * @var array<string, AgentTool>
     */
    private array $tools;

    /**
     * @param  list<AgentTool>  $tools
     */
    public function __construct(array $tools)
    {
        $this->tools = [];

        foreach ($tools as $tool) {
            $this->tools[$tool->definition()->name] = $tool;
        }
    }

    public function find(string $name): ?AgentTool
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return list<ToolDefinition>
     */
    public function definitions(): array
    {
        return array_values(array_map(fn (AgentTool $tool) => $tool->definition(), $this->tools));
    }
}
