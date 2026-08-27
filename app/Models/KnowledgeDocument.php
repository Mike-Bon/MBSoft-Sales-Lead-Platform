<?php

namespace App\Models;

use App\Enums\KnowledgeType;
use App\Enums\KnowledgeVisibility;
use Database\Factories\KnowledgeDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A logical knowledge document (a policy, SOP, playbook, etc.) — see
 * the migration's docblock for why content lives on
 * KnowledgeDocumentVersion instead of here. `current_version_id` names
 * which version is presently Active; `null` means the document has
 * never successfully finished processing (STEP 9).
 */
class KnowledgeDocument extends Model
{
    /** @use HasFactory<KnowledgeDocumentFactory> */
    use HasFactory;

    /**
     * Deliberately excludes `created_by`, `team_id`, and
     * `current_version_id`: authorship, team scope, and which version is
     * "current" are always set explicitly by KnowledgeDocumentService,
     * never from request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'type',
        'visibility',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => KnowledgeType::class,
            'visibility' => KnowledgeVisibility::class,
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocumentVersion::class, 'current_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(KnowledgeDocumentVersion::class)->orderByDesc('version_number');
    }
}
