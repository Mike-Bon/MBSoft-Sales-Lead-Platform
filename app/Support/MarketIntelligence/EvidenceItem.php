<?php

namespace App\Support\MarketIntelligence;

/**
 * V2.1 evidence + provenance (spec §7/§8). One observed fact about a
 * prospect, tied to the exact public source it came from. Designed so
 * later V2 phases (qualification, scoring, duplicate detection) can
 * reuse the same provenance without re-fetching anything.
 *
 * `summary` is a short factual restatement of what the source showed —
 * never an inference, never a claim the source did not make.
 *
 * V2.2 (spec §11/§20) adds two OPTIONAL classifications used by
 * qualification: `strength` (how strongly this item supports a claim)
 * and `sourceQuality` (what kind of source it came from). They default
 * to null so every V2.1 construction site stays valid unchanged.
 */
final readonly class EvidenceItem
{
    public const TYPE_LOCATION = 'location';

    public const TYPE_PRODUCT = 'product';

    public const TYPE_ONLINE_SELLING = 'online_selling';

    public const TYPE_SHIPPING = 'shipping';

    public const TYPE_SOCIAL_PRESENCE = 'social_presence';

    public const TYPE_CONTACT = 'contact';

    public const TYPE_DESCRIPTION = 'description';

    public const TYPE_MARKETPLACE = 'marketplace';

    public const TYPE_CONTRADICTION = 'contradiction';

    public function __construct(
        public string $type,
        public string $summary,
        public string $sourceUrl,
        public string $sourceDomain,
        public string $observedAt,
        public ?EvidenceStrength $strength = null,
        public ?SourceQuality $sourceQuality = null,
    ) {}

    public function withStrength(EvidenceStrength $strength): self
    {
        return new self($this->type, $this->summary, $this->sourceUrl, $this->sourceDomain, $this->observedAt, $strength, $this->sourceQuality);
    }

    public function withSourceQuality(SourceQuality $quality): self
    {
        return new self($this->type, $this->summary, $this->sourceUrl, $this->sourceDomain, $this->observedAt, $this->strength, $quality);
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'summary' => $this->summary,
            'source_url' => $this->sourceUrl,
            'source_domain' => $this->sourceDomain,
            'observed_at' => $this->observedAt,
            'strength' => $this->strength?->value,
            'source_quality' => $this->sourceQuality?->value,
        ];
    }
}
