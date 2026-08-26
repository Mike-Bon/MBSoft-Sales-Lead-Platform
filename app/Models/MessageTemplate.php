<?php

namespace App\Models;

use App\Enums\CommunicationChannel;
use App\Enums\RecordStatus;
use Database\Factories\MessageTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable message body with `{{variable}}` placeholders (STEP 17),
 * rendered by App\Support\Communication\TemplateRenderer via safe string
 * substitution only — never interpreted as code (no Blade compilation,
 * no eval, no callable resolution). Organisation-wide when team_id is
 * null, otherwise scoped to one team.
 */
class MessageTemplate extends Model
{
    /** @use HasFactory<MessageTemplateFactory> */
    use HasFactory;

    /**
     * Deliberately excludes `created_by` and `team_id`: authorship and
     * team scope are always set explicitly by TemplateController from
     * the authenticated actor, never from request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'channel',
        'subject',
        'body',
        'status',
    ];

    protected $attributes = [
        'status' => RecordStatus::Active->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => CommunicationChannel::class,
            'status' => RecordStatus::class,
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function communications(): HasMany
    {
        return $this->hasMany(Communication::class, 'template_id');
    }
}
