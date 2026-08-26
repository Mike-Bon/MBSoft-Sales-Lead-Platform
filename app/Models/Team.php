<?php

namespace App\Models;

use App\Enums\TeamStatus;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * Deliberately does not include `team_head_id`: leadership changes are
     * a meaningful organisational event and always go through
     * TeamManagementService::assignTeamHead(), which also updates the
     * head's own role/team_id consistently and is the intended future
     * audit-log hook point.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'code',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TeamStatus::class,
        ];
    }

    /**
     * The team's current Team Head, if any.
     */
    public function teamHead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'team_head_id');
    }

    /**
     * All users belonging to this team (Team Head and Team Members alike).
     */
    public function members(): HasMany
    {
        return $this->hasMany(User::class, 'team_id');
    }
}
