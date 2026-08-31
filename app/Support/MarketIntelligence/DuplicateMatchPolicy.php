<?php

namespace App\Support\MarketIntelligence;

/**
 * V2.4 (spec §28): the deterministic matching policy — its version and
 * the few thresholds the pure matcher uses. Config-backed
 * (`config('services.market_intelligence.duplicate_check')`, the same
 * pattern as the V2.3 scoring model), validated on load with a
 * fall-back to frozen defaults. No database table, no migration.
 */
final readonly class DuplicateMatchPolicy
{
    public const DEFAULT_VERSION = 'v2.4-default-1';

    public function __construct(
        public string $version,
        public float $fuzzyNameDiceThreshold,
        public int $minDistinctiveNameTokens,
        public int $maxCandidatesPerProspect,
        public int $candidateScanCap,
        public int $maxProspectsPerCheck,
        public bool $configValid,
    ) {}

    public static function default(): self
    {
        return new self('v2.4-default-1', 0.85, 2, 5, 50, 10, true);
    }

    public static function fromConfig(): self
    {
        $config = config('services.market_intelligence.duplicate_check', []);

        $version = is_string($config['policy_version'] ?? null) && trim($config['policy_version']) !== ''
            ? trim($config['policy_version'])
            : self::DEFAULT_VERSION;

        $candidate = new self(
            version: $version,
            fuzzyNameDiceThreshold: (float) ($config['fuzzy_name_dice_threshold'] ?? 0.85),
            minDistinctiveNameTokens: (int) ($config['min_distinctive_name_tokens'] ?? 2),
            maxCandidatesPerProspect: (int) ($config['max_candidates_per_prospect'] ?? 5),
            candidateScanCap: (int) ($config['candidate_scan_cap'] ?? 50),
            maxProspectsPerCheck: (int) ($config['max_prospects_per_check'] ?? 10),
            configValid: true,
        );

        return $candidate->isValid() ? $candidate : self::invalidFallback($version);
    }

    public function isValid(): bool
    {
        return $this->fuzzyNameDiceThreshold > 0.5
            && $this->fuzzyNameDiceThreshold <= 1.0
            && $this->minDistinctiveNameTokens >= 1
            && $this->maxCandidatesPerProspect >= 1
            && $this->maxCandidatesPerProspect <= 25
            && $this->candidateScanCap >= 1
            && $this->candidateScanCap <= 500
            && $this->maxProspectsPerCheck >= 1
            && $this->maxProspectsPerCheck <= 50;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'fuzzy_name_dice_threshold' => $this->fuzzyNameDiceThreshold,
            'min_distinctive_name_tokens' => $this->minDistinctiveNameTokens,
            'max_candidates_per_prospect' => $this->maxCandidatesPerProspect,
            'candidate_scan_cap' => $this->candidateScanCap,
            'max_prospects_per_check' => $this->maxProspectsPerCheck,
            'config_valid' => $this->configValid,
        ];
    }

    private static function invalidFallback(string $version): self
    {
        $default = self::default();

        return new self(
            $version === self::DEFAULT_VERSION ? $version : $version.' (invalid config — defaults applied)',
            $default->fuzzyNameDiceThreshold,
            $default->minDistinctiveNameTokens,
            $default->maxCandidatesPerProspect,
            $default->candidateScanCap,
            $default->maxProspectsPerCheck,
            false,
        );
    }
}
