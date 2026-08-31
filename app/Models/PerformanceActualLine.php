<?php

namespace App\Models;

use Database\Factories\PerformanceActualLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One monthly operational-actuals line — the authoritative operational
 * revenue/unit figure for a branch in a fiscal month. NEVER a CRM
 * Opportunity. `period_month` is the FISCAL ordinal (1 = December …
 * 12 = November). Written only by
 * App\Services\Performance\PerformanceImportService.
 */
class PerformanceActualLine extends Model
{
    /** @use HasFactory<PerformanceActualLineFactory> */
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
            'actual_units' => 'decimal:2',
            'actual_revenue' => 'decimal:2',
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
