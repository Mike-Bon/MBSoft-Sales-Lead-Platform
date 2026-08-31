<?php

namespace App\Models;

use App\Enums\ReportingUnitStatus;
use Database\Factories\ReportingUnitFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An internal branch / reporting location belonging to one Team. See the
 * creating migration for why this is not an Organization. Written only
 * by the operational-performance importer and (later) an admin screen —
 * never from arbitrary request input, so `team_id` is not fillable.
 */
class ReportingUnit extends Model
{
    /** @use HasFactory<ReportingUnitFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'status',
        'sort_order',
    ];

    protected $attributes = [
        'status' => ReportingUnitStatus::Active->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReportingUnitStatus::class,
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function planLines(): HasMany
    {
        return $this->hasMany(PerformancePlanLine::class);
    }

    public function actualLines(): HasMany
    {
        return $this->hasMany(PerformanceActualLine::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ReportingUnitStatus::Active->value);
    }
}
