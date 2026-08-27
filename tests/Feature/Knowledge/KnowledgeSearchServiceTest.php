<?php

namespace Tests\Feature\Knowledge;

use App\Enums\KnowledgeSearchStatus;
use App\Enums\KnowledgeStatus;
use App\Enums\KnowledgeType;
use App\Enums\KnowledgeVisibility;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeDocumentVersion;
use App\Models\Team;
use App\Models\User;
use App\Services\Knowledge\KnowledgeSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 10 STEP 20-23/29/39: authorization-filtered full-text search —
 * the single most important test file for STEP 20's rule ("never treat
 * the search index as an authorization boundary"). Every test proves
 * eligibility is decided BEFORE ranking, never as a post-filter.
 */
class KnowledgeSearchServiceTest extends TestCase
{
    use RefreshDatabase;

    private function activeDocument(KnowledgeVisibility $visibility, KnowledgeType $type, string $content, ?Team $team = null, ?string $heading = 'Refund Policy'): KnowledgeDocument
    {
        $document = KnowledgeDocument::factory()->create([
            'visibility' => $visibility,
            'type' => $type,
            'team_id' => $team?->id,
        ]);
        $version = KnowledgeDocumentVersion::factory()->create([
            'knowledge_document_id' => $document->id,
            'status' => KnowledgeStatus::Active,
        ]);
        KnowledgeChunk::factory()->create([
            'knowledge_document_version_id' => $version->id,
            'heading' => $heading,
            'content' => $content,
        ]);
        $document->current_version_id = $version->id;
        $document->save();

        return $document;
    }

    public function test_a_manager_can_find_an_organisation_wide_document(): void
    {
        $manager = User::factory()->manager()->create();
        $this->activeDocument(KnowledgeVisibility::Organisation, KnowledgeType::Policy, 'Refunds are processed within fourteen days.');

        $result = app(KnowledgeSearchService::class)->search($manager, 'refunds fourteen days', [KnowledgeType::Policy]);

        $this->assertSame(KnowledgeSearchStatus::Found, $result->status);
        $this->assertCount(1, $result->results);
    }

    public function test_a_manager_can_find_a_manager_only_document(): void
    {
        $manager = User::factory()->manager()->create();
        $this->activeDocument(KnowledgeVisibility::Manager, KnowledgeType::Policy, 'Compensation bands are reviewed annually.');

        $result = app(KnowledgeSearchService::class)->search($manager, 'compensation bands', [KnowledgeType::Policy]);

        $this->assertSame(KnowledgeSearchStatus::Found, $result->status);
    }

    public function test_a_team_head_cannot_find_a_manager_only_document(): void
    {
        $teamHead = User::factory()->teamHead()->create();
        $this->activeDocument(KnowledgeVisibility::Manager, KnowledgeType::Policy, 'Compensation bands are reviewed annually.');

        $result = app(KnowledgeSearchService::class)->search($teamHead, 'compensation bands', [KnowledgeType::Policy]);

        $this->assertSame(KnowledgeSearchStatus::NotFound, $result->status);
    }

    public function test_a_team_head_can_find_their_own_teams_document_but_not_another_teams(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $head = User::factory()->teamHead($teamA)->create();

        $this->activeDocument(KnowledgeVisibility::Team, KnowledgeType::Sop, 'Escalate stalled deals after seven days.', $teamA, heading: 'Team A SOP');
        $this->activeDocument(KnowledgeVisibility::Team, KnowledgeType::Sop, 'Escalate stalled deals after seven days.', $teamB, heading: 'Team B SOP');

        $result = app(KnowledgeSearchService::class)->search($head, 'escalate stalled deals', [KnowledgeType::Sop]);

        $this->assertSame(KnowledgeSearchStatus::Found, $result->status);
        $this->assertCount(1, $result->results);
        $this->assertSame('Team A SOP', $result->results[0]['section']);
    }

