<?php

namespace Tests\Feature\Knowledge;

use App\Enums\KnowledgeStatus;
use App\Jobs\Knowledge\ProcessKnowledgeDocumentVersionJob;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeDocumentVersion;
use App\Support\Knowledge\DocumentChunker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 10 STEP 32/34/35: the async ingestion pipeline — chunk, index,
 * activate, and retire whichever version it replaces. Idempotent
 * against a version that already left Processing.
 */
class ProcessKnowledgeDocumentVersionJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_chunks_the_content_and_activates_the_version(): void
    {
        $document = KnowledgeDocument::factory()->create();
        $version = KnowledgeDocumentVersion::factory()->create([
            'knowledge_document_id' => $document->id,
            'status' => KnowledgeStatus::Processing,
            'raw_content' => "# Heading One\n\nBody one.\n\n# Heading Two\n\nBody two.",
        ]);

        (new ProcessKnowledgeDocumentVersionJob($version->id))->handle(app(DocumentChunker::class));

        $version->refresh();
        $this->assertSame(KnowledgeStatus::Active, $version->status);
        $this->assertSame(2, $version->chunks()->count());
        $this->assertSame($version->id, $document->fresh()->current_version_id);
    }

    public function test_it_archives_the_previously_active_version_it_replaces(): void
    {
        $document = KnowledgeDocument::factory()->create();
        $oldVersion = KnowledgeDocumentVersion::factory()->create([
            'knowledge_document_id' => $document->id,
            'status' => KnowledgeStatus::Active,
            'version_number' => 1,
        ]);
        $document->current_version_id = $oldVersion->id;
        $document->save();

        $newVersion = KnowledgeDocumentVersion::factory()->create([
            'knowledge_document_id' => $document->id,
            'status' => KnowledgeStatus::Processing,
            'version_number' => 2,
            'raw_content' => 'Replacement content with enough words to chunk.',
        ]);

        (new ProcessKnowledgeDocumentVersionJob($newVersion->id))->handle(app(DocumentChunker::class));

        $this->assertSame(KnowledgeStatus::Archived, $oldVersion->fresh()->status);
        $this->assertSame(KnowledgeStatus::Active, $newVersion->fresh()->status);
        $this->assertSame($newVersion->id, $document->fresh()->current_version_id);
    }

    public function test_empty_content_after_chunking_marks_the_version_failed_never_active(): void
    {
        $version = KnowledgeDocumentVersion::factory()->create([
            'status' => KnowledgeStatus::Processing,
            'raw_content' => '   ',
        ]);

        (new ProcessKnowledgeDocumentVersionJob($version->id))->handle(app(DocumentChunker::class));

        $version->refresh();
        $this->assertSame(KnowledgeStatus::Failed, $version->status);
        $this->assertNotNull($version->processing_error);
        $this->assertSame(0, $version->chunks()->count());
    }

    public function test_it_is_a_no_op_for_a_version_that_already_left_processing(): void
    {
        $version = KnowledgeDocumentVersion::factory()->archived()->create();

        (new ProcessKnowledgeDocumentVersionJob($version->id))->handle(app(DocumentChunker::class));

        $this->assertSame(0, $version->chunks()->count());
        $this->assertSame(KnowledgeStatus::Archived, $version->fresh()->status);
    }

    public function test_it_is_a_no_op_for_a_version_that_no_longer_exists(): void
    {
        // Should not throw even though the id refers to nothing.
        (new ProcessKnowledgeDocumentVersionJob(999999))->handle(app(DocumentChunker::class));
        $this->assertTrue(true);
    }

    public function test_the_failed_handler_marks_the_version_failed_without_exposing_the_raw_exception(): void
    {
        $version = KnowledgeDocumentVersion::factory()->create(['status' => KnowledgeStatus::Processing]);

        (new ProcessKnowledgeDocumentVersionJob($version->id))->failed(new \RuntimeException('some internal detail with a secret path'));

        $version->refresh();
        $this->assertSame(KnowledgeStatus::Failed, $version->status);
        $this->assertNotNull($version->processing_error);
        $this->assertStringNotContainsString('secret path', (string) $version->processing_error);
    }
}
