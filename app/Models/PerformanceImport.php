<?php

namespace App\Models;

use App\Enums\PerformanceImportChannel;
use App\Enums\PerformanceImportStatus;
use App\Enums\PerformanceImportType;
use Database\Factories\PerformanceImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Provenance for one operational-performance import batch. Written only
 * by App\Services\Performance\PerformanceImportService.
 *
 * For the web "Import Actuals" flow this row also serves as the
 * short-lived STAGED PREVIEW (status = Previewing): the parsed +
 * classified rows live in `preview_payload`, `preview_fingerprint` binds
 * a confirm POST to exactly what was reviewed, and `preview_expires_at`
 * makes a stale preview non-actionable. `file_sha256` is a separate
 * concept — the integrity of the exact uploaded bytes.
 */
class PerformanceImport extends Model
{
    /** @use HasFactory<PerformanceImportFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected $attributes = [
        'channel' => PerformanceImportChannel::CsvImport->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PerformanceImportType::class,
            'channel' => PerformanceImportChannel::class,
            'status' => PerformanceImportStatus::class,
            'dry_run' => 'boolean',
            'preview_payload' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'preview_expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * Pending web preview that has not expired — the only state in which
     * a confirm may act.
     */
    public function isPreviewActionable(): bool
    {
        return $this->status === PerformanceImportStatus::Previewing
            && $this->preview_expires_at !== null
            && $this->preview_expires_at->isFuture();
    }

    public function isPreviewExpired(): bool
    {
        return $this->status === PerformanceImportStatus::Previewing
            && $this->preview_expires_at !== null
            && $this->preview_expires_at->isPast();
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(PerformanceActualLineRevision::class);
    }
}
