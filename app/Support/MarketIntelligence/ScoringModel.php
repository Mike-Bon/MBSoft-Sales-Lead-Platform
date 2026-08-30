<?php

namespace App\Support\MarketIntelligence;

/**
 * V2.3 (spec §4/§5/§19/§20): the transparent 100-point prospect-scoring
 * model — its version, dimension weights, qualification-outcome caps,
 * and priority-band thresholds.
 *
 * Everything here is DATA read from `config('services.market_intelligence.scoring')`
 * (the same pattern as V1's `config('services.business_development')`).
 * The LLM never sees or changes it. `fromConfig()` VALIDATES the
 * assembled model and falls back to the frozen `default()` on anything
 * malformed, so scoring can never run on a broken model (spec §5).
 *
 * No database table, no migration, no settings UI in V2.3.
 */
final readonly class ScoringModel
{
    public const DEFAULT_VERSION = 'v2.3-default-1';

    /** @var list<string> the seven scoring dimensions, in display order */
    public const DIMENSIONS = [
        'industry_fit',
        'geography_fit',
        'online_selling',
        'physical_product_relevance',
        'shipping_signals',
        'digital_activity',
        'evidence_quality',
    ];

    /** @var array<string, int> */
    public const DEFAULT_WEIGHTS = [
        'industry_fit' => 20,
        'geography_fit' => 15,
        'online_selling' => 20,
        'physical_product_relevance' => 15,
        'shipping_signals' => 15,
        'digital_activity' => 10,
        'evidence_quality' => 5,
    ];

    /** @var array<string, int> per qualification outcome — a CEILING, never added points (spec §14) */
    public const DEFAULT_OUTCOME_CAPS = [
        'strong_match' => 100,
        'possible_match' => 85,
        'weak_match' => 55,
        'insufficient_evidence' => 35,
    ];

    /** @var array{high: int, medium: int} */
    public const DEFAULT_BANDS = ['high' => 75, 'medium' => 50];

    /**
     * @param  array<string, int>  $weights
     * @param  array<string, int>  $outcomeCaps
     * @param  array{high: int, medium: int}  $bands
     */
    public function __construct(
        public string $version,
        public array $weights,
        public array $outcomeCaps,
        public array $bands,
        public bool $configValid,
    ) {}

    public static function default(): self
    {
        return new self(self::DEFAULT_VERSION, self::DEFAULT_WEIGHTS, self::DEFAULT_OUTCOME_CAPS, self::DEFAULT_BANDS, true);
    }

    public static function fromConfig(): self
    {
        $config = config('services.market_intelligence.scoring', []);

        $version = is_string($config['model_version'] ?? null) && trim($config['model_version']) !== ''
            ? trim($config['model_version'])
            : self::DEFAULT_VERSION;

        $weights = self::intMap($config['weights'] ?? [], self::DIMENSIONS);
        $caps = self::intMap($config['outcome_caps'] ?? [], array_keys(self::DEFAULT_OUTCOME_CAPS));
        $bands = [
            'high' => (int) ($config['bands']['high'] ?? self::DEFAULT_BANDS['high']),
            'medium' => (int) ($config['bands']['medium'] ?? self::DEFAULT_BANDS['medium']),
        ];

        $candidate = new self($version, $weights, $caps, $bands, true);

        return $candidate->isValid() ? $candidate : self::invalidFallback($version);
    }

    /** Sum of the dimension weights — must be exactly 100 for a valid model. */
    public function maxScore(): int
    {
        return array_sum($this->weights);
    }

    public function weightFor(string $dimension): int
    {
        return $this->weights[$dimension] ?? 0;
    }

    public function capFor(QualificationOutcome $outcome): int
    {
        return $this->outcomeCaps[$outcome->value] ?? 100;
    }

    public function bandFor(int $total): ScorePriority
    {
        return match (true) {
            $total >= $this->bands['high'] => ScorePriority::High,
            $total >= $this->bands['medium'] => ScorePriority::Medium,
            default => ScorePriority::Low,
        };
    }

    public function isValid(): bool
    {
        foreach (self::DIMENSIONS as $dimension) {
            if (! isset($this->weights[$dimension]) || $this->weights[$dimension] < 0) {
                return false;
            }
        }

        if ($this->maxScore() !== 100) {
            return false;
        }

        foreach (self::DEFAULT_OUTCOME_CAPS as $key => $_) {
            $cap = $this->outcomeCaps[$key] ?? null;
            if (! is_int($cap) || $cap < 0 || $cap > 100) {
                return false;
            }
        }

        // Caps must not reward a weaker qualification more than a stronger one.
        if (! ($this->outcomeCaps['strong_match'] >= $this->outcomeCaps['possible_match']
            && $this->outcomeCaps['possible_match'] >= $this->outcomeCaps['weak_match']
            && $this->outcomeCaps['weak_match'] >= $this->outcomeCaps['insufficient_evidence'])) {
            return false;
        }

        // Bands: no overlap, no gap, inside 1..100.
        return $this->bands['medium'] > 0
            && $this->bands['high'] > $this->bands['medium']
            && $this->bands['high'] <= 100;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'max_score' => $this->maxScore(),
            'weights' => $this->weights,
            'outcome_caps' => $this->outcomeCaps,
            'priority_bands' => [
                'high' => $this->bands['high'].'-100',
                'medium' => $this->bands['medium'].'-'.($this->bands['high'] - 1),
                'low' => '0-'.($this->bands['medium'] - 1),
            ],
            'config_valid' => $this->configValid,
        ];
    }

    /**
     * @param  mixed  $raw
     * @param  list<string>  $keys
     * @return array<string, int>
     */
    private static function intMap($raw, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $value = is_array($raw) ? ($raw[$key] ?? null) : null;
            $out[$key] = is_numeric($value) ? (int) $value : (self::DEFAULT_WEIGHTS[$key] ?? self::DEFAULT_OUTCOME_CAPS[$key] ?? 0);
        }

        return $out;
    }

    private static function invalidFallback(string $version): self
    {
        return new self(
            $version === self::DEFAULT_VERSION ? $version : $version.' (invalid config — defaults applied)',
            self::DEFAULT_WEIGHTS,
            self::DEFAULT_OUTCOME_CAPS,
            self::DEFAULT_BANDS,
            false,
        );
    }
}
