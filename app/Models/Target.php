<?php

namespace App\Models;

use App\Enums\TargetPeriodType;
use App\Enums\TargetStatus;
use App\Enums\TargetType;
use Database\Factories\TargetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Target extends Model
{
    /** @use HasFactory<TargetFactory> */
    use HasFactory;

    /**
     * PHP-side mirror of the database column defaults — see
     * App\Models\Lead for why this matters (a freshly-constructed,
     * unsaved model must not have a genuinely null status in memory).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'currency' => 'USD',
    ];

    /**
     * Deliberately excludes `owner_id` and `team_id` — only
     * TargetService writes these (derived/validated from target_type and
     * the request, never taken directly from arbitrary input).
     *
     * @var list<string>
     */
    protected $fillable = [
        'target_type',
        'period_type',
        'period_start',
        'period_end',
        'target_amount',
        'currency',
        'status',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_type' => TargetType::class,
            'period_type' => TargetPeriodType::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'target_amount' => 'decimal:2',
            'status' => TargetStatus::class,
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function isActive(): bool
    {
        return $this->status === TargetStatus::Active;
    }
}
