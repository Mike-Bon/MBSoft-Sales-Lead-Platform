<?php

namespace App\Services\Ai;

use App\Enums\AgentIdentifier;
use App\Support\Ai\AgentDefinition;
use InvalidArgumentException;

/**
 * STEP 6: a lightweight, controlled way to discover the three approved
 * agents — not a plugin framework. Built once in AppServiceProvider from
 * a fixed list of AgentDefinition instances; nothing registers itself
 * at runtime, and nothing outside this class decides what counts as a
 * valid agent identifier.
 */
final class AgentRegistry
{
    /**
     * @var array<string, AgentDefinition>
     */
    private array $agents;

    /**
     * @param  list<AgentDefinition>  $agents
     */
    public function __construct(array $agents)
    {
        $this->agents = [];

        foreach ($agents as $agent) {
            $this->agents[$agent->identifier->value] = $agent;
        }
    }

    public function get(AgentIdentifier $identifier): AgentDefinition
    {
        return $this->agents[$identifier->value]
            ?? throw new InvalidArgumentException("Unknown agent: {$identifier->value}");
    }

    /**
     * @return list<AgentDefinition>
     */
    public function all(): array
    {
        return array_values($this->agents);
    }
}
