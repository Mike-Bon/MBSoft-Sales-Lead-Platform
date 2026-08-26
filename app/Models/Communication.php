<?php

namespace App\Models;

use App\Enums\CommunicationChannel;
use App\Enums\CommunicationDirection;
use App\Enums\CommunicationFailureCode;
use App\Enums\CommunicationStatus;
use Database\Factories\CommunicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An actual external message sent or received through a connected
 * provider (STEP 3) — distinct from Activity, which stays the
 * lightweight, immutable CRM timeline fact. See the creating migration's
 * docblock for the full split rationale.
 *
 * Core content (channel, direction, recipient, body) never changes after
 * creation; status/provider_message_id/delivered_at/read_at/failed_at
 * mutate as SendCommunicationJob and inbound webhooks update the
 * record's lifecycle — so this model is not treated as fully immutable
 * the way Activity is.
 */
class Communication extends Model
{
    /** @use HasFactory<CommunicationFactory> */
    use HasFactory;

    /**
     * Deliberately excludes every identity/ownership/lifecycle field —
     * channel, provider, all account/user/team FKs, all CRM-record FKs,
     * and status/timestamps/failure fields are always set explicitly by
     * CommunicationService/SendCommunicationJob/the webhook controllers,
     * never taken from request input (STEP 20: "never trust frontend
     * owner_id, team_id, sender account ID").
     *
     * @var list<string>
     */
    protected $fillable = [];

    protected $attributes = [
        'status' => CommunicationStatus::Queued->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => CommunicationChannel::class,
            'direction' => CommunicationDirection::class,
            'status' => CommunicationStatus::class,
            'failure_code' => CommunicationFailureCode::class,
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function emailAccount(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class);
    }

    public function whatsAppNumber(): BelongsTo
    {
        return $this->belongsTo(WhatsAppBusinessNumber::class, 'whatsapp_number_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'template_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
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

    /**
     * The Activity timeline entry this communication is reflected as, if
     * any (see activities.communication_id).
     */
    public function activity(): HasOne
    {
        return $this->hasOne(Activity::class);
    }
}
