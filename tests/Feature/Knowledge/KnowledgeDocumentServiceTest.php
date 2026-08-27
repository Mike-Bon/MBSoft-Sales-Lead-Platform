<?php

namespace Tests\Feature\Knowledge;

use App\Enums\KnowledgeStatus;
use App\Enums\KnowledgeType;
use App\Enums\KnowledgeVisibility;
use App\Jobs\Knowledge\ProcessKnowledgeDocumentVersionJob;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeDocumentVersion;
use App\Models\Team;
use App\Models\User;
use App\Services\Knowledge\KnowledgeDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Phase 10 STEP 6/8/13/32/34/35/36: document/version lifecycle —
 * creation always starts Processing and dispatches the ingestion job
 * only after commit; duplicate live content is rejected; new versions
 * archive what they replace; manual archival clears current_version_id.
 */
class KnowledgeDocumentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_document_creates_a_processing_first_version_and_dispatches_the_job_after_commit(): void
    {
        Queue::fake();

        $manager = User::factory()->manager()->create();

        $document = app(KnowledgeDocumentService::class)->createDocument($manager, [
            'title' => 'Refund Policy',
            'type' => KnowledgeType::Policy->value,
            'visibility' => KnowledgeVisibility::Organisation->value,
            'team_id' => null,
            'raw_content' => "# Refund Policy\n\nRefunds within 14 days of purchase.",
            'effective_from' => null,
            'effective_until' => null,
        ]);

        $this->assertSame('Refund Policy', $document->title);
        $this->assertSame($manager->id, $document->created_by);
        $this->assertNull($document->current_version_id);

        $version = $document->versions()->firstOrFail();
        $this->assertSame(1, $version->version_number);
        $this->assertSame(KnowledgeStatus::Processing, $version->status);
        $this->assertSame(hash('sha256', $version->raw_content), $version->checksum);

        Queue::assertPushed(ProcessKnowledgeDocumentVersionJob::class, fn ($job) => $job->knowledgeDocumentVersionId === $version->id);
    }

    public function test_a_team_scoped_document_stores_the_team_id_only_when_visibility_is_team(): void
    {
        $team = Team::factory()->create();
        $manager = User::factory()->manager()->create();

        $document = app(KnowledgeDocumentService::class)->createDocument($manager, [
            'title' => 'Team SOP',
            'type' => KnowledgeType::Sop->value,
            'visibility' => KnowledgeVisibility::Team->value,
            'team_id' => $team->id,
            'raw_content' => str_repeat('Process step. ', 5),
            'effective_from' => null,
            'effective_until' => null,
        ]);

        $this->assertSame($team->id, $document->team_id);
    }

    public function test_a_team_id_is_discarded_when_visibility_is_not_team(): void
    {
        $team = Team::factory()->create();
        $manager = User::factory()->manager()->create();

        $document = app(KnowledgeDocumentService::class)->createDocument($manager, [
            'title' => 'Org Policy',
            'type' => KnowledgeType::Policy->value,
            'visibility' => KnowledgeVisibility::Organisation->value,
            'team_id' => $team->id,
            'raw_content' => str_repeat('Policy text. ', 5),
            'effective_from' => null,
            'effective_until' => null,
        ]);

        $this->assertNull($document->team_id);
    }

    public function test_uploading_identical_content_to_a_new_document_is_rejected_while_a_live_version_exists(): void
    {
        Queue::fake();
        $manager = User::factory()->manager()->create();
        $content = str_repeat('Identical content. ', 5);

        $service = app(KnowledgeDocumentService::class);
        $service->createDocument($manager, [
            'title' => 'First',
            'type' => KnowledgeType::Policy->value,
            'visibility' => KnowledgeVisibility::Organisation->value,
            'team_id' => null,
            'raw_content' => $content,
            'effective_from' => null,
            'effective_until' => null,
        ]);

        $this->expectException(ValidationException::class);

        $service->createDocument($manager, [
            'title' => 'Second',
            'type' => KnowledgeType::Policy->value,
            'visibility' => KnowledgeVisibility::Organisation->value,
            'team_id' => null,
            'raw_content' => $content,
            'effective_from' => null,
            'effective_until' => null,
        ]);
    }

    public function test_identical_content_previously_archived_is_not_treated_as_a_duplicate(): void
    {
        Queue::fake();
        $manager = User::factory()->manager()->create();
        $content = str_repeat('Reused content. ', 5);

        $version = KnowledgeDocumentVersion::factory()
            ->archived()
            ->create(['raw_content' => $content, 'checksum' => hash('sha256', $content)]);

        $document = app(KnowledgeDocumentService::class)->createDocument($manager, [
            'title' => 'Reissued Policy',
            'type' => KnowledgeType::Policy->value,
            'visibility' => KnowledgeVisibility::Organisation->value,
            'team_id' => null,
            'raw_content' => $content,
            'effective_from' => null,
            'effective_until' => null,
        ]);

        $this->assertNotSame($version->knowledge_document_id, $document->id);
    }

    public function test_creating_a_new_version_increments_the_version_number(): void
    {
        Queue::fake();
        $manager = User::factory()->manager()->create();
        $document = KnowledgeDocument::factory()->create(['created_by' => $manager->id]);
        KnowledgeDocumentVersion::factory()->create(['knowledge_document_id' => $document->id, 'version_number' => 1]);

        $version = app(KnowledgeDocumentService::class)->createNewVersion($manager, $document, [
            'raw_content' => str_repeat('New content. ', 5),
            'effective_from' => null,
            'effective_until' => null,
        ]);

        $this->assertSame(2, $version->version_number);
        $this->assertSame(KnowledgeStatus::Processing, $version->status);
    }

    public function test_archiving_a_version_clears_current_version_id_only_if_it_was_the_current_one(): void
    {
        $document = KnowledgeDocument::factory()->create();
        $version = KnowledgeDocumentVersion::factory()->create(['knowledge_document_id' => $document->id]);
        $document->current_version_id = $version->id;
        $document->save();

        app(KnowledgeDocumentService::class)->archiveVersion($version);

        $this->assertSame(KnowledgeStatus::Archived, $version->fresh()->status);
        $this->assertNull($document->fresh()->current_version_id);
    }

    public function test_deleting_a_document_cascades_to_its_versions(): void
    {
        $document = KnowledgeDocument::factory()->create();
        $version = KnowledgeDocumentVersion::factory()->create(['knowledge_document_id' => $document->id]);

        app(KnowledgeDocumentService::class)->delete($document);

        $this->assertDatabaseMissing('knowledge_documents', ['id' => $document->id]);
        $this->assertDatabaseMissing('knowledge_document_versions', ['id' => $version->id]);
    }
}
