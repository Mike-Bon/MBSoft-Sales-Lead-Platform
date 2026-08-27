<?php

namespace App\Models;

use Database\Factories\KnowledgeChunkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One section-sized piece of a version's content, produced by
 * DocumentChunker. `search_vector` (a Postgres generated tsvector
 * column, added outside the schema builder — see the RLS migration) is
 * intentionally not modeled here as an attribute: application code
 * never reads or writes it directly, only KnowledgeSearchService's raw
 * `@@`/`ts_rank` queries reference it.
 */
class KnowledgeChunk extends Model
{
    /** @use HasFactory<KnowledgeChunkFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'heading',
        'section_order',
        'content',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocumentVersion::class, 'knowledge_document_version_id');
    }
}