    public function test_a_document_of_a_type_the_agent_is_not_allowed_to_search_is_excluded(): void
    {
        $manager = User::factory()->manager()->create();
        $this->activeDocument(KnowledgeVisibility::Organisation, KnowledgeType::Training, 'Onboarding takes place over two weeks.');

        // Caller only permits Policy — Training is authorized-visibility
        // but out of this agent's own scope, and must still be excluded.
        $result = app(KnowledgeSearchService::class)->search($manager, 'onboarding two weeks', [KnowledgeType::Policy]);

        $this->assertSame(KnowledgeSearchStatus::NotFound, $result->status);
    }

    public function test_only_active_versions_are_ever_searched(): void
    {
        $manager = User::factory()->manager()->create();
        $document = KnowledgeDocument::factory()->create(['visibility' => KnowledgeVisibility::Organisation, 'type' => KnowledgeType::Policy]);

        foreach ([KnowledgeStatus::Draft, KnowledgeStatus::Processing, KnowledgeStatus::Archived, KnowledgeStatus::Failed] as $index => $status) {
            $version = KnowledgeDocumentVersion::factory()->create(['knowledge_document_id' => $document->id, 'version_number' => $index + 1, 'status' => $status]);
            KnowledgeChunk::factory()->create(['knowledge_document_version_id' => $version->id, 'content' => 'A unique searchable phrase about vacation policy.']);
        }

        $result = app(KnowledgeSearchService::class)->search($manager, 'unique searchable phrase vacation', [KnowledgeType::Policy]);

        $this->assertSame(KnowledgeSearchStatus::NotFound, $result->status);
    }

    public function test_two_distinct_active_documents_of_the_same_type_produce_a_conflicting_status(): void
    {
        $manager = User::factory()->manager()->create();
        $this->activeDocument(KnowledgeVisibility::Organisation, KnowledgeType::Policy, 'Returns accepted within thirty days.', heading: 'Old Return Policy');
        $this->activeDocument(KnowledgeVisibility::Organisation, KnowledgeType::Policy, 'Returns accepted within sixty days.', heading: 'New Return Policy');

        $result = app(KnowledgeSearchService::class)->search($manager, 'returns accepted days', [KnowledgeType::Policy]);

        $this->assertSame(KnowledgeSearchStatus::Conflicting, $result->status);
        $this->assertCount(2, $result->results);
    }

    public function test_no_match_returns_not_found_with_no_results(): void
    {
        $manager = User::factory()->manager()->create();
        $this->activeDocument(KnowledgeVisibility::Organisation, KnowledgeType::Policy, 'Refunds are processed within fourteen days.');

        $result = app(KnowledgeSearchService::class)->search($manager, 'something totally unrelated', [KnowledgeType::Policy]);

        $this->assertSame(KnowledgeSearchStatus::NotFound, $result->status);
        $this->assertSame([], $result->results);
    }

    public function test_an_empty_query_returns_not_found_without_throwing(): void
    {
        $manager = User::factory()->manager()->create();

        $result = app(KnowledgeSearchService::class)->search($manager, '  ', [KnowledgeType::Policy]);

        $this->assertSame(KnowledgeSearchStatus::NotFound, $result->status);
    }

    public function test_an_empty_allowed_types_list_returns_not_found_without_throwing(): void
    {
        $manager = User::factory()->manager()->create();
        $this->activeDocument(KnowledgeVisibility::Organisation, KnowledgeType::Policy, 'Refunds are processed within fourteen days.');

        $result = app(KnowledgeSearchService::class)->search($manager, 'refunds', []);

        $this->assertSame(KnowledgeSearchStatus::NotFound, $result->status);
    }

    public function test_results_never_include_a_raw_model_only_curated_fields(): void
    {
        $manager = User::factory()->manager()->create();
        $this->activeDocument(KnowledgeVisibility::Organisation, KnowledgeType::Policy, 'Refunds are processed within fourteen days.');

        $result = app(KnowledgeSearchService::class)->search($manager, 'refunds fourteen days', [KnowledgeType::Policy]);

        $this->assertSame(['document_id', 'title', 'type', 'version', 'section', 'excerpt'], array_keys($result->results[0]));
    }
}
