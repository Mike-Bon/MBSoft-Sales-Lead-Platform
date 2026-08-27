<?php

namespace Tests\Feature\Ai\Tools;

use App\Enums\KnowledgeSearchStatus;
use App\Enums\KnowledgeStatus;
use App\Enums\KnowledgeType;
use App\Enums\KnowledgeVisibility;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeDocumentVersion;
use App\Models\User;
use App\Services\Ai\Tools\SearchKnowledgeTool;
use App\Services\Knowledge\KnowledgeSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 10 STEP 24/25: the tool contract itself — never trusts a query
 * outside what its own fixed $allowedTypes permits, never returns a raw
 * model.
 */
class SearchKnowledgeToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_definition_names_only_its_own_allowed_types(): void
    {
        $tool = new SearchKnowledgeTool(app(KnowledgeSearchService::class), [KnowledgeType::Policy, KnowledgeType::Training]);

        $definition = $tool->definition();

        $this->assertSame('search_knowledge', $definition->name);
        $this->assertStringContainsString('Policy', $definition->description);
        $this->assertStringContainsString('Training', $definition->description);
        $this->assertStringNotContainsString('Sales Playbook', $definition->description);
    }

    public function test_execute_returns_the_search_services_status_and_results(): void
    {
        $document = KnowledgeDocument::factory()->create(['visibility' => KnowledgeVisibility::Organisation, 'type' => KnowledgeType::Policy]);
        $version = KnowledgeDocumentVersion::factory()->create(['knowledge_document_id' => $document->id, 'status' => KnowledgeStatus::Active]);
        KnowledgeChunk::factory()->create(['knowledge_document_version_id' => $version->id, 'content' => 'Refunds within fourteen days.']);
        $document->current_version_id = $version->id;
        $document->save();

        $tool = new SearchKnowledgeTool(app(KnowledgeSearchService::class), [KnowledgeType::Policy]);
        $manager = User::factory()->manager()->create();

        $result = $tool->execute($manager, ['query' => 'refunds fourteen days']);

        $this->assertSame(KnowledgeSearchStatus::Found->value, $result['status']);
        $this->assertCount(1, $result['results']);
    }

    public function test_an_empty_query_throws(): void
    {
        $tool = new SearchKnowledgeTool(app(KnowledgeSearchService::class), [KnowledgeType::Policy]);
        $manager = User::factory()->manager()->create();

        $this->expectException(\InvalidArgumentException::class);

        $tool->execute($manager, ['query' => '   ']);
    }

    public function test_a_document_outside_the_tools_own_allowed_types_is_never_returned_even_though_the_actor_is_authorized(): void
    {
        $document = KnowledgeDocument::factory()->create(['visibility' => KnowledgeVisibility::Organisation, 'type' => KnowledgeType::SalesPlaybook]);
        $version = KnowledgeDocumentVersion::factory()->create(['knowledge_document_id' => $document->id, 'status' => KnowledgeStatus::Active]);
        KnowledgeChunk::factory()->create(['knowledge_document_version_id' => $version->id, 'content' => 'Qualify leads using BANT criteria.']);
        $document->current_version_id = $version->id;
        $document->save();

        // A Performance Agent instance — its allowed types never include
        // SalesPlaybook.
        $tool = new SearchKnowledgeTool(app(KnowledgeSearchService::class), [KnowledgeType::Policy, KnowledgeType::Training]);
        $manager = User::factory()->manager()->create();

        $result = $tool->execute($manager, ['query' => 'qualify leads BANT']);

        $this->assertSame(KnowledgeSearchStatus::NotFound->value, $result['status']);
    }
}
