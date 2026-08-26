<?php

namespace App\Models;

use App\Enums\WorkflowScopeType;
use App\Enums\WorkflowStatus;
use App\Enums\WorkflowType;
use Database\Factories\WorkflowExecutionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One audited run of a Phase 8 workflow — see the creating migration's
 * docblock for the idempotency/scope-ownership rules.
 */
class WorkflowExecution extends Model
{
    /** @use HasFactory<WorkflowExecutionFactory> */
    use HasFactory;

    /**
     * Deliberately excludes everything — only ever written by
     * WorkflowExecutionService from its own internal state, never from
     * request input.
     *
     * @var list<string>
     */
    protected $fillable = [];

    protected $attributes = [
        'trigger' => 'scheduled',
        'status' => WorkflowStatus::Pending->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'workflow' => WorkflowType::class,
            'status' => WorkflowStatus::class,
            'scope_type' => WorkflowScopeType::class,
            'findings' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'scope_team_id');
    }

    public function agentInteraction(): BelongsTo
    {
        return $this->belongsTo(AgentInteraction::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(WorkflowApproval::class);
    }
}
