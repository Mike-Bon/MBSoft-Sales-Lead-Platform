<?php

namespace App\Models;

use App\Enums\AgentInteractionStatus;
use Database\Factories\AgentInteractionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * STEP 35: audit record of one assistant request/response cycle. See
 * the creating migration's docblock for exactly what is and isn't
 * stored. Written only by AssistantService — never by request input.
 */
class AgentInteraction extends Model
{
    /** @use HasFactory<AgentInteractionFactory> */
    use HasFactory;

    /**
     * Deliberately excludes everything — an audit record is only ever
     * written by AssistantService from its own internal state, never
     * from request input.
     *
     * @var list<string>
     */
    protected $fillable = [];

    protected $attributes = [
        'agent' => 'crm-assistant',
        'status' => AgentInteractionStatus::Completed->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AgentInteractionStatus::class,
            'tool_calls' => 'array',
            'usage' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
