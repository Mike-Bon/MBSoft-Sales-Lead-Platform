<?php

namespace App\Models;

use App\Enums\ProspectLeadEligibility;
use App\Enums\ProspectProposalStatus;
use Database\Factories\ProspectLeadProposalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * V2.5: a prospect → CRM lead proposal. Not a Lead, not an Organization.
 *
 * `$fillable = []` on purpose — every column is written by
 * ProspectLeadProposalService / ProspectLeadCreationService only, never
 * from request input. The confirm request supplies *edited CRM field
 * values*, which are validated and applied by the service; it never
 * mass-assigns the model.
 */
class ProspectLeadProposal extends Model
{
    /** @use HasFactory<ProspectLeadProposalFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [];

    protected $attributes = [
        'status' => ProspectProposalStatus::Pending->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProspectProposalStatus::class,
            'eligibility' => ProspectLeadEligibility::class,
            'prospect_snapshot' => 'array',
            'proposed_organization' => 'array',
            'proposed_lead' => 'array',
            'duplicate_ack_required' => 'boolean',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->status === ProspectProposalStatus::Pending && $this->expires_at->isPast();
    }

    /** Pending, not expired — the only state in which a confirmation may act. */
    public function isActionable(): bool
    {
        return $this->status === ProspectProposalStatus::Pending && ! $this->isExpired();
    }

    /**
     * Deterministic content fingerprint (spec §17). Binds a confirmation
     * to one specific proposal — its id + actor + the exact canonical CRM
     * fields + duplicate state + acknowledgement requirement + policy
     * version the human reviewed. Any material change produces a
     * different hash (so a stale confirmation stops matching), and
     * Proposal A's fingerprint can never confirm Proposal B.
     *
     * @param  array<string, mixed>  $organization
     * @param  array<string, mixed>  $lead
     */
    public static function fingerprintFor(
        array $organization,
        array $lead,
        int $userId,
        string $duplicateCheckStatus,
        ?string $duplicateStatus,
        bool $ackRequired,
        string $policyVersion,
        ?int $proposalId = null,
    ): string {
        $canonical = [
            'proposal_id' => $proposalId,
            'organization' => self::canonicalise($organization),
            'lead' => self::canonicalise($lead),
            'user_id' => $userId,
            'duplicate_check_status' => $duplicateCheckStatus,
            'duplicate_status' => $duplicateStatus,
            'ack_required' => $ackRequired,
            'policy_version' => $policyVersion,
        ];

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function currentFingerprint(): string
    {
        return self::fingerprintFor(
            $this->proposed_organization,
            $this->proposed_lead,
            $this->user_id,
            $this->duplicate_check_status,
            $this->duplicate_status,
            $this->duplicate_ack_required,
            $this->policy_version,
            $this->id,
        );
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private static function canonicalise(array $fields): array
    {
        $normalised = [];
        foreach ($fields as $key => $value) {
            $normalised[$key] = is_string($value) ? trim(mb_strtolower($value)) : $value;
        }
        ksort($normalised);

        return $normalised;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
