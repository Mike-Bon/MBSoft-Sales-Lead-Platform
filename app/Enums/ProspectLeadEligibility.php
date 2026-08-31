<?php

namespace App\Enums;

/**
 * V2.5 (spec §6): the deterministic creation-eligibility of a researched
 * prospect, decided by application logic from the V2.4 duplicate-check
 * result — NEVER by the LLM, the score, the priority, or a webpage.
 */
enum ProspectLeadEligibility: string
{
    /** check_status = ok, duplicate_status = no_match — may proceed straight to human confirmation. */
    case EligibleForConfirmation = 'eligible_for_confirmation';

    /** check_status = ok, duplicate_status = possible_duplicate — the human must review the match and explicitly acknowledge it before confirming. */
    case ReviewRequired = 'review_required';

    /** check_status = ok, duplicate_status = likely_duplicate | exact_duplicate — no ordinary new-lead creation. */
    case BlockedDuplicate = 'blocked_duplicate';

    /** check_status = unavailable — the CRM could not be checked; do not create. */
    case BlockedCheckUnavailable = 'blocked_check_unavailable';

    /** check_status = skipped — the prospect identity was too thin to check; do not create. */
    case BlockedInsufficientIdentity = 'blocked_insufficient_identity';

    /**
     * The single source of truth. `$checkStatus` and `$duplicateStatus`
     * are the V2.4 `check_status` / `duplicate_status` string values.
     */
    public static function forCheck(string $checkStatus, ?string $duplicateStatus): self
    {
        return match ($checkStatus) {
            'unavailable' => self::BlockedCheckUnavailable,
            'skipped' => self::BlockedInsufficientIdentity,
            'ok' => match ($duplicateStatus) {
                'no_match' => self::EligibleForConfirmation,
                'possible_duplicate' => self::ReviewRequired,
                'likely_duplicate', 'exact_duplicate' => self::BlockedDuplicate,
                default => self::BlockedCheckUnavailable,
            },
            default => self::BlockedCheckUnavailable,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::EligibleForConfirmation => 'Eligible for confirmation',
            self::ReviewRequired => 'Review required — possible duplicate',
            self::BlockedDuplicate => 'Blocked — duplicate in CRM',
            self::BlockedCheckUnavailable => 'Blocked — duplicate check unavailable',
            self::BlockedInsufficientIdentity => 'Blocked — not enough identity information',
        };
    }

    /** Can this prospect reach a human "Create Lead" confirmation at all? */
    public function canReachConfirmation(): bool
    {
        return $this === self::EligibleForConfirmation || $this === self::ReviewRequired;
    }

    public function requiresDuplicateAcknowledgement(): bool
    {
        return $this === self::ReviewRequired;
    }

    public function isBlocked(): bool
    {
        return ! $this->canReachConfirmation();
    }
}
