<?php

namespace App\Models;

use App\Enums\FollowUpStatus;
use App\Enums\LeadPriority;
use App\Enums\LeadStatus;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory, SoftDeletes;

    /**
     * PHP-side mirror of the database column defaults. Without this, a
     * freshly-constructed (unsaved) Lead that doesn't explicitly set
     * status/priority has a genuinely null attribute in memory — the
     * database's own DEFAULT only takes effect once the row is
     * re-fetched, which code immediately after `save()` (e.g.
     * LeadService's activity-log message) does not do.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'new',
        'priority' => 'medium',
    ];

    /**
     * Deliberately excludes `owner_id` and `team_id` — assignment always
     * goes through CrmAssignmentService, which derives/validates them
     * server-side rather than trusting request input (STEP 14).
     *
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'contact_id',
        'source',
        'status',
        'priority',
        'estimated_value',
        'currency',
        'expected_close_date',
        'next_follow_up_at',
        'description',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'priority' => LeadPriority::class,
            'estimated_value' => 'decimal:2',
            'expected_close_date' => 'date',
            'next_follow_up_at' => 'datetime',
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

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /**
     * Classify next_follow_up_at relative to now, in the application's
     * configured timezone. Purely informational — see App\Enums\FollowUpStatus.
     */
    public function followUpStatus(): FollowUpStatus
    {
        if (! $this->next_follow_up_at) {
            return FollowUpStatus::NotSet;
        }

        $now = Carbon::now();
        $dueDate = $this->next_follow_up_at;

        if ($dueDate->isToday()) {
            return FollowUpStatus::DueToday;
        }

        return $dueDate->lessThan($now) ? FollowUpStatus::Overdue : FollowUpStatus::Upcoming;
    }
}
