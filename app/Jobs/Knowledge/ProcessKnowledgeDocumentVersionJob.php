<?php

namespace App\Jobs\Knowledge;

use App\Enums\KnowledgeStatus;
use App\Models\KnowledgeDocumentVersion;
use App\Support\Knowledge\DocumentChunker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 10 STEP 6/32/34: extract (already-plain text/Markdown — no
 * macro/script execution is ever possible, STEP 30) → chunk → index →
 * Active. Runs off the request cycle (STEP 32); a Draft/Processing
 * version is invisible to every agent until this job succeeds.
 *
 * Idempotent: re-running against a version that has already left
 * Processing (Active/Archived/Failed) is a safe no-op, matching every
 * other queued job in this codebase.
 */
class ProcessKnowledgeDocumentVersionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 60];

    public function __construct(public readonly int $knowledgeDocumentVersionId) {}

    public function handle(DocumentChunker $chunker): void
    {
        $version = KnowledgeDocumentVersion::find($this->knowledgeDocumentVersionId);

        if (! $version || $version->status !== KnowledgeStatus::Processing) {
            return;
        }

        $chunks = $chunker->chunk($version->raw_content);

        if ($chunks === []) {
            $version->status = KnowledgeStatus::Failed;
            $version->processing_error = 'No extractable content after chunking.';
            $version->save();

            return;
        }

        DB::transaction(function () use ($version, $chunks) {
            foreach ($chunks as $index => $chunk) {
                $version->chunks()->create([
                    'heading' => $chunk['heading'],
                    'section_order' => $index,
                    'content' => $chunk['content'],
                ]);
            }

            $version->status = KnowledgeStatus::Active;
            $version->save();

            $document = $version->document;
            $previousVersionId = $document->current_version_id;
            $document->current_version_id = $version->id;
            $document->save();

            // STEP 35: replacing a version retires the one it supersedes
            // — never two Active versions of the same document at once.
            if ($previousVersionId !== null && $previousVersionId !== $version->id) {
                KnowledgeDocumentVersion::where('id', $previousVersionId)
                    ->where('status', KnowledgeStatus::Active->value)
                    ->update(['status' => KnowledgeStatus::Archived->value]);
            }
        });
    }

    /**
     * STEP 33: a version must never be left stuck at Processing forever
     * — an exhausted retry is recorded as Failed. The raw exception is
     * logged server-side only (matching SendCommunicationJob's own
     * failed() convention); `processing_error`, which the knowledge
     * admin UI displays to whichever users can view the document, only
     * ever gets a generic, safe message — never internal exception
     * text, which could otherwise leak implementation detail to a
     * non-Manager Team Head/Member.
     */
    public function failed(?Throwable $exception): void
    {
        $version = KnowledgeDocumentVersion::find($this->knowledgeDocumentVersionId);

        if (! $version || $version->status !== KnowledgeStatus::Processing) {
            return;
        }

        Log::error('ProcessKnowledgeDocumentVersionJob exhausted retries', [
            'knowledge_document_version_id' => $this->knowledgeDocumentVersionId,
            'exception' => $exception?->getMessage(),
        ]);

        $version->status = KnowledgeStatus::Failed;
        $version->processing_error = 'This document could not be processed after multiple attempts.';
        $version->save();
    }
}
