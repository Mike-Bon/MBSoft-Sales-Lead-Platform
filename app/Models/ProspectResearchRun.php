<?php

namespace App\Models;

use App\Enums\ProspectResearchStatus;
use Database\Factories\ProspectResearchRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * V2.0.3: one user-initiated Market Intelligence research request,
 * executed asynchronously by ProspectResearchJob. See the creating
 * migration's docblock for the idempotency/no-sensitive-data rules.
 *
 * Only two writers exist: AssistantController creates the row (status
 * queued) via createOrFirst() on the unique idempotency_key, and
 * ProspectResearchJob transitions it (running -> completed/failed).
 * Nothing else mass-assigns it; `$fillable` is limited to the fields
 * the controller sets at creation.
 */
class ProspectResearchRun extends Model
{
    /** @use HasFactory<ProspectResearchRunFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'conversation_key',
        'idempotency_key',
        'message',
        'status',
    ];

    protected $attributes = [
        'status' => ProspectResearchStatus::Queued->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProspectResearchStatus::class,
            'tools_used' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function markRunning(): void
    {
        $this->forceFill([
            'status' => ProspectResearchStatus::Running,
            'started_at' => now(),
        ])->save();
    }

    /**
     * @param  list<string>  $toolNames
     */
    public function markCompleted(string $result, array $toolNames, ?int $agentInteractionId): void
    {
        $this->forceFill([
            'status' => ProspectResearchStatus::Completed,
            'result' => $result,
            'tools_used' => array_values($toolNames),
            'agent_interaction_id' => $agentInteractionId,
            'error_summary' => null,
            'completed_at' => now(),
        ])->save();
    }

    public function markFailed(string $safeSummary): void
    {
        $this->forceFill([
            'status' => ProspectResearchStatus::Failed,
            // Never persist a raw exception/provider detail — this string
            // is always a fixed, generic, user-facing message set by the
            // job. Bounded defensively.
            'error_summary' => Str::limit($safeSummary, 240, ''),
            'result' => null,
            'completed_at' => now(),
        ])->save();
    }
}
