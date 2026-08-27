<?php

namespace App\Models;

use App\Enums\KnowledgeStatus;
use Database\Factories\KnowledgeDocumentVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One immutable revision of a document's content — see the migration's
 * docblock. `raw_content` is never edited after creation; a correction
 * always means a new row (KnowledgeDocumentService::createNewVersion).
 */
class KnowledgeDocumentVersion extends Model
{
    /** @use HasFactory<KnowledgeDocumentVersionFactory> */
    use HasFactory;

    /**
     * Deliberately excludes `status`, `version_number`, `checksum`, and
     * `uploaded_by`: these are always derived/set by
     * KnowledgeDocumentService, never from request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'raw_content',
        'effective_from',
        'effective_until',
        'processing_error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => KnowledgeStatus::class,
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'knowledge_document_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class)->orderBy('section_order');
    }
}
