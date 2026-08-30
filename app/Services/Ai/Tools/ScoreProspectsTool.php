<?php

namespace App\Services\Ai\Tools;

use App\Contracts\Ai\AgentTool;
use App\Models\User;
use App\Services\MarketIntelligence\ProspectScoringService;
use App\Support\Ai\ToolDefinition;
use App\Support\MarketIntelligence\DiscoveryCriteria;
use App\Support\MarketIntelligence\QualificationCriteria;
use App\Support\MarketIntelligence\QualificationCriterion;
use App\Support\MarketIntelligence\ScoringModel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * V2.3: the third (and last) Market Intelligence tool. It qualifies the
 * businesses a discovery request surfaces (V2.2 pipeline) and then
 * applies the transparent, config-backed 100-point prospect-scoring
 * model, returning a ranked list of businesses each with a full
 * dimension breakdown, a priority band, and the scoring model version.
 *
 * By construction it exposes NO way for the caller to influence the
 * number: there is no weight, threshold, priority, bonus, or score
 * parameter. Weights come from `config('services.market_intelligence.scoring')`
 * only. Same isolation as the other two MI tools — NO CRM, NO duplicate
 * detection (V2.4), NO Cost-to-Serve, NO send/draft, NO SQL, NO
 * arbitrary URL.
 *
 * Authorization is re-derived from $actor (Manager or Team Head only).
 */
class ScoreProspectsTool implements AgentTool
{
    public function __construct(private readonly ProspectScoringService $scoring) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'score_prospects',
            description: 'Qualify the businesses matching a discovery request and then score them with the transparent, configurable 100-point business-development prioritisation model. Returns a ranked list, each business with its total score (0-100), priority band (high/medium/low), the per-dimension breakdown with the evidence behind each dimension, what is still unknown, and the scoring model version. The score is decided by the application from the qualification evidence — NOT a conversion probability, NOT a revenue estimate, NOT a CRM action. Nothing is created or contacted. Provide at least a location, an industry, or one product keyword.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'location' => ['type' => 'string', 'description' => 'Geographic area the businesses should be in, e.g. "Cebu City".'],
                    'industry' => ['type' => 'string', 'description' => 'Industry or business category, e.g. "cosmetics".'],
                    'product_keywords' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Specific products/categories to look for.'],
                    'online_signals' => [
                        'type' => 'array',
                        'items' => ['type' => 'string', 'enum' => DiscoveryCriteria::ALLOWED_ONLINE_SIGNALS],
                        'description' => 'Which public online-selling presences the user cares about.',
                    ],
                    'exclude_keywords' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Terms to steer away from.'],
                    'hard_criteria' => [
                        'type' => 'array',
                        'items' => ['type' => 'string', 'enum' => QualificationCriterion::KNOWN_KEYS],
                        'description' => 'Qualification criteria that MUST be met. Defaults are derived from the discovery request.',
                    ],
                    'supporting_criteria' => [
                        'type' => 'array',
                        'items' => ['type' => 'string', 'enum' => QualificationCriterion::KNOWN_KEYS],
                        'description' => 'Nice-to-have qualification signals.',
                    ],
                    'focus_domains' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Optional: restrict scoring to these domains from a previous discovery/qualification result.',
                    ],
                    'max_results' => ['type' => 'integer', 'description' => 'How many businesses to score (capped by the application).'],
                ],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function execute(User $actor, array $arguments): array
    {
        if (! $actor->isManager() && ! $actor->isTeamHead()) {
            throw new AuthorizationException('Prospect scoring is available to Managers and Team Heads only.');
        }

        $config = config('services.market_intelligence');

        $discoveryCriteria = DiscoveryCriteria::fromArray($arguments, (int) ($config['max_results'] ?? 20));

        $qualificationCriteria = QualificationCriteria::fromArray(
            $arguments,
            $discoveryCriteria,
            (int) ($config['max_qualification_prospects'] ?? 8),
        );

        return $this->scoring->score(
            $actor,
            $discoveryCriteria,
            $qualificationCriteria,
            ScoringModel::fromConfig(),
            $this->cleanDomains($arguments['focus_domains'] ?? []),
        );
    }

    /**
     * @return list<string>
     */
    private function cleanDomains(mixed $value): array
    {
        $items = is_array($value) ? $value : (is_string($value) ? explode(',', $value) : []);
        $clean = [];

        foreach ($items as $item) {
            if (is_string($item) && trim($item) !== '') {
                $clean[] = mb_substr(trim($item), 0, 120);
            }
        }

        return array_values(array_slice(array_unique($clean), 0, 20));
    }
}
