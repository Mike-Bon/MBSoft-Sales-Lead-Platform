<?php

namespace App\Support\MarketIntelligence;

/**
 * V2.4 (spec §5/§6): the identity of an external prospect to be
 * duplicate-checked — exactly the `identity` block V2.3's
 * `ScoredProspect::toArray()` produces, plus optional pass-through
 * score fields that V2.4 echoes back UNCHANGED (it never recomputes
 * them — spec §27).
 *
 * A ProspectIdentity carries no web-fetching ability and triggers no
 * pipeline. The values were already established by V2.1–V2.3.
 */
final readonly class ProspectIdentity
{
    /**
     * @param  list<string>  $publicProfiles
     */
    public function __construct(
        public string $business,
        public ?string $website,
        public ?string $domain,
        public ?string $location,
        public array $publicProfiles = [],
        // Opaque pass-through — displayed, never used in matching (spec §15/§27).
        public ?int $totalScore = null,
        public ?string $priority = null,
        public ?string $qualificationOutcome = null,
        public ?string $scoringModel = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $identity = is_array($input['identity'] ?? null) ? $input['identity'] : $input;

        return new self(
            business: trim((string) ($identity['business'] ?? $input['business'] ?? '')),
            website: self::str($identity['website'] ?? $input['website'] ?? null),
            domain: self::str($identity['domain'] ?? $input['domain'] ?? null),
            location: self::str($identity['location'] ?? $input['location'] ?? null),
            publicProfiles: array_values(array_filter(
                (array) ($identity['public_profiles'] ?? []),
                fn ($v) => is_string($v) && trim($v) !== '',
            )),
            totalScore: isset($input['total_score']) ? (int) $input['total_score'] : null,
            priority: self::str($input['priority'] ?? null),
            qualificationOutcome: self::str($input['qualification_outcome'] ?? null),
            scoringModel: self::str($input['scoring_model'] ?? null),
        );
    }

    /** The normalised host V2.4 matches on — from `domain`, falling back to `website`. */
    public function normalizedHost(): ?string
    {
        return IdentityNormalizer::host($this->domain) ?? IdentityNormalizer::host($this->website);
    }

    public function normalizedWebsite(): ?string
    {
        return IdentityNormalizer::website($this->website) ?? IdentityNormalizer::host($this->domain);
    }

    /** @return list<string> */
    public function nameTokens(): array
    {
        return IdentityNormalizer::nameTokens($this->business);
    }

    /** @return list<string> */
    public function distinctiveTokens(): array
    {
        return IdentityNormalizer::distinctiveTokens($this->business);
    }

    /**
     * Enough to check? Need a host OR a name with at least one
     * non-empty token (spec §33 malformed-identity handling).
     */
    public function isCheckable(): bool
    {
        return $this->normalizedHost() !== null || $this->nameTokens() !== [];
    }

    /** @return array<string, mixed> */
    public function passthroughScore(): array
    {
        return array_filter([
            'total_score' => $this->totalScore,
            'priority' => $this->priority,
            'qualification_outcome' => $this->qualificationOutcome,
            'scoring_model' => $this->scoringModel,
        ], fn ($v) => $v !== null);
    }

    private static function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
