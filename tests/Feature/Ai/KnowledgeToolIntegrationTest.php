<?php

namespace Tests\Feature\Ai;

use App\Contracts\Ai\LlmProvider;
use App\Enums\AgentIdentifier;
use App\Enums\KnowledgeStatus;
use App\Enums\KnowledgeType;
use App\Enums\KnowledgeVisibility;
use App\Models\AgentInteraction;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeDocumentVersion;
use App\Models\User;
use App\Services\Ai\AssistantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeLlmProvider;
use Tests\TestCase;

/**
 * Phase 10 STEP 24/26/27: an end-to-end round trip through the real
 * Agent engine + a real AgentDefinition from AppServiceProvider,
 * proving search_knowledge is actually wired in (not just present in
 * unit tests) and that its use is captured in the ordinary
 * AgentInteraction audit trail, exactly like every other tool call.
 */
class KnowledgeToolIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function activeDocument(KnowledgeType $type, string $content): KnowledgeDocument
    {
        $document = KnowledgeDocument::factory()->create(['visibility' => KnowledgeVisibility::Organisation, 'type' => $type]);
        $version = KnowledgeDocumentVersion::factory()->create(['knowledge_document_id' => $document->id, 'status' => KnowledgeStatus::Active]);
        KnowledgeChunk::factory()->create(['knowledge_document_version_id' => $version->id, 'content' => $content]);
        $document->current_version_id = $version->id;
        $document->save();

        return $document;
    }

    public function test_the_sales_agent_can_retrieve_a_sales_playbook_via_search_knowledge_and_it_is_audited(): void
    {
        $this->activeDocument(KnowledgeType::SalesPlaybook, 'Qualify every lead using the BANT framework before proposing a demo.');

        $this->app->instance(LlmProvider::class, new FakeLlmProvider([
            FakeLlmProvider::toolCall('search_knowledge', ['query' => 'qualify leads BANT']),
            FakeLlmProvider::text('Per the Sales Playbook, qualify leads using BANT before a demo.'),
        ]));

        $manager = User::factory()->manager()->create();
        $response = app(AssistantService::class)->respond(AgentIdentifier::Sales, $manager, 'How should I qualify a new lead?');

        $this->assertStringContainsString('BANT', $response->text);

        $interaction = AgentInteraction::firstOrFail();
        $this->assertSame('sales', $interaction->agent);
        $this->assertSame('search_knowledge', $interaction->tool_calls[0]['name']);
    }

    public function test_the_performance_agent_cannot_retrieve_a_sales_playbook_document_even_though_it_is_organisation_wide(): void
    {
        $this->activeDocument(KnowledgeType::SalesPlaybook, 'Qualify every lead using the BANT framework before proposing a demo.');

        $this->app->instance(LlmProvider::class, new FakeLlmProvider([
            FakeLlmProvider::toolCall('search_knowledge', ['query' => 'qualify leads BANT']),
            FakeLlmProvider::text('I could not find that in the available company knowledge.'),
        ]));

        $manager = User::factory()->manager()->create();
        app(AssistantService::class)->respond(AgentIdentifier::Performance, $manager, 'How should I qualify a new lead?');

        // The tool call happened (audited), but its result must have
        // been not_found — the Performance Agent's own SearchKnowledgeTool
        // instance was never given KnowledgeType::SalesPlaybook.
        $sentToolResult = collect(json_decode($this->lastToolResultJson(), true));
        $this->assertSame('not_found', $sentToolResult->get('status'));
    }

    /**
     * Reaches into the fake provider's recorded second call to read the
     * tool_result message the Agent engine built from the tool's actual
     * output — the most direct way to assert on what the tool returned
     * without re-implementing KnowledgeSearchService's own logic here.
     */
    private function lastToolResultJson(): string
    {
        $provider = app(LlmProvider::class);
        $secondCallMessages = $provider->calls[1]['messages'];
        $toolResultMessage = collect($secondCallMessages)->firstWhere('role', 'tool_result');

        return $toolResultMessage['content'];
    }
}
