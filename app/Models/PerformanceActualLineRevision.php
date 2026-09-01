<?php

namespace App\Models;

use App\Enums\ActualLineChangeType;
use App\Enums\PerformanceImportChannel;
use Database\Factories\PerformanceActualLineRevisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable change record for an operational actual line. Written
 * only by App\Services\Performance\AuthoritativeActualLineWriter, never
 * from request input. Never updated or deleted — there is no
 * `updated_at`.
 */
class PerformanceActualLineRevision extends Model
{
    /** @use HasFactory<PerformanceActualLineRevisionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'period_month' => 'integer',
            'previous_revenue' => 'decimal:2',
            'previous_units' => 'decimal:2',
            'new_revenue' => 'decimal:2',
            'new_units' => 'decimal:2',
            'change_type' => ActualLineChangeType::class,
            'channel' => PerformanceImportChannel::class,
            'created_at' => 'datetime',
        ];
    }

    public function actualLine(): BelongsTo
    {
        return $this->belongsTo(PerformanceActualLine::class, 'performance_actual_line_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function reportingUnit(): BelongsTo
    {
        return $this->belongsTo(ReportingUnit::class);
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(PerformanceImport::class, 'performance_import_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
