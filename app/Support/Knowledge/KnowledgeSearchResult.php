<?php

namespace App\Support\Knowledge;

use App\Enums\KnowledgeSearchStatus;

/**
 * STEP 39/40: the structured outcome search_knowledge (and
 * KnowledgeSearchService directly) returns — one best-matching chunk
 * per distinct source document, never raw Eloquent models
 * (data minimization, matching every other AgentTool in this codebase).
 */
final readonly class KnowledgeSearchResult
{
    /**
     * @param  list<array{document_id: int, title: string, type: string, version: int, section: ?string, excerpt: string}>  $results
     */
    public function __construct(
        public KnowledgeSearchStatus $status,
        public array $results,
    ) {}

    public static function notFound(): self
    {
        return new self(KnowledgeSearchStatus::NotFound, []);
    }
}
