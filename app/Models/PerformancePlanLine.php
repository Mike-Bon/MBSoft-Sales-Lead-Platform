<?php

namespace App\Models;

use Database\Factories\PerformancePlanLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One monthly phased-budget line. `period_month` is the FISCAL ordinal
 * (1 = December … 12 = November). Written only by
 * App\Services\Performance\PerformanceImportService — never from request
 * input — so it is fully guarded.
 */
class PerformancePlanLine extends Model
{
    /** @use HasFactory<PerformancePlanLineFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected $attributes = [
        'currency' => 'PHP',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'period_month' => 'integer',
            'target_units' => 'decimal:2',
            'target_revenue' => 'decimal:2',
            'imported_at' => 'datetime',
        ];
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
}
