<?php

namespace App\Services\Knowledge;

use App\Enums\KnowledgeStatus;
use App\Enums\KnowledgeVisibility;
use App\Jobs\Knowledge\ProcessKnowledgeDocumentVersionJob;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeDocumentVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 10 STEP 6/34/35: document/version lifecycle. Every write here
 * — never a controller — decides the trusted fields (created_by,
 * uploaded_by, team_id, status, version_number, checksum); a Form
 * Request only ever validates shape, matching every other service in
 * this codebase.
 *
 * Ingestion is asynchronous (STEP 32): creating a document/version only
 * ever leaves it at status=Processing and dispatches
 * ProcessKnowledgeDocumentVersionJob after the transaction commits — it
 * becomes Active only once that job actually chunks and indexes it.
 */
class KnowledgeDocumentService
{
    /**
     * @param  array{title: string, type: string, visibility: string, team_id: ?int, raw_content: string, effective_from: ?string, effective_until: ?string}  $data
     */
    public function createDocument(User $actor, array $data): KnowledgeDocument
    {
        $this->assertNoLiveDuplicate($data['raw_content']);

        return DB::transaction(function () use ($actor, $data) {
            $document = new KnowledgeDocument([
                'title' => $data['title'],
                'type' => $data['type'],
                'visibility' => $data['visibility'],
            ]);
            $document->created_by = $actor->id;
            $document->team_id = $data['visibility'] === KnowledgeVisibility::Team->value ? ($data['team_id'] ?? null) : null;
            $document->save();

            $version = $this->makeVersion($document, $actor, $data, versionNumber: 1);

            ProcessKnowledgeDocumentVersionJob::dispatch($version->id)->afterCommit();

            return $document;
        });
    }

    /**
     * @param  array{raw_content: string, effective_from: ?string, effective_until: ?string}  $data
     */
    public function createNewVersion(User $actor, KnowledgeDocument $document, array $data): KnowledgeDocumentVersion
    {
        $this->assertNoLiveDuplicate($data['raw_content']);

        return DB::transaction(function () use ($actor, $document, $data) {
            $nextVersionNumber = ((int) $document->versions()->max('version_number')) + 1;
            $version = $this->makeVersion($document, $actor, $data, versionNumber: $nextVersionNumber);

            ProcessKnowledgeDocumentVersionJob::dispatch($version->id)->afterCommit();

            return $version;
        });
    }

    /**
     * STEP 36: manual archival — takes a document out of retrieval
     * without deleting its history.
     */
    public function archiveVersion(KnowledgeDocumentVersion $version): void
    {
        $version->status = KnowledgeStatus::Archived;
        $version->save();

        $document = $version->document;

        if ($document->current_version_id === $version->id) {
            $document->current_version_id = null;
            $document->save();
        }
    }

    /**
     * STEP 36: full deletion — versions and chunks cascade via the
     * foreign key, so this never leaves an orphaned chunk searchable.
     */
    public function delete(KnowledgeDocument $document): void
    {
        $document->delete();
    }

    /**
     * @param  array{raw_content: string, effective_from?: ?string, effective_until?: ?string}  $data
     */
    private function makeVersion(KnowledgeDocument $document, User $actor, array $data, int $versionNumber): KnowledgeDocumentVersion
    {
        $rawContent = $data['raw_content'];

        $version = new KnowledgeDocumentVersion([
            'raw_content' => $rawContent,
            'effective_from' => $data['effective_from'] ?? null,
            'effective_until' => $data['effective_until'] ?? null,
        ]);
        $version->knowledge_document_id = $document->id;
        $version->version_number = $versionNumber;
        $version->status = KnowledgeStatus::Processing;
        $version->checksum = hash('sha256', $rawContent);
        $version->uploaded_by = $actor->id;
        $version->save();

        return $version;
    }

    /**
     * STEP 13: reject an upload whose content is byte-identical to a
     * currently live (Active or still-Processing) version anywhere —
     * not a database constraint, because a deliberate later reversion to
     * previously-Archived content is legitimate and must not be
     * permanently blocked by the schema.
     *
     * @throws ValidationException
     */
    private function assertNoLiveDuplicate(string $rawContent): void
    {
        $checksum = hash('sha256', $rawContent);

        $duplicate = KnowledgeDocumentVersion::query()
            ->where('checksum', $checksum)
            ->whereIn('status', [KnowledgeStatus::Active->value, KnowledgeStatus::Processing->value])
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'raw_content' => 'This content is identical to an existing active knowledge document — no new version was created.',
            ]);
        }
    }
}
