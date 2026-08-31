<?php

namespace App\Support\MarketIntelligence;

/**
 * V2.4 (spec §27/§29): a prospect after CRM duplicate detection. Adds
 * duplicate status + candidate matches to the identity; carries the
 * V2.3 score / priority / qualification through UNCHANGED (V2.4 never
 * mutates them); hands V2.5 a structured decision input.
 *
 * `checkStatus`:
 *   - 'ok'        — the CRM was checked within the actor's scope
 *   - 'skipped'   — the prospect identity was too thin to check
 *   - 'unavailable' — the CRM lookup failed; NOT the same as NO_MATCH
 *     (spec §33: failure to check is not evidence of no duplicate)
 */
final readonly class DuplicateCheckedProspect
{
    /**
     * @param  list<DuplicateCandidate>  $candidates
     */
    public function __construct(
        public ProspectIdentity $identity,
        public string $checkStatus,
        public ?DuplicateStatus $status,
        public array $candidates,
        public int $candidatesExamined,
        public string $policyVersion,
        public string $scopeNote,
        public string $nextAction,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge([
            'business' => $this->identity->business,
            'website' => $this->identity->website,
            'domain' => $this->identity->domain,
            'check_status' => $this->checkStatus,
            'duplicate_status' => $this->status?->value,
            'duplicate_status_label' => $this->status?->label(),
            'candidate_matches' => array_map(fn (DuplicateCandidate $c) => $c->toArray(), $this->candidates),
            'candidates_examined' => $this->candidatesExamined,
            'match_policy' => $this->policyVersion,
            'scope_note' => $this->scopeNote,
            'next_action' => $this->nextAction,
            // V2.3 fields carried through untouched (spec §27) — only
            // present when the caller supplied them.
            'carried_from_scoring' => $this->identity->passthroughScore(),
        ]);
    }
}
