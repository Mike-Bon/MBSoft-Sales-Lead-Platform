<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\CommunicationChannel;
use Database\Factories\WorkflowApprovalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WorkflowApproval extends Model
{
    /** @use HasFactory<WorkflowApprovalFactory> */
    use HasFactory;

    /**
     * Deliberately excludes everything — only ever written by the
     * workflow analyzers/WorkflowExecutionService, never from request
     * input. Deciding (approve/reject) is a separate, explicit
     * ApprovalService action, never a mass-assignable field update.
     *
     * @var list<string>
     */
    protected $fillable = [];

    protected $attributes = [
        'status' => ApprovalStatus::Pending->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => CommunicationChannel::class,
            'status' => ApprovalStatus::class,
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * STEP 39: an approval whose expiry has passed is treated as
     * expired regardless of what its stored `status` column still says
     * — this is the authoritative check ApprovalService uses before
     * ever acting on one, so a scheduler that hasn't yet run a cleanup
     * pass can never let a stale approval through.
     */
    public function isExpired(): bool
    {
        return $this->status === ApprovalStatus::Pending && $this->expires_at->isPast();
    }

    public function isActionable(): bool
    {
        return $this->status === ApprovalStatus::Pending && ! $this->isExpired();
    }

    public function workflowExecution(): BelongsTo
    {
        return $this->belongsTo(WorkflowExecution::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
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

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function whatsAppNumber(): BelongsTo
    {
        return $this->belongsTo(WhatsAppBusinessNumber::class, 'whatsapp_number_id');
    }

    public function communication(): HasOne
    {
        return $this->hasOne(Communication::class);
    }
}
