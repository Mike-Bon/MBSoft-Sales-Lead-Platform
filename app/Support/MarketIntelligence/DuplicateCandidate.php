<?php

namespace App\Support\MarketIntelligence;

/**
 * V2.4 (spec §16/§17/§27): one authorised CRM organisation a prospect
 * matched, with the transparent reasons. `matchStrength` is an internal
 * 0–100 ordering value ONLY — it is not, and must never be confused
 * with, the V2.3 prospect score, priority, or qualification (spec §15).
 */
final readonly class DuplicateCandidate
{
    /**
     * @param  list<MatchSignal>  $signals
     * @param  array{has_lead: bool, has_opportunity: bool}  $crmLinkage
     */
    public function __construct(
        public int $organizationId,
        public string $name,
        public ?string $website,
        public ?string $domain,
        public ?string $location,
        public DuplicateStatus $classification,
        public int $matchStrength,
        public array $signals,
        public array $crmLinkage,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'crm_record_type' => 'organization',
            'crm_record_id' => $this->organizationId,
            'business_name' => $this->name,
            'website' => $this->website,
            'domain' => $this->domain,
            'location' => $this->location,
            'classification' => $this->classification->value,
            'classification_label' => $this->classification->label(),
            'match_strength' => $this->matchStrength,
            'match_reasons' => array_map(fn (MatchSignal $s) => $s->toArray(), $this->signals),
            'crm_linkage' => $this->crmLinkage,
        ];
    }
}
