<?php

namespace App\Models;

use App\Enums\OpportunityStage;
use Database\Factories\OpportunityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Opportunity extends Model
{
    /** @use HasFactory<OpportunityFactory> */
    use HasFactory, SoftDeletes;

    /**
     * PHP-side mirror of the database column default — see Lead.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'stage' => 'qualification',
    ];

    /**
     * Deliberately excludes `owner_id` and `team_id` — see Lead.
     *
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'contact_id',
        'lead_id',
        'name',
        'stage',
        'value',
        'currency',
        'probability',
        'expected_close_date',
        'description',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => OpportunityStage::class,
            'value' => 'decimal:2',
            'probability' => 'integer',
            'expected_close_date' => 'date',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function isClosed(): bool
    {
        return $this->stage->isClosed();
    }

    public function isWon(): bool
    {
        return $this->stage->isWon();
    }

    public function isLost(): bool
    {
        return $this->stage->isLost();
    }
}
