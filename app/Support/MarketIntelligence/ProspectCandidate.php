<?php

namespace App\Support\MarketIntelligence;

/**
 * V2.1: one discovered candidate business. It is a RESEARCH RESULT, not
 * a CRM record — nothing here is or becomes a Lead/Account/Opportunity
 * in V2.1.
 *
 * Every candidate that reaches the caller has at least one EvidenceItem
 * with a real source URL (ProspectDiscoveryService drops candidates
 * with no evidence). `missing` lists the requested criteria for which
 * no evidence was found. `confidence` is a plain band derived
 * deterministically from how much corroborating evidence exists — NOT a
 * numeric lead score (that is V2.3).
 */
final readonly class ProspectCandidate
{
    public const CONFIDENCE_LOW = 'low';

    public const CONFIDENCE_MEDIUM = 'medium';

    public const CONFIDENCE_HIGH = 'high';

    /**
     * @param  list<string>  $observedProducts
     * @param  list<string>  $socialPresence  full URLs of public social/business profiles found
     * @param  list<EvidenceItem>  $evidence
     * @param  list<string>  $missing
     */
    public function __construct(
        public string $name,
        public ?string $website,
        public ?string $domain,
        public ?string $location,
        public ?string $category,
        public array $observedProducts,
        public bool $onlineSellingEvidence,
        public bool $shippingEvidence,
        public array $socialPresence,
        public array $evidence,
        public array $missing,
        public string $confidence,
        public string $recommendedNextStep,
    ) {}

    /**
     * V2.2: a copy with extra evidence appended (qualification's bounded
     * additional research — spec §18). Immutable; nothing else changes.
     *
     * @param  list<EvidenceItem>  $items
     */
    public function withAdditionalEvidence(array $items): self
    {
        return new self(
            $this->name, $this->website, $this->domain, $this->location, $this->category,
            $this->observedProducts, $this->onlineSellingEvidence, $this->shippingEvidence,
            $this->socialPresence, array_values(array_merge($this->evidence, $items)),
            $this->missing, $this->confidence, $this->recommendedNextStep,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'website' => $this->website,
            'domain' => $this->domain,
            'location' => $this->location,
            'category' => $this->category,
            'observed_products' => $this->observedProducts,
            'online_selling_evidence' => $this->onlineSellingEvidence,
            'shipping_evidence' => $this->shippingEvidence,
            'social_presence' => $this->socialPresence,
            'evidence' => array_map(fn (EvidenceItem $e) => $e->toArray(), $this->evidence),
            'missing_information' => $this->missing,
            'discovery_confidence' => $this->confidence,
            'recommended_next_step' => $this->recommendedNextStep,
        ];
    }
}
