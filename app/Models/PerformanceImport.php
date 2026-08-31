<?php

namespace App\Models;

use App\Enums\PerformanceImportStatus;
use App\Enums\PerformanceImportType;
use Database\Factories\PerformanceImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Provenance for one operational-performance import batch. Written only
 * by App\Services\Performance\PerformanceImportService.
 */
class PerformanceImport extends Model
{
    /** @use HasFactory<PerformanceImportFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PerformanceImportType::class,
            'status' => PerformanceImportStatus::class,
            'dry_run' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
