<?php

namespace App\Services\Ai\Tools;

use App\Contracts\Ai\AgentTool;
use App\Enums\KnowledgeType;
use App\Models\User;
use App\Services\Knowledge\KnowledgeSearchService;
use App\Support\Ai\ToolDefinition;

/**
 * Phase 10 STEP 24/25: the one tool that lets an agent consult company
 * knowledge (policies/SOPs/playbooks) instead of guessing. Each of the
 * three agents is wired (AppServiceProvider) with its OWN instance of
 * this class, constructed with its own fixed $allowedTypes — the model
 * never gets to name a type outside that list, and this tool never
 * accepts one as an argument at all, so there is nothing here for a
 * prompt-injected instruction to widen.
 *
 * Authorization is unchanged from every other tool in this codebase:
 * KnowledgeSearchService re-derives the actor's own visibility scope
 * from $actor every call, never from anything in $arguments.
 */
class SearchKnowledgeTool implements AgentTool
{
    /**
     * @param  list<KnowledgeType>  $allowedTypes
     */
    public function __construct(
        private readonly KnowledgeSearchService $search,
        private readonly array $allowedTypes,
    ) {}

    public function definition(): ToolDefinition
    {
        $typeLabels = implode(', ', array_map(fn (KnowledgeType $type) => $type->label(), $this->allowedTypes));

        return new ToolDefinition(
            name: 'search_knowledge',
            description: "Search authorized company knowledge (policies, SOPs, and playbooks) for information relevant to the user's question. This agent may only search: {$typeLabels}. Returns cited excerpts from active documents only, never full document text. Use this whenever the user asks about a company policy or procedure — never answer such a question from general assumptions instead.",
            parameters: [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'The topic or question to search company knowledge for.'],
                ],
                'required' => ['query'],
            ],
        );
    }

    public function execute(User $actor, array $arguments): array
    {
        $query = trim((string) ($arguments['query'] ?? ''));

        if ($query === '') {
            throw new \InvalidArgumentException('A search query is required.');
        }

        $result = $this->search->search($actor, $query, $this->allowedTypes);

        return [
            'status' => $result->status->value,
            'results' => $result->results,
        ];
    }
}
